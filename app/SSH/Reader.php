<?php
namespace App\SSH;

/**
 * A bounds-checked cursor over SSH wire-format data (RFC 4251 section 5).
 * Every read past the end throws rather than returning a short value, so a
 * truncated or malformed blob can never be silently misread.
 */
class Reader {

  private string $data;
  private int $pos = 0;

  public function __construct(string $data) {
    $this->data = $data;
  }

  public function bytes(int $length): string {
    if($length < 0 || $this->pos + $length > strlen($this->data))
      throw new SSHException('The SSH data ended unexpectedly');

    $bytes = substr($this->data, $this->pos, $length);
    $this->pos += $length;
    return $bytes;
  }

  public function byte(): int {
    return ord($this->bytes(1));
  }

  public function uint32(): int {
    return unpack('N', $this->bytes(4))[1];
  }

  public function string(): string {
    return $this->bytes($this->uint32());
  }

  /**
   * An mpint, as its unsigned big-endian magnitude. Only positive values
   * appear in keys and signatures; a negative one means the data is bad.
   */
  public function mpint(): string {
    $bytes = $this->string();
    if($bytes !== '' && (ord($bytes[0]) & 0x80))
      throw new SSHException('The SSH data has a negative number where none belongs');

    return ltrim($bytes, "\x00");
  }

  public function atEnd(): bool {
    return $this->pos === strlen($this->data);
  }

  public function expectEnd(): void {
    if(!$this->atEnd())
      throw new SSHException('The SSH data has unexpected bytes at the end');
  }

  /**
   * An SSH string: a uint32 length and then the bytes.
   */
  public static function encodeString(string $bytes): string {
    return pack('N', strlen($bytes)).$bytes;
  }

}
