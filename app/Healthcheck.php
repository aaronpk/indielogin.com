<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use ORM;

class Healthcheck {
  const ERROR = 'ERROR';
  const OK = 'OK';
  const REDIS_SUCCESSFUL_PING = 'PONG';

  public function index(ServerRequestInterface $request, ResponseInterface $response) {
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

    // Always
    $response->getBody()->write(json_encode($services));
    return $response;
  }
}

