<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>

<section id="pgp">
  <h1>PGP Key</h1>

  <p>If you have a PGP key, you can sign in by signing a short challenge with it. Put your public key on your website, and link to it from your home page with <code>rel="pgpkey"</code>:</p>

  <p><pre><?= e('<link rel="pgpkey" href="/key.asc">') ?></pre></p>

  <p>The file is your public key in its ASCII-armored form, the text that starts with <code>-----BEGIN PGP PUBLIC KEY BLOCK-----</code>. With GnuPG, you can make it with:</p>

  <p><pre>gpg --armor --export you@example.com &gt; key.asc</pre></p>

  <p>When you sign in, you'll be shown a challenge to sign. Sign it with your key, for example by pasting it into <code class="text-nowrap">gpg --clearsign</code>, and paste the signed message back in. Any OpenPGP tool that makes a clear-signed or armored signed message works.</p>

  <p>RSA, DSA, ECDSA and Ed25519 keys work, including keys that sign with a separate signing subkey. A key that has been revoked is refused.</p>
</section>
