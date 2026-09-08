<?php
namespace App\OpenPGP;

/**
 * Signature verification. The OpenPGP key material and signature are
 * re-encoded into the shapes OpenSSL and libsodium expect; all of the actual
 * cryptography is done by those libraries.
 */
class Crypto {

  const OID_RSA = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
  const OID_DSA = "\x2a\x86\x48\xce\x38\x04\x01";
  const OID_EC_PUBLIC_KEY = "\x2a\x86\x48\xce\x3d\x02\x01";

  public static function verify(PublicKey $key, Signature $signature, string $message): bool {
    if(!self::algorithmsMatch($key->algorithm, $signature->publicKeyAlgorithm))
      return false;

    $signedData = $signature->signedData($message);
    $digest = hash(Algorithm::hashName($signature->hashAlgorithm), $signedData, true);

    // Cheap rejection of a signature that plainly covers other data. This is
    // not a security check; the real one follows.
    if(!hash_equals($signature->digestPrefix, substr($digest, 0, 2)))
      return false;

    switch($key->algorithm) {
      case Algorithm::RSA:
      case Algorithm::RSA_ENCRYPT_ONLY:
      case Algorithm::RSA_SIGN_ONLY:
        return self::verifyOpenssl(self::rsaPem($key), $signedData, self::rsaSignature($key, $signature), $signature->hashAlgorithm);

      case Algorithm::DSA:
        return self::verifyOpenssl(self::dsaPem($key), $signedData, self::ecdsaSignature($signature), $signature->hashAlgorithm);

      case Algorithm::ECDSA:
        return self::verifyOpenssl(self::ecdsaPem($key), $signedData, self::ecdsaSignature($signature), $signature->hashAlgorithm);

      case Algorithm::EDDSA_LEGACY:
        return self::verifyEd25519(self::eddsaLegacyPublicKey($key), self::eddsaLegacySignature($signature), $digest);

      case Algorithm::ED25519:
        return self::verifyEd25519($key->material['point'], $signature->mpis['native'], $digest);

      default:
        throw new OpenPGPException('Unsupported public key algorithm ('.$key->algorithm.')');
    }
  }

  private static function algorithmsMatch(int $keyAlgorithm, int $signatureAlgorithm): bool {
    if(Algorithm::isRSA($keyAlgorithm))
      return Algorithm::isRSA($signatureAlgorithm);

    return $keyAlgorithm === $signatureAlgorithm;
  }

  private static function verifyOpenssl(string $pem, string $data, string $signature, int $hashAlgorithm): bool {
    if(!isset(Algorithm::OPENSSL_HASHES[$hashAlgorithm]))
      throw new OpenPGPException('Unsupported hash algorithm for this key type ('.$hashAlgorithm.')');

    $publicKey = openssl_pkey_get_public($pem);
    if($publicKey === false)
      throw new OpenPGPException('The PGP key could not be read');

    // Anything other than an explicit 1 (including -1, an OpenSSL error) is
    // treated as a failed verification
    return openssl_verify($data, $signature, $publicKey, Algorithm::OPENSSL_HASHES[$hashAlgorithm]) === 1;
  }

  private static function verifyEd25519(string $publicKey, string $signature, string $digest): bool {
    // EdDSA in OpenPGP signs the message digest rather than the message
    if(strlen($publicKey) != SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) != SODIUM_CRYPTO_SIGN_BYTES)
      return false;

    return sodium_crypto_sign_verify_detached($signature, $digest, $publicKey);
  }

  private static function rsaPem(PublicKey $key): string {
    return Der::pem(Der::sequence(
      Der::sequence(Der::oid(self::OID_RSA), Der::null()),
      Der::bitString(Der::sequence(
        Der::integer($key->material['n']),
        Der::integer($key->material['e'])
      ))
    ));
  }

  private static function dsaPem(PublicKey $key): string {
    return Der::pem(Der::sequence(
      Der::sequence(Der::oid(self::OID_DSA), Der::sequence(
        Der::integer($key->material['p']),
        Der::integer($key->material['q']),
        Der::integer($key->material['g'])
      )),
      Der::bitString(Der::integer($key->material['y']))
    ));
  }

  private static function ecdsaPem(PublicKey $key): string {
    $curve = Algorithm::curve($key->material['oid']);
    if($curve['oid'] === null)
      throw new OpenPGPException('The curve '.$curve['name'].' cannot be used for ECDSA');

    return Der::pem(Der::sequence(
      Der::sequence(Der::oid(self::OID_EC_PUBLIC_KEY), Der::oid($curve['oid'])),
      Der::bitString($key->material['point'])
    ));
  }

  /**
   * OpenSSL wants an RSA signature at exactly the length of the modulus,
   * where the OpenPGP MPI has its leading zero octets stripped.
   */
  private static function rsaSignature(PublicKey $key, Signature $signature): string {
    $modulusLength = strlen(ltrim($key->material['n'], "\x00"));
    $value = ltrim($signature->mpis['s'], "\x00");

    if(strlen($value) > $modulusLength)
      throw new OpenPGPException('The PGP signature does not match the size of the key');

    return str_pad($value, $modulusLength, "\x00", STR_PAD_LEFT);
  }

  /**
   * DSA and ECDSA signatures are a pair of MPIs in OpenPGP, and a DER
   * SEQUENCE of two INTEGERs everywhere else.
   */
  private static function ecdsaSignature(Signature $signature): string {
    return Der::sequence(
      Der::integer($signature->mpis['r']),
      Der::integer($signature->mpis['s'])
    );
  }

  private static function eddsaLegacyPublicKey(PublicKey $key): string {
    $curve = Algorithm::curve($key->material['oid']);
    if($curve['name'] != 'ed25519')
      throw new OpenPGPException('Unsupported EdDSA curve ('.$curve['name'].')');

    $point = $key->material['point'];

    // Native point format: a 0x40 prefix octet followed by the 32 byte key
    if(strlen($point) == 33 && $point[0] === "\x40")
      return substr($point, 1);

    if(strlen($point) == 32)
      return $point;

    throw new OpenPGPException('The EdDSA public key is malformed');
  }

  private static function eddsaLegacySignature(Signature $signature): string {
    return str_pad(ltrim($signature->mpis['r'], "\x00"), 32, "\x00", STR_PAD_LEFT)
      .str_pad(ltrim($signature->mpis['s'], "\x00"), 32, "\x00", STR_PAD_LEFT);
  }

}
