<?php $this->layout('layout', ['title' => $title, 'nofooter' => true]) ?>

<div class="container container-narrow">

  <p>Enter your URL to sign in.</p>

  <div class="login-details">
    <p>Log in to <a href="<?= $client_id ?>"><?= $client_id ?></a></p>

    <form action="/auth" method="get">
      <div class="form-group">
        <input type="url" placeholder="example.com" name="me" id="me" class="form-control">
      </div>

      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <input type="hidden" name="redirect_uri" value="<?= $redirect_uri ?>">
      <input type="hidden" name="state" value="<?= $state ?>">

      <button class="btn btn-primary">Sign In</button>
    </form>
  </div>

</div>
<script src="/assets/fedcm.js"></script>
<script>
$(function(){
  // Save the URL entered and restore it next time they come back
  if(window.localStorage) {
    $("form").on("submit", function(e){
      window.localStorage.setItem('me', $("#me").val());
    });
    if(me=window.localStorage.getItem('me')) {
      $("#me").val(me);
    }
  }
});
</script>
