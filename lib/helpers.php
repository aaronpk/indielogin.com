<?php
date_default_timezone_set('UTC');

if(getenv('ENV')) {
  require(dirname(__FILE__).'/config.'.getenv('ENV').'.php');
} else {
  require(dirname(__FILE__).'/config.php');
}

function view($template, $data=[]) {
  global $templates;
  return $templates->render($template, $data);
}

function e($text) {
  return htmlspecialchars($text);
}

function j($json) {
  return htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES));
}

function generate_state() {
  $_SESSION['state'] = bin2hex(random_bytes(12));
  return $_SESSION['state'];
}

function is_logged_in() {
  return isset($_SESSION) && array_key_exists('me', $_SESSION);
}

function display_date($format, $date) {
  try {
    $d = new DateTime($date);
    return $d->format($format);
  } catch(Exception $e) {
    return false;
  }
}

function login_required(&$response) {
  return $response->withHeader('Location', '/?login_required')->withStatus(302);
}

function http_client() {
  static $http;
  if(!isset($http))
    $http = new \p3k\HTTP(Config::$useragent);
  $http->set_timeout(10);
  return $http;
}
