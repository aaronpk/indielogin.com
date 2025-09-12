<?php
chdir('..');
include('vendor/autoload.php');

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use League\Route\Http\Exception\NotFoundException;
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
$route->map('GET', '/api', 'App\\Controller::api_docs');
$route->map('GET', '/setup', 'App\\Controller::setup_docs');
$route->map('GET', '/faq', 'App\\Controller::faq');
$route->map('GET', '/privacy-policy', 'App\\Controller::privacy');
$route->map('GET', '/demo', 'App\\Controller::demo');

$route->map('GET', '/debug', 'App\\Controller::debug');
$route->map('GET', '/debug/github', 'App\\Controller::debug_github');
$route->map('GET', '/debug/gitlab', 'App\\Controller::debug_gitlab');
$route->map('GET', '/debug/codeberg', 'App\\Controller::debug_codeberg');

$route->map('GET', '/id', 'App\\Controller::client_metadata'); # IndieAuth client metadata

$route->map('GET', '/auth', 'App\\Authenticate::start')->middleware(new App\CORSStrategy);
$route->map('GET', '/select', 'App\\Authenticate::select');
$route->map('POST', '/auth', 'App\\Authenticate::verify')->middleware(new App\CORSStrategy);
$route->map('POST', '/select', 'App\\Authenticate::post_select');

$route->map('GET', '/redirect/github', 'App\\Authenticate::redirect_github');
$route->map('GET', '/redirect/gitlab', 'App\\Authenticate::redirect_gitlab');
$route->map('GET', '/redirect/codeberg', 'App\\Authenticate::redirect_codeberg');
$route->map('GET', '/redirect/indieauth', 'App\\Authenticate::redirect_indieauth');

$route->map('POST', '/auth/send_email', 'App\\Authenticate::send_email');
$route->map('POST', '/auth/verify_email_code', 'App\\Authenticate::verify_email_code');
$route->map('POST', '/auth/verify_pgp_challenge', 'App\\Authenticate::verify_pgp_challenge');

$route->map('POST', '/fedcm/start', 'App\\Authenticate::fedcm_start');
$route->map('POST', '/fedcm/login', 'App\\Authenticate::fedcm_login');

$templates = new League\Plates\Engine(dirname(__FILE__).'/../views');

$route->middleware(new App\NotFoundMiddleware());

$response = $route->dispatch($request);
(new Laminas\HttpHandlerRunner\Emitter\SapiEmitter)->emit($response);
