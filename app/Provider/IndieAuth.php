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
    $code_verifier = generate_pkce_code_verifier();
    $authorize = \IndieAuth\Client::buildAuthorizationURL($details['authorization_endpoint'], [
      'me' => $login_request['me'],
      'redirect_uri' => getenv('BASE_URL').'redirect/indieauth',
      'client_id' => getenv('BASE_URL'),
      'state' => $state,
      'code_verifier' => $code_verifier,
    ]);

    $userlog->info('Beginning IndieAuth login', ['provider' => $details, 'login' => $login_request]);

    $_SESSION['login_request']['profile'] = $details['authorization_endpoint'];

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
      'grant_type' => 'authorization_code',
      'code' => $query['code'],
      'client_id' => getenv('BASE_URL'),
      'redirect_uri' => getenv('BASE_URL').'redirect/indieauth',
      'code_verifier' => $_SESSION['code_verifier'],
    ];

    $userlog->info('Exchanging the authorization code at the IndieAuth server', [
      'authorization_endpoint' => $_SESSION['login_request']['authorization_endpoint'],
      'params' => $params,
      'login' => $_SESSION['login_request'],
    ]);

    $http = http_client();
    $result = $http->post($_SESSION['login_request']['authorization_endpoint'], http_build_query($params), [
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
      return $this->_userError($response, 'Your IndieAuth server did not return a valid response.', [
        'response' => $debug_txt,
        'response_code' => $result['code'],
        'error_description' => $result['error_description'],
      ]);
    }

    // Make sure "me" returned matches the original or shares an authorization endpoint
    if($_SESSION['expected_me'] != $auth['me']) {
      $newAuthorizationEndpoint = \IndieAuth\Client::discoverAuthorizationEndpoint($auth['me']);

      $userlog->info('Entered URL ('.$_SESSION['expected_me'].') was different than resulting URL ('.$auth['me'].'), verifying authorization server');

      if(!$newAuthorizationEndpoint) {
        $userlog->warning('No authorization endpoint found', ['response' => $auth, 'expected' => $_SESSION['expected_me']]);
        return $this->_userError($response, 'Error verifying the login attempt. Could not find an authorization endpoint at the profile URL returned (<b>'.$auth['me'].'</b>)');
      }

      if($_SESSION['login_request']['authorization_endpoint'] != $newAuthorizationEndpoint) {
        $userlog->warning('IndieAuth user mismatch', ['response' => $auth, 'expected' => $_SESSION['expected_me']]);
        return $this->_userError($response, 'Error verifying the login attempt. The profile URL returned (<b>'.$auth['me'].'</b>) doesn\'t have the same authorization endpoint found at <b>'.$_SESSION['expected_me'].'</b>');
      }
    }

    unset($_SESSION['state']);

    $_SESSION['authorization_endpoint'] = $_SESSION['login_request']['authorization_endpoint'];

    // Override the expected "me" with whatever the IndieAuth server returned, since we know it's valid now
    $_SESSION['expected_me'] = $auth['me'];

    $userlog->info('Successful IndieAuth login', ['me' => $auth['me']]);

    return $this->_finishAuthenticate($response);
  }

}

