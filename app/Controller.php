<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use ORM;

class Controller {

  public function index(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('index', [
      'title' => get_setting('name'),
    ]));
    return $response;
  }

  public function demo(ServerRequestInterface $request, ResponseInterface $response) {
    $params = $request->getQueryParams();

    if(!isset($params['code'])) {
      return $response->withHeader('Location', '/')->withStatus(302);
    }

    // We'll cheat and extract the user details directly instead of making a post request to ourselves here
    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login) {
      return $response->withHeader('Location', '/?error=code_expired')->withStatus(302);
    }

    $login = json_decode($login, true);

    $log = ORM::for_table('logins')->where('code', $params['code'])->find_one();
    $log->complete = 1;
    $log->date_complete = date('Y-m-d H:i:s');
    $log->code = '';
    $log->save();

    $response->getBody()->write(view('demo', [
      'title' => get_setting('name'),
      'me' => $login['me'],
    ]));
    return $response;
  }

  public function api_docs(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/api', [
      'title' => get_setting('name').' API Docs',
    ]));
    return $response;
  }

  public function setup_docs(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/setup', [
      'title' => 'How to Start Using '.get_setting('name'),
    ]));
    return $response;
  }

  public function faq(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/faq', [
      'title' => get_setting('name').' FAQ',
    ]));
    return $response;
  }

  public function privacy(ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write(view('docs/privacy', [
      'title' => 'Privacy Policy',
    ]));
    return $response;
  }

}

