# OpenPGP test vectors

## `keys/`, `cleartext/`, `inline/`

Generated with GnuPG 2.4.7. The keys are throwaway test keys; their secret
halves were never saved. `challenge.txt` holds the string that every message
in `cleartext/` and `inline/` is signed over.

```sh
export GNUPGHOME=$(mktemp -d) && chmod 700 "$GNUPGHOME"
CODE=$(cat challenge.txt)

for algo in rsa2048 rsa4096 ed25519 nistp256 nistp384 nistp521 \
            brainpoolP256r1 brainpoolP512r1 secp256k1 dsa2048; do
  gpg --batch --pinentry-mode loopback --passphrase '' \
      --quick-gen-key "Test $algo <$algo@example.com>" $algo sign never
  gpg --batch --armor --export "$algo@example.com" > keys/$algo.key
  for hash in SHA1 SHA224 SHA256 SHA384 SHA512; do
    printf '%s' "$CODE" | gpg --batch --pinentry-mode loopback --passphrase '' \
      --digest-algo $hash --local-user "$algo@example.com" --clearsign \
      > cleartext/$algo.$hash.asc
  done
done
```

Combinations GnuPG refuses to produce (a digest shorter than the curve, for
instance) were deleted rather than committed as empty files.

`keys/subkey.key` is a certification-only primary key with a separate signing
subkey and an encryption subkey, so that the signing-subkey path is covered.
`keys/rsadefault.key` is the default GnuPG key shape: a signing primary key
with an encryption subkey.

The messages in `inline/` were produced with `--sign --armor` instead of
`--clearsign`, using `--compress-algo 0` (none), `1` (ZIP, the default) and
`2` (ZLIB), plus one with `--textmode`.

`keys/notrevoked.key` and `keys/revoked.key` are the same key exported before
and after merging in the revocation certificate GnuPG writes to
`$GNUPGHOME/openpgp-revocs.d/` at key creation. `revoked-signature.asc` is a
signature made by that key while it was still valid.

## `rfc9580/`

The sample key, certificate and signed messages from
[RFC 9580 Appendix A](https://www.rfc-editor.org/rfc/rfc9580.html#appendix-A),
copied verbatim:

| File | Source |
| --- | --- |
| `a1-v4-ed25519legacy.key` | A.1, sample version 4 Ed25519Legacy key |
| `a2-v4-ed25519legacy.sig` | A.2, its detached signature over `OpenPGP` |
| `a3-v6-ed25519.key` | A.3, sample version 6 certificate |
| `a6-v6-cleartext.asc` | A.6, sample cleartext signed message |
| `a7-v6-inline.asc` | A.7, the same message, inline-signed |

These cover the version 6 signature format (including the salt), native
Ed25519 keys, and dash-escaping, none of which GnuPG 2.4 can produce.
