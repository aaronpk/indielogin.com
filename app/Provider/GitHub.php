<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait GitHub {

  private function _start_github(&$response, $login_request, $details) {
    $state = generate_state();

    $params = [
      'client_id' => Config::$githubClientID,
      'redirect_uri' => Config::$base.'redirect/github',
      'state' => $state,
      'allow_signup' => 'false',
    ];
    $authorize = 'https://github.com/login/oauth/authorize?'.http_build_query($params);

    $_SESSION['github_expected_user'] = $details['username'];
    pa($details);

    die('Me: '.$login_request['me'].' Click to continue <a href="'.$authorize.'">'.$authorize.'</a>');
  }

  public function redirect_github(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $query = $request->getQueryParams();

    // Verify the state parameter
    if(!isset($_SESSION['state']) || $_SESSION['state'] != $query['state']) {
      die('Invalid state parameter from GitHub');
    }

    $params = [
      'client_id' => Config::$githubClientID,
      'client_secret' => Config::$githubClientSecret,
      'code' => $query['code'],
      'redirect_uri' => Config::$base.'redirect/github',
    ];

    $http = http_client();
    $result = $http->post('https://github.com/login/oauth/access_token', $params, [
      'Accept: application/json'
    ]);

    $token = json_decode($result['body'], true);

    if(!isset($token['access_token'])) {
      // Error authorizing github
      echo 'GitHub error';
      pa($token);
      die();
    }

    // Find out who logged in, and get their profile
    $result = $http->get('https://api.github.com/user', [
      'Accept: application/vnd.github.v3+json',
      'Authorization: Bearer '.$token['access_token']
    ]);

    $profile = json_decode($result['body'], true);

    if(!isset($profile['login'])) {
      // Error fetching the user's profile
      echo 'Error getting GitHub Profile';
      pa($profile);
      die();
    }

    // Verify that the GitHub user that we expected signed in
    if($profile['login'] != $_SESSION['github_expected_user']) {
      echo 'A different GitHub user authenticated';
      die();
    }

    // Verify that their GitHub profile links to the website we expected
    if($profile['blog'] != $_SESSION['expected_me'] && strpos($profile['bio'], $_SESSION['expected_me']) === false) {
      echo 'Your GitHub profile linked to '.$profile['blog'].' but we were expecting '.$_SESSION['expected_me'];
      pa($profile);
      die();
    }

    // Store this in the session to remember them for next time
    $_SESSION['me'] = $_SESSION['expected_me'];

    unset($_SESSION['github_expected_user']);
    unset($_SESSION['expected_me']);
    unset($_SESSION['state']);

    return $this->_finishAuthenticate($response);
  }

}

