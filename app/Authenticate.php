<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use ORM;

class Authenticate {

  use Provider\GitHub;
  use Provider\Twitter;
  use Provider\IndieAuth;
  use Provider\Email;
  use Provider\PGP;

  public function start(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $params = $request->getQueryParams();

    unset($_SESSION['twitter_expected_user']);
    unset($_SESSION['github_expected_user']);
    unset($_SESSION['expected_me']);
    unset($_SESSION['me_entered']);

    // Check that the application provided all the necessary parameters

    $errors = [];

    $client_id = false;
    $redirect_uri = false;
    $state = false;

    if(!isset($params['client_id'])) {
      $errors[] = 'The request is missing the client_id parameter';
    } else if(!\p3k\url\is_url($params['client_id'])) {
      $errors[] = 'The client_id parameter provided is not a URL';
    } else if(strpos($params['client_id'], '.') === false && parse_url($params['client_id'],PHP_URL_HOST) != 'localhost') {
      $errors[] = 'The client_id parameter must be a full URL';
    } else {
      $client_id = $params['client_id'];
    }

    $client = false;
    if($client_id && parse_url($client_id, PHP_URL_HOST) != 'localhost') {
      $client = ORM::for_table('clients')->where('client_id', $client_id)->find_one();
      if(!$client) {
        $errors[] = 'This client_id is not registered ('.htmlspecialchars($client_id).')';
      }
    }

    if(!isset($params['redirect_uri'])) {
      $errors[] = 'The request is missing the redirect_uri parameter';
    } else if(!\p3k\url\is_url($params['redirect_uri'])) {
      $errors[] = 'The redirect_uri parameter provided is not a complete URL';
    } else {
      $redirect_uri = $params['redirect_uri'];

      // check that the redirect uri is on the same domain as the client id,
      // or that the redirect uri is a subdomain of the client id
      if($client_id) {
        $client_host = parse_url($client_id, PHP_URL_HOST);
        $redirect_host = parse_url($redirect_uri, PHP_URL_HOST);
        // TODO: need to somehow prevent TLDs like .co.uk from being used as a client_id
        if(
          ($client_host != $redirect_host)
          &&
          (strpos($redirect_host, '.'.$client_host) === false)
        ) {
          if($client) {
            // If the client_id and redirect_uri have a different domain, ensure it's registered
            $registered = ORM::for_table('redirect_uris')
              ->where('client_id', $client->id)
              ->where('redirect_uri', $redirect_uri)
              ->find_one();
          } else {
            $registered = false;
          }
          if(!$registered) {
            $errors[] = 'The client_id and redirect_uri must be on the same domain';
          }
        }
      }
    }

    if(!isset($params['state'])) {
      $errors[] = 'The request is missing the state parameter';
    } else {
      $state = $params['state'];
    }

    $login_request = [
      'client_id' => $client_id,
      'redirect_uri' => $redirect_uri,
      'state' => $state,
    ];


    if(count($errors)) {
      $devlog->info('Bad auth request', ['errors' => $errors, 'params' => $params]);

      $response->getBody()->write(view('auth/dev-error', [
        'title' => getenv('APP_NAME').' Error',
        'errors' => $errors
      ]));
      return $response;
    }

    if(!isset($params['me'])) {
      if(isset($params['action']) && $params['action'] == 'logout')
        unset($_SESSION['me']);

      if(isset($params['prompt']) && $params['prompt'] == 'login')
        unset($_SESSION['me']);

      if(isset($_SESSION['me']))
        $_SESSION['expected_me'] = $_SESSION['me'];

      // If the developer isn't expecting a particular user, use the session user if present
      $response->getBody()->write(view('auth/login-form', [
        'title' => 'Sign In using '.getenv('APP_NAME'),
        'me' => $_SESSION['me'] ?? '',
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'state' => $state,
      ]));
      return $response;
    } else {

      // Verify the "me" parameter is a URL
      if(!\p3k\url\is_url($params['me'])) {
        $userlog->info('Invalid "me" entered', ['me' => $params['me']]);
        return $this->_userError($response, 'You entered something that doesn\'t look like a URL. Please go back and try again.');
      }

      $_SESSION['me_entered'] = $params['me'];

      // Fetch the user's home page now
      $profile = fetch_profile($params['me']);

      // If the user-entered 'me' is the same as the one in the session, skip authentication and show a prompt
      // But don't show this prompt to people who have an authorization endpoint or if prompt=login
      if(!isset($_SESSION['authorization_endpoint'])
        && (($_GET['prompt'] ?? false) != 'login')
        && isset($_SESSION['me']) && $_SESSION['me'] == $profile['final_url']) {

        $switch_account = '/auth?'.http_build_query([
          'action' => 'logout',
          'client_id' => $client_id,
          'redirect_uri' => $redirect_uri,
          'state' => $state,
        ]);

        $code = random_string();
        $login_request['provider'] = 'session';
        redis()->setex('indielogin:select:'.$code, 120, json_encode($login_request));
        $_SESSION['expected_me'] = $_SESSION['me'];
        $_SESSION['me_entered'] = $_SESSION['me'];

        $response->getBody()->write(view('auth/prompt', [
          'title' => 'Sign In using '.getenv('APP_NAME'),
          'me' => $_SESSION['expected_me'] ?? '',
          'code' => $code,
          'client_id' => $client_id,
          'redirect_uri' => $redirect_uri,
          'state' => $state,
          'switch_account' => $switch_account
        ]));
        return $response;
      }

      // Otherwise, drop the session 'me' and make the user authenticate again
      unset($_SESSION['me']);



      $errors = [];

      // Show an error to the user if there was a problem
      if($profile['code'] != 200 ) {
        $userlog->warning('Problem connecting to website', ['me' => $params['me'], 'exception' => $profile['exception']]);

        return $this->_userError($response, 'There was a problem connecting to your website', [
          'me' => $params['me'],
          'response' => $profile['exception'],
        ]);
      }

      // Store the canonical URL of the user
      $_SESSION['expected_me'] = $profile['final_url'];
      $login_request['me'] = $profile['final_url'];

      $rels = $profile['rels'];

      // If there is an IndieAuth authorization_endpoint, redirect there now
      if(count($rels['authorization_endpoint'])) {
        $authorization_endpoint = $rels['authorization_endpoint'][0];

        // Check that it's a full URL and was not a relative URL.
        if(!\p3k\url\is_url($authorization_endpoint)) {
          $userlog->warning('Authorization endpoint does not look like a URL', ['me' => $params['me'], 'authorization_endpoint' => $authorization_endpoint]);
          return $this->_userError($response, 'We found an authorization_endpoint but it does not look like a URL');
        }

        $login_request['authorization_endpoint'] = $authorization_endpoint;

        return $this->_startAuthenticate($response, $login_request, [
          'provider' => 'indieauth',
          'authorization_endpoint' => $authorization_endpoint,
        ]);
      }

      // If there are any rel=authn values defined, *only* search those for supported providers.
      // The user has said to only trust these specific providers, so don't fall back to rel=me
      if(count($rels['authn'])) {
        // Find which of the rels are supported providers
        $supported = $this->_getSupportedProviders($rels, 'authn');

        // If there are none, then error out now since the user explicitly said not to trust rel=mes
        if(count($supported) == 0) {
          $userlog->warning('No supported rel=authn URLs', ['me' => $params['me'], 'relauthn' => $rels['authn']]);
          return $this->_userError($response,
            'None of the rel=authn URLs found on your page were recognized as a supported provider', [
              'found' => $rels['authn']
            ]
          );
        }

        // If there is one rel=authn, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=authn, show the chooser
        return $this->_showProviderChooser($response, $login_request, $supported);
      }

      // Check for any rel=me or rel=pgpkey
      if(count($rels['me']) || count($rels['pgpkey'])) {
        $supported = $this->_getSupportedProviders($rels, 'me');

        // If there are no supported rel=me, then show an error
        if(count($supported) == 0) {
          $userlog->warning('No supported rel=me URLs', ['me' => $params['me'], 'relme' => $rels['me']]);
          return $this->_userError($response,
            'None of the rel=me URLs found on your page were recognized as a supported provider', [
              'found' => $rels['me']
            ]
          );
        }

        // If there is one rel=me, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=me, show the chooser
        return $this->_showProviderChooser($response, $login_request, $supported);
      }

      // Show an error
      $userlog->warning('No rel=me URLs found', ['me' => $params['me']]);
      return $this->_userError($response, 'We couldn\'t find any way to authenticate you using your website.');
    }
  }

