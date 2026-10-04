<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>

<section id="email">
  <h1>Email</h1>

  <p>To use your email address to authenticate, you'll receive a short code you'll have to enter while signing in. Just link to your email address from your home page.</p>

  <p><pre><?= e('<a href="mailto:me@example.com" rel="me">me@example.com</a>') ?></pre></p>
</section>
