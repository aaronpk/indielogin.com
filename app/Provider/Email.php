<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait Email {

  private function _start_email(&$response, $me, $details) {

    echo $me;
    die();
  }

}

