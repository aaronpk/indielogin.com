<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

class Authenticate {

  use Provider\GitHub;
  use Provider\Twitter;
  use Provider\IndieAuth;

  public function start(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getQueryParams();

    // Check that the application provided all the necessary parameters

    $errors = [];

    $client_id = false;
    $redirect_uri = false;
    $state = false;

    if(!isset($params['client_id'])) {
      $errors[] = 'The request is missing the client_id parameter';
    } else if(!\p3k\url\is_url($params['client_id'])) {
      $errors[] = 'The client_id parameter provided is not a URL';
    } else {
      $client_id = $params['client_id'];
    }

    if(!isset($params['redirect_uri'])) {
      $errors[] = 'The request is missing the redirect_uri parameter';
    } else if(!\p3k\url\is_url($params['redirect_uri'])) {
      $errors[] = 'The redirect_uri parameter provided is not a complete URL';
    } else {
      $redirect_uri = $params['redirect_uri'];

      // check that the redirect uri is on the same domain as the client id
      if($client_id) {
        if(parse_url($client_id, PHP_URL_HOST) != parse_url($redirect_uri, PHP_URL_HOST)) {
          $errors[] = 'The client_id and redirect_uri must be on the same domain';
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
      $response->getBody()->write(view('auth/app-error', [
        'title' => Config::$name.' Error',
        'errors' => $errors
      ]));
      return $response;
    }

    if(!isset($params['me'])) {
      $view = 'auth/form';
    } else {

      // Check if there is a cookie identifying the user and show a prompt instead



      // Verify the "me" parameter is a URL



      $view = 'auth/start';

      // Fetch the user's home page now
      $profile = fetch_profile($params['me']);

      $errors = [];

      // Show an error to the user if there was a problem
      if($profile['code'] != 200 ) {
        return $this->_userError($response, ['Your website did not return HTTP 200']);
      }

      // Store the canonical URL of the user
      $_SESSION['expected_me'] = $profile['me'];
      $login_request['me'] = $profile['me'];

      $rels = $profile['rels'];

      // If there is an IndieAuth authorization_endpoint, redirect there now
      if(count($rels['authorization_endpoint'])) {
        $authorization_endpoint = $rels['authorization_endpoint'][0];

        // Check that it's a full URL
        if(!\p3k\url\is_url($authorization_endpoint)) {
          return $this->_userError($response, ['We found an authorization_endpoint but it does not look like a URL']);
        }

        $login_request['authorization_endpoint'] = $authorization_endpoint;

        return $this->_startAuthenticate($response, $login_request, [
          'provider' => 'indieauth',
          'authorization_endpoint' => $authorization_endpoint,
        ]);
      }

      if(count($rels['authn'])) {
        // Find which of the rels are supported providers
        $supported = $this->_getSupportedProviders($rels['authn'], ($rels['pgpkey'] ?? []));

        // If there are none, then error out now since the user explicitly said not to trust rel=mes
        if(count($supported) == 0) {
          return $this->_userError($response, ['None of the rel=authn URLs found on your page were recognized as a supported provider']);
        }

        // If there is one rel=authn, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=authn, show the chooser


      }

      if(count($rels['me'])) {
        $supported = $this->_getSupportedProviders($rels['me']);

        // If there is one rel=me, redirect now
        if(count($supported) == 1) {
          return $this->_startAuthenticate($response, $login_request, $supported[0]);
        }

        // If there is more than one rel=me, show the chooser

      }

      // Show an error
      return $this->_userError($response, ['We couldn\'t find any rel=me links.']);
    }

    $response->getBody()->write(view($view, [
      'title' => 'Sign In using '.Config::$name,
      'me' => ($profile['me'] ?? ($params['me'] ?? '')),
      'client_id' => $client_id,
      'redirect_uri' => $redirect_uri,
      'state' => $state,
    ]));
    return $response;
  }

  public function verify(ServerRequestInterface $request, ResponseInterface $response) {
    $params = $request->getParsedBody();

    if(!isset($params['code'])) {
      die('missing code');
    }

    if(!isset($params['client_id'])) {
      die('missing client_id');
    }

    if(!isset($params['redirect_uri'])) {
      die('missing redirect_uri');
    }

    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login) {
      die('code expired');
    }

    $login = json_decode($login, true);

    // Verify client_id and redirect_uri match
    if($params['client_id'] != $login['client_id']) {
      die('client_id mismatch');
    }

    if($params['redirect_uri'] != $login['redirect_uri']) {
      die('redirect_uri mismatch');
    }

    $response->getBody()->write(json_encode([
      'me' => $login['me']
    ]));
    return $response->withHeader('Content-type', 'application/json');
  }

  private function _startAuthenticate(&$response, $login_request, $details) {
    $_SESSION['login_request'] = $login_request;

    $method = '_start_'.$details['provider'];
    return $this->{$method}($response, $login_request, $details);
  }

  private function _finishAuthenticate(&$response) {
    // Generate a temporary authorization code to store the user details
    $code = bin2hex(random_bytes(32));

    $params = [
      'code' => $code,
      'state' => $_SESSION['login_request']['state'],
    ];
    $redirect = \p3k\url\add_query_params_to_url($_SESSION['login_request']['redirect_uri'], $params);

    redis()->setex('indielogin:code:'.$code, 60, json_encode($_SESSION['login_request']));

    unset($_SESSION['login_request']);

    return $response->withHeader('Location', $redirect)->withStatus(302);
  }

  private function _userError(&$response, $errors) {
    $response->getBody()->write(view('auth/user-error', [
      'title' => 'Error',
      'errors' => $errors,
    ]));
    return $response;
  }

  private function _getSupportedProviders($rels, $pgps=[]) {
    $supported = [];

    foreach($rels as $url) {
      if(preg_match('~^https:?//(?:www\.)?(github|twitter)\.com/([a-z0-9_]+$)~', $url, $match)) {
        $supported[] = [
          'provider' => $match[1],
          'username' => $match[2],
        ];
      } elseif(preg_match('~^mailto:(.+)$~', $url, $match)) {
        $supported[] = [
          'provider' => 'email',
          'email' => $match[1],
        ];
      } else {
        if(in_array($url, $pgps)) {
          $supported[] = [
            'provider' => 'pgp',
            'key' => $url
          ];
        }
      }
    }

    return $supported;
  }


}
