<?php
/**
 * Tests for the SSH sign-in server's private API (app/SSHServerApi.php) and
 * the waiting sign-ins behind it (app/SSH/Pending.php).
 *
 * Run with:  php tests/ssh_server_api.php
 *
 * Needs a local Redis, and uses database 15 of it, which it empties first.
 */

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

use App\SSH\Pending;
use App\SSHServerApi;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;

putenv('REDIS_URL=tcp://127.0.0.1:6379?database=15');
redis()->flushdb();

$templates = new League\Plates\Engine(__DIR__.'/../views');

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

function key_line(string $pubFile): string {
  return implode(' ', array_slice(preg_split('/\s+/', trim(file_get_contents(__DIR__.'/vectors/ssh/'.$pubFile))), 0, 2));
}

/**
 * Call the API as the SSH server would. Returns [status, decoded JSON].
 */
function call(string $action, array $params, string $bearer='test-key', string $from='192.0.2.10'): array {
  $request = (new ServerRequest(['REMOTE_ADDR' => $from], [], '/ssh-server/'.$action, 'POST'))
    ->withHeader('Authorization', 'Bearer '.$bearer)
    ->withBody((new StreamFactory())->createStream(json_encode($params)));

  $response = (new SSHServerApi)->$action($request);
  return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
}

function start(string $me, string $pubFile='ed25519.pub'): array {
  return Pending::create([
    'key' => 'https://'.parse_url($me, PHP_URL_HOST).'/ssh.pub',
    'keystext' => file_get_contents(__DIR__.'/vectors/ssh/'.$pubFile),
    'client_id' => 'https://app.example/',
    'me' => $me,
    'started' => time(),
    'ip' => '203.0.113.7',
  ]);
}

$listed = key_line('ed25519.pub');
$unlisted = key_line('unlisted.pub');


echo "\nAccess\n";

putenv('SSH_SERVER_API_KEY');
putenv('SSH_SERVER_API_IPS');
ok('without a key configured, a 404', call('check', ['user' => 'example.com', 'key' => $listed])[0] === 404);

putenv('SSH_SERVER_API_KEY=test-key');
ok('without allowed addresses configured, a 404', call('check', ['user' => 'example.com', 'key' => $listed])[0] === 404);

putenv('SSH_SERVER_API_IPS=192.0.2.10, 2001:db8::10');
ok('the wrong key, a 404', call('check', ['user' => 'example.com', 'key' => $listed], 'wrong')[0] === 404);
ok('no key at all, a 404', call('check', ['user' => 'example.com', 'key' => $listed], '')[0] === 404);
ok('the right key from another address, a 404', call('check', ['user' => 'example.com', 'key' => $listed], 'test-key', '192.0.2.99')[0] === 404);
ok('the right key from an allowed address, answered', call('check', ['user' => 'example.com', 'key' => $listed])[0] === 200);
ok('an allowed IPv6 address, written differently', call('check', ['user' => 'example.com', 'key' => $listed], 'test-key', '2001:db8:0::10')[0] === 200);


echo "\nFinding the sign-in\n";

[, $r] = call('check', ['user' => 'example.com', 'key' => $listed]);
ok('nothing waiting', $r['ok'] === false && $r['reason'] === 'none_waiting');

[$one, $oneCode] = start('https://example.com/');
[, $r] = call('check', ['user' => 'example.com', 'key' => $listed]);
ok('one waiting, found by domain', $r['ok'] && $r['matches'] === 1 && $r['me'] === 'https://example.com/' && $r['ip'] === '203.0.113.7', $r['fingerprint'] ?? '');
[, $r] = call('check', ['user' => 'Example.COM', 'key' => $listed]);
ok('the domain in another case', $r['ok'] && $r['matches'] === 1);
[, $r] = call('check', ['user' => 'www.example.com', 'key' => $listed]);
ok('the domain with www.', $r['ok'] && $r['matches'] === 1);
[, $r] = call('check', ['user' => strtoupper(Pending::displayCode($oneCode)), 'key' => $listed]);
ok('found by its connect code, as displayed and in capitals', $r['ok'] && $r['matches'] === 1);

