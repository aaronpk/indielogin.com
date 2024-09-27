<?php
require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$container = new League\Container\Container;
$container->share('response', \Laminas\Diactoros\Response::class);
$container->share('request', function () {
  return \Laminas\Diactoros\ServerRequestFactory::fromGlobals(
      $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
  );
});
$container->share('emitter', \Laminas\HttpHandlerRunner\Emitter\SapiEmitter::class);

initdb();

$route = new League\Route\RouteCollection($container);

$route->map('GET', '/', 'App\\Controller::index');
$route->map('GET', '/health', 'App\\Healthcheck::index');
$route->map('GET', '/api', 'App\\Controller::api_docs');
$route->map('GET', '/setup', 'App\\Controller::setup_docs');
$route->map('GET', '/faq', 'App\\Controller::faq');
$route->map('GET', '/privacy-policy', 'App\\Controller::privacy');
$route->map('GET', '/debug', 'App\\Controller::debug');
$route->map('GET', '/demo', 'App\\Controller::demo');

$route->map('GET', '/id', 'App\\Controller::client_metadata'); # IndieAuth client metadata

$route->map('GET', '/auth', 'App\\Authenticate::start')->setStrategy(new App\CORSStrategy);
$route->map('GET', '/select', 'App\\Authenticate::select');
$route->map('POST', '/auth', 'App\\Authenticate::verify')->setStrategy(new App\CORSStrategy);
$route->map('POST', '/select', 'App\\Authenticate::post_select');

$route->map('GET', '/redirect/github', 'App\\Authenticate::redirect_github');
$route->map('GET', '/redirect/twitter', 'App\\Authenticate::redirect_twitter');
$route->map('GET', '/redirect/indieauth', 'App\\Authenticate::redirect_indieauth');

$route->map('POST', '/auth/send_email', 'App\\Authenticate::send_email');
$route->map('POST', '/auth/verify_email_code', 'App\\Authenticate::verify_email_code');
$route->map('POST', '/auth/verify_pgp_challenge', 'App\\Authenticate::verify_pgp_challenge');

$route->map('POST', '/fedcm/start', 'App\\Authenticate::fedcm_start');
$route->map('POST', '/fedcm/login', 'App\\Authenticate::fedcm_login');


$templates = new League\Plates\Engine(dirname(__FILE__).'/../views');

// Check for existence of config variables, and show an error page if not set
if(empty(getenv('APP_NAME')) || empty(getenv('DB_HOST'))) {
  echo view('setup-error', [
      'title' => 'Setup Error',
  ]);
  die();
}

try {
  $response = $route->dispatch($container->get('request'), $container->get('response'));
  $container->get('emitter')->emit($response);
} catch(League\Route\Http\Exception\NotFoundException $e) {
  $response = $container->get('response');
  $response->getBody()->write("Not Found\n");
  $container->get('emitter')->emit($response->withStatus(404));
} catch(League\Route\Http\Exception\MethodNotAllowedException $e) {
  $response = $container->get('response');
  $response->getBody()->write("Method not allowed\n");
  $container->get('emitter')->emit($response->withStatus(405));
}

