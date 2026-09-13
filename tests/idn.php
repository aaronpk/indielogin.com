<?php
/**
 * Tests for the internationalized domain name handling in lib/helpers.php.
 *
 * Run with:  php tests/idn.php
 *
 * Everything here is a pure function, so nothing in this file touches the
 * network. The domain used throughout is the one from issue #122.
 */

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

const UNICODE  = 'https://www.tīkōuka.dev/';
const PUNYCODE = 'https://www.xn--tkuka-m3a3v.dev/';

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
 * Assert that a function returns exactly the expected value.
 */
function is_exactly(string $label, $actual, $expected): void {
  ok($label, $actual === $expected, $actual === $expected ? '' : 'got '.var_export($actual, true));
}


echo "\nidn_host\n";

is_exactly('a Māori domain becomes an A-label', idn_host('www.tīkōuka.dev'), 'www.xn--tkuka-m3a3v.dev');
is_exactly('a Cyrillic domain becomes an A-label', idn_host('тест.example'), 'xn--e1aybc.example');
is_exactly('a Japanese domain becomes an A-label', idn_host('例え.テスト'), 'xn--r8jz45g.xn--zckzah');
is_exactly('a German domain becomes an A-label', idn_host('Bücher.example'), 'xn--bcher-kva.example');

// Every host that already works has to come through untouched, or this
// change would quietly rewrite identifiers that people are logged in with
is_exactly('an A-label is left alone', idn_host('www.xn--tkuka-m3a3v.dev'), 'www.xn--tkuka-m3a3v.dev');
is_exactly('an ordinary domain is left alone', idn_host('aaronparecki.com'), 'aaronparecki.com');
is_exactly('case is left alone', idn_host('EXAMPLE.com'), 'EXAMPLE.com');
is_exactly('an IPv4 literal is left alone', idn_host('127.0.0.1'), '127.0.0.1');
is_exactly('an IPv6 literal is left alone', idn_host('[::1]'), '[::1]');
is_exactly('an empty host is left alone', idn_host(''), '');

// idn_to_ascii fails on these, and the host we already have beats nothing
is_exactly('an over-long label survives conversion failure',
  idn_host(str_repeat('a', 70).'.ünicode'), str_repeat('a', 70).'.ünicode');


echo "\nsplit_url_host\n";

// parse_url() replaces every byte in the C1 range with an underscore, so it
// silently corrupts most non-Latin domains. These are three it mangles; they
// are here so that nothing goes back to using parse_url() for the host.
is_exactly('a Japanese host survives', split_url_host('https://中.example/')[1], '中.example');
is_exactly('a Cyrillic host survives', split_url_host('https://я.example/')[1], 'я.example');
is_exactly('the domain from issue #122 survives', split_url_host(UNICODE)[1], 'www.tīkōuka.dev');
// The authority is more than just a host
is_exactly('a port is not part of the host',
  split_url_host('https://example.com:8443/x'), ['https://', 'example.com', ':8443/x']);
is_exactly('userinfo is not part of the host',
  split_url_host('https://user:pw@example.com/x'), ['https://user:pw@', 'example.com', '/x']);
is_exactly('an "@" in the userinfo is handled',
  split_url_host('https://user@host:pw@example.com/'), ['https://user@host:pw@', 'example.com', '/']);
is_exactly('the colons in an IPv6 literal are not a port',
  split_url_host('https://[::1]/x'), ['https://', '[::1]', '/x']);
is_exactly('an IPv6 literal can still have a port',
  split_url_host('https://[::1]:8443/x'), ['https://', '[::1]', ':8443/x']);
is_exactly('a query with no path is not part of the host',
  split_url_host('https://example.com?a=b'), ['https://', 'example.com', '?a=b']);
is_exactly('a URL with no host is rejected', split_url_host('https:///path'), false);
is_exactly('a string that is not a URL is rejected', split_url_host('example.com'), false);


echo "\nidn_normalize_url_host\n";

is_exactly('a host that cannot be converted is rejected',
  idn_normalize_url_host('https://'.str_repeat('a', 70).'.ünicode/'), false);
is_exactly('a port survives conversion',
  idn_normalize_url_host('https://www.tīkōuka.dev:8443/x'),
  'https://www.xn--tkuka-m3a3v.dev:8443/x');
is_exactly('a query survives conversion',
  idn_normalize_url_host('https://тест.example/?a=b'),
  'https://xn--e1aybc.example/?a=b');

echo "\nnormalize_me_url\n";

is_exactly('a Unicode URL is canonicalized to punycode', normalize_me_url(UNICODE), PUNYCODE);
is_exactly('a punycode URL is already canonical', normalize_me_url(PUNYCODE), PUNYCODE);
is_exactly('a Unicode host with no scheme gets https',
  normalize_me_url('www.tīkōuka.dev'), PUNYCODE);
