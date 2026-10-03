<?php
/**
 * Tests for SSH signature verification in app/SSH.
 *
 * Run with:  php tests/ssh.php
 *
 * The vectors in tests/vectors/ssh were made with OpenSSH 10.0's ssh-keygen
 * (see tests/vectors/ssh/README.md). The security key vectors in
 * tests/vectors/ssh/openssh come from OpenSSH's own regression tests, since
 * making them needs the hardware.
 */

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use App\SSH\KeyList;
use App\SSH\PublicKey;
use App\SSH\Reader;
use App\SSH\SSHException;
use App\SSH\Verifier;

const VECTORS = __DIR__.'/vectors/ssh';
const NS = 'indielogin.com';

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
 * Assert that a signature is accepted, and was made by the expected key.
 */
function accepts(string $label, string $keys, string $sig, string $challenge, string $namespace=NS, ?string $fingerprint=null): void {
  try {
    $result = Verifier::verify($keys, $sig, $namespace, $challenge);
  } catch(Throwable $e) {
    ok($label, false, 'rejected: '.$e->getMessage());
    return;
  }

  ok($label, $fingerprint === null || $result['fingerprint'] === $fingerprint, $result['type'].' '.$result['fingerprint']);
}

/**
 * Assert that a signature is rejected. Anything that verifies here is a
 * potential authentication bypass, so these matter more than the ones above.
 */
function rejects(string $label, string $keys, string $sig, string $challenge, string $namespace=NS, ?string $expect=null): void {
  try {
    Verifier::verify($keys, $sig, $namespace, $challenge);
  } catch(SSHException $e) {
    ok($label, $expect === null || str_contains($e->getMessage(), $expect), $e->getMessage());
    return;
  } catch(Throwable $e) {
    ok($label, false, 'threw '.get_class($e).' instead of SSHException: '.$e->getMessage());
    return;
  }

  ok($label, false, 'ACCEPTED a signature that should have been rejected');
}

function vector(string $path): string {
  $contents = file_get_contents(VECTORS.'/'.$path);
  if($contents === false)
    throw new RuntimeException('Missing test vector: '.$path);
  return $contents;
}

function fingerprint(string $pubFile): string {
  [, $b64] = preg_split('/\s+/', trim(vector($pubFile)));
  return PublicKey::fingerprintOf(base64_decode($b64));
}

// Unwrap an armored signature into its blob, and wrap one back up
function unarmor(string $armored): string {
  preg_match('/-----BEGIN SSH SIGNATURE-----(.*?)-----END SSH SIGNATURE-----/s', $armored, $m);
  return base64_decode(preg_replace('/\s+/', '', $m[1]));
}
function armor(string $blob): string {
  return "-----BEGIN SSH SIGNATURE-----\n".chunk_split(base64_encode($blob), 70, "\n")."-----END SSH SIGNATURE-----\n";
}

// Take an SSHSIG blob apart into its fields, and put it back together, so
// that individual fields can be swapped out
function fields(string $blob): array {
  $r = new Reader($blob);
  $r->bytes(6);
  $r->uint32();
  return ['key' => $r->string(), 'namespace' => $r->string(), 'reserved' => $r->string(), 'hash' => $r->string(), 'sig' => $r->string()];
}
function assemble(array $f): string {
  return 'SSHSIG'.pack('N', 1).implode('', array_map([Reader::class, 'encodeString'], [$f['key'], $f['namespace'], $f['reserved'], $f['hash'], $f['sig']]));
}

$challenge = vector('challenge.txt');
$types = ['ed25519', 'rsa', 'nistp256', 'nistp384', 'nistp521'];


echo "\nSigned with ssh-keygen -Y sign, key listed in full\n";

foreach($types as $type)
  accepts($type, vector("$type.pub"), vector("$type.sig"), $challenge, NS, fingerprint("$type.pub"));


echo "\nKey listed only by its fingerprint\n";

foreach($types as $type)
  accepts($type, fingerprint("$type.pub")."\n", vector("$type.sig"), $challenge);


echo "\nThe shapes a key file comes in\n";

$ed = trim(vector('ed25519.pub'));
[$edType, $edB64] = preg_split('/\s+/', $ed);

accepts('authorized_keys line with options and a comment',
  'restrict,from="10.0.0.1,192.168.0.0/16" '.$ed."\n", vector('ed25519.sig'), $challenge);
accepts('option with a quoted space before the key',
  'command="echo hello world" '.$edType.' '.$edB64.' laptop'."\n", vector('ed25519.sig'), $challenge);
accepts('GitHub .keys file: several keys, no comments',
  implode("\n", array_map(fn($t) => implode(' ', array_slice(preg_split('/\s+/', trim(vector("$t.pub"))), 0, 2)), ['rsa', 'nistp256', 'ed25519']))."\n",
  vector('ed25519.sig'), $challenge);
accepts('comments, blank lines and Windows line endings',
  "# my keys\r\n\r\n".fingerprint('rsa.pub')."\r\n".$ed."\r\n", vector('ed25519.sig'), $challenge);
accepts('fingerprint as ssh-keygen -l prints it',
  '256 '.fingerprint('ed25519.pub').' test-ed25519@example.com (ED25519)'."\n", vector('ed25519.sig'), $challenge);
