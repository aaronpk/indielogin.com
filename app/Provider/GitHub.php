<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;

trait GitHub {

  private function _start_github($login_request, $details) {
    $userlog = make_logger('user');

    $state = generate_state();

    $params = [
      'client_id' => getenv('GITHUB_CLIENT_ID'),
      'redirect_uri' => getenv('BASE_URL').'redirect/github',
      'state' => $state,
      'allow_signup' => 'false',
    ];
    $authorize = 'https://github.com/login/oauth/authorize?'.http_build_query($params);

    $_SESSION['github_expected_user'] = $details['username'];
    $_SESSION['login_request']['profile'] = 'https://github.com/'.$details['username'];

    $userlog->info('Beginning GitHub login', ['provider' => $details, 'login' => $login_request]);

    return redirect_response($authorize, 302);
  }

  public function redirect_github(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    // Verify the state parameter
    if(!isset($_SESSION['state']) || $_SESSION['state'] != $query['state']) {
      die('Invalid state parameter from GitHub');
    }

    unset($_SESSION['state']);

    $params = [
      'client_id' => getenv('GITHUB_CLIENT_ID'),
      'client_secret' => getenv('GITHUB_CLIENT_SECRET'),
      'code' => $query['code'],
      'redirect_uri' => getenv('BASE_URL').'redirect/github',
    ];

    $http = http_client();
    $result = $http->post('https://github.com/login/oauth/access_token', $params, [
      'Accept: application/json'
    ]);

    $token = json_decode($result['body'], true);

    if(!isset($token['access_token'])) {
      $userlog->warning('GitHub authorization error', ['response' => $token]);
      return $this->_userError('There was a problem verifying the request from GitHub', [
        'response' => json_encode($token, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    // Find out who logged in, and get their profile
    $result = $http->get('https://api.github.com/user', [
      'Accept: application/vnd.github.v3+json',
      'Authorization: Bearer '.$token['access_token']
    ]);

    $profile = json_decode($result['body'], true);

    if(!isset($profile['login'])) {
      $userlog->warning('Error fetching user profile', ['response' => $result, 'useragent' => getenv('HTTP_USER_AGENT')]);
      $userlog->warning('Github token', ['token' => $token['access_token']]);
      return $this->_userError('There was a problem with the profile request to GitHub', [
        'response' => json_encode($profile, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    if(preg_match('/debug_/', $query['state'])) {
      return \App\Controller::debug_github_callback($profile, $token);
    }

    // Verify that the GitHub user that we expected signed in
    if(strtolower($profile['login']) != strtolower($_SESSION['github_expected_user'])) {
      $userlog->warning('GitHub user mismatch', ['profile' => $profile, 'expected' => $_SESSION['github_expected_user']]);
      return $this->_userError('You logged in to GitHub as <b>'.$profile['login'].'</b> but your website links to <b>'.$_SESSION['github_expected_user'].'</b>');
    }

    $verified = false;

    // Verify that their GitHub profile links to the website we expected

    // Check for the simple case of an exact URL match
    // if(!empty($profile['blog'])) {
    //   if(urls_are_equivalent($profile['blog']) && $_SESSION['expected_me']) {
    //     $verified = true;
    //   }
    // }

    // Follow redirects on their bio URL in case their github profile has the non-canonical version of their URL
    if(!$verified && !empty($profile['blog'])) {
      $expanded_url = $profile['blog'];

      // Assume https if no scheme was entered in their github profile
      if(!preg_match('/^https?:\/\//', $expanded_url)) {
        $expanded_url = 'https://'.$expanded_url;
      }

      $expanded_url = fetch_profile($expanded_url);

      if(!empty($expanded_url['me'])) {
        if(urls_are_equivalent($expanded_url['me'], $_SESSION['expected_me'])) {
          $verified = true;
          $userlog->info('GitHub blog URL matched expected URL');
        }
      }
    }

    if(!$verified) {
      // Allow a URL in the bio to match
      if(strpos($profile['bio'], $_SESSION['expected_me']) !== false) {
        $verified = true;
          $userlog->info('GitHub URL in bio matched expected URL');
      }
    }

    if(!$verified) {
      // Fetch a link to their "social accounts" to find more links
      $userlog->info('No link found in GitHub profile, fetching social accounts', [
        'profile' => $profile,
        'expected' => $_SESSION['expected_me']
      ]);

      $result = $http->get('https://api.github.com/user/social_accounts', [
        'Accept: application/vnd.github.v3+json',
        'Authorization: Bearer '.$token['access_token']
      ]);

      $social = json_decode($result['body'], true);
      foreach($social as $s) {
        if(urls_are_equivalent($s['url'], $_SESSION['expected_me'])) {
          $verified = true;
          $userlog->info('GitHub social URL matched expected URL');
        }
      }
    }

    if($profile['blog']) {
      $linked_to = 'Your GitHub profile linked to <b>'.e($profile['blog']).'</b> but we were expecting to see <b>'.$_SESSION['expected_me'].'</b>.';
    } else {
      $linked_to = 'We were unable to find a link to '.$_SESSION['expected_me'].' in your GitHub profile.';
    }

    if(!$verified) {
      $userlog->warning('GitHub URL mismatch', ['profile' => $profile, 'expected' => $_SESSION['expected_me']]);
      return $this->_userError($linked_to.' Make sure you link to <b>'.$_SESSION['expected_me'].'</b> in your GitHub profile.');
    }

    $userlog->info('Successful GitHub login', ['username' => $_SESSION['github_expected_user']]);

    unset($_SESSION['github_expected_user']);

    return $this->_finishAuthenticate();
  }

}
