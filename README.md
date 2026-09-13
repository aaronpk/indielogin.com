IndieLogin
==========

IndieLogin enables users to sign in with their domain name by linking their domain name to existing authentication providers.

[Read more on the Wiki](https://indieweb.org/indielogin.com)


## Development

Needs PHP with the `intl` extension, which is what converts an
internationalized domain name to the punycode form everything else works in.

To run a local copy for development:

1. Copy `.env.example` to `.env` and fill out the details

2. Load `schema/schema.sql` into the database named in `.env`

3. Start a development webserver:

```sh
composer start
```

4. Open your web browser to `http://localhost:8080`


## Client registration

Applications have to have their `client_id` registered before they can sign
anyone in. Developers can do that themselves at `/developers` by signing in
with their own website.

To run an instance where only you can add applications, set
`CLIENT_REGISTRATION=false` in `.env`. The developer area then disappears and
client IDs have to be inserted into the `clients` table by hand. Set
`clients.active` to `0` to stop an application from signing anyone in.


## PGP

Signing in with a `rel="pgpkey"` link is handled entirely in PHP, in
`app/OpenPGP`. There is nothing external to install or run: OpenPGP packet
parsing is done here, and the signature checks themselves are handed to PHP's
`openssl` (RSA, DSA, ECDSA) and `sodium` (Ed25519) extensions.

Run its tests with:

```sh
php tests/openpgp.php
```


## Internationalized domain names

Someone can sign in with either spelling of an internationalized domain, the
Unicode `https://bücher.example/` or the punycode
`https://xn--bcher-kva.example/`. The punycode form is canonical: it is what
gets fetched, compared against the URL on someone's provider profile, stored,
and returned to the application as `me`. The Unicode form is what they are
shown. `lib/helpers.php` holds the conversion, in `normalize_me_url()` and the
`idn_*` helpers around it.

Note that PHP's `parse_url()` cannot be used to pull the host out of one of
these URLs -- it replaces every byte in the C1 range with an underscore, which
corrupts most non-Latin domains. Use `split_url_host()` instead.

Run its tests with:

```sh
php tests/idn.php
```
