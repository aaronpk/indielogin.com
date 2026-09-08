<?php
namespace App\OpenPGP;

/**
 * A signature packet.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-5.2
 */
class Signature {

  const TYPE_BINARY = 0x00;
  const TYPE_CANONICAL_TEXT = 0x01;

  const SUBPACKET_CREATION_TIME = 2;
  const SUBPACKET_EXPIRATION_TIME = 3;
  const SUBPACKET_ISSUER_KEY_ID = 16;
  const SUBPACKET_ISSUER_FINGERPRINT = 33;

  public int $version;
  public int $type;
  public int $publicKeyAlgorithm;
  public int $hashAlgorithm;

  /** The prefix of the packet that is itself covered by the signature */
  public string $hashedData;
  /** v6 signatures prepend a random salt to the hash input */
  public string $salt = '';
  /** The first two octets of the digest, used only as a sanity check */
  public string $digestPrefix;

  /** @var string[] the signature MPIs, in packet order */
  public array $mpis = [];

  public ?string $issuerFingerprint = null;
  public ?string $issuerKeyId = null;
  public ?int $created = null;
  public ?int $expiresAfter = null;

  public static function fromPacket(string $body): self {
    $signature = new self();
    $reader = new Reader($body);
    $signature->version = $reader->byte();

    switch($signature->version) {
      case 3:
        if($reader->byte() != 5)
          throw new OpenPGPException('Invalid version 3 signature packet');
        $signature->hashedData = $reader->bytes(5);
        $signature->type = ord($signature->hashedData[0]);
        $signature->created = unpack('N', substr($signature->hashedData, 1, 4))[1];
        $signature->issuerKeyId = strtoupper(bin2hex($reader->bytes(8)));
        $signature->publicKeyAlgorithm = $reader->byte();
        $signature->hashAlgorithm = $reader->byte();
        $signature->digestPrefix = $reader->bytes(2);
        break;

      case 4:
      case 6:
        $lengthSize = $signature->version == 6 ? 4 : 2;
        $signature->type = $reader->byte();
        $signature->publicKeyAlgorithm = $reader->byte();
        $signature->hashAlgorithm = $reader->byte();

        $hashedLength = $reader->uint($lengthSize);
        $hashedSubpackets = $reader->bytes($hashedLength);
        $signature->hashedData = substr($body, 0, $reader->position());

        $reader->bytes($reader->uint($lengthSize)); // unhashed subpackets
        $signature->digestPrefix = $reader->bytes(2);

        if($signature->version == 6)
          $signature->salt = $reader->bytes($reader->byte());

        // Only the hashed subpackets are covered by the signature, so the
        // unhashed ones are not consulted at all
        $signature->parseSubpackets($hashedSubpackets);
        break;

      default:
        throw new OpenPGPException('Unsupported signature version ('.$signature->version.')');
    }

    $signature->mpis = self::parseMpis($signature->publicKeyAlgorithm, $reader);

    return $signature;
  }

  private function parseSubpackets(string $data): void {
    $reader = new Reader($data);

    while(!$reader->eof()) {
      $first = $reader->byte();
      if($first < 192) {
        $length = $first;
      } elseif($first < 255) {
        $length = (($first - 192) << 8) + $reader->byte() + 192;
      } else {
        $length = $reader->uint(4);
      }

      if($length < 1)
        throw new OpenPGPException('Invalid signature subpacket');

      $type = $reader->byte();
      $value = $reader->bytes($length - 1);

      switch($type & 0x7F) {
        case self::SUBPACKET_CREATION_TIME:
          if(strlen($value) == 4)
            $this->created = unpack('N', $value)[1];
          break;

        case self::SUBPACKET_EXPIRATION_TIME:
          if(strlen($value) == 4)
            $this->expiresAfter = unpack('N', $value)[1];
          break;

        case self::SUBPACKET_ISSUER_KEY_ID:
          if(strlen($value) == 8)
            $this->issuerKeyId = strtoupper(bin2hex($value));
          break;

        case self::SUBPACKET_ISSUER_FINGERPRINT:
          if(strlen($value) > 1)
            $this->issuerFingerprint = strtoupper(bin2hex(substr($value, 1)));
          break;
      }
    }
  }

  private static function parseMpis(int $algorithm, Reader $reader): array {
    switch($algorithm) {
      case Algorithm::RSA:
      case Algorithm::RSA_ENCRYPT_ONLY:
      case Algorithm::RSA_SIGN_ONLY:
        return ['s' => $reader->mpi()];

      case Algorithm::DSA:
      case Algorithm::ECDSA:
      case Algorithm::EDDSA_LEGACY:
        return ['r' => $reader->mpi(), 's' => $reader->mpi()];

      case Algorithm::ED25519:
        return ['native' => $reader->bytes(64)];

      case Algorithm::ED448:
        return ['native' => $reader->bytes(114)];

      default:
        throw new OpenPGPException('Unsupported public key algorithm ('.$algorithm.')');
    }
  }

  /**
   * The exact byte string the digest is computed over, for a given message.
   */
  public function signedData(string $message): string {
    if($this->version == 3)
      return $message.$this->hashedData;

    $trailer = chr($this->version)."\xff".pack('N', strlen($this->hashedData));

    return $this->salt.$message.$this->hashedData.$trailer;
  }

  public function digest(string $message): string {
    return hash(Algorithm::hashName($this->hashAlgorithm), $this->signedData($message), true);
  }

  public function hasExpired(): bool {
    if(!$this->expiresAfter || !$this->created)
      return false;

    return time() > $this->created + $this->expiresAfter;
  }

}
