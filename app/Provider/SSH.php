<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;

use App\SSH\KeyList;
use App\SSH\Pending;
use App\SSH\SSHException;
use App\SSH\Verifier;

define('SSH_MAX_KEYS_SIZE', 65536);

/**
 * Signing in with an SSH key: the website links to its keys with
 * rel="ssh-key", and the person proves they hold one in either of two ways.
 * They can sign a challenge with ssh-keygen -Y sign and paste it back, the
 * same way PGP works, or, where an SSH sign-in server is configured, run
 * ssh <domain>@<server> and confirm there, while this page waits.
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

    // What the SSH sign-in server shows when someone confirms, so they can
    // tell this sign-in from one they did not start
    [$code, $connect] = Pending::create([
      'key' => $details['key'],
      'keystext' => $keystext,
      'client_id' => $_SESSION['login_request']['client_id'] ?? null,
      'me' => $_SESSION['expected_me'] ?? '',
      'started' => time(),
      'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

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
      'server' => ssh_server(),
      'domain' => Pending::domain($_SESSION['expected_me'] ?? ''),
      'connect' => Pending::displayCode($connect),
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

    $login = Pending::get($code);

    if(!$login)
      return $this->_sshError('The session expired');

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
    Pending::forget($code);
    unset($_SESSION['ssh_challenge']);

    $userlog->info('Verified SSH challenge', [
      'key' => $login['key'],
      'type' => $result['type'],
      'fingerprint' => $result['fingerprint'],
      'method' => 'signature',
    ]);

    return $this->_finishAuthenticate();
  }

  /**
   * What the challenge page polls while it waits for someone to confirm over
   * SSH. It only ever answers about the challenge bound to this browser's
   * session.
   */
  public function ssh_status(ServerRequestInterface $request): ResponseInterface {
    session_start();
    $challenge = $_SESSION['ssh_challenge']['code'] ?? null;
    // Polling must not hold the session lock while other requests wait on it
    session_write_close();

    if(!is_string($challenge) || !Pending::get($challenge))
      $status = 'expired';
    elseif(Pending::approval($challenge))
      $status = 'approved';
    else
      $status = 'waiting';

    return new JsonResponse(['status' => $status], 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Finish a sign-in that was confirmed over SSH. The SSH sign-in server has
   * checked that the person holds a key the website lists; this checks that
   * the confirmation is for the sign-in this browser started.
   */
  public function verify_ssh_connection(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $params = $request->getParsedBody();
    $code = $params['code'] ?? '';

    $userlog = make_logger('user');

    if(!$this->_ssh_challenge_matches_session($code)) {
      $userlog->warning('SSH connection confirmation did not match the challenge issued for this session');
      return $this->_sshError('The session expired');
    }

    $login = Pending::get($code);
    if(!$login)
      return $this->_sshError('The session expired');

    $approval = Pending::approval($code);
    if(!$approval)
      return $this->_sshError('This sign-in has not been confirmed over SSH yet. Run the ssh command shown on the previous page, and press Enter when it asks.');

    Pending::forget($code);
    unset($_SESSION['ssh_challenge']);

    $userlog->info('Verified SSH challenge', [
      'key' => $login['key'],
      'type' => $approval['type'],
      'fingerprint' => $approval['fingerprint'],
      'method' => 'connect',
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
