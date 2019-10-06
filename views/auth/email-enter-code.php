<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <? if($error ?? false): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <? endif ?>

  <div id="usercode-prompt">

    <p>Enter the code you received in your email.</p>

    <form action="/auth/verify_email_code" method="POST">
      <input type="hidden" id="code" name="code" value="<?= $code ?>">

      <div class="form-group">
        <input type="text" id="usercode" name="usercode" class="form-control" autocomplete="off" maxlength="7">
      </div>

      <input type="submit" id="verify-code" value="Verify Code" class="btn btn-primary">
    </form>

  </div>

</div>
<script>
$(function(){

  $("#verify-code").click(function(){
    $(this).attr("disabled", "disabled");
    $("#usercode-prompt form").submit();
  });

});
</script>