  public function select(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $userlog = make_logger('user');

    $params = $request->getQueryParams();

    if(!isset($params['code'])) {
      $userlog->info('No code was present in the select request');
      die('bad request');
    }

    $details = redis()->get('indielogin:select:'.$params['code']);
    if(!$details) {
      $userlog->warning('Select code expired');
      return $this->_userError($response, 'The session timed out. Please go back and try again.');
    }

    $details = json_decode($details, true);

    return $this->_startAuthenticate($response, $details['login_request'], $details['provider']);
  }

  public function post_select(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $userlog = make_logger('user');

    $params = $request->getParsedBody();

    if(!isset($params['code'])) {
      $userlog->info('No code was present in the select request');
      die('bad request');
    }

    $details = redis()->get('indielogin:select:'.$params['code']);

    if(!$details) {
      $userlog->warning('Select code expired');
      return $this->_userError($response, 'The session timed out. Please go back and try again.');
    }

    $details = json_decode($details, true);
    $_SESSION['login_request'] = $details;

    return $this->_finishAuthenticate($response);
  }

  public function verify(ServerRequestInterface $request, ResponseInterface $response) {
    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $errors = [];

    if(!isset($params['code'])) {
      $errors[] = 'Request is missing the "code" parameter';
    }

    if(!isset($params['client_id'])) {
      $errors[] = 'Request is missing the "client_id" parameter';
    }

    if(!isset($params['redirect_uri'])) {
      $errors[] = 'Request is missing the "redirect_uri" parameter';
    }

    if(count($errors)) {
      $devlog->info('verify request is missing one or more parameters', ['errors' => $errors, 'params' => $params]);
      $response->getBody()->write(json_encode([
        'error' => 'invalid_request',
        'details' => $errors,
      ]));
      return $response->withStatus(400);
    }

    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login) {
      $devlog->info('authorization code expired', ['params' => $params]);
      $response->getBody()->write(json_encode([
        'error' => 'invalid_request',
        'error_description' => 'The authorization code expired',
      ]));
      return $response->withStatus(400);
    }

