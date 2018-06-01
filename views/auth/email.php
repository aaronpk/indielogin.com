<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <div id="send-email-section">

    <p>Click to receive a one-time code to your email address.</p>

    <form action="/auth/send_email" method="POST">
      <input type="hidden" id="code" name="code" value="<?= $code ?>">

      <div class="form-group">
        <input type="email" disabled="disabled" class="form-control" value="<?= e($email) ?>">
      </div>

      <input type="submit" id="send-email" class="btn btn-primary" value="Send Email">
    </form>

  </div>

</div>
<script>
$(function(){

  $("#send-email").click(function(){
    $(this).attr("disabled", "disabled");
    $("#send-email-section form").submit();
  });


});
</script>
