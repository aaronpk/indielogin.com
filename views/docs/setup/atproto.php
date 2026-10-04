<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>

<section id="atproto">
  <h1>Bluesky / ATProto</h1>

  <p>There are two ways to use BlueSky or a compatible ATProto server to authenticate.</p>
  <p>If you have a custom domain on BlueSky, you can <b>enter it directly</b> in the login form. You will be redirected to BlueSky to log in.</p>
  <p>Alternatively, you can link to your BlueSky handle from your website, similar to how you would link to a GitHub or other external profile.</p>
  <p><pre><?= e('<a href="https://aaronpk.bsky.social" rel="me atproto">aaronpk.bsky.social</a>') ?></pre></p>
  <p>Make sure you add <code>rel="me atproto"</code> to the link. You will also need to link back to your website in your BlueSky profile.</p>
</section>
