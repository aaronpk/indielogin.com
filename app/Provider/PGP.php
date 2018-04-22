<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait PGP {

  private function _start_pgp(&$response, $me, $details) {

    echo $me;
    die();
  }

}

