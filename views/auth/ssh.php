<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * @var string $code      The challenge to sign.
 * @var string $namespace What the signature has to be made for.
 * @var string $keys_url  Where the website lists its keys.
 */
$command = "printf '%s' '".$code."' | ssh-keygen -Y sign -n ".$namespace." -f ~/.ssh/id_ed25519";
?>

<div class="container container-narrow">

  <div id="verify-challenge-section">

    <p>Sign this challenge with one of the SSH keys listed at <code><?= e($keys_url) ?></code>. Run this in a terminal, changing <code>~/.ssh/id_ed25519</code> to your own key if it is somewhere else:</p>

    <pre id="ssh-command" style="white-space: pre-wrap; word-break: break-all"><?= e($command) ?></pre>
    <p><button type="button" id="copy-command" class="btn btn-sm btn-outline-secondary" hidden>Copy command</button></p>

    <p style="font-size: 0.9em">If your key is held by ssh-agent or a password manager, give the <code>.pub</code> file after <code>-f</code> instead, and the agent will do the signing.</p>

    <form action="/auth/verify_ssh_challenge" method="POST">
      <input type="hidden" id="code" name="code" value="<?= e($code) ?>">

      <div class="form-group">
        <label for="signed">Paste the signature it prints, from <code>-----BEGIN SSH SIGNATURE-----</code> to <code>-----END SSH SIGNATURE-----</code>:</label>
        <textarea class="form-control" id="signed" name="signed" rows="8" style="font-family: monospace; font-size: 0.85em" spellcheck="false" autocomplete="off"></textarea>
      </div>

      <div class="form-group">
        <p style="font-size: 0.9em">The button below will be enabled when an SSH signature is detected in the box above.</p>
      </div>

      <input type="submit" id="submit-challenge" class="btn btn-primary" value="Verify">
    </form>

  </div>

</div>
<script>
$(function(){

  // Disable the submit button until it looks like there is a signature in the
  // box. Do this in JS so that without JS the button will be enabled.
  $("#submit-challenge").attr("disabled", "disabled");

  var enableSubmit = function(){
    if(/-----BEGIN SSH SIGNATURE-----/.test($("#signed").val())) {
      $("#submit-challenge").removeAttr("disabled");
    }
  };

  $("#signed").on("change keyup keydown paste input", enableSubmit);
  setInterval(enableSubmit, 500);

  // Copying the command is offered only where the browser can do it
  if(navigator.clipboard) {
    $("#copy-command").removeAttr("hidden").click(function(){
      var button = $(this);
      navigator.clipboard.writeText($("#ssh-command").text()).then(function(){
        button.text("Copied");
        setTimeout(function(){ button.text("Copy command"); }, 2000);
      });
    });
  }

  $("#submit-challenge").click(function(){
    $(this).attr("disabled", "disabled");
    $("#verify-challenge-section form").submit();
  });

});
</script>
