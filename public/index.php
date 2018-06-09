<?php
chdir('..');

if(!file_exists('./lib/config.php')) {
  die('Please copy lib/config.template.php to lib/config.php and fill in your configuration details');
}

include('vendor/autoload.php');

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$container = new League\Container\Container;
$container->share('response', Zend\Diactoros\Response::class);
$container->share('request', function () {
  return Zend\Diactoros\ServerRequestFactory::fromGlobals(
      $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
  );
});
$container->share('emitter', Zend\Diactoros\Response\SapiEmitter::class);

initdb();

$route = new League\Route\RouteCollection($container);

$route->map('GET', '/', 'App\\Controller::index');

$route->map('GET', '/api', 'App\\Controller::api_docs');
$route->map('GET', '/setup', 'App\\Controller::setup_docs');
$route->map('GET', '/faq', 'App\\Controller::faq');
$route->map('GET', '/privacy-policy', 'App\\Controller::privacy');
$route->map('GET', '/debug', 'App\\Controller::debug');
$route->map('GET', '/demo', 'App\\Controller::demo');

$route->map('GET', '/auth', 'App\\Authenticate::start');
$route->map('GET', '/select', 'App\\Authenticate::select');
$route->map('POST', '/auth', 'App\\Authenticate::verify');
$route->map('POST', '/select', 'App\\Authenticate::post_select');

$route->map('GET', '/redirect/github', 'App\\Authenticate::redirect_github');
$route->map('GET', '/redirect/twitter', 'App\\Authenticate::redirect_twitter');
$route->map('GET', '/redirect/indieauth', 'App\\Authenticate::redirect_indieauth');

$route->map('POST', '/auth/send_email', 'App\\Authenticate::send_email');
$route->map('POST', '/auth/verify_email_code', 'App\\Authenticate::verify_email_code');
$route->map('POST', '/auth/verify_pgp_challenge', 'App\\Authenticate::verify_pgp_challenge');


$templates = new League\Plates\Engine(dirname(__FILE__).'/../views');

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

