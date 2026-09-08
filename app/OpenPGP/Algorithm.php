<?php
namespace App\OpenPGP;

/**
 * Algorithm identifiers from the OpenPGP registry, and the mapping to the
 * names PHP's hash/openssl/sodium functions use.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-9
 */
class Algorithm {

  // Public key algorithms
  const RSA = 1;
  const RSA_ENCRYPT_ONLY = 2;
  const RSA_SIGN_ONLY = 3;
  const ELGAMAL = 16;
  const DSA = 17;
  const ECDH = 18;
  const ECDSA = 19;
  const EDDSA_LEGACY = 22;
  const X25519 = 25;
  const X448 = 26;
  const ED25519 = 27;
  const ED448 = 28;

  // Hash algorithms
  const HASHES = [
    1  => 'md5',
    2  => 'sha1',
    3  => 'ripemd160',
    8  => 'sha256',
    9  => 'sha384',
    10 => 'sha512',
    11 => 'sha224',
    12 => 'sha3-256',
    14 => 'sha3-512',
  ];

  // Hash algorithms that openssl_verify() can be asked to use directly. The
  // rest can only be verified for key types where we hash the data ourselves.
  const OPENSSL_HASHES = [
    1  => OPENSSL_ALGO_MD5,
    2  => OPENSSL_ALGO_SHA1,
    3  => OPENSSL_ALGO_RMD160,
    8  => OPENSSL_ALGO_SHA256,
    9  => OPENSSL_ALGO_SHA384,
    10 => OPENSSL_ALGO_SHA512,
    11 => OPENSSL_ALGO_SHA224,
  ];

  // Elliptic curves, keyed by the DER content octets of their object
  // identifier as they appear inside an OpenPGP key packet.
  const CURVES = [
    "\x2a\x86\x48\xce\x3d\x03\x01\x07"             => ['name' => 'nistp256', 'oid' => "\x2a\x86\x48\xce\x3d\x03\x01\x07", 'size' => 32],
    "\x2b\x81\x04\x00\x22"                         => ['name' => 'nistp384', 'oid' => "\x2b\x81\x04\x00\x22", 'size' => 48],
    "\x2b\x81\x04\x00\x23"                         => ['name' => 'nistp521', 'oid' => "\x2b\x81\x04\x00\x23", 'size' => 66],
    "\x2b\x81\x04\x00\x0a"                         => ['name' => 'secp256k1', 'oid' => "\x2b\x81\x04\x00\x0a", 'size' => 32],
    "\x2b\x24\x03\x03\x02\x08\x01\x01\x07"         => ['name' => 'brainpoolP256r1', 'oid' => "\x2b\x24\x03\x03\x02\x08\x01\x01\x07", 'size' => 32],
    "\x2b\x24\x03\x03\x02\x08\x01\x01\x0b"         => ['name' => 'brainpoolP384r1', 'oid' => "\x2b\x24\x03\x03\x02\x08\x01\x01\x0b", 'size' => 48],
    "\x2b\x24\x03\x03\x02\x08\x01\x01\x0d"         => ['name' => 'brainpoolP512r1', 'oid' => "\x2b\x24\x03\x03\x02\x08\x01\x01\x0d", 'size' => 64],
    "\x2b\x06\x01\x04\x01\xda\x47\x0f\x01"         => ['name' => 'ed25519', 'oid' => null, 'size' => 32],
    "\x2b\x06\x01\x04\x01\x97\x55\x01\x05\x01"     => ['name' => 'curve25519', 'oid' => null, 'size' => 32],
  ];

  public static function hashName(int $algorithm): string {
    if(!isset(self::HASHES[$algorithm]))
      throw new OpenPGPException('Unsupported hash algorithm ('.$algorithm.')');

    $name = self::HASHES[$algorithm];
    if(!in_array($name, hash_algos()))
      throw new OpenPGPException('This server does not support the '.$name.' hash algorithm');

    return $name;
  }

  public static function isRSA(int $algorithm): bool {
    return in_array($algorithm, [self::RSA, self::RSA_ENCRYPT_ONLY, self::RSA_SIGN_ONLY], true);
  }

  public static function curve(string $oid): array {
    if(!isset(self::CURVES[$oid]))
      throw new OpenPGPException('Unsupported elliptic curve');

    return self::CURVES[$oid];
  }

}
