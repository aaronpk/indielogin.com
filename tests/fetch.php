<?php
/**
 * Tests for safe_get() and the private network allow-list in lib/helpers.php.
 *
 * Run with:  php tests/fetch.php
 *
 * Serves tests/fixtures/fetch-router.php on 127.0.0.1 with php -S, and
 * otherwise only checks addresses that must be refused before any connection
 * is made, so it needs no network access.
 */

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

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

function allow(string $list): void {
  putenv('ALLOW_PRIVATE_NETWORK='.$list);
}

function refused(string $label, string $url): void {
  $res = safe_get($url);
  ok($label, isset($res['exception']) && $res['code'] === 0, $res['exception'] ?? 'FETCHED '.$url);
}

// Serve the fixture site on a free port
$port = 0;
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'tests/fixtures/fetch-router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++)
  usleep(100000);
$site = 'http://127.0.0.1:'.$port;


echo "\nThe allow-list\n";

allow('');
ok('empty means nothing', private_network_allow() === []);
allow(' 10.11.11.0/24, dev.example.com ,,127.0.0.1 ');
ok('a list with spaces and an empty entry', private_network_allow() === ['10.11.11.0/24', 'dev.example.com', '127.0.0.1']);
putenv('ALLOW_PRIVATE_NETWORK');
ok('unset means nothing', private_network_allow() === []);


echo "\nRefused with nothing allowed, before connecting\n";

allow('');
refused('this machine', $site.'/final');
refused('localhost by name', 'http://localhost/');
refused('a private network address', 'http://10.0.0.1/');
refused('the cloud metadata address', 'http://169.254.169.254/latest/meta-data/');
refused('IPv6 loopback', 'http://[::1]/');
refused('IPv4-mapped IPv6 loopback', 'http://[::ffff:127.0.0.1]/');
refused('loopback written as a decimal number', 'http://2130706433/');
refused('loopback written in hex', 'http://0x7f.0.0.1/');
refused('a file URL', 'file:///etc/passwd');
refused('a gopher URL', 'gopher://127.0.0.1:6379/_INFO');


echo "\nWith this machine allowed\n";

allow('127.0.0.1');
$redirects = [];
$res = safe_get($site.'/permanent', function($r) use(&$redirects) { $redirects[] = $r; });
ok('a 301 then a 302 is followed to the page', ($res['code'] ?? 0) === 200 && str_contains($res['body'] ?? '', 'final page'));
ok('each hop is reported in order with its status', array_map(fn($r) => $r['code'].' '.parse_url($r['to'], PHP_URL_PATH), $redirects) === ['301 /temporary', '302 /final'],
  implode(', ', array_map(fn($r) => $r['code'].' '.parse_url($r['to'], PHP_URL_PATH), $redirects)));
ok('the final URL is reported', ($res['url'] ?? '') === $site.'/final');
ok('Link headers come through for rel parsing', isset(\IndieWeb\http_rels($res['header'] ?? '', $res['url'] ?? '')['authorization_endpoint']));

refused('a redirect from an allowed host to the metadata address', $site.'/to-metadata');
refused('a redirect to localhost by name, which is not on the list', $site.'/to-loopback-name');

$res = safe_get($site.'/loop');
ok('a redirect loop stops', isset($res['exception']) && str_contains($res['exception'], 'redirects'), $res['exception'] ?? '');

$res = safe_get($site.'/missing');
ok('an error status is an error', isset($res['exception']) && $res['code'] === 404, $res['exception'] ?? '');

allow('');
refused('and refused again once it is taken off the list', $site.'/final');


echo "\nThe profile fetch\n";

allow('127.0.0.1');
$profile = fetch_profile($site.'/permanent');
ok('fetch_profile follows the redirects', ($profile['code'] ?? 0) === 200);
// The identity follows the permanent redirect and stops at the temporary
// one, even though the page itself was read from where that one led
ok('the identity follows the 301 but not the 302', ($profile['final_url'] ?? '') === normalize_me_url($site.'/temporary'),
  'identity '.($profile['final_url'] ?? '-'));
ok('rel links from the page and the Link header are found', !empty($profile['rels']['ssh-key']) && !empty($profile['rels']['authorization_endpoint']));

allow('');
$profile = fetch_profile($site.'/final');
ok('fetch_profile refuses this machine when it is not allowed', ($profile['code'] ?? 0) === -1 && str_contains($profile['exception'] ?? '', 'not a public address'), $profile['exception'] ?? '');


proc_terminate($server);
proc_close($server);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed ? 1 : 0);
