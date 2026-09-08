<?php
namespace App\OpenPGP;

/**
 * A bounds-checked cursor over a string of binary OpenPGP data. Every read
 * past the end of the buffer throws rather than returning a short string, so
 * a truncated or malformed packet can never be silently misinterpreted.
 */
class Reader {

  private string $data;
  private int $pos = 0;

  public function __construct(string $data) {
    $this->data = $data;
  }

  public function bytes(int $length): string {
    if($length < 0 || $this->pos + $length > strlen($this->data))
      throw new OpenPGPException('Unexpected end of OpenPGP data');

    $bytes = substr($this->data, $this->pos, $length);
    $this->pos += $length;
    return $bytes;
  }

  public function byte(): int {
    return ord($this->bytes(1));
  }

  public function uint(int $length): int {
    $value = 0;
    foreach(str_split($this->bytes($length)) as $char) {
      $value = ($value << 8) | ord($char);
    }
    return $value;
  }

  /**
   * A multiprecision integer: a two-octet bit count followed by that many
   * bits of big-endian value, with leading zero octets omitted.
   */
  public function mpi(): string {
    $bits = $this->uint(2);
    return $this->bytes(intdiv($bits + 7, 8));
  }

  public function rest(): string {
    return $this->bytes(strlen($this->data) - $this->pos);
  }

  public function position(): int {
    return $this->pos;
  }

  public function eof(): bool {
    return $this->pos >= strlen($this->data);
  }

}
