<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;
use ATProto, ATProtoException;

use Config;

trait ATProtoProvider {

  private function _start_atproto($login_request, $details) {
    $userlog = make_logger('user');

    $at = new ATProto();
    $at->initialize($details['atproto']['handle'], $details['atproto']['did']);
    $authorize = $at->start_oauth();

    $_SESSION['atproto.did'] = $details['atproto']['did'];
    $at->save_state();

    $userlog->info('Beginning ATProto login', ['provider' => $details, 'login' => $login_request]);

    return redirect_response($authorize, 302);
  }

  public function redirect_atproto(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    $at = ATProto::restore_from_session();

    try {
      $at->finish_oauth($query);
    } catch(ATProtoException $e) {
      return $this->_userError($e->getMessage());
    }


    return $this->_finishAuthenticate();
  }

}
