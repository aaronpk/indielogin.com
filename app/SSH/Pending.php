<?php
namespace App\SSH;

/**
 * SSH key sign-ins waiting to be finished, in Redis.
 *
 * Each one is a challenge, which can be finished in either of two ways: by
 * pasting a signature over it, or by connecting to the SSH sign-in server
 * and confirming there. For the second, a waiting sign-in can be found by a
 * short connect code, or by the domain being signed in as, since that is
 * what people type as their SSH username.
 *
 *   indielogin:ssh:<challenge>           the sign-in's details, as JSON
 *   indielogin:ssh:connect:<code>        connect code => challenge
 *   indielogin:ssh:pending:<domain>      set of challenges for that domain
 *   indielogin:ssh:approved:<challenge>  confirmed over SSH: fingerprint, type
 */
class Pending {

  // Long enough to answer a first-connection host key prompt
  const TTL = 300;

  // Connect codes avoid 0/o, 1/i/l, which are easy to misread
  const CODE_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
  const CODE_LENGTH = 8;

  /**
   * Store a new waiting sign-in. $details has at least 'me'. Returns the
   * challenge and the connect code.
   */
  public static function create(array $details): array {
    $challenge = random_string();
    $connect = self::newConnectCode();
    $domain = self::domain($details['me'] ?? '');

    $details['connect'] = $connect;
    $details['domain'] = $domain;

    redis()->setex('indielogin:ssh:'.$challenge, self::TTL, json_encode($details));
    redis()->setex('indielogin:ssh:connect:'.$connect, self::TTL, $challenge);

    if($domain !== null) {
      redis()->sadd('indielogin:ssh:pending:'.$domain, [$challenge]);
      redis()->expire('indielogin:ssh:pending:'.$domain, self::TTL);
    }

    return [$challenge, $connect];
  }

  public static function get(string $challenge): ?array {
    $json = redis()->get('indielogin:ssh:'.$challenge);
    $details = $json ? json_decode($json, true) : null;

    return is_array($details) ? $details : null;
  }

  /**
   * The challenge a connect code names, if it is still waiting.
   */
  public static function byConnectCode(string $code): ?string {
    $code = self::normalizeConnectCode($code);
    if($code === null)
      return null;

    $challenge = redis()->get('indielogin:ssh:connect:'.$code);
    return ($challenge && self::get($challenge)) ? $challenge : null;
  }

  /**
   * Every challenge still waiting for this domain, whichever application it
   * is for. Entries whose sign-in has expired are dropped from the set.
   */
  public static function byDomain(string $domain): array {
    $domain = self::domain($domain);
    if($domain === null)
      return [];

    $live = [];
    foreach(redis()->smembers('indielogin:ssh:pending:'.$domain) as $challenge) {
      if(self::get($challenge))
        $live[] = $challenge;
      else
        redis()->srem('indielogin:ssh:pending:'.$domain, $challenge);
    }

    sort($live);
    return $live;
  }

  /**
   * Record that a sign-in was confirmed over SSH. The connect code is spent
   * at once, so it can only ever be used for one confirmation.
   */
  public static function approve(string $challenge, string $fingerprint, string $type): bool {
    $details = self::get($challenge);
    if(!$details)
      return false;

    redis()->setex('indielogin:ssh:approved:'.$challenge, self::TTL, json_encode([
      'fingerprint' => $fingerprint,
      'type' => $type,
    ]));
    redis()->del('indielogin:ssh:connect:'.$details['connect']);

    return true;
  }

  public static function approval(string $challenge): ?array {
    $json = redis()->get('indielogin:ssh:approved:'.$challenge);
    $approval = $json ? json_decode($json, true) : null;

    return is_array($approval) ? $approval : null;
  }

  /**
   * Remove every trace of a sign-in, once it is finished either way.
   */
  public static function forget(string $challenge): void {
    $details = self::get($challenge);

    redis()->del('indielogin:ssh:'.$challenge);
    redis()->del('indielogin:ssh:approved:'.$challenge);

    if($details) {
      if(!empty($details['connect']))
        redis()->del('indielogin:ssh:connect:'.$details['connect']);
      if(!empty($details['domain']))
        redis()->srem('indielogin:ssh:pending:'.$details['domain'], $challenge);
    }
  }

  /**
   * The domain a sign-in is matched by, from a URL or as typed for an SSH
   * username: the host, lowercased, in its ASCII form, without a leading
   * "www.", so that www.example.com, Example.com and an internationalized
   * domain in either spelling all match. Null if there is none.
   */
  public static function domain(string $urlOrHost): ?string {
    $host = $urlOrHost;

    if(preg_match('~\A[a-z][a-z0-9+.\-]*://~i', $urlOrHost)) {
      $parts = split_url_host($urlOrHost);
      if($parts === false)
        return null;
      $host = $parts[1];
    }

    $host = rtrim(mb_strtolower(trim($host)), '.');
    $host = strtolower((string)idn_host($host));

    if(str_starts_with($host, 'www.'))
      $host = substr($host, 4);

    // Only something that could be a hostname: no path, port, user or spaces
    if($host === '' || !preg_match('/\A[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+\z/', $host))
      return null;

    return $host;
  }

  public static function newConnectCode(): string {
    $code = '';
    for($i = 0; $i < self::CODE_LENGTH; $i++)
      $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];

    return $code;
  }

  /**
   * A connect code as someone typed it, without case, dashes or spaces, or
   * null if it cannot be one.
   */
  public static function normalizeConnectCode(string $input): ?string {
    $code = preg_replace('/[\s\-]+/', '', strtolower($input));

    if(strlen($code) !== self::CODE_LENGTH || strspn($code, self::CODE_ALPHABET) !== self::CODE_LENGTH)
      return null;

    return $code;
  }

  /**
   * A connect code as it is shown: k7f2-9qxm.
   */
  public static function displayCode(string $code): string {
    return substr($code, 0, 4).'-'.substr($code, 4);
  }

}
