<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use Abraham\TwitterOAuth\TwitterOAuth;

trait Twitter {

  private function _start_twitter(&$response, $login_request, $details) {
    $userlog = make_logger('user');

    $_SESSION['twitter_expected_user'] = $details['username'];
    $_SESSION['login_request']['profile'] = 'https://twitter.com/'.$details['username'];

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret);

    $request_token = $twitter->oauth('oauth/request_token', [
      'oauth_callback' => Config::$base . 'redirect/twitter'
    ]);
    $_SESSION['twitter_request_token'] = $request_token;
    $twitter_login_url = $twitter->url('oauth/authenticate', ['oauth_token' => $request_token['oauth_token']]);

    $userlog->info('Beginning Twitter login', ['provider' => $details, 'login' => $login_request]);

    return $response->withHeader('Location', $twitter_login_url)->withStatus(302);
  }

  public function redirect_twitter(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    if(!isset($_SESSION['twitter_request_token'])) {
      return $response->withHeader('Location', '/')->withStatus(302);
    }

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
      $_SESSION['twitter_request_token']['oauth_token'], $_SESSION['twitter_request_token']['oauth_token_secret']);
    $credentials = $twitter->oauth('oauth/access_token', ['oauth_verifier' => $query['oauth_verifier']]);

    unset($_SESSION['twitter_request_token']);

    if(!isset($credentials['screen_name'])) {
      $userlog->warning('Twitter authorization error', ['response' => $credentials]);
      return $this->_userError($response, 'There was a problem verifying the request from Twitter', [
        'response' => json_encode($credentials, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    $twitter_user = $credentials['screen_name'];

    if($twitter_user != $_SESSION['twitter_expected_user']) {
      $userlog->warning('Twitter user mismatch', ['profile' => $twitter_user, 'expected' => $_SESSION['twitter_expected_user']]);
      return $this->_userError($response, 'You logged in to Twitter as <b>@'.$twitter_user.'</b> but your website links to <b>@'.$_SESSION['twitter_expected_user'].'</b>');
    }

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
      $credentials['oauth_token'], $credentials['oauth_token_secret']);

    // Fetch the full profile to look for the link to their website
    $profile = $twitter->get('users/show', ['screen_name'=>$twitter_user]);

    if(!$profile) {
      $userlog->warning('Error fetching Twitter profile', ['response' => $profile]);
      return $this->_userError($response, 'There was a problem with the request to Twitter', [
        'response' => json_encode($profile, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES)
      ]);
    }

    $verified = false;
    $expanded_url = false;

    // Extract the expanded profile URL
    if(isset($profile->url) && $profile->url && isset($profile->entities->url->urls)
      && count($profile->entities->url->urls)) {
      $expanded_url = \p3k\url\normalize($profile->entities->url->urls[0]->expanded_url); // add slash path

      // Follow this URL for redirects in case they put the non-canonical URL in their bio
      // e.g. they put "http://aaronpk.com" in their bio but that resolves to "https://aaronparecki.com/"

      $expanded_url = fetch_profile($expanded_url);

      if(($expanded_url['final_url'] ?? false) == $_SESSION['expected_me']) {
        $verified = true;
      }
    }

    // If not found in the URL field, check links in the bio
    if(!$verified) {
      if($profile->description) {
        $bio = $profile->description;
        foreach($profile->entities->description->urls as $url) {
          $bio = str_replace($url->url, $url->expanded_url, $bio);
        }
        if(strpos($bio, $_SESSION['expected_me']) !== false) {
          $verified = true;
        }
      }
    }

    if(!$verified) {
      $userlog->warning('Twitter URL mismatch', ['bio' => $bio, 'website' => $expanded_url, 'expected' => $_SESSION['expected_me']]);

      if($expanded_url)
        $msg = 'Your Twitter profile linked to <b>'.e($expanded_url['final_url']).'</b> but we were expecting to see <b>'.$_SESSION['expected_me'].'</b>. Make sure you link to <b>'.$_SESSION['expected_me'].'</b> in your Twitter profile.';
      else
        $msg = 'There were no links found in your Twitter profile.';

      return $this->_userError($response, $msg);

    }

    $userlog->info('Successful Twitter login', ['username' => $_SESSION['twitter_expected_user']]);

    unset($_SESSION['twitter_expected_user']);

    return $this->_finishAuthenticate($response);
  }

}

