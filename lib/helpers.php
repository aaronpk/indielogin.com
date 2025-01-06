<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Dotenv\Dotenv;

const LOCAL_FALLBACK_REDIS = 'tcp://127.0.0.1:6379';

date_default_timezone_set('UTC');

// Load .env file if exists
$dotenv = Dotenv::createImmutable(__DIR__.'/..');
if(file_exists(__DIR__.'/../.env')) {
  $dotenv->load();
}

function initdb() {
  if(!empty(getenv('DB_HOST'))) {
    ORM::configure('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'));
    ORM::configure('username', getenv('DB_USER'));
    ORM::configure('password', getenv('DB_PASS'));
  }
}

function make_logger($channel) {
  $log = new Logger($channel);
  $log->pushHandler(new StreamHandler(dirname(__FILE__).'/../logs/app.log', Logger::DEBUG));
  $log->pushProcessor(new Monolog\Processor\WebProcessor);
  return $log;
}

function view($template, $data=[]) {
  global $templates;
  return $templates->render($template, $data);
}

function redirect_response($url, $code=302) {
  $response = new \Laminas\Diactoros\Response();
  return $response->withHeader('Location', $url)->withStatus($code);
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
  for($i = 0; $i < 6; $i++) {
    $code .= substr($charset, random_int(0, strlen($charset)-1), 1);
  }
  $code = substr($code, 0, 3).'-'.substr($code, 3);
  return $code;
}

function get_redis_url() {
  return $redisURL = getenv('REDIS_URL') ?: LOCAL_FALLBACK_REDIS;
}
function redis() {
  static $client = false;
  if(!$client) {
    $client = new Predis\Client(get_redis_url());
  }
  return $client;
}

function pa($a) {
  echo '<pre>';
  print_r($a);
  echo '</pre>';
}

function generate_state($prefix=false) {
  return $_SESSION['state'] = ($prefix ? $prefix.'_' : '').bin2hex(random_bytes(12));
}

function generate_pkce_code_verifier() {
  return $_SESSION['code_verifier'] = bin2hex(random_bytes(50));
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

function http_client() {
  static $http;
  if(!isset($http))
    $http = new \p3k\HTTP(getenv('HTTPCLIENT_USER_AGENT'));
  $http->set_timeout(10);
  return $http;
}

// For link-rel-parser
function get_absolute_uri($href, $url) {
  return \Mf2\resolveUrl($url, $href);
}

// Compare URLs for equality, with case-insensitive hostname checking.
// We should probably replace this with another library but I couldn't
// find a good one that I trust right now.
function urls_are_equivalent($a, $b) {
  $a = parse_url($a);
  $b = parse_url($b);
  if(!empty($a['host'])) $a['host'] = strtolower($a['host']);
  if(!empty($b['host'])) $b['host'] = strtolower($b['host']);
  $a = p3k\url\build_url($a);
  $b = p3k\url\build_url($b);
  return $a == $b;
}

function guzzle_request_get($client, $url, $onRedirect=null) {

  try {
    // Fetch the entered URL
    $res = $client->request('GET', $url, [
      'timeout'         => 10,
      'allow_redirects' => [
        'max'             => 10,
        'strict'          => true,
        'referer'         => true,
        'on_redirect'     => $onRedirect,
        'track_redirects' => true
      ],
      'headers' => [
        'User-Agent' => getenv('HTTP_USER_AGENT'),
        'Accept'     => 'text/html,*/*'
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

  return $res;
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

  $res = guzzle_request_get($client, $me, $onRedirect);

  if(!is_object($res) && isset($res['exception'])) {
    return $res;
  }

  $original_me = $me;
  $me = \IndieAuth\Client::normalizeMeURL($me);

  // Get the final URL
  $final_url = $me;
  $final_profile_url = $me;
  if(count($redirects)) {
    foreach($redirects as $r) {
      if($r['code'] == 302 || $r['code'] == 307) {
        // Abort on temporary redirects
        break;
      } else {
        $final_profile_url = $r['to'];
      }
    }
    $final_url = $redirects[count($redirects)-1]['to'];
  }
  $final_url = \IndieAuth\Client::normalizeMeURL($final_url);
  $final_profile_url = \IndieAuth\Client::normalizeMeURL($final_profile_url);

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
    if(isset($link_rels['indieauth-metadata'])) {
      $rels['indieauth-metadata'] = $link_rels['indieauth-metadata'];
    }
  }

  // If IndieAuth metadata was discovered, fetch it and populate the authorization and token endpoint from there
  if(isset($rels['indieauth-metadata'])) {

    $res = guzzle_request_get($client, $rels['indieauth-metadata'][0]);
    if(!is_object($res) && isset($res['exception'])) {
      return $res;
    }

    $metadata = json_decode(''.$res->getBody(), true);

    if(empty($metadata)) {
      return [
        'code' => 0,
        'exception' => 'IndieAuth server metadata could not be parsed',
      ];
    }

    if(empty($metadata['issuer'])) {
      return [
        'code' => 0,
        'exception' => 'IndieAuth metadata is missing an issuer value',
      ];
    }

    if(empty($metadata['authorization_endpoint']) || empty($metadata['token_endpoint'])) {
      return [
        'code' => 0,
        'exception' => 'IndieAuth metadata was missing authorization endpoint and/or token endpoint',
      ];
    }

    $indieauth_issuer = $metadata['issuer'];
    $rels['authorization_endpoint'] = [$metadata['authorization_endpoint']];
    $rels['token_endpoint'] = [$metadata['token_endpoint']];

  } else {
    $indieauth_issuer = null;
  }

  return [
    'code' => $res->getStatusCode(),
    'me' => $me,
    'me_entered' => $original_me,
    'final_url' => $final_profile_url,
    'rels' => [
      'me' => $rels['me'] ?? [],
      'authn' => $rels['authn'] ?? [],
      'authorization_endpoint' => $rels['authorization_endpoint'] ?? [],
      'token_endpoint' => $rels['token_endpoint'] ?? [],
      'indieauth-metadata' => $rels['indieauth-metadata'] ?? [],
      'pgpkey' => $rels['pgpkey'] ?? [],
    ],
    'indieauth-issuer' => $indieauth_issuer,
    'redirects' => $redirects,
  ];
}

function discover_authorization_endpoint($url) {

  $profile = fetch_profile($url);

  if($profile['code'] != 200) {
    return null;
  }

  if(empty($profile['rels']['authorization_endpoint'])) {
    return null;
  }

  return $profile['rels']['authorization_endpoint'][0];
}
