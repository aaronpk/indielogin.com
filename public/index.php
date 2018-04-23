<?php
chdir('..');
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

$route = new League\Route\RouteCollection($container);

$route->map('GET', '/', 'App\\Controller::index');

$route->map('GET', '/api', 'App\\Controller::api_docs');
$route->map('GET', '/setup', 'App\\Controller::setup_docs');
$route->map('GET', '/debug', 'App\\Controller::debug');

$route->map('GET', '/auth', 'App\\Authenticate::start');
$route->map('GET', '/select', 'App\\Authenticate::select');
$route->map('POST', '/auth', 'App\\Authenticate::verify');

$route->map('GET', '/redirect/github', 'App\\Authenticate::redirect_github');
$route->map('GET', '/redirect/twitter', 'App\\Authenticate::redirect_twitter');
$route->map('GET', '/redirect/indieauth', 'App\\Authenticate::redirect_indieauth');


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

