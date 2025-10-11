<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;
use ORM;

class Controller {

  public function index(ServerRequestInterface $request): ResponseInterface {
    return new HtmlResponse(view('index', [
      'title' => getenv('APP_NAME'),
    ]));
  }
  
  public function client_metadata(ServerRequestInterface $request): ResponseInterface {
    return new JsonResponse([
      'client_id' => getenv('BASE_URL').'id',
      'client_name' => getenv('APP_NAME'),
      'client_uri' => getenv('BASE_URL'),
      'logo_uri' => getenv('BASE_URL').'icons/apple-touch-icon.png',
      'redirect_uris' => [
        getenv('BASE_URL').'redirect/indieauth',
      ],
    ]);
  }

  public function demo(ServerRequestInterface $request): ResponseInterface {
    $params = $request->getQueryParams();

    if(!isset($params['code'])) {
      $response = new Response();
      return $response->withHeader('Location', '/')->withStatus(302);
    }

    // We'll cheat and extract the user details directly instead of making a post request to ourselves here
    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login) {
      $response = new Response();
      return $response->withHeader('Location', '/?error=code_expired')->withStatus(302);
    }

    $login = json_decode($login, true);

    $log = ORM::for_table('logins')->where('code', $params['code'])->find_one();

    if(!$log) {
      $response = new Response();
      return $response->withHeader('Location', '/?error=code_expired')->withStatus(302);
    }

    $log->complete = 1;
    $log->date_complete = date('Y-m-d H:i:s');
    $log->code = '';
    $log->save();

    return new HtmlResponse(view('demo', [
      'title' => getenv('APP_NAME'),
      'me' => $login['me'],
    ]));
  }

  public function api_docs(ServerRequestInterface $request): ResponseInterface {
    return new HtmlResponse(view('docs/api', [
      'title' => getenv('APP_NAME').' API Docs',
    ]));
  }

  public function setup_docs(ServerRequestInterface $request): ResponseInterface {
    return new HtmlResponse(view('docs/setup', [
      'title' => 'How to Start Using '.getenv('APP_NAME'),
    ]));
  }

  public function faq(ServerRequestInterface $request): ResponseInterface {
    return new HtmlResponse(view('docs/faq', [
      'title' => getenv('APP_NAME').' FAQ',
    ]));
  }

  public function privacy(ServerRequestInterface $request): ResponseInterface {
    return new HtmlResponse(view('docs/privacy', [
      'title' => 'Privacy Policy',
    ]));
  }

}
