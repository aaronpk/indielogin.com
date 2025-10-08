<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;

trait GitLab {

  private function _start_gitlab($login_request, $details) {
    $userlog = make_logger('user');

    $state = generate_state();

    $params = [
      'client_id' => getenv('GITLAB_CLIENT_ID'),
      'redirect_uri' => getenv('BASE_URL').'redirect/gitlab',
      'state' => $state,
      'allow_signup' => 'false',
      'response_type' => 'code',
      'scope' => 'read_user',
    ];
    $authorize = 'https://gitlab.com/oauth/authorize?'.http_build_query($params);

    $_SESSION['gitlab_expected_user'] = $details['username'];
    $_SESSION['login_request']['profile'] = 'https://gitlab.com/'.$details['username'];

    $userlog->info('Beginning GitLab login', ['provider' => $details, 'login' => $login_request]);

    return redirect_response($authorize, 302);
  }

  public function redirect_gitlab(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    // Verify the state parameter
    if(!isset($_SESSION['state']) || $_SESSION['state'] != $query['state']) {
      die('Invalid state parameter from GitLab');
    }

    unset($_SESSION['state']);

    $params = [
      'client_id' => getenv('GITLAB_CLIENT_ID'),
      'client_secret' => getenv('GITLAB_CLIENT_SECRET'),
      'code' => $query['code'],
      'grant_type' => 'authorization_code',
      'redirect_uri' => getenv('BASE_URL').'redirect/gitlab',
    ];

    $http = http_client();
    $result = $http->post('https://gitlab.com/oauth/token', $params, [
      'Accept: application/json'
    ]);

    $token = json_decode($result['body'], true);

    if(!isset($token['access_token'])) {
      $userlog->warning('GitLab authorization error', ['response' => $token]);
      return $this->_userError('There was a problem verifying the request from GitLab', [
        'response' => json_encode($token, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    // Find out who logged in, and get their profile
    $result = $http->get('https://gitlab.com/api/v4/user', [
      'Accept: application/json',
      'Authorization: Bearer '.$token['access_token']
    ]);

    $profile = json_decode($result['body'], true);

    if(!isset($profile['username'])) {
      $userlog->warning('Error fetching user profile', ['response' => $result, 'useragent' => getenv('HTTP_USER_AGENT')]);
      return $this->_userError('There was a problem with the profile request to GitLab', [
        'response' => json_encode($profile, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    if(preg_match('/debug_/', $query['state'])) {
      return \App\Controller::debug_gitlab_callback($profile, $token);
    }

    // Verify that the GitLab user that we expected signed in
    if(strtolower($profile['username']) != strtolower($_SESSION['gitlab_expected_user'])) {
      $userlog->warning('GitLab user mismatch', ['profile' => $profile, 'expected' => $_SESSION['gitlab_expected_user']]);
      return $this->_userError('You logged in to GitLab as <b>'.$profile['login'].'</b> but your website links to <b>'.$_SESSION['gitlab_expected_user'].'</b>');
    }

    $verified = false;

    // Verify that their GitLab profile links to the website we expected

    // Check for the simple case of an exact URL match
    if(!empty($profile['website_url'])) {
      if(urls_are_equivalent($profile['website_url'], $_SESSION['expected_me'])) {
        $verified = true;
        $userlog->info('GitLab website URL matched expected URL without fetching', [
          'website_url' => $profile['website_url'],
          'expected' => $_SESSION['expected_me']
        ]);
      }
    }

    // Follow redirects on their bio URL in case their GitLab profile has the non-canonical version of their URL
    if(!$verified && !empty($profile['website_url'])) {
      $expanded_url = $profile['website_url'];

      // Assume https if no scheme was entered in their GitLab profile
      if(!preg_match('/^https?:\/\//', $expanded_url)) {
        $expanded_url = 'https://'.$expanded_url;
      }

      $expanded_url = fetch_profile($expanded_url);

      if(!empty($expanded_url['me'])) {
        if(urls_are_equivalent($expanded_url['me'], $_SESSION['expected_me'])) {
          $verified = true;
          $userlog->info('GitLab website URL matched expected URL after following redirects', [
            'website_url' => $profile['website_url'],
            'expanded_url' => $expanded_url,
            'expected' => $_SESSION['expected_me']
          ]);
        }
      }
    }

    if(!$verified) {
      // Allow a URL in the bio to match
      if(string_contains_url($profile['bio'], $_SESSION['expected_me'])) {
        $verified = true;
        $userlog->info('GitLab URL in bio matched expected URL', [
          'bio' => $profile['bio'],
          'expected' => $_SESSION['expected_me']
        ]);
      }
    }

    if($profile['website_url']) {
      $linked_to = 'Your GitLab profile linked to <b>'.e($profile['website_url']).'</b> but we were expecting to see <b>'.$_SESSION['expected_me'].'</b>.';
    } else {
      $linked_to = 'We were unable to find a link to '.$_SESSION['expected_me'].' in your GitLab profile.';
    }

    if(!$verified) {
      $userlog->warning('GitLab URL mismatch', [
        'profile' => $profile,
        'expected' => $_SESSION['expected_me']
      ]);
      return $this->_userError($linked_to.' Make sure you link to <b>'.$_SESSION['expected_me'].'</b> in your GitLab profile.');
    }

    $userlog->info('Successful GitLab login', ['username' => $_SESSION['gitlab_expected_user']]);

    unset($_SESSION['gitlab_expected_user']);

    return $this->_finishAuthenticate();
  }

}
