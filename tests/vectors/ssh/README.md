# SSH signature test vectors

## Keys and signatures in this directory

Made with OpenSSH 10.0's `ssh-keygen`. The keys are throwaway test keys; their
private halves were never saved. `challenge.txt` holds the string the
signatures are made over, and they are made for the `indielogin.com`
namespace, as the sign-in page asks for.

```sh
CODE=$(cat challenge.txt)
for k in "ed25519:-t ed25519" "rsa:-t rsa -b 3072" "nistp256:-t ecdsa -b 256" \
         "nistp384:-t ecdsa -b 384" "nistp521:-t ecdsa -b 521" \
         "rsa1024:-t rsa -b 1024" "unlisted:-t ed25519"; do
  name=${k%%:*}; opts=${k#*:}
  ssh-keygen -q $opts -N '' -C "test-$name@example.com" -f ./$name
  printf '%s' "$CODE" | ssh-keygen -q -Y sign -n indielogin.com -f ./$name > $name.sig
done

printf '%s\n' "$CODE" | ssh-keygen -q -Y sign -n indielogin.com -f ./ed25519 > ed25519.newline.sig
printf '%s' "$CODE" | ssh-keygen -q -Y sign -n indielogin.com -O hashalg=sha256 -f ./ed25519 > ed25519.sha256.sig
printf '%s' "$CODE" | ssh-keygen -q -Y sign -n git -f ./ed25519 > ed25519.git-namespace.sig
printf '%s' 'some-other-challenge' | ssh-keygen -q -Y sign -n indielogin.com -f ./ed25519 > ed25519.other-message.sig
```

`certified-cert.pub` is the public half of a key certified by a throwaway CA
(`ssh-keygen -s ca -I certified -n test certified.pub`), and
`certified-cert.sig` a signature made with `-f certified-cert.pub`, which
carries the certificate rather than the plain key.

The `.pub` comments are `test-<name>@example.com`, except `unlisted.pub`,
`rsa1024.pub` and the certificate, whose comments differ.

## `openssh/`

Copied from OpenSSH's own regression tests,
`regress/unittests/sshsig/testdata/` in openssh-portable, since a security key
signature needs the hardware to make. `namespace` and `signed-data` are the
namespace and message those signatures are made over. The tests they come from
are placed in the public domain.