    $login = json_decode($login, true);

    // Verify client_id and redirect_uri match
    if($params['client_id'] != $login['client_id']) {
      $devlog->info('client_id mismatch', ['params' => $params, 'login' => $login]);
      $response->getBody()->write(json_encode([
        'error' => 'invalid_grant',
        'error_description' => 'The client_id in the request did not match the client_id the code was issued to',
      ]));
      return $response->withStatus(400);
    }

    if($params['redirect_uri'] != $login['redirect_uri']) {
      $devlog->info('redirect_uri mismatch', ['params' => $params, 'login' => $login]);
      $response->getBody()->write(json_encode([
        'error' => 'invalid_grant',
        'error_description' => 'The redirect_uri in the request did not match the redirect_uri the code was issued to',
      ]));
      return $response->withStatus(400);
    }

    $userlog->info('Completed login for user', ['me' => $login['me'], 'details' => $login]);

    redis()->del('indielogin:code:'.$params['code']);

    $log = ORM::for_table('logins')->where('code', $params['code'])->find_one();
    $log->complete = 1;
    $log->date_complete = date('Y-m-d H:i:s');
    $log->code = '';
    $log->save();

    $response->getBody()->write(json_encode([
      'me' => $login['me']
    ]));
    return $response->withHeader('Content-type', 'application/json');
  }

  private function _showProviderChooser(&$response, $login_request, $providers) {
    $choices = [];

    // Generate a temporary code for each provider
    foreach($providers as $provider) {
      $code = random_string();
      $details = [
        'login_request' => $login_request,
        'provider' => $provider,
      ];
      $choices[] = [
        'code' => $code,
        'provider' => $provider,
      ];
      redis()->setex('indielogin:select:'.$code, 120, json_encode($details));
    }

    // Show the select form
    $response->getBody()->write(view('auth/select', [
      'title' => 'Authenticate',
      'me' => $login_request['me'],
      'client_id' => $login_request['client_id'],
      'redirect_uri' => $login_request['redirect_uri'],
      'choices' => $choices,
    ]));
    return $response;
  }

  private function _startAuthenticate(&$response, $login_request, $details) {
    $_SESSION['login_request'] = $login_request;
    $_SESSION['login_request']['provider'] = $details['provider'];

    $method = '_start_'.$details['provider'];
    return $this->{$method}($response, $login_request, $details);
  }

  private function _finishAuthenticate(&$response) {
    if(!isset($_SESSION['login_request'])) {
      return $response->withHeader('Location', '/')->withStatus(302);
    }

    // Generate a temporary authorization code to store the user details
    $code = random_string();

    $params = [
      'code' => $code,
      'state' => $_SESSION['login_request']['state'],
    ];

    $redirect = \p3k\url\add_query_params_to_url($_SESSION['login_request']['redirect_uri'], $params);

    $_SESSION['login_request']['me'] = $_SESSION['expected_me'];

    redis()->setex('indielogin:code:'.$code, 60, json_encode($_SESSION['login_request']));

    $_SESSION['me'] = $_SESSION['expected_me'];
    unset($_SESSION['expected_me']);

    $login = ORM::for_table('logins')->create();
    $login->date = date('Y-m-d H:i:s');
    $login->client_id = $_SESSION['login_request']['client_id'];
    $login->redirect_uri = $_SESSION['login_request']['redirect_uri'];
    $login->me_resolved = $_SESSION['me'];
    $login->me_entered = $_SESSION['me_entered'];
    $login->authn_provider = $_SESSION['login_request']['provider'] ?? '';
    $login->authn_profile = $_SESSION['login_request']['profile'] ?? '';
    $login->code = $code;
    $login->save();

    unset($_SESSION['login_request']);

    return $response->withHeader('Location', $redirect)->withStatus(302);
  }

  private function _userError(&$response, $error, $opts=[]) {
    $response->getBody()->write(view('auth/user-error', [
      'title' => 'Error',
      'error' => $error,
      'opts' => $opts
    ]));
    return $response;
  }

  private function _getSupportedProviders($rels, $mode='me') {
    $supported = [];

    foreach($rels[$mode] as $url) {
      if(preg_match('~^https?://(?:www\.)?github\.com/([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})$~', $url, $match)) {
        $supported[] = [
          'provider' => 'github',
          'username' => $match[1],
          'display' => 'github.com/'.$match[1],
        ];
      } elseif(preg_match('~^https?://(?:www\.)?twitter\.com/([a-zA-Z0-9_]{1,20})$~', $url, $match)) {
        $supported[] = [
          'provider' => 'twitter',
          'username' => $match[1],
          'display' => 'twitter.com/'.$match[1],
        ];
      } elseif(preg_match('~https?://(?:www\.)?twitter\.com/intent/user\?screen_name=([a-zA-Z0-9_]{1,20})$~', $url, $match)) {
        $supported[] = [
          'provider' => 'twitter',
          'username' => $match[1],
          'display' => 'twitter.com/'.$match[1],
        ];
      } elseif(preg_match('~^mailto:(.+\@.+?)(\?.*)?$~', $url, $match)) {
        $supported[] = [
          'provider' => 'email',
          'email' => $match[1],
          'display' => $match[1],
        ];
      }
    }

    foreach($rels['pgpkey'] as $url) {
      if($mode == 'me' || ($mode == 'authn' && in_array($url, $rels['authn']))) {
        $supported[] = [
          'provider' => 'pgp',
          'key' => $url,
          'display' => $url,
        ];
      }
    }

    return $supported;
  }


}
