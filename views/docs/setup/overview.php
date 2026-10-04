<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>

<div class="h-entry">

  <h1 class="p-name">How to Set Up Your Website for <?= getenv('APP_NAME') ?></h1>

  <div class="e-content">
    <p>You've likely ended up here because a website you're trying to sign in to uses <?= getenv('APP_NAME') ?> to handle logging users in.</p>

    <p>Instead of making a new account here, we'll take advantage of some accounts you may already have in order to authenticate you. You can always choose the services you use to log in, and the site you're logging in to won't have access to them.</p>
  </div>

  <section id="supported-providers">
    <h3>Ways to sign in</h3>

    <p>When you sign in with your website's address, <?= getenv('APP_NAME') ?> looks at your home page to find out how you can prove that it's yours. Set up any one of these, or several and choose each time.</p>

    <p><b>If your website handles sign-in itself:</b></p>
    <ul>
      <li><a href="/setup/indieauth">IndieAuth</a>: if your site supports IndieAuth, it's used automatically.</li>
      <li><a href="/setup/atproto">Bluesky / ATProto</a>: if your domain is your Bluesky handle, or you link to one.</li>
    </ul>

    <p><b>Otherwise, link to an account you already have</b> from your home page:</p>
    <ul>
      <li><a href="/setup/github">GitHub</a></li>
      <li><a href="/setup/gitlab">GitLab</a></li>
      <li><a href="/setup/codeberg">Codeberg</a></li>
      <li><a href="/setup/email">Email address</a>: you'll be sent a code.</li>
    </ul>

    <p><b>Or publish a key</b> and sign with it:</p>
    <ul>
      <li><a href="/setup/ssh-key">SSH key</a></li>
      <li><a href="/setup/pgp">PGP key</a></li>
    </ul>

    <p>To keep the links out of sight, use more than one domain, or choose which of your links count, see <a href="/setup/advanced">Advanced options</a>.</p>
  </section>

</div>

<script>
// Each of these used to be a section of this one page. Links to them, with
// the section after the #, still arrive here, so send them on to its page.
(function(){
  var moved = {
    "indieauth": "/setup/indieauth",
    "atproto": "/setup/atproto",
    "github": "/setup/github",
    "gitlab": "/setup/gitlab",
    "codeberg": "/setup/codeberg",
    "email": "/setup/email",
    "ssh-key": "/setup/ssh-key",
    "pgp": "/setup/pgp",
    "advanced": "/setup/advanced",
    "hidden-links": "/setup/advanced#hidden-links",
    "multiple-domains": "/setup/advanced#multiple-domains",
    "choosing-auth-providers": "/setup/advanced#choosing-auth-providers"
  };
  var target = moved[window.location.hash.substring(1)];
  if(target) window.location.replace(target);
})();
</script>
