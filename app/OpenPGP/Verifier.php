<?php
namespace App\OpenPGP;

/**
 * Verifies a signed message against a published PGP public key block.
 *
 * Accepts either a cleartext signed message (gpg --clearsign), which is what
 * the login form asks for, or an armored OpenPGP message containing a
 * literal data packet and its signature (gpg --sign --armor).
 */
class Verifier {

  const MAX_DECOMPRESSED_SIZE = 1048576;

  /**
   * @return array{plaintext: string, fingerprint: string, key_id: string, hash_algorithm: string}
   * @throws OpenPGPException with a message suitable for showing to the person signing in
   */
  public static function verify(string $publicKeyBlock, string $signedMessage): array {
    $certificate = Certificate::parse($publicKeyBlock);

    if(str_contains($signedMessage, '-----BEGIN PGP SIGNED MESSAGE-----')) {
      [$plaintext, $signatures] = self::readCleartextMessage($signedMessage);
    } elseif(Armor::looksArmored($signedMessage)) {
      [$plaintext, $signatures] = self::readArmoredMessage($signedMessage);
    } else {
      throw new OpenPGPException('That does not look like a PGP signed message.');
    }

    if(!$signatures)
      throw new OpenPGPException('The message does not contain a PGP signature.');

    $matchedAKey = false;
    $matchedARevokedKey = false;
    $error = null;

    foreach($signatures as [$signature, $message]) {
      if($signature->hasExpired())
        continue;

      foreach(self::candidateKeys($certificate, $signature) as $key) {
        if($key->revoked) {
          $matchedARevokedKey = true;
          continue;
        }

        $matchedAKey = true;

        try {
          if(Crypto::verify($key, $signature, $message)) {
            return [
              'plaintext' => $plaintext,
              'fingerprint' => $key->fingerprint,
              'key_id' => $key->keyId,
              'hash_algorithm' => Algorithm::hashName($signature->hashAlgorithm),
            ];
          }
        } catch(OpenPGPException $e) {
          // Keep trying the remaining candidates, but hold on to the reason
          // this one could not be checked in case none of them work out
          $error = $error ?? $e;
        }
      }
    }

    if($matchedAKey)
      throw $error ?? new OpenPGPException('The PGP signature was not valid.');

    if($matchedARevokedKey)
      throw new OpenPGPException('The message was signed with a key that has been revoked.');

    throw new OpenPGPException('The message was signed with a different key than the one published at your PGP key URL.');
  }

  /**
   * The keys in the certificate that the signature claims to have been made
   * with. A signature that names no issuer is tried against every key.
   *
   * @return PublicKey[]
   */
  private static function candidateKeys(Certificate $certificate, Signature $signature): array {
    if($signature->issuerFingerprint !== null) {
      $key = $certificate->findByFingerprint($signature->issuerFingerprint);
      return $key ? [$key] : [];
    }

    if($signature->issuerKeyId !== null) {
      $key = $certificate->findByKeyId($signature->issuerKeyId);
      return $key ? [$key] : [];
    }

    return array_filter($certificate->keys(), fn($key) => $key->canSign());
  }

  /**
   * @return array{0: string, 1: array<array{0: Signature, 1: string}>}
   */
  private static function readCleartextMessage(string $input): array {
    $parts = Armor::splitCleartext($input);
    $canonical = Armor::canonicalizeCleartext($parts['text']);

    $data = Armor::decode($parts['signature'], $label);
    if(!str_contains($label, 'SIGNATURE'))
      throw new OpenPGPException('The message does not contain a PGP signature.');

    $signatures = [];
    foreach(Packet::parseAll($data) as $packet) {
      if($packet->tag != Packet::SIGNATURE)
        continue;

      $signature = Signature::fromPacket($packet->body);
      $signatures[] = [
        $signature,
        $signature->type == Signature::TYPE_CANONICAL_TEXT ? $canonical : $parts['text'],
      ];
    }

    return [$parts['text'], $signatures];
  }

  /**
   * @return array{0: string, 1: array<array{0: Signature, 1: string}>}
   */
  private static function readArmoredMessage(string $input): array {
    $packets = Packet::parseAll(Armor::decode($input, $label));

    if(!str_contains($label, 'MESSAGE'))
      throw new OpenPGPException('That does not look like a PGP signed message.');

    $packets = self::decompress($packets);

    $literal = null;
    foreach($packets as $packet) {
      if($packet->tag == Packet::LITERAL_DATA) {
        $literal = self::readLiteralData($packet->body);
        break;
      }
    }

    if($literal === null)
      throw new OpenPGPException('The PGP message does not contain any signed text.');

    $signatures = [];
    foreach($packets as $packet) {
      if($packet->tag != Packet::SIGNATURE)
        continue;

      $signature = Signature::fromPacket($packet->body);
      $signatures[] = [
        $signature,
        $signature->type == Signature::TYPE_CANONICAL_TEXT
          ? Armor::canonicalizeLineEndings($literal)
          : $literal,
      ];
    }

    return [str_replace("\r\n", "\n", $literal), $signatures];
  }

  /**
   * @param Packet[] $packets
   * @return Packet[]
   */
  private static function decompress(array $packets): array {
    foreach($packets as $packet) {
      if($packet->tag != Packet::COMPRESSED_DATA)
        continue;

      $reader = new Reader($packet->body);
      $algorithm = $reader->byte();
      $compressed = $reader->rest();

      switch($algorithm) {
        case 0: $data = $compressed; break;
        case 1: $data = @gzinflate($compressed, self::MAX_DECOMPRESSED_SIZE); break;
        case 2: $data = @gzuncompress($compressed, self::MAX_DECOMPRESSED_SIZE); break;
        case 3:
          if(!function_exists('bzdecompress'))
            throw new OpenPGPException('This server cannot read bzip2 compressed PGP messages.');
          $data = @bzdecompress($compressed);
          break;
        default:
          throw new OpenPGPException('The PGP message uses an unsupported compression algorithm.');
      }

      if(!is_string($data) || $data === '')
        throw new OpenPGPException('The PGP message could not be decompressed.');

      return Packet::parseAll($data);
    }

    return $packets;
  }

  private static function readLiteralData(string $body): string {
    $reader = new Reader($body);
    $reader->byte();                        // format: b, t, u, l or 1
    $reader->bytes($reader->byte());        // filename
    $reader->uint(4);                       // date
    return $reader->rest();
  }

}
