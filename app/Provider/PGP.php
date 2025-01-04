<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;

use Config;

define('PGP_TIMEOUT', 120);

trait PGP {

  private function _start_pgp($me, $details) {

    $keytext = file_get_contents($details['key']);

    $_SESSION['login_request']['profile'] = $details['key'];

    $code = random_string();
    $details['keytext'] = $keytext;
    redis()->setex('indielogin:pgp:'.$code, PGP_TIMEOUT, json_encode($details));

    return new HtmlResponse(view('auth/pgp', [
      'title' => 'Log In via PGP',
      'code' => $code,
    ]));
  }

  public function verify_pgp_challenge(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $login = redis()->get('indielogin:pgp:'.$params['code']);

    if(!$login) {
      return new HtmlResponse(view('auth/pgp-error', [
        'title' => 'Error',
        'error' => 'The session expired',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    if($params['signed'] == $params['code']) {
      return new HtmlResponse(view('auth/pgp-error', [
        'title' => 'Error',
        'error' => 'It looks like you did not sign the challenge.',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    $login = json_decode($login, true);

    $keytext = $login['keytext'];

    $ch = curl_init(getenv('PGP_VERIFICATION_API'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
      'key' => $keytext,
      'signed' => $params['signed'],
    ]));
    $result = json_decode(curl_exec($ch), true);

    if(!$result) {
      return new HtmlResponse(view('auth/pgp-error', [
        'title' => 'Error',
        'error' => '<b>Something went wrong!</b> There was an internal error attempting to verify the challenge. Please try a different authentication method.',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    if(isset($result['error'])) {
      switch($result['error']) {
        case 'invalid_signature':
          $description = 'The PGP signature was not valid.'; break;
        case 'key_mismatch':
          $description = 'The signature was valid, but was signed with a different key than we expected.'; break;
        default:
          $description = ''; break;
      }

      return new HtmlResponse(view('auth/pgp-error', [
        'title' => 'Error',
        'error' => '<b>There was a problem!</b> '.e($description),
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }

    if($result['result'] == 'verified') {
      return $this->_finishAuthenticate();
    } else {
      return new HtmlResponse(view('auth/pgp-error', [
        'title' => 'Error',
        'error' => '<b>Something went wrong!</b> There was an internal error attempting to verify the challenge.',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
    }
  }

}
