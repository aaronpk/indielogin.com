<?php
namespace App;

use \Exception;
use League\Route\Http\Exception\MethodNotAllowedException;
use League\Route\Http\Exception\NotFoundException;
use League\Route\Middleware\ExecutionChain;
use League\Route\Route;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CORSStrategy extends \League\Route\Strategy\ApplicationStrategy {

    public function getCallable(Route $route, array $vars)
    {
        return function (ServerRequestInterface $request, ResponseInterface $response, callable $next) use ($route, $vars) {
            $response = call_user_func_array($route->getCallable(), [$request, $response, $vars]);

            if (! $response instanceof ResponseInterface) {
                throw new RuntimeException(
                    'Route callables must return an instance of (Psr\Http\Message\ResponseInterface)'
                );
            }

            $response = $response
              ->withHeader('Access-Control-Allow-Origin', '*')
              ->withHeader('Access-Control-Allow-Methods', 'GET, POST');

            return $next($request, $response);
        };
    }

}
