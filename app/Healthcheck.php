<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;
use ORM;

class Healthcheck {
  const ERROR = 'ERROR';
  const OK = 'OK';
  const REDIS_SUCCESSFUL_PING = 'PONG';

  public function index(ServerRequestInterface $request): ResponseInterface {
    $userlog = make_logger('healthcheck');

    $services = [
      'mysql' => self::ERROR,
      'redis' => self::ERROR,
    ];

    // Redis
    try {
      if(redis()->ping() == self::REDIS_SUCCESSFUL_PING) {
        $services['redis'] = self::OK;
      }
    } catch(\Predis\Connection\ConnectionException $e) {
      $userlog->info(
        'Redis connection healthcheck failure'
      );
    }

    // MySQL
    try {
      ORM::raw_execute('SELECT version();');
      $services['mysql'] = self::OK;
    } catch(\Exception $e) {
      $userlog->info(
        'MySQL connection healthcheck failure'
      );
    }

    return new JsonResponse($services);
  }
}

