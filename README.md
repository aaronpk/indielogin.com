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
