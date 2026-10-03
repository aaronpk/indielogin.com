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


## Admin

`ADMIN_USERS` in `.env` is a comma-separated list of profile URLs, such as
`ADMIN_USERS=https://aaronparecki.com/`. Sign in at `/developers` as one of
them and an Admin button appears there. Everyone else, signed in or not, gets
a 404 on every `/admin` path, so the section leaves no trace. Leave it empty
and nobody is an admin. The host is compared without regard to case, but the
scheme has to match, so an `http://` sign-in does not count for an `https://`
entry. The admin section uses the developer sign-in, so it is not available
when `CLIENT_REGISTRATION=false`.

Signing in to the developer area issues a new session ID and CSRF token, and
the session cookie is `HttpOnly`, refuses IDs the server did not issue, and is
`Secure` whenever `BASE_URL` is https. These are set in `configure_session()`
rather than left to the server's php.ini.

Run `schema/0006.sql` before using it. It adds the indexes on `logins` that
the admin pages query by.

* `/admin`: sign-ins today and over 7 and 30 days, with the share that
  completed (the application exchanged the code). Also sign-ins per day,
  sign-ins by provider, the busiest clients, and counts of developers and
  clients. Recounted at most once a minute.
* `/admin/activity`: the service month by month, over 12, 24 or 60 months or
  everything since the first sign-in. It shows sign-ins and the share
  completed, active people (different URLs that signed in), new people
  (signing in for the first time on record), active clients, sign-ins by
  provider, and new developer accounts and clients. There are charts and a
  table of the same figures.
* `/admin/clients`: every client, searchable by client ID or owner. A client's
  page shows its owner and recent sign-ins. It is also where you deactivate or
  reactivate the client, turn its PKCE requirement on or off, and add or remove
  the registered redirect URIs it needs for a `redirect_uri` on another host.
* `/admin/users`: developer accounts, searchable by URL or email, each with
  their clients and their own recent sign-ins.
* `/admin/logins`: the sign-in log, filterable by client ID, the person's URL,
  provider, and whether it completed.

The activity figures count each month once. A month that has ended is counted
and kept in Redis for good, and its people are added to a set of everyone seen,
which is how a later month knows who is new. The month in progress is
recounted at most every ten minutes. The first time, every month back to the
first sign-in has to be counted. The page does up to 20 seconds of that per
load and says how far it has got. To count everything in one go, run this once
after deploying:

```sh
php bin/count-activity
```

It is safe to run again at any time, or from cron. To start the counting over,
delete the `indielogin:admin:activity:v1:*` keys from Redis.

Every change made there is checked against the session's CSRF token and logged
to `logs/app.log` with the admin's URL.

Run its tests with:

```sh
php tests/admin.php
```


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
