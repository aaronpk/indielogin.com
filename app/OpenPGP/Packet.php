<?php
namespace App\OpenPGP;

/**
 * Splits a stream of OpenPGP data into packets.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-4
 */
class Packet {

  const PUBLIC_KEY = 6;
  const SIGNATURE = 2;
  const ONE_PASS_SIGNATURE = 4;
  const COMPRESSED_DATA = 8;
  const LITERAL_DATA = 11;
  const USER_ID = 13;
  const PUBLIC_SUBKEY = 14;
  const PADDING = 21;

  const MAX_PACKETS = 2048;

  public int $tag;
  public string $body;

  public function __construct(int $tag, string $body) {
    $this->tag = $tag;
    $this->body = $body;
  }

  /**
   * @return Packet[]
   */
  public static function parseAll(string $data): array {
    $packets = [];
    $length = strlen($data);
    $i = 0;

    while($i < $length) {
      $header = ord($data[$i]);
      $i++;

      if(!($header & 0x80))
        throw new OpenPGPException('Invalid OpenPGP packet header');

      $body = '';

      if($header & 0x40) {
        // OpenPGP (new) format. A packet may arrive as a chain of partial
        // length chunks terminated by one chunk with a definite length.
        $tag = $header & 0x3F;
        while(true) {
          [$partial, $chunk] = self::readNewFormatLength($data, $i);
          $body .= self::slice($data, $i, $chunk);
          if(!$partial)
            break;
        }
      } else {
        // Legacy format
        $tag = ($header & 0x3C) >> 2;
        switch($header & 0x03) {
          case 0: $chunk = self::readUint($data, $i, 1); break;
          case 1: $chunk = self::readUint($data, $i, 2); break;
          case 2: $chunk = self::readUint($data, $i, 4); break;
          // An indeterminate length runs to the end of the stream
          default: $chunk = $length - $i; break;
        }
        $body = self::slice($data, $i, $chunk);
      }

      $packets[] = new self($tag, $body);

      if(count($packets) > self::MAX_PACKETS)
        throw new OpenPGPException('The OpenPGP data contains too many packets');
    }

    return $packets;
  }

  /**
   * @return array{0: bool, 1: int} whether the chunk is partial, and its length
   */
  private static function readNewFormatLength(string $data, int &$i): array {
    $first = self::readUint($data, $i, 1);

    if($first < 192)
      return [false, $first];

    if($first < 224)
      return [false, (($first - 192) << 8) + self::readUint($data, $i, 1) + 192];

    if($first < 255)
      return [true, 1 << ($first & 0x1F)];

    return [false, self::readUint($data, $i, 4)];
  }

  private static function readUint(string $data, int &$i, int $length): int {
    if($i + $length > strlen($data))
      throw new OpenPGPException('The OpenPGP data is truncated');

    $value = 0;
    for($n = 0; $n < $length; $n++) {
      $value = ($value << 8) | ord($data[$i + $n]);
    }
    $i += $length;
    return $value;
  }

  private static function slice(string $data, int &$i, int $length): string {
    if($length < 0 || $i + $length > strlen($data))
      throw new OpenPGPException('The OpenPGP data is truncated');

    $bytes = substr($data, $i, $length);
    $i += $length;
    return $bytes;
  }

}
