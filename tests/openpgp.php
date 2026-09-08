<?php
/**
 * Tests for the native OpenPGP signature verification in app/OpenPGP.
 *
 * Run with:  php tests/openpgp.php
 *
 * The vectors in tests/vectors were produced with GnuPG 2.4 (see
 * tests/vectors/README.md), plus the official sample key, certificate and
 * signed messages from RFC 9580 Appendix A.
 */

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use App\OpenPGP\Armor;
use App\OpenPGP\Certificate;
use App\OpenPGP\Crypto;
use App\OpenPGP\Packet;
use App\OpenPGP\Signature;
use App\OpenPGP\Verifier;

const VECTORS = __DIR__.'/vectors';

$passed = 0;
$failed = 0;

function ok(string $label, bool $result, string $detail=''): void {
  global $passed, $failed;

  if($result) {
    $passed++;
    printf("  \033[32mok\033[0m   %s%s\n", $label, $detail ? "  ($detail)" : '');
  } else {
    $failed++;
    printf("  \033[31mFAIL\033[0m %s%s\n", $label, $detail ? "  ($detail)" : '');
  }
}

/**
 * Assert that a message is accepted and covers the expected text.
 */
function accepts(string $label, string $key, string $message, string $expectedText): void {
  try {
    $result = Verifier::verify($key, $message);
  } catch(Throwable $e) {
    ok($label, false, 'rejected: '.$e->getMessage());
    return;
  }

  ok($label, $result['plaintext'] === $expectedText, $result['fingerprint'].' '.$result['hash_algorithm']);
}

/**
 * Assert that a message is rejected. Anything that verifies here is a
 * potential authentication bypass, so these matter more than the ones above.
 */
function rejects(string $label, string $key, string $message): void {
  try {
    Verifier::verify($key, $message);
  } catch(Throwable $e) {
    ok($label, true, $e->getMessage());
    return;
  }

  ok($label, false, 'ACCEPTED a message that should have been rejected');
}

function vector(string $path): string {
  $contents = file_get_contents(VECTORS.'/'.$path);
  if($contents === false)
    throw new RuntimeException('Missing test vector: '.$path);
  return $contents;
}

$challenge = vector('challenge.txt');


echo "\nCleartext signatures (gpg --clearsign)\n";

foreach(glob(VECTORS.'/cleartext/*.asc') as $file) {
  [$name, $hash] = explode('.', basename($file, '.asc'));
  accepts(sprintf('%-12s %s', $name, $hash), vector("keys/$name.key"), file_get_contents($file), $challenge);
}


echo "\nInline signed messages (gpg --sign --armor)\n";

foreach(glob(VECTORS.'/inline/*.asc') as $file) {
  $name = explode('-', basename($file, '.asc'))[0];
  accepts(basename($file, '.asc'), vector("keys/$name.key"), file_get_contents($file), $challenge);
}


echo "\nRFC 9580 Appendix A sample vectors\n";

// A.1: version 4 Ed25519Legacy key
$a1 = Certificate::parse(vector('rfc9580/a1-v4-ed25519legacy.key'))->keys()[0];
ok('A.1 v4 Ed25519Legacy key fingerprint',
  $a1->fingerprint === 'C959BDBAFA32A2F89A153B678CFDE12197965A9A', $a1->fingerprint);

// A.2: detached signature over the literal string "OpenPGP"
$a2 = Signature::fromPacket(Packet::parseAll(Armor::decode(vector('rfc9580/a2-v4-ed25519legacy.sig')))[0]->body);
ok('A.2 hashed data stream matches the RFC',
  bin2hex($a2->signedData('OpenPGP')) === '4f70656e504750040016080006050255f95f9504ff0000000c');
ok('A.2 digest matches the RFC',
  bin2hex($a2->digest('OpenPGP')) === 'f6220a3f757814f4c2176ffbb68b00249cd4ccdc059c4b34ad871f30b1740280');
ok('A.2 v4 Ed25519Legacy signature verifies', Crypto::verify($a1, $a2, 'OpenPGP'));
ok('A.2 rejects a modified message', !Crypto::verify($a1, $a2, 'OpenPGQ'));

// A.3: version 6 certificate, with an Ed25519 primary key and an X25519 subkey
$a3 = array_map(fn($key) => $key->fingerprint, Certificate::parse(vector('rfc9580/a3-v6-ed25519.key'))->keys());
ok('A.3 v6 primary key fingerprint',
  in_array('CB186C4F0609A697E4D52DFA6C722B0C1F1E27C18A56708F6525EC27BAD9ACC9', $a3));
