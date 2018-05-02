<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait IndieAuth {

  private function _start_indieauth(&$response, $login_request, $details) {
    $userlog = make_logger('user');

    // Encode this request's me/redirect_uri/state in the state parameter to avoid a session?
    $state = generate_state();
    $authorize = \IndieAuth\Client::buildAuthorizationURL($details['authorization_endpoint'], $login_request['me'], Config::$base.'redirect/indieauth', Config::$base, $state, '');

    $userlog->info('Beginning IndieAuth login', ['provider' => $details, 'login' => $login_request]);

    return $response->withHeader('Location', $authorize)->withStatus(302);
  }

  public function redirect_indieauth(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    // Verify the state parameter
    if(!isset($_SESSION['state']) || $_SESSION['state'] != $query['state']) {
      $userlog->warning('IndieAuth server returned an invalid state parameter', ['query' => $query]);
      return $this->_userError($response, 'Your IndieAuth server did not return a valid state parameter');
    }

    if(!isset($query['code'])) {
      if(isset($query['error'])) {
        $userlog->warning('IndieAuth endpoint returned an error in the redirect', [
          'query' => $query,
          'login' => $_SESSION['login_request'],
        ]);
        return $this->_userError($response, 'Your IndieAuth server returned an error', [
          'response' => $query['error']
        ]);
      } else {
        $userlog->warning('IndieAuth endpoint returned an invalid response in the redirect', [
          'query' => $query,
          'login' => $_SESSION['login_request'],
        ]);
        return $this->_userError($response, 'Your IndieAuth server returned an invalid response');
      }
    }

    $params = [
      'code' => $query['code'],
      'client_id' => Config::$base,
      'redirect_uri' => Config::$base.'redirect/indieauth',
    ];

    $userlog->info('Verifying the authorization code with the IndieAuth server', [
      'authorization_endpoint' => $_SESSION['login_request']['authorization_endpoint'],
      'params' => $params,
      'login' => $_SESSION['login_request'],
    ]);

    $http = http_client();
    $result = $http->post($_SESSION['login_request']['authorization_endpoint'], $params, [
      'Accept: application/json'
    ]);

    $auth = json_decode($result['body'], true);
    if(!$auth) {
      parse_str($result['body'], $auth);
    }

    if(!isset($auth['me'])) {
      if($auth) {
        $debug_txt = json_encode($auth, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES);
        $debug_obj = $auth;
      } else {
        $debug_txt = $debug_obj = $result['body'];
      }
      $userlog->warning('Invalid response from IndieAuth server', ['response' => $debug_obj]);
      return $this->_userError($response, 'Your IndieAuth server did not return a valid response. Below is the response from your server.', [
        'response' => $debug_txt
      ]);
    }

    // Make sure "me" returned is on the same domain
    $expectedHost = parse_url($_SESSION['expected_me'], PHP_URL_HOST);
    $actualHost = parse_url($auth['me'], PHP_URL_HOST);

    if($expectedHost != $actualHost) {
      $userlog->warning('IndieAuth user mismatch', ['response' => $auth, 'expected' => $_SESSION['expected_me']]);
      return $this->_userError($response, 'It looks like a different user signed in. The user <b>'.$auth['me'].'</b> signed in, but we were expecting <b>'.$_SESSION['expected_me'].'</b>');
    }

    unset($_SESSION['state']);

    $_SESSION['authorization_endpoint'] = $_SESSION['login_request']['authorization_endpoint'];

    $userlog->info('Successful IndieAuth login', ['me' => $_SESSION['expected_me']]);

    return $this->_finishAuthenticate($response);
  }

}

