<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;

use App\SSH\KeyList;
use App\SSH\Pending;
use App\SSH\PublicKey;
use App\SSH\SSHException;

/**
 * The private API the SSH sign-in server (ssh-server/) calls. It runs on a
 * machine of its own and has no access to Redis or the database, so it asks
 * here whether a key may sign in for the username it was given, and tells us
 * when someone has confirmed.
 *
 * This trusts that server: it is what checks, over SSH, that the person
 * holds the key. Anyone with SSH_SERVER_API_KEY, from an address in
 * SSH_SERVER_API_IPS, can approve SSH sign-ins. Without both settings every
 * path here is a 404.
 *
 * The username someone ssh's in with is either the domain they are signing
 * in as, or the connect code their browser shows. A domain matches every
 * sign-in waiting for it, whichever application it is for, and when more
 * than one could be meant, nothing is picked until the code is given: that
 * is also what stops a sign-in someone else started for the same domain at
 * the same time from being confirmed by mistake.
 */
class SSHServerApi {

  public function check(ServerRequestInterface $request): ResponseInterface {
    if(!$this->_authorized($request))
      return $this->_notFound();

    $params = $this->_params($request);
    $result = $this->_resolve($params);

    if(isset($result['error']))
      return $this->_answer('check', $params, $result['error']);

    if(count($result['matches']) > 1)
      return $this->_answer('check', $params, ['ok' => true, 'matches' => count($result['matches'])]);

    return $this->_answer('check', $params, $this->_describe($result['matches'][0], $result['key']));
  }

  public function approve(ServerRequestInterface $request): ResponseInterface {
    if(!$this->_authorized($request))
      return $this->_notFound();

    $params = $this->_params($request);
    $result = $this->_resolve($params);

    if(isset($result['error']))
      return $this->_answer('approve', $params, $result['error']);

    // Something else may have started for this domain since the check, so
    // which sign-in is meant has to be settled again here, not remembered
    if(count($result['matches']) > 1)
      return $this->_answer('approve', $params, ['ok' => false, 'reason' => 'ambiguous', 'matches' => count($result['matches'])]);

    $challenge = $result['matches'][0];
    if(!Pending::approve($challenge, $result['key']->fingerprint(), $result['key']->type))
      return $this->_answer('approve', $params, ['ok' => false, 'reason' => 'none_waiting']);

    return $this->_answer('approve', $params, $this->_describe($challenge, $result['key']));
  }

  /**
   * The waiting sign-ins this request could be about, narrowed to those whose
   * website lists the key, and to the one a connect code names if one was
   * given. Returns ['matches' => [...], 'key' => PublicKey] or ['error' => ...].
   */
  private function _resolve(array $params): array {
    $user = is_string($params['user'] ?? null) ? $params['user'] : '';
    $keyLine = is_string($params['key'] ?? null) ? $params['key'] : '';
    $code = is_string($params['code'] ?? null) ? $params['code'] : '';

    $parts = preg_split('/\s+/', trim($keyLine));
    $blob = count($parts) >= 2 ? base64_decode($parts[1], true) : false;
    if($blob === false)
      return ['error' => ['ok' => false, 'reason' => 'bad_request']];

    try {
      $key = PublicKey::parse($blob);
    } catch(SSHException $e) {
      return ['error' => ['ok' => false, 'reason' => 'unsupported_key', 'message' => $e->getMessage()]];
    }

    // A domain has a dot in it; a connect code never does
    $candidates = str_contains($user, '.')
      ? Pending::byDomain($user)
      : array_filter([Pending::byConnectCode($user)]);

    if(!$candidates)
      return ['error' => ['ok' => false, 'reason' => 'none_waiting']];

    $matches = [];
    $keysUrls = [];
    foreach($candidates as $challenge) {
      $details = Pending::get($challenge);
      if(!$details)
        continue;
      $keysUrls[] = $details['key'];
      try {
        if(KeyList::parse($details['keystext'])->contains($key))
          $matches[] = $challenge;
      } catch(SSHException $e) {
        // A key file that could not be read lists nothing
      }
    }

    if(!$matches)
      return ['error' => ['ok' => false, 'reason' => 'unlisted', 'keys_urls' => array_values(array_unique($keysUrls))]];

    if($code !== '') {
      $named = Pending::byConnectCode($code);
      if(!$named || !in_array($named, $matches, true))
        return ['error' => ['ok' => false, 'reason' => 'bad_code']];
      $matches = [$named];
    }

    return ['matches' => array_values($matches), 'key' => $key];
  }

  /**
   * What the SSH server shows someone before they confirm.
   */
  private function _describe(string $challenge, PublicKey $key): array {
    $details = Pending::get($challenge) ?? [];

    return [
      'ok' => true,
      'matches' => 1,
      'client_id' => $details['client_id'] ?? null,
      'me' => $details['me'] ?? null,
      'started' => $details['started'] ?? null,
      'ip' => $details['ip'] ?? null,
      'fingerprint' => $key->fingerprint(),
      'type' => $key->type,
    ];
  }

  private function _authorized(ServerRequestInterface $request): bool {
    $secret = trim((string)getenv('SSH_SERVER_API_KEY'));
    $allowed = array_filter(array_map('trim', explode(',', (string)getenv('SSH_SERVER_API_IPS'))), 'strlen');

    if($secret === '' || !$allowed)
      return false;

    $given = preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) ? $m[1] : '';
    $address = @inet_pton($request->getServerParams()['REMOTE_ADDR'] ?? '');

    $addressAllowed = false;
    foreach($allowed as $ip) {
      if($address !== false && @inet_pton($ip) === $address)
        $addressAllowed = true;
    }

    if(!hash_equals($secret, $given) || !$addressAllowed) {
      make_logger('user')->warning('Refused SSH server API request', [
        'remote_addr' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
        'key_given' => $given !== '',
      ]);
      return false;
    }

    return true;
  }

  private function _params(ServerRequestInterface $request): array {
    $params = json_decode((string)$request->getBody(), true);
    return is_array($params) ? $params : [];
  }

  private function _answer(string $action, array $params, array $result): ResponseInterface {
    make_logger('user')->info('SSH server '.$action, [
      'user' => is_string($params['user'] ?? null) ? $params['user'] : null,
      'result' => $result['ok'] ? 'ok' : $result['reason'],
      'matches' => $result['matches'] ?? null,
      'fingerprint' => $result['fingerprint'] ?? null,
    ]);

    return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
  }

  private function _notFound(): ResponseInterface {
    return new HtmlResponse(view('http-error', [
      'title' => '404 Not Found',
      'status' => 404,
      'message' => 'Not Found',
    ]), 404);
  }

}
