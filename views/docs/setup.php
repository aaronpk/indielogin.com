<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <h1>How to Set Up Your Website for <?= Config::$name ?></h1>

  <p>You've likely ended up here because a website you're trying to sign in to uses <?= Config::$name ?> to handle logging users in.</p>

  <p>Instead of making a new account, we'll take advantage of some accounts you may already have in order to authenticate you. You can always choose the services you use to log in, and the site you're logging in to won't have access to them.</p>


  <h2 id="supported-providers">Add links to your existing profiles</h2>

  <p>This site supports authenticating using the following existing providers:</p>

  <ul>
    <li><a href="#twitter">Twitter</a></li>
    <li><a href="#github">GitHub</a></li>
    <li><a href="#email">Email Address</a></li>
    <li><a href="#pgp">PGP Key</a></li>
    <li><a href="#indieauth">Your IndieAuth Server</a></li>
  </ul>


  <h3 id="twitter">Twitter</h3>

  <p>To use Twitter, link to your Twitter profile on your home page.</p>

  <p><pre><?= e('<a href="https://twitter.com/aaronpk" rel="me">twitter.com/aaronpk</a>') ?></pre></p>

  <p>Make sure your Twitter account has your URL in your profile.</p>


  <h3 id="github">GitHub</h3>

  <p>To use GitHub, link to your GitHub profile on your home page.</p>

  <p><pre><?= e('<a href="https://github.com/aaronpk" rel="me">github.com/aaronpk</a>') ?></pre></p>

  <p>Make sure your GitHub account has your URL in your profile.</p>


  <h3 id="email">Email</h3>

  <p>To use your email address to authenticate, you'll receive a short code you'll have to enter while signing in. Just link to your email address from your home page.</p>

  <p><pre><?= e('<a href="mailto:me@example.com" rel="me">me@example.com</a>') ?></pre></p>


  <h3 id="pgp">PGP Key</h3>

  <p>If you don't want to use any existing accounts to authenticate, you can use a PGP key and sign a challenge while logging in instead. You'll just need to link to your PGP key from your website.</p>

  <p><pre><?= e('<a href="/key.txt" rel="pgpkey authn">PGP Key</a>') ?></pre></p>


  <h3 id="indieauth">IndieAuth</h3>

  <p>If your website supports <a href="https://indieweb.org/IndieAuth">IndieAuth</a>, then this site will use your IndieAuth server automatically! No further setup is needed, and you don't need to add any other social profiles to your site.</p>



  <h2 id="advanced">Advanced Options</h2>

  <h3 id="hidden">Hidden Links</h3>

  <p>If you don't want visible links to any of the above, you can use an invisible <code>&lt;link&gt;</code> tag instead. For example,</p>

  <p><pre><?= e('<link href="https://github.com/aaronpk" rel="me">') ?></pre></p>


  <h3 id="explicit">Explicitly Choosing Auth Providers</h3>

  <p>If you don't want <?= Config::$name ?> to consider <i>all</i> your <code>rel="me"</code> links as possible authentication options, you can choose which ones specifically by using <code>rel="me authn"</code> instead. This allows you to for example only use providers that support two-factor authorization, while still linking to your existing profiles using <code>rel="me"</code>.</p>

  <p><pre><?= e('<a href="https://twitter.com/aaronpk" rel="me">twitter.com/aaronpk</a>
<a href="https://github.com/aaronpk" rel="me authn">github.com/aaronpk</a>') ?></pre></p>

  <p>If <i>any</i> of your <code>rel="me"</code> links also include <code>authn</code> in the list of rels, then <?= Config::$name ?> will <i>only</i> use the links with <code>authn</code>, and will no longer consider your plain <code>rel="me"</code> links as authentication options.</p>

</div>
