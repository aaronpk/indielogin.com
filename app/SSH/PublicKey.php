<?php
namespace App\SSH;

use App\OpenPGP\Der;

/**
 * An SSH public key, parsed from its wire-format blob, which can check a
 * signature made with it. The key material is re-encoded into the shapes
 * OpenSSL and libsodium expect; all of the actual cryptography is done by
 * those libraries.
 */
class PublicKey {

  const OID_RSA = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
  const OID_EC_PUBLIC_KEY = "\x2a\x86\x48\xce\x3d\x02\x01";

  // SSH curve name => DER OID contents, and the hash its plain ECDSA
  // signatures use (RFC 5656 section 6.2.1)
  const CURVES = [
    'nistp256' => ['oid' => "\x2a\x86\x48\xce\x3d\x03\x01\x07", 'hash' => OPENSSL_ALGO_SHA256],
    'nistp384' => ['oid' => "\x2b\x81\x04\x00\x22", 'hash' => OPENSSL_ALGO_SHA384],
    'nistp521' => ['oid' => "\x2b\x81\x04\x00\x23", 'hash' => OPENSSL_ALGO_SHA512],
  ];

  // RSA signature types SSHSIG may use. The original "ssh-rsa" signature
  // type is SHA-1 and is refused.
  const RSA_SIGNATURES = [
    'rsa-sha2-256' => OPENSSL_ALGO_SHA256,
    'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
  ];

  // FIDO security keys must have seen a touch for the signature to count,
  // as OpenSSH requires unless told otherwise
  const SK_USER_PRESENT = 0x01;

  public string $type;
  public string $blob;
  private array $material;

  private function __construct(string $type, string $blob, array $material) {
    $this->type = $type;
    $this->blob = $blob;
    $this->material = $material;
  }

