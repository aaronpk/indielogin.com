<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">


  <div class="login-details">
    <p>Log in to <a href="<?= $client_id ?>"><?= $client_id ?></a></p>

    <form action="/auth" method="get">
      <div class="form-group">
        <input type="url" placeholder="example.com" name="me" class="form-control">
      </div>

      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <input type="hidden" name="redirect_uri" value="<?= $redirect_uri ?>">
      <input type="hidden" name="state" value="<?= $state ?>">

      <button class="btn btn-primary">Sign In</button>
    </form>
  </div>

  <div class="setup-help">
    <h3>Sign in with your Website</h3>

    <div class="profile-examples"><img src="/images/profiles.png" style="width: 100%;"></div>

    <p>This is a <a href="https://indieweb.org/web_sign-in">web sign-in</a> prompt. To use it, you'll need to:</p>

    <ul>
      <li>Add links on your website to your various social profiles (Twitter, Github, etc) with the attribute rel="me"</li>
      <li>Ensure these profiles link back to your website</li>
      <li>Alternatively, you can use your <a href="/setup#email">email</a> or <a href="/setup#pgp">PGP key</a></li>
      <li>If your website supports the <a href="https://www.w3.org/TR/indieauth/">IndieAuth protocol</a> then you don't need to do anything special, this site will use your IndieAuth server!</li>
    </ul>

    <p>Read the <a href="/setup">full setup instructions</a></p>
  </div>

</div>
