<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

class Controller {

  public function index(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('index', [
      'title' => 'IndieLogin.com',
    ]));
    return $response;
  }

  public function api_docs(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/api', [
      'title' => 'IndieLogin.com API Docs',
    ]));
    return $response;
  }

  public function setup_docs(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/setup', [
      'title' => 'How to Start Using IndieLogin.com',
    ]));
    return $response;
  }

}

