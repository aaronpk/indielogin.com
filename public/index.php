<?php
chdir('..');
include('vendor/autoload.php');

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;

// Check for existence of config variables, and show an error page if not set
if(empty(getenv('APP_NAME')) || empty(getenv('DB_HOST'))) {
  echo view('setup-error', [
    'title' => 'Setup Error',
  ]);
  die();
}

$request = Laminas\Diactoros\ServerRequestFactory::fromGlobals(
  $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
);

$route = new League\Route\Router;

initdb();

$route->map('GET', '/', 'App\\Controller::index');
$route->map('GET', '/health', 'App\\Healthcheck::index');
# /api was the old address of the developer docs
$route->map('GET', '/api', function() { return redirect_response('/developers', 301); });

$route->map('GET', '/developers', 'App\\Developers::docs');
$route->map('GET', '/developers/login', 'App\\Developers::login');
$route->map('GET', '/developers/redirect', 'App\\Developers::redirect');
$route->map('POST', '/developers/logout', 'App\\Developers::logout');
$route->map('GET', '/developers/clients', 'App\\Developers::clients');
$route->map('POST', '/developers/clients', 'App\\Developers::register');
$route->map('POST', '/developers/clients/delete', 'App\\Developers::delete');
$route->map('POST', '/developers/profile', 'App\\Developers::profile');
$route->map('GET', '/setup', 'App\\Controller::setup_docs');
$route->map('GET', '/faq', 'App\\Controller::faq');
$route->map('GET', '/privacy-policy', 'App\\Controller::privacy');
$route->map('GET', '/demo_start', 'App\\Controller::demo_start');
$route->map('GET', '/demo_redirect', 'App\\Controller::demo_redirect');

# Client ID Metadata Document
# https://datatracker.ietf.org/doc/draft-parecki-oauth-client-id-metadata-document/
$route->map('GET', '/id', 'App\\Controller::client_metadata');

$route->map('GET', '/auth', 'App\\Authenticate::start')->middleware(new App\CORSStrategy);
$route->map('POST', '/auth', 'App\\Authenticate::verify')->middleware(new App\CORSStrategy);

$route->map('GET', '/authorize', 'App\\Authenticate::start')->middleware(new App\CORSStrategy);
$route->map('POST', '/token', 'App\\Authenticate::verify')->middleware(new App\CORSStrategy);

$route->map('GET', '/select', 'App\\Authenticate::select');
$route->map('POST', '/select', 'App\\Authenticate::post_select');

$route->map('GET', '/redirect/github', 'App\\Authenticate::redirect_github');
$route->map('GET', '/redirect/gitlab', 'App\\Authenticate::redirect_gitlab');
$route->map('GET', '/redirect/codeberg', 'App\\Authenticate::redirect_codeberg');
$route->map('GET', '/redirect/atproto', 'App\\Authenticate::redirect_atproto');
$route->map('GET', '/redirect/indieauth', 'App\\Authenticate::redirect_indieauth');

$route->map('POST', '/auth/send_email', 'App\\Authenticate::send_email');
$route->map('POST', '/auth/verify_email_code', 'App\\Authenticate::verify_email_code');
$route->map('POST', '/auth/verify_pgp_challenge', 'App\\Authenticate::verify_pgp_challenge');

$route->map('POST', '/fedcm/start', 'App\\Authenticate::fedcm_start');
$route->map('POST', '/fedcm/login', 'App\\Authenticate::fedcm_login');

$templates = new League\Plates\Engine(dirname(__FILE__).'/../views');

try {
  $response = $route->dispatch($request);
} catch(League\Route\Http\Exception $e) {
  // The router raises these for an unmatched path or a method that is not
  // allowed, and it does so ahead of any middleware of ours, so they have to
  // be turned into a response here
  $response = new HtmlResponse(view('http-error', [
    'title' => $e->getStatusCode().' '.$e->getMessage(),
    'status' => $e->getStatusCode(),
    'message' => $e->getMessage(),
  ]), $e->getStatusCode(), $e->getHeaders());
}

(new Laminas\HttpHandlerRunner\Emitter\SapiEmitter)->emit($response);
