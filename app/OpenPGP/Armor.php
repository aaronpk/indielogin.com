<?php
namespace App\OpenPGP;

/**
 * ASCII armor and the cleartext signature framework.
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-6
 * https://www.rfc-editor.org/rfc/rfc9580.html#section-7
 */
class Armor {

  const MAX_INPUT_SIZE = 1048576;

  /**
   * Decode an armored block into the raw OpenPGP packet stream it contains.
   */
  public static function decode(string $armored, ?string &$label=null): string {
    if(strlen($armored) > self::MAX_INPUT_SIZE)
      throw new OpenPGPException('The OpenPGP data is too large');

    $normalized = str_replace(["\r\n", "\r"], "\n", $armored);

    if(!preg_match('/^-----BEGIN PGP ([A-Z0-9 ,]+)-----[ \t]*$/m', $normalized, $match, PREG_OFFSET_CAPTURE))
      throw new OpenPGPException('No OpenPGP armored data was found');

    $label = $match[1][0];
    $body = substr($normalized, $match[0][1] + strlen($match[0][0]));

    $base64 = '';
    $checksum = null;
    $ended = false;
    $inHeaders = true;

    foreach(explode("\n", $body) as $line) {
      $line = rtrim($line, " \t");

      if($inHeaders) {
        // Armor headers run until the first blank line, but not every
        // implementation emits either the headers or the blank line.
        if($line === '') {
          $inHeaders = false;
          continue;
        }
        if(preg_match('/^[A-Za-z][A-Za-z0-9-]*: /', $line))
          continue;
        $inHeaders = false;
      }

      if(str_starts_with($line, '-----END PGP ')) {
        $ended = true;
        break;
      }

      if($line === '')
        continue;

      // The CRC-24 checksum is the only line that can begin with '='
      if(str_starts_with($line, '=')) {
        $checksum = substr($line, 1);
        continue;
      }

      $base64 .= $line;
    }

    if(!$ended)
      throw new OpenPGPException('The OpenPGP armored data is incomplete');

    $data = base64_decode($base64, true);
    if($data === false || $data === '')
      throw new OpenPGPException('The OpenPGP armored data could not be decoded');

    if($checksum !== null) {
      $expected = base64_decode($checksum, true);
      if($expected === false || strlen($expected) != 3)
        throw new OpenPGPException('The OpenPGP armor checksum is malformed');
      if(!hash_equals($expected, substr(pack('N', self::crc24($data)), 1)))
        throw new OpenPGPException('The OpenPGP armor checksum does not match');
    }

    return $data;
  }

  public static function looksArmored(string $input): bool {
    return str_contains($input, '-----BEGIN PGP ');
  }

  /**
   * Split a cleartext signed message into the text that was signed and the
   * armored signature that follows it.
   *
   * Returns ['text' => the dash-unescaped text, 'signature' => armored block]
   */
  public static function splitCleartext(string $message): array {
    if(strlen($message) > self::MAX_INPUT_SIZE)
      throw new OpenPGPException('The signed message is too large');

    $normalized = str_replace(["\r\n", "\r"], "\n", $message);

    if(!preg_match('/^-----BEGIN PGP SIGNED MESSAGE-----[ \t]*$/m', $normalized, $match, PREG_OFFSET_CAPTURE))
      throw new OpenPGPException('No cleartext signed message was found');

    $body = substr($normalized, $match[0][1] + strlen($match[0][0]));
    if(str_starts_with($body, "\n"))
      $body = substr($body, 1);

    $lines = explode("\n", $body);

    // Skip the armor headers (typically "Hash: SHA256") and the blank line
    $i = 0;
    while($i < count($lines) && rtrim($lines[$i], " \t") !== '') {
      if(!preg_match('/^[A-Za-z][A-Za-z0-9-]*: /', $lines[$i]))
        throw new OpenPGPException('The cleartext signed message is malformed');
      $i++;
    }
    $i++;

    $text = [];
    $signatureStart = null;
    for(; $i < count($lines); $i++) {
      if(rtrim($lines[$i], " \t") === '-----BEGIN PGP SIGNATURE-----') {
        $signatureStart = $i;
        break;
      }
      // Undo dash-escaping
      $text[] = str_starts_with($lines[$i], '- ') ? substr($lines[$i], 2) : $lines[$i];
    }

    if($signatureStart === null)
      throw new OpenPGPException('The signed message does not contain a PGP signature');

    return [
      'text' => implode("\n", $text),
      'signature' => implode("\n", array_slice($lines, $signatureStart)),
    ];
  }

  /**
   * The form of the text that a canonical text signature is computed over:
   * CRLF line endings, with trailing spaces and tabs removed from each line.
   */
  public static function canonicalizeCleartext(string $text): string {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    foreach($lines as &$line) {
      $line = rtrim($line, " \t");
    }
    return implode("\r\n", $lines);
  }

  /**
   * Line-ending normalization only, for text stored in a literal data packet.
   */
  public static function canonicalizeLineEndings(string $text): string {
    return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $text));
  }

  public static function crc24(string $data): int {
    $crc = 0xB704CE;
    for($i = 0; $i < strlen($data); $i++) {
      $crc ^= ord($data[$i]) << 16;
      for($j = 0; $j < 8; $j++) {
        $crc <<= 1;
        if($crc & 0x1000000)
          $crc ^= 0x1864CFB;
      }
    }
    return $crc & 0xFFFFFF;
  }

}
