<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait IndieAuth {

  private function _start_indieauth(&$response, $me, $details) {
    // Encode this request's me/redirect_uri/state in the state parameter to avoid a session?
    $state = generate_state();
    $authorize = \IndieAuth\Client::buildAuthorizationURL($details['authorization_endpoint'], $me, Config::$base.'start/indieauth/redirect', Config::$base, $state, '');

    echo $authorize;
    die();
    return $response->withHeader('Location', $authorize)->withStatus(302);
  }

}

