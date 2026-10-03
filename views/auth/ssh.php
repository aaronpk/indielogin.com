<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * @var string      $code      The challenge to sign.
 * @var string      $namespace What the signature has to be made for.
 * @var string      $keys_url  Where the website lists its keys.
 * @var array|null  $server    The SSH sign-in server (host, port, fingerprint),
 *                             or null if there is none.
 * @var string|null $domain    What to ssh in as: the domain being signed in as.
 * @var string      $connect   The connect code, for when the domain is not
 *                             enough to tell which sign-in is meant.
 */
$command = "printf '%s' '".$code."' | ssh-keygen -Y sign -n ".$namespace." -f ~/.ssh/id_ed25519";

if($server) {
  // Without a usable domain, the connect code is the username instead
  $sshUser = $domain ?? str_replace('-', '', $connect);
  $sshCommand = 'ssh '.($server['port'] != 22 ? '-p '.$server['port'].' ' : '').$sshUser.'@'.$server['host'];
}
?>

<div class="container container-narrow">

  <?php if($server): ?>
    <section id="ssh-connect-section" class="mb-4">
      <h4>Sign in from your terminal</h4>

      <p>Run this, and press Enter when it asks you to confirm. Your SSH client will offer your keys; one of them has to be listed at <code><?= e($keys_url) ?></code>.</p>

      <pre id="ssh-connect-command"><?= e($sshCommand) ?></pre>
      <p><button type="button" class="btn btn-sm btn-outline-secondary copy-button" data-copy="#ssh-connect-command" hidden>Copy command</button></p>

      <p>If it asks for a code, enter <b><code id="ssh-connect-code"><?= e($connect) ?></code></b>.</p>

      <?php if($server['fingerprint']): ?>
        <p style="font-size: 0.9em">The first time you connect, ssh will show the server's key fingerprint. It should be <code><?= e($server['fingerprint']) ?></code>.</p>
      <?php endif ?>

      <form action="/auth/verify_ssh_connection" method="POST" id="ssh-connect-form">
        <input type="hidden" name="code" value="<?= e($code) ?>">
        <p id="ssh-connect-status" class="text-muted" hidden>Waiting for you to confirm in your terminal&hellip;</p>
        <noscript>
          <input type="submit" class="btn btn-primary" value="I've confirmed in my terminal">
        </noscript>
      </form>
    </section>

    <h5>Or sign a challenge</h5>
  <?php endif ?>

  <div id="verify-challenge-section">

    <p>Sign this challenge with one of the SSH keys listed at <code><?= e($keys_url) ?></code>. Run this in a terminal, changing <code>~/.ssh/id_ed25519</code> to your own key if it is somewhere else:</p>

    <pre id="ssh-command" style="white-space: pre-wrap; word-break: break-all"><?= e($command) ?></pre>
    <p><button type="button" class="btn btn-sm btn-outline-secondary copy-button" data-copy="#ssh-command" hidden>Copy command</button></p>

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

  // Copying a command is offered only where the browser can do it
  if(navigator.clipboard) {
    $(".copy-button").removeAttr("hidden").click(function(){
      var button = $(this);
      navigator.clipboard.writeText($(button.data("copy")).text()).then(function(){
        button.text("Copied");
        setTimeout(function(){ button.text("Copy command"); }, 2000);
      });
    });
  }

  $("#submit-challenge").click(function(){
    $(this).attr("disabled", "disabled");
    $("#verify-challenge-section form").submit();
  });

  // While the SSH sign-in server waits for someone to confirm, check every
  // couple of seconds, and finish the sign-in as soon as they have
  if($("#ssh-connect-form").length) {
    var status = $("#ssh-connect-status").removeAttr("hidden");
    var check = function(){
      $.ajax({url: "/auth/ssh_status", cache: false, dataType: "json"}).done(function(data){
        if(data.status == "approved") {
          status.text("Confirmed. Signing you in…");
          $("#ssh-connect-form").submit();
        } else if(data.status == "expired") {
          status.text("This sign-in has expired. Go back to the application and start again.");
        } else {
          setTimeout(check, 1500);
        }
      }).fail(function(){
        setTimeout(check, 5000);
      });
    };
    setTimeout(check, 1500);
  }

});
</script>
