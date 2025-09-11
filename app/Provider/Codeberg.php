<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;

trait Codeberg {

  private function _start_codeberg($login_request, $details) {
    $userlog = make_logger('user');

    $state = generate_state();

    $params = [
      'client_id' => getenv('CODEBERG_CLIENT_ID'),
      'redirect_uri' => getenv('BASE_URL').'redirect/codeberg',
      'state' => $state,
      'allow_signup' => 'false',
		'response_type' => 'code',
    ];
    $authorize = 'https://codeberg.org/login/oauth/authorize?'.http_build_query($params);

    $_SESSION['codeberg_expected_user'] = $details['username'];
    $_SESSION['login_request']['profile'] = 'https://codeberg.com/'.$details['username'];

    $userlog->info('Beginning Codeberg login', ['provider' => $details, 'login' => $login_request]);

    return redirect_response($authorize, 302);
  }

  public function redirect_codeberg(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    // Verify the state parameter
    if(!isset($_SESSION['state']) || $_SESSION['state'] != $query['state']) {
      die('Invalid state parameter from Codeberg');
    }

    unset($_SESSION['state']);

    $params = [
      'client_id' => getenv('CODEBERG_CLIENT_ID'),
      'client_secret' => getenv('CODEBERG_CLIENT_SECRET'),
      'code' => $query['code'],
      'grant_type' => 'authorization_code',
      'redirect_uri' => getenv('BASE_URL').'redirect/codeberg',
    ];

    $http = http_client();
    $result = $http->post('https://codeberg.org/login/oauth/access_token', $params, [
      'Accept: application/json'
    ]);

    $token = json_decode($result['body'], true);

    if(!isset($token['access_token'])) {
      $userlog->warning('Codeberg authorization error', ['response' => $token]);
      return $this->_userError('There was a problem verifying the request from Codeberg', [
        'response' => json_encode($token, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    // Find out who logged in, and get their profile
    $result = $http->get('https://codeberg.org/api/v1/user', [
      'Accept: application/json',
      'Authorization: Bearer '.$token['access_token']
    ]);

    $profile = json_decode($result['body'], true);

    if(!isset($profile['login'])) {
      $userlog->warning('Error fetching user profile', ['response' => $result, 'useragent' => getenv('HTTP_USER_AGENT')]);
      return $this->_userError('There was a problem with the profile request to Codeberg', [
        'response' => json_encode($profile, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    if(preg_match('/debug_/', $query['state'])) {
      return \App\Controller::debug_codeberg_callback($profile);
    }

    // Verify that the Codeberg user that we expected signed in
    if(strtolower($profile['login']) != strtolower($_SESSION['codeberg_expected_user'])) {
      $userlog->warning('Codeberg user mismatch', ['profile' => $profile, 'expected' => $_SESSION['codeberg_expected_user']]);
      return $this->_userError('You logged in to Codeberg as <b>'.$profile['login'].'</b> but your website links to <b>'.$_SESSION['codeberg_expected_user'].'</b>');
    }

    $verified = false;

    // Verify that their Codeberg profile links to the website we expected

    // Check for the simple case of an exact URL match
    if(!empty($profile['website'])) {
      if(urls_are_equivalent($profile['website'], $_SESSION['expected_me'])) {
        $verified = true;
        $userlog->info('Codeberg website URL matched expected URL without fetching', [
          'website' => $profile['website'],
          'expected' => $_SESSION['expected_me']
        ]);
      }
    }

    // Follow redirects on their bio URL in case their Codeberg profile has the non-canonical version of their URL
    if(!$verified && !empty($profile['website'])) {
      $expanded_url = $profile['website'];

      // Assume https if no scheme was entered in their Codeberg profile
      if(!preg_match('/^https?:\/\//', $expanded_url)) {
        $expanded_url = 'https://'.$expanded_url;
      }

      $expanded_url = fetch_profile($expanded_url);

      if(!empty($expanded_url['me'])) {
        if(urls_are_equivalent($expanded_url['me'], $_SESSION['expected_me'])) {
          $verified = true;
          $userlog->info('Codeberg website URL matched expected URL after following redirects', [
            'website' => $profile['website'],
            'expanded_url' => $expanded_url,
            'expected' => $_SESSION['expected_me']
          ]);
        }
      }
    }

    if(!$verified) {
      // Allow a URL in the bio to match
      if(strpos($profile['description'], $_SESSION['expected_me']) !== false) {
        $verified = true;
        $userlog->info('Codeberg URL in bio matched expected URL', [
          'description' => $profile['description'],
          'expected' => $_SESSION['expected_me']
        ]);
      }
    }

    if($profile['website']) {
      $linked_to = 'Your Codeberg profile linked to <b>'.e($profile['website']).'</b> but we were expecting to see <b>'.$_SESSION['expected_me'].'</b>.';
    } else {
      $linked_to = 'We were unable to find a link to '.$_SESSION['expected_me'].' in your Codeberg profile.';
    }

    if(!$verified) {
      $userlog->warning('Codeberg URL mismatch', [
        'profile' => $profile,
        'expected' => $_SESSION['expected_me']
      ]);
      return $this->_userError($linked_to.' Make sure you link to <b>'.$_SESSION['expected_me'].'</b> in your Codeberg profile.');
    }

    $userlog->info('Successful Codeberg login', ['username' => $_SESSION['codeberg_expected_user']]);

    unset($_SESSION['codeberg_expected_user']);

    return $this->_finishAuthenticate();
  }

}
