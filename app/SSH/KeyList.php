<?php
namespace App\SSH;

/**
 * The keys a website says are its own: the file its rel="ssh-key" link
 * points to. Each line can be a public key in the form of an authorized_keys
 * or .pub file, options and comment included, or a SHA256: fingerprint, so a
 * plain id_ed25519.pub, an authorized_keys file and https://github.com/USER.keys
 * all work as they are.
 */
class KeyList {

  const MAX_LINES = 1000;

  /** @var string[] key blobs */
  public array $keys = [];

  /** @var string[] SHA256: fingerprints, unpadded */
  public array $fingerprints = [];

  private function __construct() {}

  public static function parse(string $text): self {
    $list = new self();
    $lines = preg_split('/\r\n|\r|\n/', $text);

    if(count($lines) > self::MAX_LINES)
      throw new SSHException('The SSH key file has more than '.self::MAX_LINES.' lines');

    foreach($lines as $line) {
      $line = trim($line);
      if($line === '' || $line[0] === '#')
        continue;

      // A key: its type and then its base64 blob, wherever they are in the
      // line, so that authorized_keys options in front and a comment after
      // are both allowed. The blob has to begin with the type it is listed
      // under, which rules out anything that only looks like a key.
      if(preg_match_all('~(?:^|\s)((?:ssh|ecdsa|sk)-[A-Za-z0-9@.\-]+)\s+([A-Za-z0-9+/]+={0,2})(?=\s|$)~', $line, $matches, PREG_SET_ORDER)) {
        foreach($matches as $m) {
          $blob = base64_decode($m[2], true);
          if($blob !== false && strlen($blob) > 4 && substr($blob, 4, unpack('N', $blob)[1]) === $m[1]) {
            $list->keys[] = $blob;
            continue 2;
          }
        }
      }

      // A fingerprint, alone or as ssh-keygen -l prints it
      if(preg_match('~(?:^|\s)SHA256:([A-Za-z0-9+/]{43})=?(?=\s|$)~', $line, $m)) {
        $list->fingerprints[] = 'SHA256:'.$m[1];
      }
    }

    if(!$list->keys && !$list->fingerprints)
      throw new SSHException('No SSH public keys or SHA256 fingerprints were found in the file');

    return $list;
  }

  public function contains(PublicKey $key): bool {
    foreach($this->keys as $blob)
      if(hash_equals($blob, $key->blob))
        return true;

    return in_array($key->fingerprint(), $this->fingerprints, true);
  }

}