is_exactly('a Cyrillic host with a path', normalize_me_url('https://тест.example/blog'),
  'https://xn--e1aybc.example/blog');
is_exactly('an ordinary URL is unchanged',
  normalize_me_url('https://aaronparecki.com/'), 'https://aaronparecki.com/');
is_exactly('a bare domain gets https and a path',
  normalize_me_url('aaronparecki.com'), 'https://aaronparecki.com/');
is_exactly('surrounding whitespace is dropped',
  normalize_me_url('  https://www.tīkōuka.dev/  '), PUNYCODE);

// The rules that were already enforced still are
is_exactly('a fragment is rejected', normalize_me_url('https://example.com/#me'), false);
is_exactly('a non-http scheme is rejected', normalize_me_url('mailto:me@example.com'), false);
is_exactly('a javascript URL is rejected', normalize_me_url('javascript:alert(1)'), false);
is_exactly('an empty string is rejected', normalize_me_url(''), false);
is_exactly('a non-string is rejected', normalize_me_url(null), false);


echo "\ndisplay_url_host\n";

is_exactly('punycode is shown as Unicode', display_url_host(PUNYCODE), UNICODE);
is_exactly('Cyrillic punycode is shown as Unicode',
  display_url_host('https://xn--e1aybc.example/'), 'https://тест.example/');
is_exactly('an ordinary URL is unchanged',
  display_url_host('https://aaronparecki.com/'), 'https://aaronparecki.com/');
is_exactly('a path is preserved',
  display_url_host('https://www.xn--tkuka-m3a3v.dev/blog/'), 'https://www.tīkōuka.dev/blog/');

// A label that does not convert back to what we started with is not a
// spelling we can vouch for, so it has to be shown as it is
is_exactly('a malformed A-label is left alone',
  display_url_host('https://xn--a.example/'), 'https://xn--a.example/');

is_exactly('display and normalize are inverses',
  normalize_me_url(display_url_host(PUNYCODE)), PUNYCODE);


echo "\nurls_are_equivalent\n";

// This is the half of issue #122 that blocks someone who has already worked
// around the fetch by entering punycode: their provider profile holds the
// Unicode spelling and the two have to match
ok('Unicode matches punycode', urls_are_equivalent(UNICODE, PUNYCODE));
ok('punycode matches Unicode', urls_are_equivalent(PUNYCODE, UNICODE));
ok('Unicode matches itself', urls_are_equivalent(UNICODE, UNICODE));
ok('a mixed-case Unicode host still matches',
  urls_are_equivalent('https://www.Tīkōuka.dev/', PUNYCODE));
ok('a missing trailing slash still matches',
  urls_are_equivalent('https://www.tīkōuka.dev', PUNYCODE));

// The case-insensitive host comparison that was already here
ok('case is still ignored', urls_are_equivalent('https://Example.com/', 'https://example.com/'));

// And none of that is allowed to make different domains compare equal
ok('a different IDN does not match', !urls_are_equivalent('https://www.tīkouka.dev/', PUNYCODE));
ok('a different path does not match', !urls_are_equivalent(UNICODE, PUNYCODE.'blog'));
ok('a different scheme does not match',
  !urls_are_equivalent('http://www.tīkōuka.dev/', PUNYCODE));
ok('a lookalike domain does not match',
  !urls_are_equivalent('https://www.tīkōuka.dev.evil.example/', PUNYCODE));


echo "\nsame_host\n";

ok('Unicode and punycode are the same host', same_host(UNICODE, PUNYCODE));
ok('paths are ignored', same_host(UNICODE, PUNYCODE.'somewhere/else'));
ok('different hosts are different', !same_host(UNICODE, 'https://example.com/'));


echo "\nstring_contains_url\n";

// A provider bio is free text, so both spellings have to be searched for
ok('a bio holding the Unicode URL matches a punycode me',
  string_contains_url('my site is https://www.tīkōuka.dev/ btw', PUNYCODE));
ok('a bio holding the punycode URL matches a punycode me',
  string_contains_url('my site is https://www.xn--tkuka-m3a3v.dev/ btw', PUNYCODE));
ok('the trailing slash is still optional',
  string_contains_url('my site is https://www.tīkōuka.dev btw', PUNYCODE));
ok('an ordinary URL still matches',
  string_contains_url('see https://aaronparecki.com', 'https://aaronparecki.com/'));
ok('an unrelated bio does not match',
  !string_contains_url('nothing to see here', PUNYCODE));
ok('a lookalike domain in a bio does not match',
  !string_contains_url('https://www.tīkouka.dev/', PUNYCODE));
ok('a null bio does not match', !string_contains_url(null, PUNYCODE));


printf("\n%d passed, %d failed\n\n", $passed, $failed);
exit($failed ? 1 : 0);