[, $r] = call('check', ['user' => 'example.com', 'key' => $unlisted]);
ok('a key the website does not list', $r['ok'] === false && $r['reason'] === 'unlisted' && $r['keys_urls'] === ['https://example.com/ssh.pub']);
[, $r] = call('check', ['user' => 'example.com', 'key' => 'ssh-ed25519 !!!']);
ok('a key that is not a key', $r['ok'] === false && $r['reason'] === 'bad_request');

[$idn] = start('https://www.bücher.example/');
[, $r] = call('check', ['user' => 'bücher.example', 'key' => $listed]);
ok('an internationalized domain, in Unicode', $r['ok'] && $r['matches'] === 1);
[, $r] = call('check', ['user' => 'xn--bcher-kva.example', 'key' => $listed]);
ok('and in punycode', $r['ok'] && $r['matches'] === 1);
Pending::forget($idn);


echo "\nMore than one waiting for a domain\n";

[$two, $twoCode] = start('https://example.com/');
[, $r] = call('check', ['user' => 'example.com', 'key' => $listed]);
ok('two waiting: nothing is picked', $r['ok'] && $r['matches'] === 2 && !isset($r['me']));
[, $r] = call('approve', ['user' => 'example.com', 'key' => $listed]);
ok('and approving without a code is refused', $r['ok'] === false && $r['reason'] === 'ambiguous' && !Pending::approval($one) && !Pending::approval($two));

[, $r] = call('check', ['user' => 'example.com', 'key' => $listed, 'code' => 'zzzz-zzzz']);
ok('a code that names neither', $r['ok'] === false && $r['reason'] === 'bad_code');

[$other, $otherCode] = start('https://other.example/');
[, $r] = call('check', ['user' => 'example.com', 'key' => $listed, 'code' => $otherCode]);
ok("another domain's code does not pick one of these", $r['ok'] === false && $r['reason'] === 'bad_code');

[, $r] = call('check', ['user' => 'example.com', 'key' => $listed, 'code' => $twoCode]);
ok('the code picks one', $r['ok'] && $r['matches'] === 1);
[, $r] = call('approve', ['user' => 'example.com', 'key' => $listed, 'code' => $twoCode]);
ok('and approves that one only', $r['ok'] && Pending::approval($two) && !Pending::approval($one));
[, $r] = call('approve', ['user' => 'example.com', 'key' => $listed, 'code' => $twoCode]);
ok('its code cannot be used again', $r['ok'] === false && $r['reason'] === 'bad_code');

Pending::forget($two);
Pending::forget($other);


echo "\nApproving\n";

[, $r] = call('approve', ['user' => 'example.com', 'key' => $unlisted]);
ok('not with an unlisted key', $r['ok'] === false && $r['reason'] === 'unlisted' && !Pending::approval($one));

// A second sign-in starting between the check and the approval
[, $r] = call('check', ['user' => 'example.com', 'key' => $listed]);
[$late] = start('https://example.com/');
[, $r2] = call('approve', ['user' => 'example.com', 'key' => $listed]);
ok('a sign-in that starts after the check makes the approval need a code', $r['matches'] === 1 && $r2['ok'] === false && $r2['reason'] === 'ambiguous');
Pending::forget($late);

[, $r] = call('approve', ['user' => 'example.com', 'key' => $listed]);
$approval = Pending::approval($one);
ok('approved', $r['ok'] && $approval && $approval['type'] === 'ssh-ed25519' && str_starts_with($approval['fingerprint'], 'SHA256:'));
ok('its connect code is spent', Pending::byConnectCode($oneCode) === null);

Pending::forget($one);
[, $r] = call('check', ['user' => 'example.com', 'key' => $listed]);
ok('once finished, nothing is waiting', $r['ok'] === false && $r['reason'] === 'none_waiting');
ok('and the domain index is empty', redis()->scard('indielogin:ssh:pending:example.com') === 0);


redis()->flushdb();

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed ? 1 : 0);
