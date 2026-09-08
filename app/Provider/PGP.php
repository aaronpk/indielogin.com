<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;

use App\OpenPGP\Certificate;
use App\OpenPGP\OpenPGPException;
use App\OpenPGP\Verifier;

use Config;

define('PGP_TIMEOUT', 120);
define('PGP_MAX_KEY_SIZE', 1048576);

trait PGP {

  private function _start_pgp($me, $details) {

    $userlog = make_logger('user');

    $keytext = $this->_fetch_pgp_key($details['key']);

    if($keytext === false) {
      $userlog->warning('Could not fetch PGP key', ['key' => $details['key']]);
      return $this->_pgpError('<b>There was a problem!</b> We could not fetch the PGP key at <code>'.e($details['key']).'</code>.');
    }

    // Parse the key now so that an unusable key is reported before we ask
    // someone to go and sign something
    try {
      Certificate::parse($keytext);
    } catch(OpenPGPException $e) {
      $userlog->warning('Could not parse PGP key', ['key' => $details['key'], 'error' => $e->getMessage()]);
      return $this->_pgpError('<b>There was a problem!</b> We could not read the PGP key at <code>'.e($details['key']).'</code>. '.e($e->getMessage()));
    }

    $_SESSION['login_request']['profile'] = $details['key'];

    $code = random_string();
    $details['keytext'] = $keytext;
    redis()->setex('indielogin:pgp:'.$code, PGP_TIMEOUT, json_encode($details));

    // Bind the challenge to this session and to the identity it was issued
    // for, so that a challenge cannot be carried over into a login attempt
    // for someone else
    $_SESSION['pgp_challenge'] = [
      'code' => $code,
      'me' => $_SESSION['expected_me'] ?? null,
    ];

    return new HtmlResponse(view('auth/pgp', [
      'title' => 'Log In via PGP',
      'code' => $code,
    ]));
  }

  public function verify_pgp_challenge(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();

    $userlog = make_logger('user');

    $code = $params['code'] ?? '';
    $signed = $params['signed'] ?? '';

    if(!$this->_pgp_challenge_matches_session($code)) {
      $userlog->warning('PGP challenge did not match the one issued for this session');
      return $this->_pgpError('The session expired');
    }

    $login = redis()->get('indielogin:pgp:'.$code);

    if(!$login)
      return $this->_pgpError('The session expired');

    $login = json_decode($login, true);

    if(!is_string($signed) || !str_contains($signed, '-----BEGIN PGP '))
      return $this->_pgpError('It looks like you did not sign the challenge.');

    try {
      $result = Verifier::verify($login['keytext'], $signed);
    } catch(OpenPGPException $e) {
      $userlog->info('PGP verification failed', ['key' => $login['key'], 'error' => $e->getMessage()]);
      return $this->_pgpError('<b>There was a problem!</b> '.e($e->getMessage()));
    }

    // The signature has to cover this login attempt's challenge. Without this,
    // any text the person had ever signed with their key could be replayed here.
    if(!hash_equals($code, trim($result['plaintext']))) {
      $userlog->warning('PGP signature was valid but covered the wrong text', ['key' => $login['key']]);
      return $this->_pgpError('<b>There was a problem!</b> The signature was valid, but it was not made over the challenge we asked you to sign.');
    }

    // The challenge is good for one use
    redis()->del('indielogin:pgp:'.$code);
    unset($_SESSION['pgp_challenge']);

    $userlog->info('Verified PGP challenge', [
      'key' => $login['key'],
      'fingerprint' => $result['fingerprint'],
    ]);

    return $this->_finishAuthenticate();
  }

  private function _pgp_challenge_matches_session($code) {
    $challenge = $_SESSION['pgp_challenge'] ?? null;

    if(!is_array($challenge) || !is_string($code) || !is_string($challenge['code'] ?? null))
      return false;

    return hash_equals($challenge['code'], $code)
      && ($challenge['me'] ?? null) === ($_SESSION['expected_me'] ?? null);
  }

  private function _fetch_pgp_key($url) {
    if(!\p3k\url\is_url($url))
      return false;

    $response = guzzle_request_get(new \GuzzleHttp\Client(), $url);

    if(!is_object($response) || $response->getStatusCode() != 200)
      return false;

    $keytext = \GuzzleHttp\Psr7\Utils::copyToString($response->getBody(), PGP_MAX_KEY_SIZE);

    return $keytext === '' ? false : $keytext;
  }

  private function _pgpError($error): ResponseInterface {
    return new HtmlResponse(view('auth/pgp-error', [
      'title' => 'Error',
      'error' => $error,
      'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
    ]));
  }

}
