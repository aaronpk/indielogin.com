<?php
namespace App;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\HtmlResponse;

class NotFoundMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    try {
      // Let the handler process the request
      return $handler->handle($request);
    } catch (\RuntimeException $e) {
      // Handle "route not found" errors
      if($e->getMessage() === 'Could not resolve a callable for this route') {
        return new HtmlResponse(
          '<h1>404 Not Found</h1><p>The page you are looking for does not exist.</p>',
          404
        );
      }

      // Rethrow other exceptions
      throw $e;
    }
  }
}
