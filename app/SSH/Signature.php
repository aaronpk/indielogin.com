<?php
namespace App\SSH;

/**
 * A detached SSH signature in OpenSSH's SSHSIG format (PROTOCOL.sshsig), as
 * made by `ssh-keygen -Y sign`. It carries the public key that made it, the
 * namespace it was made for, and the hash of the message it covers.
 */
class Signature {

  const MAGIC = 'SSHSIG';
  const VERSION = 1;
  const HASHES = ['sha256', 'sha512'];

  // A pasted signature is a few kilobytes at most, even for a large RSA key
  const MAX_LENGTH = 16384;

  public PublicKey $key;
  public string $namespace;
  public string $reserved;
  public string $hashAlgorithm;
  public string $signature;

  private function __construct() {}

  /**
   * Parse an armored signature, the text between -----BEGIN SSH SIGNATURE-----
   * and -----END SSH SIGNATURE-----, wherever it is in the pasted text.
   */
  public static function parse(string $armored): self {
    if(strlen($armored) > self::MAX_LENGTH)
      throw new SSHException('That is too long to be an SSH signature');

    if(!preg_match('/-----BEGIN SSH SIGNATURE-----(.*?)-----END SSH SIGNATURE-----/s', $armored, $match))
      throw new SSHException('That does not look like an SSH signature. It should start with -----BEGIN SSH SIGNATURE-----.');

    $blob = base64_decode(preg_replace('/\s+/', '', $match[1]), true);
    if($blob === false)
      throw new SSHException('The SSH signature is not valid base64');

    $r = new Reader($blob);

    if($r->bytes(strlen(self::MAGIC)) !== self::MAGIC)
      throw new SSHException('That is not an SSHSIG signature');

    if($r->uint32() !== self::VERSION)
      throw new SSHException('That SSH signature uses a version of the format that is not supported');

    $sig = new self();
    $sig->key = PublicKey::parse($r->string());
    $sig->namespace = $r->string();
    $sig->reserved = $r->string();
    $sig->hashAlgorithm = $r->string();
    $sig->signature = $r->string();
    $r->expectEnd();

    if(!in_array($sig->hashAlgorithm, self::HASHES, true))
      throw new SSHException('SSH signatures hashed with '.$sig->hashAlgorithm.' are not supported');

    return $sig;
  }

  /**
   * Whether this signature covers $message. What the key signs is not the
   * message itself but this wrapper around its hash, which binds in the
   * namespace.
   */
  public function covers(string $message): bool {
    $signed = self::MAGIC
      .Reader::encodeString($this->namespace)
      .Reader::encodeString($this->reserved)
      .Reader::encodeString($this->hashAlgorithm)
      .Reader::encodeString(hash($this->hashAlgorithm, $message, true));

    return $this->key->verify($signed, $this->signature);
  }

}
