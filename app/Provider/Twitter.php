<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use Abraham\TwitterOAuth\TwitterOAuth;

trait Twitter {

  private function _start_twitter(&$response, $login_request, $details) {
    $_SESSION['twitter_expected_user'] = $details['username'];

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret);

    $request_token = $twitter->oauth('oauth/request_token', [
      'oauth_callback' => Config::$base . 'redirect/twitter'
    ]);
    $_SESSION['twitter_request_token'] = $request_token;
    $twitter_login_url = $twitter->url('oauth/authorize', ['oauth_token' => $request_token['oauth_token']]);

    return $response->withHeader('Location', $twitter_login_url)->withStatus(302);
  }

  public function redirect_twitter(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $query = $request->getQueryParams();

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
      $_SESSION['twitter_request_token']['oauth_token'], $_SESSION['twitter_request_token']['oauth_token_secret']);
    $credentials = $twitter->oauth('oauth/access_token', ['oauth_verifier' => $query['oauth_verifier']]);

    unset($_SESSION['twitter_request_token']);

    if(!isset($credentials['screen_name'])) {
      // Error authorizing
      echo 'Twitter error';
      pa($credentials);
      die();
    }

    $twitter_user = $credentials['screen_name'];

    if($twitter_user != $_SESSION['twitter_expected_user']) {
      echo 'A different Twitter user authenticated';
      die();
    }

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
      $credentials['oauth_token'], $credentials['oauth_token_secret']);

    // Fetch the full profile to look for the link to their website
    $profile = $twitter->get('users/show', ['screen_name'=>$twitter_user]);

    if(!$profile) {
      echo 'Problem fetching twitter profile';
      die();
    }

    $verified = false;
    $expanded_url = false;

    // Extract the expanded profile URL
    if(isset($profile->url) && $profile->url && isset($profile->entities->url->urls)
      && count($profile->entities->url->urls)) {
      $expanded_url = $profile->entities->url->urls[0]->expanded_url;

      if($expanded_url == $_SESSION['expected_me']) {
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
      if($expanded_url)
        echo 'Your Twitter profile linked to '.$expanded_url.' but we were expecting '.$_SESSION['expected_me'];
      else
        echo 'There was no link in your Twitter profile.';

      pa($profile);
      die();
    }

    unset($_SESSION['twitter_expected_user']);

    return $this->_finishAuthenticate($response);
  }

}

