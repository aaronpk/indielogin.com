<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;

use App\SSH\KeyList;
use App\SSH\SSHException;
use App\SSH\Verifier;

define('SSH_TIMEOUT', 120);
define('SSH_MAX_KEYS_SIZE', 65536);

/**
 * Signing in with an SSH key: the website links to its keys with
 * rel="ssh-key", and the person signs a challenge with ssh-keygen -Y sign.
 * Works the same way as PGP.
 */
trait SSH {

  private function _start_ssh($me, $details) {

    $userlog = make_logger('user');

    $keystext = $this->_fetch_ssh_keys($details['key']);

    if($keystext === false) {
      $userlog->warning('Could not fetch SSH keys', ['key' => $details['key']]);
      return $this->_sshError('<b>There was a problem!</b> We could not fetch the SSH keys at <code>'.e($details['key']).'</code>.');
    }

    // Read the file now so that an unusable one is reported before we ask
    // someone to go and sign something
    try {
      KeyList::parse($keystext);
    } catch(SSHException $e) {
      $userlog->warning('Could not read SSH keys', ['key' => $details['key'], 'error' => $e->getMessage()]);
      return $this->_sshError('<b>There was a problem!</b> We could not read the SSH keys at <code>'.e($details['key']).'</code>. '.e($e->getMessage()));
    }

    $_SESSION['login_request']['profile'] = $details['key'];

    $code = random_string();
    $details['keystext'] = $keystext;
    redis()->setex('indielogin:ssh:'.$code, SSH_TIMEOUT, json_encode($details));

    // Bind the challenge to this session and to the identity it was issued
    // for, so that a challenge cannot be carried over into a login attempt
    // for someone else
    $_SESSION['ssh_challenge'] = [
      'code' => $code,
      'me' => $_SESSION['expected_me'] ?? null,
    ];

    return new HtmlResponse(view('auth/ssh', [
      'title' => 'Log In via SSH Key',
      'code' => $code,
      'namespace' => ssh_signature_namespace(),
      'keys_url' => $details['key'],
    ]));
  }

  public function verify_ssh_challenge(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();

    $userlog = make_logger('user');

    $code = $params['code'] ?? '';
    $signed = $params['signed'] ?? '';

    if(!$this->_ssh_challenge_matches_session($code)) {
      $userlog->warning('SSH challenge did not match the one issued for this session');
      return $this->_sshError('The session expired');
    }

    $login = redis()->get('indielogin:ssh:'.$code);

    if(!$login)
      return $this->_sshError('The session expired');

    $login = json_decode($login, true);

    if(!is_string($signed) || !str_contains($signed, '-----BEGIN SSH SIGNATURE-----'))
      return $this->_sshError('It looks like you did not sign the challenge.');

    // The signature has to cover this login attempt's challenge, in this
    // site's namespace, by a key the website lists
    try {
      $result = Verifier::verify($login['keystext'], $signed, ssh_signature_namespace(), $code);
    } catch(SSHException $e) {
      $userlog->info('SSH verification failed', ['key' => $login['key'], 'error' => $e->getMessage()]);
      return $this->_sshError('<b>There was a problem!</b> '.e($e->getMessage()));
    }

    // The challenge is good for one use
    redis()->del('indielogin:ssh:'.$code);
    unset($_SESSION['ssh_challenge']);

    $userlog->info('Verified SSH challenge', [
      'key' => $login['key'],
      'type' => $result['type'],
      'fingerprint' => $result['fingerprint'],
    ]);

    return $this->_finishAuthenticate();
  }

  private function _ssh_challenge_matches_session($code) {
    $challenge = $_SESSION['ssh_challenge'] ?? null;

    if(!is_array($challenge) || !is_string($code) || !is_string($challenge['code'] ?? null))
      return false;

    return hash_equals($challenge['code'], $code)
      && ($challenge['me'] ?? null) === ($_SESSION['expected_me'] ?? null);
  }

  private function _fetch_ssh_keys($url) {
    if(!\p3k\url\is_url($url))
      return false;

    // The same safe fetch as the profile page: public addresses only, on
    // every redirect
    $response = safe_get($url);

    if(isset($response['exception']) || $response['code'] != 200)
      return false;

    $keystext = substr((string)$response['body'], 0, SSH_MAX_KEYS_SIZE);

    return $keystext === '' ? false : $keystext;
  }

  private function _sshError($error): ResponseInterface {
    return new HtmlResponse(view('auth/ssh-error', [
      'title' => 'Error',
      'error' => $error,
      'client_id' => ($_SESSION['login_request']['client_id'] ?? false)
    ]));
  }

}
