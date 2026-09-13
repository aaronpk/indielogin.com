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
  $userlog = make_logger('user');
  $state = bin2hex(random_bytes(12));
  $key = $prefix ? $prefix . '.state' : 'state';
  $userlog->warning('Generating STATE parameter: ' . $key . '=' . $state);
  return $_SESSION[$key] = $state;
}

function generate_pkce_code_verifier() {
  return $_SESSION['code_verifier'] = bin2hex(random_bytes(28));
}

function pkce_code_challenge($verifier) {
  return base64_urlencode(hash('sha256', $verifier, true));
}

function base64_urlencode($string) {
  return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
}

function is_logged_in() {
  return isset($_SESSION) && array_key_exists('me', $_SESSION);
}

// Self-service client registration is on unless a deployment turns it off.
// Absent means enabled, so that existing installs keep working after an
// upgrade without having to add anything to their .env.
function client_registration_enabled() {
  $value = getenv('CLIENT_REGISTRATION');

  if($value === false || trim($value) === '')
    return true;

  return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off', 'disabled'], true);
}

// A CSRF token for the developer area, the only place on this site with
// session-authenticated forms that change something. Requires session_start().
function csrf_token() {
  if(empty($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

  return $_SESSION['csrf_token'];
}

function csrf_valid($token) {
  return !empty($_SESSION['csrf_token'])
    && is_string($token)
    && hash_equals($_SESSION['csrf_token'], $token);
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

// The A-label ("xn--") spelling of a host name. That is the ASCII form that
// DNS, Guzzle's request host validation, and a byte-for-byte comparison
// against whatever a provider has on file can all agree on, so it is the
// spelling everything below the user interface works in. A host that is
// already ASCII comes back untouched, which leaves IP literals, existing
// A-labels and every ordinary domain exactly as they are.
function idn_host($host) {
  if($host === null || $host === '' || preg_match('/\A[\x21-\x7E]*\z/D', $host))
    return $host;

  return idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) ?: $host;
}

// Split a URL into the part before the host, the host itself, and everything
// from the port onwards.
//
// This exists because parse_url() cannot be trusted with an internationalized
// host: it replaces every byte in the C1 range with an underscore, which
// quietly corrupts the UTF-8 of most non-Latin domains -- all of CJK, most of
// Cyrillic, and the "o" with a macron in the domain from issue #122. So the
// host is cut out of the raw string here, and parse_url() only ever sees a
// URL whose host has already been reduced to ASCII.
//
// Returns false if there is no host to be found.
function split_url_host($url) {
  if(!is_string($url))
    return false;

  if(!preg_match('~\A([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)\z~is', $url, $match))
    return false;

  list(, $scheme, $authority, $rest) = $match;

  // Userinfo can itself contain an "@", so the host starts after the last one
  $at = strrpos($authority, '@');
  if($at !== false) {
    $scheme .= substr($authority, 0, $at + 1);
    $authority = substr($authority, $at + 1);
  }

  // A colon only introduces a port if it comes after the brackets around an
  // IPv6 literal, which are full of colons themselves
  $colon = strrpos($authority, ':');
  $bracket = strrpos($authority, ']');
  if($colon !== false && ($bracket === false || $colon > $bracket)) {
    $rest = substr($authority, $colon).$rest;
    $authority = substr($authority, 0, $colon);
  }

  if($authority === '')
    return false;

  return [$scheme, $authority, $rest];
}

// Rewrite a URL so that its host is an A-label, leaving the rest of it alone.
// Returns false if there is no host, or if the host is still not ASCII
// afterwards -- a URL like that is one nothing downstream can handle.
function idn_normalize_url_host($url) {
  $parts = split_url_host($url);

  if($parts === false)
    return false;

  $host = idn_host($parts[1]);

  if(!preg_match('/\A[\x21-\x7E]*\z/D', $host))
    return false;

  return $parts[0].$host.$parts[2];
}

// The Unicode spelling of a URL's host, for showing someone their own domain
// the way they wrote it. Display only: every request we make and every
// comparison we do uses the A-label. A spelling that does not convert
// straight back to the host we started with is not one we can vouch for, so
// it is left as it is.
function display_url_host($url) {
  $parts = split_url_host($url);

  if($parts === false || strpos($parts[1], 'xn--') === false)
    return $url;

  $host = idn_to_utf8($parts[1], IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

  if($host === false || idn_host($host) !== $parts[1])
    return $url;

  return $parts[0].$host.$parts[2];
}

// Input: anything someone might type into the sign-in form
// Output: the canonical URL we fetch, store, compare, and hand back to the
//         application, or false if it is not a URL we can use
function normalize_me_url($url) {
  if(!is_string($url))
    return false;

  $url = trim($url);

  if($url === '')
    return false;

  // No scheme, so assume https. That is what the sign-in form already assumes
  // in the browser and what the developer area assumes for a client ID; the
  // library call below would otherwise default it to http.
  if(!preg_match('/\A[a-z][a-z0-9+.\-]*:/i', $url))
    $url = 'https://'.$url;

  // Reduce the host to ASCII before handing the URL to anything built on
  // parse_url(), which normalizeMeURL() is
  $url = idn_normalize_url_host($url);

  if($url === false)
    return false;

  $url = \IndieAuth\Client::normalizeMeURL($url);

  return $url === false ? false : $url;
}

// Compare URLs for equality, with case-insensitive hostname checking.
// We should probably replace this with another library but I couldn't
// find a good one that I trust right now.
function urls_are_equivalent($a, $b) {
  // Both spellings of an internationalized domain have to compare equal. The
  // one on someone's provider profile and the one they signed in with are
  // typed separately, so there is no reason for them to match byte for byte.
  $a = idn_normalize_url_host($a) ?: $a;
  $b = idn_normalize_url_host($b) ?: $b;
  $a = parse_url($a);
  $b = parse_url($b);
  if(!empty($a['host'])) $a['host'] = strtolower($a['host']);
  if(!empty($b['host'])) $b['host'] = strtolower($b['host']);
  if(empty($a['path'])) $a['path'] = '/';
  if(empty($b['path'])) $b['path'] = '/';
  $a = p3k\url\build_url($a);
  $b = p3k\url\build_url($b);
  return $a == $b;
}

function same_host($a, $b) {
  $a = idn_normalize_url_host($a) ?: $a;
  $b = idn_normalize_url_host($b) ?: $b;
  return strtolower(''.parse_url($a, PHP_URL_HOST))
      == strtolower(''.parse_url($b, PHP_URL_HOST));
}

// Look for URL in string, ignoring the trailing slash on root domains.
function string_contains_url($str, $url) {
  if(!is_string($str))
    return false;

  $url = idn_normalize_url_host($url) ?: $url;

  // A bio is free text, so there is nothing to normalize it to the way there
  // is for a URL. Look for either spelling of an internationalized domain
  // instead, since someone may well have written the Unicode one.
  foreach(array_unique([$url, display_url_host($url)]) as $candidate) {
    $candidate = preg_replace('~\A([a-z][a-z0-9+.\-]*://[^/?#]+)/\z~i', '$1', $candidate);

    if($candidate !== '' && strpos($str, $candidate) !== false)
      return true;
  }

  return false;
}

function guzzle_request_get($client, $url, $onRedirect=null) {

  // Guzzle refuses to send a request whose host is not printable ASCII, so
  // an internationalized domain has to be in A-label form before it gets
  // here. Doing it at the one place every Guzzle fetch goes through covers
  // the URLs we pick up from someone's page as well as the one they typed.
  if($ascii = idn_normalize_url_host($url))
    $url = $ascii;

  // Guzzle rejects a non-string header value, so only send the user agent
  // when one is actually configured
  $headers = ['Accept' => 'text/html,*/*'];
  if($user_agent = getenv('HTTPCLIENT_USER_AGENT'))
    $headers['User-Agent'] = $user_agent;

  // Likewise, on_redirect has to be left out entirely when there is no
  // callback rather than passed as null
  $allow_redirects = [
    'max'             => 10,
    'strict'          => true,
    'referer'         => true,
    'track_redirects' => true,
  ];
  if(is_callable($onRedirect))
    $allow_redirects['on_redirect'] = $onRedirect;

  try {
    // Fetch the entered URL
    $res = $client->request('GET', $url, [
      'timeout'         => 10,
      'allow_redirects' => $allow_redirects,
      'headers'         => $headers,
    ]);
  // Guzzle groups its failures by whether a response ever arrived, so these
  // three catches cover everything it can throw. Order matters, because
  // TooManyRedirectsException is itself a ResponseException.
  } catch(\GuzzleHttp\Exception\TooManyRedirectsException $e) {
    // We were bounced around and never landed anywhere usable
    return [
      'code' => 0,
      'exception' => $e->getMessage(),
    ];
  } catch(\GuzzleHttp\Exception\ResponseException $e) {
    // Response headers were received, so there is a status code to report
    return [
      'code' => $e->getResponse()->getStatusCode(),
      'exception' => $e->getMessage(),
    ];
  } catch(\GuzzleHttp\Exception\TransferException $e) {
    // Connection refused, DNS failure, timeout, or anything else that
    // produced no response at all
    return [
      'code' => 0,
      'exception' => $e->getMessage(),
    ];
  }

  return $res;
}

function fetch_profile($me) {

  $userlog = make_logger('user');

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

  // Normalize before fetching rather than after, so that the URL we ask for
  // is the same one we go on to compare against
  $original_me = $me;
  $me = normalize_me_url($me);

  if($me === false) {
    return [
      'code' => 0,
      'exception' => 'That does not look like a URL we can fetch',
    ];
  }

  $res = guzzle_request_get($client, $me, $onRedirect);

  $final_url = $me;
  $final_profile_url = $me;

  $indieauth_issuer = null;

  if(!is_object($res) && isset($res['exception'])) {

    $statusCode = -1;
    $error = $res;
    $userlog->error('Error fetching profile '.$me, [
      'error' => $error,
    ]);
    $exception = $res['exception']; // store the exception in case we need to return it
    $rels = [];

  } else {

    $statusCode = $res->getStatusCode();

    // Get the final URL
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
    $final_url = normalize_me_url($final_url);
    $final_profile_url = normalize_me_url($final_profile_url);

    // Where we ended up has to be a URL we can canonicalize, because it is
    // the one we go on to treat as this person's identity
    if($final_url === false || $final_profile_url === false) {
      return [
        'code' => 0,
        'exception' => 'We were redirected to a URL we can\'t use',
      ];
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

    }
  }

  // If no IndieAuth server was found, check for an ATProto DNS record
  if(empty($rels['authorization_endpoint'])) {
    // The normalized host, not the one that was typed: a DNS lookup needs the
    // A-label
    $handle = parse_url($me, PHP_URL_HOST);
    $did = ATProto::handle_to_did($handle);
    if($did) {
      $statusCode = 200;
      $rels['atproto_did'] = [
        'did' => $did,
        'handle' => $handle,
      ];
    }
  }

  // If we still haven't found anything, return an exception
  if($statusCode == -1) {
    return [
      'code' => -1,
      'exception' => $exception,
    ];
  }

  return [
    'code' => $statusCode,
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
      'atproto_did' => $rels['atproto_did'] ?? null,
      'atproto' => $rels['atproto'] ?? [],
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
