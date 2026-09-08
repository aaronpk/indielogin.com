<?php
namespace App\OpenPGP;

/**
 * A public key or public subkey packet.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-5.5.2
 */
class PublicKey {

  public int $version;
  public int $algorithm;
  public int $created;
  public bool $isSubkey;
  public bool $revoked = false;

  /** Uppercase hex */
  public string $fingerprint;
  /** Uppercase hex */
  public string $keyId;

  /** Algorithm-specific key material, keyed by MPI name */
  public array $material = [];

  public static function fromPacket(string $body, bool $isSubkey): self {
    $key = new self();
    $key->isSubkey = $isSubkey;

    $reader = new Reader($body);
    $key->version = $reader->byte();

    switch($key->version) {
      case 3:
        $key->created = $reader->uint(4);
        $reader->uint(2); // validity period in days, unused
        $key->algorithm = $reader->byte();
        $key->material = self::parseMaterial($key->algorithm, $reader);
        break;

      case 4:
        $key->created = $reader->uint(4);
        $key->algorithm = $reader->byte();
        $key->material = self::parseMaterial($key->algorithm, $reader);
        break;

      case 5:
      case 6:
        $key->created = $reader->uint(4);
        $key->algorithm = $reader->byte();
        // v5 and v6 length-delimit the key material so unknown algorithms
        // can still be skipped over
        $material = new Reader($reader->bytes($reader->uint(4)));
        $key->material = self::parseMaterial($key->algorithm, $material);
        break;

      default:
        throw new OpenPGPException('Unsupported public key packet version ('.$key->version.')');
    }

    $key->setFingerprint($body);

    return $key;
  }

  private function setFingerprint(string $body): void {
    switch($this->version) {
      case 3:
        // v3 fingerprints are an MD5 of the RSA modulus and exponent, and the
        // key ID is the low 64 bits of the modulus
        if(!isset($this->material['n']))
          throw new OpenPGPException('Unsupported version 3 public key');
        $this->fingerprint = strtoupper(md5($this->material['n'].$this->material['e']));
        $this->keyId = strtoupper(bin2hex(substr(str_pad($this->material['n'], 8, "\x00", STR_PAD_LEFT), -8)));
        break;

      case 4:
        $this->fingerprint = strtoupper(sha1("\x99".pack('n', strlen($body)).$body));
        $this->keyId = substr($this->fingerprint, -16);
        break;

      case 5:
        $this->fingerprint = strtoupper(hash('sha256', "\x9a".pack('N', strlen($body)).$body));
        $this->keyId = substr($this->fingerprint, 0, 16);
        break;

      case 6:
        $this->fingerprint = strtoupper(hash('sha256', "\x9b".pack('N', strlen($body)).$body));
        $this->keyId = substr($this->fingerprint, 0, 16);
        break;
    }
  }

  private static function parseMaterial(int $algorithm, Reader $reader): array {
    switch($algorithm) {
      case Algorithm::RSA:
      case Algorithm::RSA_ENCRYPT_ONLY:
      case Algorithm::RSA_SIGN_ONLY:
        return ['n' => $reader->mpi(), 'e' => $reader->mpi()];

      case Algorithm::DSA:
        return [
          'p' => $reader->mpi(),
          'q' => $reader->mpi(),
          'g' => $reader->mpi(),
          'y' => $reader->mpi(),
        ];

      case Algorithm::ECDSA:
      case Algorithm::EDDSA_LEGACY:
        $oidLength = $reader->byte();
        if($oidLength == 0 || $oidLength == 0xFF)
          throw new OpenPGPException('Invalid elliptic curve identifier');
        return [
          'oid' => $reader->bytes($oidLength),
          'point' => $reader->mpi(),
        ];

      case Algorithm::ED25519:
        return ['point' => $reader->bytes(32)];

      case Algorithm::ED448:
        return ['point' => $reader->bytes(57)];

      default:
        // Encryption-only and unrecognized algorithms are kept for their
        // fingerprint but can never satisfy a signature
        return [];
    }
  }

  public function canSign(): bool {
    return in_array($this->algorithm, [
      Algorithm::RSA,
      Algorithm::RSA_SIGN_ONLY,
      Algorithm::DSA,
      Algorithm::ECDSA,
      Algorithm::EDDSA_LEGACY,
      Algorithm::ED25519,
      Algorithm::ED448,
    ], true);
  }

}
