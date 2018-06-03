<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <div id="verify-challenge-section">

    <p>Sign the string below with your PGP key.</p>

    <form action="/auth/verify_pgp_challenge" method="POST">
      <input type="hidden" id="code" name="code" value="<?= $code ?>">

      <div class="form-group">
        <textarea class="form-control" id="signed" name="signed" rows="3"><?= e($code) ?></textarea>
      </div>

      <input type="submit" id="submit-challenge" class="btn btn-primary" value="Verify">
    </form>

  </div>

</div>
<script>
$(function(){

  // Disable the submit button until it looks like there is signed text in the box.
  // Do this in JS so that without JS the button will be enabled.
  $("#submit-challenge").attr("disabled", "disabled");

  // When the signature value is changed, check that it looks
  // like a PGP signature and enable the button
  $("#signed").on("change keyup", function(){
    if($("#signed").val().indexOf("BEGIN PGP SIGNATURE") !== -1) {
      $("#submit-challenge").removeAttr("disabled");
    }
  });

  $("#submit-challenge").click(function(){
    $(this).attr("disabled", "disabled");
    $("#verify-challenge-section form").submit();
  });

});
</script>