  public static function parse(string $blob): self {
    $r = new Reader($blob);
    $type = $r->string();

    if(str_contains($type, '-cert-'))
      throw new SSHException('SSH certificates are not supported. Sign with a plain key instead.');

    switch($type) {
      case 'ssh-ed25519':
        $material = ['pk' => $r->string()];
        break;

      case 'sk-ssh-ed25519@openssh.com':
        $material = ['pk' => $r->string(), 'application' => $r->string()];
        break;

      case 'ssh-rsa':
        $material = ['e' => $r->mpint(), 'n' => $r->mpint()];
        if(strlen($material['n']) * 8 < 2048 - 8)
          throw new SSHException('RSA keys shorter than 2048 bits are not supported');
        break;

      case 'ecdsa-sha2-nistp256':
      case 'ecdsa-sha2-nistp384':
      case 'ecdsa-sha2-nistp521':
        $material = ['curve' => $r->string(), 'point' => $r->string()];
        if('ecdsa-sha2-'.$material['curve'] !== $type)
          throw new SSHException('The SSH key names a different curve than its type');
        break;

      case 'sk-ecdsa-sha2-nistp256@openssh.com':
        $material = ['curve' => $r->string(), 'point' => $r->string(), 'application' => $r->string()];
        if($material['curve'] !== 'nistp256')
          throw new SSHException('The SSH key names a different curve than its type');
        break;

      default:
        throw new SSHException('SSH keys of type '.$type.' are not supported');
    }

    $r->expectEnd();

    if(isset($material['pk']) && strlen($material['pk']) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
      throw new SSHException('The Ed25519 key is the wrong length');

    return new self($type, $blob, $material);
  }

  /**
   * The fingerprint OpenSSH shows for this key: SHA256: and the unpadded
   * base64 of the SHA-256 of its blob.
   */
  public function fingerprint(): string {
    return self::fingerprintOf($this->blob);
  }

  public static function fingerprintOf(string $blob): string {
    return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
  }

  /**
   * Whether $signature, an SSH signature blob, is this key's signature over
   * $data.
   */
  public function verify(string $data, string $signature): bool {
    $r = new Reader($signature);
    $sigType = $r->string();

    // Made through a browser rather than by ssh-keygen, with the browser's
    // own data wrapped around what is signed
    if(str_starts_with($sigType, 'webauthn-'))
      throw new SSHException('Signatures made through WebAuthn are not supported. Sign with ssh-keygen -Y sign instead.');

    switch($this->type) {
      case 'ssh-ed25519':
        if($sigType !== 'ssh-ed25519')
          return false;
        $sig = $r->string();
        $r->expectEnd();
        return self::verifyEd25519($this->material['pk'], $sig, $data);

      case 'sk-ssh-ed25519@openssh.com':
        if($sigType !== 'sk-ssh-ed25519@openssh.com')
          return false;
        $sig = $r->string();
        $signed = $this->securityKeySignedData($r, $data);
        return self::verifyEd25519($this->material['pk'], $sig, $signed);

      case 'ssh-rsa':
        if(!isset(self::RSA_SIGNATURES[$sigType]))
          throw new SSHException('RSA signatures made with SHA-1 are not supported. Use a current version of OpenSSH, which signs with SHA-512.');
        $sig = $r->string();
        $r->expectEnd();
        return self::verifyOpenssl($this->rsaPem(), $data, $this->rsaSignature($sig), self::RSA_SIGNATURES[$sigType]);

      case 'ecdsa-sha2-nistp256':
      case 'ecdsa-sha2-nistp384':
      case 'ecdsa-sha2-nistp521':
        if($sigType !== $this->type)
          return false;
        $sig = self::ecdsaSignature($r->string());
        $r->expectEnd();
        return self::verifyOpenssl($this->ecdsaPem(), $data, $sig, self::CURVES[$this->material['curve']]['hash']);

      case 'sk-ecdsa-sha2-nistp256@openssh.com':
        if($sigType !== 'sk-ecdsa-sha2-nistp256@openssh.com')
          return false;
        $sig = self::ecdsaSignature($r->string());
        $signed = $this->securityKeySignedData($r, $data);
        return self::verifyOpenssl($this->ecdsaPem(), $signed, $sig, OPENSSL_ALGO_SHA256);
    }

    return false;
  }

  /**
   * What a FIDO security key actually signs: the hash of the application it
   * was registered for, its flags, its counter, and the hash of the data
   * (OpenSSH PROTOCOL.u2f). The flags and counter follow the signature.
   */
  private function securityKeySignedData(Reader $r, string $data): string {
    $flags = $r->byte();
    $counter = $r->uint32();
    $r->expectEnd();

    if(!($flags & self::SK_USER_PRESENT))
      throw new SSHException('The security key did not confirm that someone touched it. Sign again and touch the key when it asks.');

    return hash('sha256', $this->material['application'], true)
      .chr($flags)
      .pack('N', $counter)
      .hash('sha256', $data, true);
  }

  private static function verifyEd25519(string $publicKey, string $signature, string $data): bool {
    if(strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES)
      return false;

    return sodium_crypto_sign_verify_detached($signature, $data, $publicKey);
  }

  private static function verifyOpenssl(string $pem, string $data, string $signature, int $hash): bool {
    $publicKey = openssl_pkey_get_public($pem);
    if($publicKey === false)
      throw new SSHException('The SSH key could not be read');

    // Anything other than an explicit 1 (including -1, an OpenSSL error) is
    // treated as a failed verification
    return openssl_verify($data, $signature, $publicKey, $hash) === 1;
  }

  private function rsaPem(): string {
    return Der::pem(Der::sequence(
      Der::sequence(Der::oid(self::OID_RSA), Der::null()),
      Der::bitString(Der::sequence(
        Der::integer($this->material['n']),
        Der::integer($this->material['e'])
      ))
    ));
  }

  /**
   * OpenSSL wants an RSA signature at exactly the length of the modulus.
   */
  private function rsaSignature(string $sig): string {
    $modulusLength = strlen($this->material['n']);
    $sig = ltrim($sig, "\x00");

    if(strlen($sig) > $modulusLength)
      return '';

    return str_pad($sig, $modulusLength, "\x00", STR_PAD_LEFT);
  }

  private function ecdsaPem(): string {
    return Der::pem(Der::sequence(
      Der::sequence(Der::oid(self::OID_EC_PUBLIC_KEY), Der::oid(self::CURVES[$this->material['curve']]['oid'])),
      Der::bitString($this->material['point'])
    ));
  }

  /**
   * An SSH ECDSA signature is two mpints, r and s, inside a string; OpenSSL
   * wants them as a DER SEQUENCE of two INTEGERs.
   */
  private static function ecdsaSignature(string $blob): string {
    $r = new Reader($blob);
    $sigR = $r->mpint();
    $sigS = $r->mpint();
    $r->expectEnd();

    return Der::sequence(Der::integer($sigR), Der::integer($sigS));
  }

}
