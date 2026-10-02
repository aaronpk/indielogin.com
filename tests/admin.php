<?php
/**
 * Tests for who counts as an admin, from ADMIN_USERS, in lib/helpers.php.
 *
 * Run with:  php tests/admin.php
 *
 * Everything here is a pure function of the environment, so nothing in this
 * file touches the network or the database.
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

function with_admins($value, callable $fn): void {
  $value === null ? putenv('ADMIN_USERS') : putenv('ADMIN_USERS='.$value);
  $fn();
}

echo "Nobody is an admin unless named\n";

with_admins(null, function() {
  ok('unset matches nobody', !is_admin_url('https://aaronparecki.com/'));
  ok('unset gives an empty list', admin_urls() === []);
});

with_admins('', function() {
  ok('empty matches nobody', !is_admin_url('https://aaronparecki.com/'));
});

with_admins(' , ,', function() {
  ok('only separators matches nobody', admin_urls() === [] && !is_admin_url('https://aaronparecki.com/'));
});

echo "\nThe spellings of the same URL match\n";

with_admins('https://aaronparecki.com/', function() {
  foreach([
    'https://aaronparecki.com/',
    'https://aaronparecki.com',
    'https://AaronParecki.com/',
    'aaronparecki.com',
  ] as $url) {
    ok($url, is_admin_url($url));
  }
});

with_admins('aaronparecki.com', function() {
  ok('a bare domain in ADMIN_USERS means https', is_admin_url('https://aaronparecki.com/'));
});

echo "\nAnything else does not\n";

with_admins('https://aaronparecki.com/', function() {
  foreach([
    'http://aaronparecki.com/' => 'plain http is a weaker proof',
    'https://aaronparecki.com.evil.net/' => 'a host that only starts the same',
    'https://evilaaronparecki.com/' => 'a host that only ends the same',
    'https://www.aaronparecki.com/' => 'a subdomain',
    'https://aaronparecki.com/someone-else' => 'a path on the same host',
    '' => 'empty',
  ] as $url => $why) {
    ok($why, !is_admin_url($url), $url);
  }
  ok('not a string', !is_admin_url(null));
});

echo "\nSeveral admins\n";

with_admins('https://aaronparecki.com/, https://example.com/', function() {
  ok('the first', is_admin_url('https://aaronparecki.com/'));
  ok('the second, after a space', is_admin_url('https://example.com/'));
  ok('neither', !is_admin_url('https://example.org/'));
});

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed ? 1 : 0);
