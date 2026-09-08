<?php
namespace App\OpenPGP;

/**
 * A transferable public key: a primary key, its user IDs, and its subkeys.
 * A single armored block may hold more than one of these, and every key in
 * it is treated as belonging to whoever published the block.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-10.1
 */
class Certificate {

  const TYPE_KEY_REVOCATION = 0x20;
  const TYPE_SUBKEY_REVOCATION = 0x28;

  /** @var PublicKey[] keyed by uppercase hex fingerprint */
  private array $keys = [];

  /** @var string[] */
  public array $userIds = [];

  public static function parse(string $input): self {
    $data = Armor::looksArmored($input) ? Armor::decode($input, $label) : $input;

    if(isset($label) && !str_contains($label, 'PUBLIC KEY'))
      throw new OpenPGPException('That URL does not contain a PGP public key block');

    $certificate = new self();

    // Revocations apply to the key packet they follow, so keep track of
    // which key is currently in scope as the packets go by
    $primary = null;
    $current = null;

    foreach(Packet::parseAll($data) as $packet) {
      switch($packet->tag) {
        case Packet::PUBLIC_KEY:
          $primary = $current = PublicKey::fromPacket($packet->body, false);
          $certificate->add($primary);
          break;

        case Packet::PUBLIC_SUBKEY:
          $current = PublicKey::fromPacket($packet->body, true);
          $certificate->add($current);
          break;

        case Packet::USER_ID:
          $certificate->userIds[] = $packet->body;
          $current = $primary;
          break;

        case Packet::SIGNATURE:
          $certificate->applyRevocation($packet->body, $primary, $current);
          break;
      }
    }

    if(!$certificate->keys)
      throw new OpenPGPException('No PGP public key was found');

    return $certificate;
  }

  /**
   * Honor key and subkey revocation signatures.
   *
   * These are not checked cryptographically: the certificate is fetched from
   * a URL the person publishes on their own site, so a revocation in it is
   * exactly as trustworthy as the key it sits next to. Taking it at face
   * value fails closed, which is the safe direction for a revocation.
   */
  private function applyRevocation(string $body, ?PublicKey $primary, ?PublicKey $current): void {
    try {
      $signature = Signature::fromPacket($body);
    } catch(OpenPGPException $e) {
      // A signature we cannot parse revokes nothing
      return;
    }

    if($signature->type == self::TYPE_KEY_REVOCATION && $primary)
      $primary->revoked = true;

    if($signature->type == self::TYPE_SUBKEY_REVOCATION && $current && $current->isSubkey)
      $current->revoked = true;
  }

  private function add(PublicKey $key): void {
    $this->keys[$key->fingerprint] = $key;
  }

  /**
   * @return PublicKey[]
   */
  public function keys(): array {
    return array_values($this->keys);
  }

  public function findByFingerprint(string $fingerprint): ?PublicKey {
    return $this->keys[strtoupper($fingerprint)] ?? null;
  }

  public function findByKeyId(string $keyId): ?PublicKey {
    $keyId = strtoupper($keyId);
    foreach($this->keys as $key) {
      if(hash_equals($key->keyId, $keyId))
        return $key;
    }
    return null;
  }

}
