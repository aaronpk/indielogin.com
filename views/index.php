<?php $this->layout('layout', ['title' => $title]) ?>

<div class="position-relative overflow-hidden p-3 p-md-5 m-md-3 text-center bg-light">
  <div class="col-md-5 p-lg-5 mx-auto my-5">

    <h1 class="display-4 font-weight-normal"><?= getenv('APP_NAME') ?></h1>

    <p class="lead font-weight-normal">Sign in with your domain name.</p>

    <div>
      <p class="lead">Try it!</p>

      <form action="/authorize" method="get">
        <div class="form-group">
          <input type="url" placeholder="example.com" name="me" class="form-control">
        </div>

        <input type="hidden" name="client_id" value="<?= getenv('BASE_URL') ?>">
        <input type="hidden" name="redirect_uri" value="<?= getenv('BASE_URL') ?>demo">
        <input type="hidden" name="state" value="<?= generate_state() ?>">
        <input type="hidden" name="code_challenge" value="<?= generate_pkce_code_verifier() ?>">

        <button class="btn btn-outline-secondary">Sign In</button>
      </form>

    </div>
  </div>
</div>

<script src="/assets/fedcm.js"></script>


<div class="container container-full marketing">


  <hr class="featurette-divider">

  <div class="row featurette">
    <div class="col-md-7">
      <h2 class="featurette-heading">What is <span class="text-muted"><?= getenv('APP_NAME') ?>?</span></h2>
      <p class="lead"><?= getenv('APP_NAME') ?> makes it easy to add web sign-in to your applications.</p>

      <p>If you'd like to let your users <b>log in with their own domain name</b> as their identity, you can use IndieLogin.com to handle the details of that for you.</p>

      <p><?= getenv('APP_NAME') ?> supports <a href="https://indieauth.net/">IndieAuth</a>, so users with supported websites will be able to sign in using their own website's login. Otherwise, <?= getenv('APP_NAME')?> will check for links to GitHub, GitLab, Codeberg, an email address, and will ask the user to authenticate that way. Regardless of how the user authenticates, the identity provided to the application will always be the user's primary website.</p>
    </div>
    <div class="col-md-5">
      <img class="featurette-image img-fluid mx-auto" src="/images/web-signin-splash.jpg" alt="Web Sign-In Prompt">
    </div>
  </div>

<!--
  <hr class="featurette-divider">


  <div class="d-md-flex flex-md-equal w-100 my-md-3 pl-md-3">
    <div class="bg-dark mr-md-3 pt-3 px-3 pt-md-5 px-md-5 text-center text-white overflow-hidden">
      <div class="my-3 py-3">
        <h2 class="display-5">Another headline</h2>
        <p class="lead">And an even wittier subheading.</p>
      </div>
      <div class="bg-light box-shadow mx-auto" style="width: 80%; height: 300px; border-radius: 21px 21px 0 0;"></div>
    </div>
    <div class="bg-light mr-md-3 pt-3 px-3 pt-md-5 px-md-5 text-center overflow-hidden">
      <div class="my-3 p-3">
        <h2 class="display-5">Another headline</h2>
        <p class="lead">And an even wittier subheading.</p>
      </div>
      <div class="bg-dark box-shadow mx-auto" style="width: 80%; height: 300px; border-radius: 21px 21px 0 0;"></div>
    </div>
  </div>
-->


</div>


<style>
.product-device {
  position: absolute;
  right: 10%;
  bottom: -30%;
  width: 300px;
  height: 540px;
  background-color: #333;
  border-radius: 21px;
  -webkit-transform: rotate(30deg);
  transform: rotate(30deg);
}

.product-device::before {
  position: absolute;
  top: 10%;
  right: 10px;
  bottom: 10%;
  left: 10px;
  content: "";
  background-color: rgba(255, 255, 255, .1);
  border-radius: 5px;
}

.product-device-2 {
  top: -25%;
  right: auto;
  bottom: 0;
  left: 5%;
  background-color: #e5e5e5;
}



.overflow-hidden { overflow: hidden; }


/* Center align the text within the three columns below the carousel */
.marketing .col-lg-4 {
  margin-bottom: 1.5rem;
  text-align: center;
}
.marketing h2 {
  font-weight: 400;
}
.marketing .col-lg-4 p {
  margin-right: .75rem;
  margin-left: .75rem;
}



.featurette-divider {
  margin: 5rem 0; /* Space out the Bootstrap <hr> more */
}

/* Thin out the marketing headings */
.featurette-heading {
  font-weight: 300;
  line-height: 1;
  letter-spacing: -.05rem;
}


/* RESPONSIVE CSS
-------------------------------------------------- */

@media (min-width: 40em) {
  /* Bump up size of carousel content */
  .carousel-caption p {
    margin-bottom: 1.25rem;
    font-size: 1.25rem;
    line-height: 1.4;
  }

  .featurette-heading {
    font-size: 50px;
  }
}

@media (min-width: 62em) {
  .featurette-heading {
    margin-bottom: 2rem;
  }
}

</style>
