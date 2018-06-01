<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;
use Mailgun\Mailgun;

define('EMAIL_TIMEOUT', 300);

trait Email {

  private function _start_email(&$response, $me, $details) {

    $code = random_string();
    redis()->setex('indielogin:email:'.$code, EMAIL_TIMEOUT, json_encode($details));

    $response->getBody()->write(view('auth/email', [
      'title' => 'Log In via Email',
      'code' => $code,
      'email' => $details['email']
    ]));
    return $response;
  }

  public function send_email(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $login = redis()->get('indielogin:email:'.$params['code']);

    if(!$login) {
      $response->getBody()->write(view('auth/email-error', [
        'title' => 'Error',
        'error' => 'The session expired',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
      return $response;
    }

    $login = json_decode($login, true);

    $usercode = random_user_code();

    redis()->setex('indielogin:email:usercode:'.$params['code'], EMAIL_TIMEOUT, $usercode);

    $login_url = Config::$base.'auth/verify_email_code?'.http_build_query([
      'code' => $params['code'],
      'usercode' => $usercode,
    ]);

    $mg = new Mailgun(Config::$mailgun['key']);
    $result = $mg->sendMessage(Config::$mailgun['domain'], [
      'from'     => Config::$mailgun['from'],
      'to'       => $login['email'],
      'subject'  => 'Your '.Config::$name.' Code: '.$usercode,
      'text'     => "Enter the code below to sign in: \n\n$usercode\n"
    ]);

    $response->getBody()->write(view('auth/email-enter-code', [
      'title' => 'Log In via Email',
      'code' => $params['code'],
    ]));
    return $response;
  }

  public function verify_email_code(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getParsedBody();

    $devlog = make_logger('dev');
    $userlog = make_logger('user');

    $login = redis()->get('indielogin:email:'.$params['code']);

    if(!$login) {
      $response->getBody()->write(view('auth/email-error', [
        'title' => 'Error',
        'error' => 'The session expired',
        'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
      ]));
      return $response;
    }

    $login = json_decode($login, true);

    $usercode = redis()->get('indielogin:email:usercode:'.$params['code']);

    // Check that the code they entered matches the code that was stored

    if(strtolower($usercode) == strtolower($params['usercode'])) {
      return $this->_finishAuthenticate($response);
    } else {
      $response->getBody()->write(view('auth/email-enter-code', [
        'title' => 'Log In via Email',
        'code' => $params['code'],
        'error' => 'You entered an incorrect code. Please try again.',
      ]));
      return $response;
    }

  }


}

