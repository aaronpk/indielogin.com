<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow setup h-entry">

  <h1 class="p-name">How to Set Up Your Website for <?= getenv('APP_NAME') ?></h1>

  <div class="e-content">
    <p>You've likely ended up here because a website you're trying to sign in to uses <?= getenv('APP_NAME') ?> to handle logging users in.</p>

    <p>Instead of making a new account here, we'll take advantage of some accounts you may already have in order to authenticate you. You can always choose the services you use to log in, and the site you're logging in to won't have access to them.</p>
  </div>

  <section id="indieauth" class="h-entry">
    <h3 class="p-name">IndieAuth</h3>

    <p>If your website supports <a href="https://indieweb.org/IndieAuth">IndieAuth</a>, then this site will use your IndieAuth server automatically! No further setup is needed, and you don't need to add any other social profiles to your site.</p>
    <p><a href="https://micro.blog/">Micro.blog</a> supports IndieAuth out of the box, no configuration is necessary. Try logging in using your Micro.blog address.</p>
    <p>If your site is running WordPress, you can install the <a href="https://wordpress.org/plugins/indieauth/">IndieAuth WordPress plugin</a>.</p>
    <p>If you would like to build IndieAuth support into your own website, the links below will help:
       <ul>
         <li><a href="https://aaronparecki.com/2018/07/07/7/oauth-for-the-open-web">IndieAuth: OAuth for the Open Web</a></li>
         <li><a href="https://indieauth.spec.indieweb.org/">The IndieAuth Spec</a></li>
      </ul>
    </p>
  </section>

  <section id="supported-providers" class="h-entry">
    <h2 class="p-name">Add links to your existing profiles</h2>

    <div class="e-content">
      <p>If your website is not already an IndieAuth provider, this site can authenticate you using the following providers:</p>

      <ul>
        <li><a href="#github">GitHub</a></li>
        <li><a href="#email">Email Address</a></li>
      </ul>
    </div>
  </section>

  <section id="github" class="h-entry">
    <h3 class="p-name">GitHub</h3>

    <div class="e-content">
      <p>To use GitHub, link to your GitHub profile on your home page.</p>

      <p><pre><?= e('<a href="https://github.com/aaronpk" rel="me">github.com/aaronpk</a>') ?></pre></p>

      <p>Make sure your GitHub account has your URL in your profile.</p>
    </div>
  </section>

  <section id="email" class="h-entry">
    <h3 class="p-name">Email</h3>

    <p>To use your email address to authenticate, you'll receive a short code you'll have to enter while signing in. Just link to your email address from your home page.</p>

    <p><pre><?= e('<a href="mailto:me@example.com" rel="me">me@example.com</a>') ?></pre></p>
  </section>



  <div style="margin-top: 3em;"></div>

  <h2 id="advanced">Advanced Options</h2>

  <section id="hidden-links" class="h-entry">
    <h3 class="p-name">Hidden Links</h3>

    <p>If you don't want visible links to your profiles, you can use an invisible <code>&lt;link&gt;</code> tag instead. For example:</p>

    <p><pre><?= e('<link href="https://github.com/aaronpk" rel="me">') ?></pre></p>
  </section>

  <section id="multiple-domains" class="h-entry">
    <h3 class="p-name">Multiple Domains</h3>

    <p>If you have multiple domains, or want your GitHub profile to link to something that is not your main website, you can alternatively put one or more URLs in your "bio" field on GitHub. This allows you to use one GitHub account to authenticate multiple domains.</p>
  </section>

  <section id="choosing-auth-providers" class="h-entry">
    <h3 class="p-name">Explicitly Choosing Auth Providers</h3>

    <p>If you don't want <?= getenv('APP_NAME') ?> to consider <i>all</i> your <code>rel="me"</code> links as possible authentication options, you can choose which ones specifically by using <code>rel="me authn"</code> instead. This allows you to, for example, only use providers that support two-factor authorization, while still linking to your existing profiles using <code>rel="me"</code>.</p>

    <p><pre><?= e('<a href="https://twitter.com/aaronpk" rel="me">twitter.com/aaronpk</a>
<a href="https://github.com/aaronpk" rel="me authn">github.com/aaronpk</a>') ?></pre></p>

    <p>If <i>any</i> of your <code>rel="me"</code> links also include <code>authn</code> in the list of rels, then <?= getenv('APP_NAME') ?> will <i>only</i> use the links with <code>authn</code>, and will no longer consider your plain <code>rel="me"</code> links as authentication options.</p>
  </section>


  <div style="margin-top: 3em;"></div>

</div>