ok('A.3 v6 subkey fingerprint',
  in_array('12C83F1E706F6308FE151A417743A1F033790E93E9978488D1DB378DA9930885', $a3));

// A.6 and A.7: the same v6 Ed25519 signed message, cleartext and inline.
// The cleartext form exercises dash-escaping.
$groceries = "What we need from the grocery store:\n\n- tofu\n- vegetables\n- noodles\n";
accepts('A.6 v6 cleartext signed message', vector('rfc9580/a3-v6-ed25519.key'), vector('rfc9580/a6-v6-cleartext.asc'), $groceries);
accepts('A.7 v6 inline signed message', vector('rfc9580/a3-v6-ed25519.key'), vector('rfc9580/a7-v6-inline.asc'), $groceries);


echo "\nArmor headers\n";

// GnuPG 2.4 emits no armor headers, but GPGTools, older GnuPG and others put
// a Version or Comment line after BEGIN. Those must not be read as base64.
accepts('key block with Version and Comment headers',
  vector('armor-headers/key.asc'), vector('cleartext/ed25519.SHA256.asc'), $challenge);
accepts('signature block with Version and Comment headers',
  vector('keys/ed25519.key'), vector('armor-headers/signature.asc'), $challenge);
accepts('headers on both blocks',
  vector('armor-headers/key.asc'), vector('armor-headers/signature.asc'), $challenge);


echo "\nRevocation\n";

// The same signature, checked against the certificate before and after its
// revocation certificate was merged in
accepts('key with no revocation is accepted',
  vector('keys/notrevoked.key'), vector('revoked-signature.asc'), $challenge);
rejects('the same key once revoked',
  vector('keys/revoked.key'), vector('revoked-signature.asc'));


echo "\nMessages that must be rejected\n";

$rsaKey = vector('keys/rsa.key');
$edKey = vector('keys/ed25519.key');
$rsaSig = vector('cleartext/rsa.SHA256.asc');
$edSig = vector('cleartext/ed25519.SHA256.asc');

// The signed text is altered after the signature was made
rejects('tampered cleartext', $rsaKey, str_replace($challenge, str_repeat('a', 64), $rsaSig));
rejects('cleartext with a line appended', $rsaKey, str_replace($challenge, $challenge."\nextra", $rsaSig));
rejects('tampered cleartext (ed25519)', $edKey, str_replace($challenge, strrev($challenge), $edSig));

// A real signature, but not from the key published at the person's key URL
rejects('signature from an unpublished key', $edKey, $rsaSig);
rejects('rsa signature checked against an ec key', vector('keys/nistp256.key'), $rsaSig);

// The signature bits are altered, with the armor checksum recomputed so that
// the change has to be caught by the signature check itself
foreach(['rsa' => $rsaKey, 'ed25519' => $edKey] as $name => $key) {
  $parts = Armor::splitCleartext(vector("cleartext/$name.SHA256.asc"));
  $raw = Armor::decode($parts['signature']);
  $flipped = substr($raw, 0, -1).chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
  $armored = "-----BEGIN PGP SIGNATURE-----\n\n"
    .chunk_split(base64_encode($flipped), 64, "\n")
    .'='.base64_encode(substr(pack('N', Armor::crc24($flipped)), 1))."\n"
    ."-----END PGP SIGNATURE-----\n";

  rejects("flipped one bit of the $name signature",
    $key, "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n{$parts['text']}\n".$armored);
}

// A signature lifted off one message and pasted under different text
$parts = Armor::splitCleartext($rsaSig);
rejects('signature grafted onto other text', $rsaKey,
  "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\nnot the challenge\n".$parts['signature']);

// Nothing was actually signed
rejects('the raw challenge, unsigned', $rsaKey, $challenge);
rejects('empty message', $rsaKey, '');
rejects('unrelated text', $rsaKey, "hello\nworld\n");
rejects('cleartext header with no signature', $rsaKey, "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n$challenge\n");
rejects('truncated signature armor', $rsaKey, substr($rsaSig, 0, strlen($rsaSig) - 60));
rejects('signature armor with no end line', $rsaKey, str_replace('-----END PGP SIGNATURE-----', '', $rsaSig));
rejects('a public key block pasted as the message', $rsaKey, $rsaKey);
rejects('a detached signature on its own', $rsaKey, $parts['signature']);

// Unusable public keys
rejects('empty public key', '', $rsaSig);
rejects('public key that is not a key', 'not a key at all', $rsaSig);
rejects('binary garbage as the public key', "\x99\xff\xff\xff", $rsaSig);


printf("\n%d passed, %d failed\n\n", $passed, $failed);
exit($failed ? 1 : 0);
