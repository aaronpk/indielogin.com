<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

date_default_timezone_set('UTC');

if(getenv('ENV')) {
  require(dirname(__FILE__).'/config.'.getenv('ENV').'.php');
} else {
  require(dirname(__FILE__).'/config.php');
}

function initdb() {
  ORM::configure('mysql:host=' . Config::$db['host'] . ';dbname=' . Config::$db['database']);
  ORM::configure('username', Config::$db['username']);
  ORM::configure('password', Config::$db['password']);
}

function make_logger($channel) {
  $log = new Logger($channel);
  $log->pushHandler(new StreamHandler(dirname(__FILE__).'/../logs/app.log', Logger::DEBUG));
  $log->pushProcessor(new Monolog\Processor\WebProcessor);
  return $log;
}

function log_info($msg) {
  logger()->info($msg);
}

function log_warning($msg) {
  logger()->warning($msg);
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

function random_string() {
  return bin2hex(random_bytes(32));
}

function random_user_code() {
  $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
  $code = '';
  for($i = 0; $i < 4; $i++) {
    $code .= substr($charset, random_int(0, strlen($charset)-1), 1);
  }
  return $code;
}

function redis() {
  static $client = false;
  if(!$client)
    $client = new Predis\Client('tcp://127.0.0.1:6379');
  return $client;
}

function pa($a) {
  echo '<pre>';
  print_r($a);
  echo '</pre>';
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

// For link-rel-parser
function get_absolute_uri($href, $url) {
  return \Mf2\resolveUrl($url, $href);
}

function fetch_profile($me) {

  $client = new \GuzzleHttp\Client();

  // Keep track of redirects in this array
  $redirects = [];

  $onRedirect = function(
      RequestInterface $request,
      ResponseInterface $response,
      UriInterface $uri
  ) use(&$redirects) {
    $redirects[] = [
      'code' => $response->getStatusCode(),
      'from' => ''.$request->getUri(),
      'to' => ''.$uri
    ];
  };

  try {
    // Fetch the entered URL
    $res = $client->request('GET', $me, [
      'timeout'         => 10,
      'allow_redirects' => [
        'max'             => 10,
        'strict'          => true,
        'referer'         => true,
        'on_redirect'     => $onRedirect,
        'track_redirects' => true
      ]
    ]);
  } catch(\GuzzleHttp\Exception\ClientException $e) {
    if($e->hasResponse()) {
      $response = $e->getResponse();
      return [
        'code' => $response->getStatusCode(),
        'exception' => $e->getMessage(),
      ];
    } else {
      return [
        'code' => 0,
        'exception' => $e->getMessage(),
      ];
    }
  } catch(\GuzzleHttp\Exception\TooManyRedirectsException $e) {
    return [
      'code' => 0,
      'exception' => $e->getMessage(),
    ];
  } catch(\GuzzleHttp\Exception\ServerException $e) {
    $response = $e->getResponse();
    return [
      'code' => $response->getStatusCode(),
      'exception' => $e->getMessage(),
    ];
  } catch(\GuzzleHttp\Exception\RequestException $e) {
    return [
      'code' => 0,
      'exception' => $e->getMessage(),
    ];
  } catch(\GuzzleHttp\Exception\ConnectException $e) {
    return [
      'code' => 0,
      'exception' => $e->getMessage(),
    ];
  }

  // Check all the redirects to override $me, but stop if a 302/307 is encountered
  // https://www.w3.org/TR/indieauth/#discovery-by-clients-p-2
  $original_me = $me;

  $new_me = \IndieAuth\Client::normalizeMeURL($me);
  foreach($redirects as $r) {
    if($r['code'] == 302 || $r['code'] == 307) {
      break;
    }
    $new_me = \IndieAuth\Client::normalizeMeURL($r['to']);
  }
  $me = $new_me;

  // Get the final URL
  $final_url = $original_me;
  if(count($redirects)) {
    $final_url = $redirects[count($redirects)-1]['to'];
  }

  // Parse the resulting body for rel me/authn/authorization_endpoint
  $body = ''.$res->getBody();

  $parsed = \Mf2\parse($body, $final_url);
  $rels = $parsed['rels'];
  $relURLs = $parsed['rel-urls'];

  // If the header includes a rel=authorization_endpoint, use that instead of from the body
  // https://www.w3.org/TR/indieauth/#discovery-by-clients-p-3
  if($res->getHeaderLine('Link')) {
    $link = 'Link: '.$res->getHeaderLine('Link');
    $link_rels = \IndieWeb\http_rels($link, $final_url);
    if(isset($link_rels['authorization_endpoint'])) {
      $rels['authorization_endpoint'] = $link_rels['authorization_endpoint'];
    }
  }

  return [
    'code' => $res->getStatusCode(),
    'me' => $me,
    'me_entered' => $original_me,
    'final_url' => $final_url,
    'rels' => [
      'me' => $rels['me'] ?? [],
      'authn' => $rels['authn'] ?? [],
      'authorization_endpoint' => $rels['authorization_endpoint'] ?? [],
      'pgpkey' => $rels['pgpkey'] ?? [],
    ],
    'redirects' => $redirects,
  ];
}
