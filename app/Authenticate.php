<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

class Authenticate {

  public function start(ServerRequestInterface $request, ResponseInterface $response) {
    $params = $request->getQueryParams();

    // Check that the application provided all the necessary parameters

    $errors = [];

    $client_id = false;
    $redirect_uri = false;

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
      $r = $this->_fetchUserHomePage($params['me']);

      $errors = [];

      // Show an error to the user if there was a problem
      if($r['code'] != 200 ) {
        return $this->_userError($response, ['Your website did not return HTTP 200']);
      }

      $parsed = \Mf2\parse($r['body'], $params['me']);
      $rels = $parsed['rels'];
      $relURLs = $parsed['rel-urls'];

      // If there is an IndieAuth authorization_endpoint, redirect there now
      if(isset($rels['authorization_endpoint'])) {
        $authorization_endpoint = $rels['authorization_endpoint'][0];
        // Check that it's a full URL
        if(!\p3k\url\is_url($authorization_endpoint)) {
          return $this->_userError($response, ['We found an authorization_endpoint but it does not look like a URL']);
        }

        $me = \IndieAuth\Client::normalizeMeURL($params['me']);

        // Encode this request's me/redirect_uri/state in the state parameter to avoid a session?
        $state = generate_state();
        $authorize = \IndieAuth\Client::buildAuthorizationURL($authorization_endpoint, $me, Config::$base.'start/indieauth/redirect', Config::$base, $state, '');

        echo $authorize;
        die();
        return $response->withHeader('Location', $authorize)->withStatus(302);
      }

      if(isset($rels['authn'])) {
        // Find which of the rels are supported providers
        $supported = $this->_getSupportedProviders($rels['authn'], ($rels['pgpkey'] ?? []));

print_r($supported);

        // If there are none, then error out now since the user explicitly said not to trust rel=mes
        if(count($supported) == 0) {
          return $this->_userError($response, ['None of the rel=authn URLs found on your page were recognized as a supported provider']);
        }

        // If there is one rel=authn, redirect now
        if(count($supported) == 1) {
          echo 'starting '.$supported[0]['provider'].' auth for '.$supported[0]['url'];
          die();
        }

        // If there is more than one rel=authn, show the chooser


      }

      if(isset($rels['me'])) {
        $supported = $this->_getSupportedProviders($rels['me']);

        // If there is one rel=me, redirect now
        if(count($supported) == 1) {
          echo 'starting '.$supported[0]['provider'].' auth for '.$supported[0]['url'];
          die();
        }

        // If there is more than one rel=me, show the chooser


      }

      // Show an error
      return $this->_userError($response, ['We couldn\'t find any rel=me links.']);
    }

    $response->getBody()->write(view($view, [
      'title' => 'Sign In using '.Config::$name,
      'me' => ($params['me'] ?? ''),
      'client_id' => $client_id,
      'redirect_uri' => $redirect_uri,
      'state' => ($params['state'] ?? ''),
    ]));
    return $response;
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
          'url' => $url
        ];
      } elseif(preg_match('~^mailto:(.+)$~', $url, $match)) {
        $supported[] = [
          'provider' => 'email',
          'url' => $match[1]
        ];
      } else {
        if(in_array($url, $pgps)) {
          $supported[] = [
            'provider' => 'pgp',
            'url' => $url
          ];
        }
      }
    }

    return $supported;
  }

  private function _fetchUserHomePage($url) {
    $http = http_client();
    return $http->get($url, [
      'Accept: text/html'
    ]);
  }

}