accepts('fingerprint with base64 padding',
  fingerprint('ed25519.pub')."=\n", vector('ed25519.sig'), $challenge);

$list = KeyList::parse(vector('rsa.pub').fingerprint('nistp256.pub')."\n".'garbage line'."\n".$ed."\n");
ok('a mixed file is read as 2 keys and 1 fingerprint', count($list->keys) == 2 && count($list->fingerprints) == 1);


echo "\nHow the challenge was piped in\n";

accepts('with the newline echo adds', $ed, vector('ed25519.newline.sig'), $challenge);
accepts('hashed with SHA-256 (-O hashalg=sha256)', $ed, vector('ed25519.sha256.sig'), $challenge);


echo "\nSecurity keys (OpenSSH's own test vectors)\n";

$ns = vector('openssh/namespace');
$data = vector('openssh/signed-data');
accepts('sk-ssh-ed25519@openssh.com', vector('openssh/ed25519_sk.pub'), vector('openssh/ed25519_sk.sig'), $data, $ns);
accepts('sk-ecdsa-sha2-nistp256@openssh.com', vector('openssh/ecdsa_sk.pub'), vector('openssh/ecdsa_sk.sig'), $data, $ns);


echo "\nSignatures that must be rejected\n";

rejects('made for the git namespace', $ed, vector('ed25519.git-namespace.sig'), $challenge, NS, 'made for "git"');
rejects('over some other text', $ed, vector('ed25519.other-message.sig'), $challenge, NS, 'not a valid signature');
rejects('when a challenge one character different was asked for', $ed, vector('ed25519.sig'), substr($challenge, 0, -1), NS, 'not a valid signature');
rejects('by a key the site does not list', $ed, vector('unlisted.sig'), $challenge, NS, 'does not list');
$near = fingerprint('ed25519.pub');
$near = substr($near, 0, -1).(substr($near, -1) === 'A' ? 'B' : 'A');
rejects('by a key whose fingerprint differs from a listed one in its last character', $near, vector('ed25519.sig'), $challenge, NS, 'does not list');

$tampered = unarmor(vector('ed25519.sig'));
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);
rejects('with one bit of the signature flipped', $ed, armor($tampered), $challenge, NS, 'not a valid signature');

// The key inside a signature is what the list is checked against, so a
// signature by an unlisted key must not pass by naming a listed one
$swapped = fields(unarmor(vector('unlisted.sig')));
$swapped['key'] = fields(unarmor(vector('ed25519.sig')))['key'];
rejects('by an unlisted key, claiming to be a listed one', $ed, armor(assemble($swapped)), $challenge, NS, 'not a valid signature');

$sha1 = fields(unarmor(vector('ed25519.sig')));
$sha1['hash'] = 'sha1';
rejects('claiming a SHA-1 message hash', $ed, armor(assemble($sha1)), $challenge, NS, 'sha1');

$v2 = substr_replace(unarmor(vector('ed25519.sig')), pack('N', 2), 6, 4);
rejects('in a newer version of the format', $ed, armor($v2), $challenge, NS, 'version');

rejects('made with a certificate', vector('certified-cert.pub'), vector('certified-cert.sig'), $challenge, NS, 'certificates');
rejects('by a 1024-bit RSA key', vector('rsa1024.pub'), vector('rsa1024.sig'), $challenge, NS, '2048');
rejects('made through WebAuthn', vector('openssh/ecdsa_sk_webauthn.pub'), vector('openssh/ecdsa_sk_webauthn.sig'), $data, $ns, 'WebAuthn');
rejects('a security key signature without a touch', vector('openssh/ed25519_sk.pub'), (function() use($ns) {
  $f = fields(unarmor(vector('openssh/ed25519_sk.sig')));
  // The flags byte sits just before the 4-byte counter at the end
  $f['sig'][strlen($f['sig']) - 5] = chr(ord($f['sig'][strlen($f['sig']) - 5]) & ~0x01);
  return armor(assemble($f));
})(), $data, $ns, 'touched');


echo "\nThings that are not signatures, or not key files\n";

rejects('a PGP signature pasted instead', $ed, "-----BEGIN PGP SIGNATURE-----\nabc\n-----END PGP SIGNATURE-----\n", $challenge, NS, 'does not look like');
rejects('the challenge pasted back unsigned', $ed, $challenge, $challenge, NS, 'does not look like');
rejects('armor around bad base64', $ed, "-----BEGIN SSH SIGNATURE-----\n!!!!\n-----END SSH SIGNATURE-----\n", $challenge, NS, 'base64');
rejects('armor around a truncated signature', $ed, armor(substr(unarmor(vector('ed25519.sig')), 0, 40)), $challenge, NS, 'ended unexpectedly');
rejects('a key file with nothing in it', "\n# nothing here\n", vector('ed25519.sig'), $challenge, NS, 'No SSH public keys');
rejects('a key file that is an HTML page', "<!doctype html><html><body>Not found</body></html>", vector('ed25519.sig'), $challenge, NS, 'No SSH public keys');
rejects('a key type with a blob of another type', 'ssh-rsa '.$edB64."\n", vector('ed25519.sig'), $challenge, NS, 'No SSH public keys');


printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed ? 1 : 0);
