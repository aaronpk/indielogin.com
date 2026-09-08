<?php
namespace App\OpenPGP;

/**
 * Just enough DER to hand a public key and a signature to OpenSSL, which
 * wants both in the X.509/PKIX encodings rather than OpenPGP's own.
 */
class Der {

  public static function length(int $length): string {
    if($length < 0x80)
      return chr($length);

    $bytes = '';
    while($length > 0) {
      $bytes = chr($length & 0xFF).$bytes;
      $length >>= 8;
    }
    return chr(0x80 | strlen($bytes)).$bytes;
  }

  public static function tlv(int $tag, string $value): string {
    return chr($tag).self::length(strlen($value)).$value;
  }

  public static function sequence(string ...$parts): string {
    return self::tlv(0x30, implode('', $parts));
  }

  /**
   * An unsigned big-endian value as a DER INTEGER.
   */
  public static function integer(string $bytes): string {
    $bytes = ltrim($bytes, "\x00");
    if($bytes === '')
      $bytes = "\x00";
    if(ord($bytes[0]) & 0x80)
      $bytes = "\x00".$bytes;

    return self::tlv(0x02, $bytes);
  }

  public static function bitString(string $bytes): string {
    return self::tlv(0x03, "\x00".$bytes);
  }

  public static function oid(string $contents): string {
    return self::tlv(0x06, $contents);
  }

  public static function null(): string {
    return "\x05\x00";
  }

  public static function pem(string $der): string {
    return "-----BEGIN PUBLIC KEY-----\n"
      .chunk_split(base64_encode($der), 64, "\n")
      ."-----END PUBLIC KEY-----\n";
  }

}
