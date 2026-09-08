IndieLogin
==========

IndieLogin enables users to sign in with their domain name by linking their domain name to existing authentication providers.

[Read more on the Wiki](https://indieweb.org/indielogin.com)


## Development

To run a local copy for development:

1. Copy `.env.example` to `.env` and fill out the details

2. Load `schema/schema.sql` into the database named in `.env`

3. Start a development webserver:

```sh
composer start
```

4. Open your web browser to `http://localhost:8080`


## PGP

Signing in with a `rel="pgpkey"` link is handled entirely in PHP, in
`app/OpenPGP`. There is nothing external to install or run: OpenPGP packet
parsing is done here, and the signature checks themselves are handed to PHP's
`openssl` (RSA, DSA, ECDSA) and `sodium` (Ed25519) extensions.

Run its tests with:

```sh
php tests/openpgp.php
```
