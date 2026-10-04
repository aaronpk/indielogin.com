<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>
<?php
$ssh = ssh_server();
// The command, with the given username (already HTML)
$sshCommand = fn($user) => 'ssh '.($ssh['port'] != 22 ? '-p '.$ssh['port'].' ' : '').$user.'@'.e($ssh['host']);
?>

<section id="ssh-key">
  <h1>SSH Key</h1>

  <p>If you have an SSH key, you can use it to sign in. Put your public key on your website, and link to it from your home page with <code>rel="ssh-key"</code>:</p>

  <p><pre><?= e('<link rel="ssh-key" href="/ssh.pub">') ?></pre></p>

  <p>The file can be your <code>id_ed25519.pub</code> as it is, an <code>authorized_keys</code> file, or a list of fingerprints like <code>SHA256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU</code> (the output of <code class="text-nowrap">ssh-keygen -lf ~/.ssh/id_ed25519.pub</code>), one per line. Since the keys you've added to GitHub are published at <code>https://github.com/<i>username</i>.keys</code>, you can link straight to that instead.</p>

  <p>Ed25519, ECDSA and RSA keys work, as do keys on a hardware security key.</p>

  <?php if($ssh): ?>
    <p>When you sign in, there are two ways to prove the key is yours: connect with <code>ssh</code>, or sign a challenge and paste it in.</p>
  <?php endif ?>
</section>

<?php if($ssh): ?>
  <section id="ssh-command">
    <h3>From your terminal, with <code>ssh</code></h3>

    <p>Start signing in on the website as usual. When the sign-in page asks for your SSH key, leave it open and run:</p>

    <p><pre><?= $sshCommand('<i>yourdomain.com</i>') ?></pre></p>

    <p>using your own domain, without <code>https://</code> (a leading <code>www.</code> doesn't matter). Your SSH client offers your keys, the same way it does when you log in to a server, including keys held by <code>ssh-agent</code> or a password manager. One of them has to be listed in your file; to use a particular key, add <code>-i</code> and its path.</p>

    <?php if($ssh['fingerprint']): ?>
      <p>The first time you connect, ssh asks you to confirm the server's key. Its fingerprint should be <code><?= e($ssh['fingerprint']) ?></code>.</p>
    <?php endif ?>

    <p>Then it shows what you're about to sign in to:</p>

    <p><pre>IndieLogin.com
Sign in to https://app.example/
as https://yourdomain.com/
started 20 seconds ago from 203.0.113.7
with your key SHA256:…

Press Enter to confirm, or Ctrl-C to cancel.</pre></p>

    <p>Check that it's the sign-in you just started: the application, when it started and the address it started from. Press Enter, and the sign-in page carries on by itself.</p>

    <p>If more than one sign-in is waiting for your domain, perhaps because you have two tabs open, or someone else started signing in as you at the same time, it doesn't pick one. It asks for the code shown on your sign-in page instead. You can also use that code in place of your domain: <code><?= $sshCommand('<i>k7f2-9qxm</i>') ?></code>.</p>

    <p>If none of your keys is accepted, ssh tells you why: no sign-in is waiting for your domain, or none of the keys it offered are in your file, along with the fingerprints of the keys it tried.</p>

    <p>That server does nothing but confirm sign-ins. There is no shell, it runs no commands, and forwarding is turned off. Like any SSH server, it never sees your private key; your SSH client only proves that it has it.</p>
  </section>
<?php endif ?>

<section id="ssh-signature">
  <h3><?= $ssh ? 'Or sign a challenge' : 'Signing in' ?></h3>

  <p>When you sign in, you'll be given a command to run, which signs a challenge with <code class="text-nowrap">ssh-keygen -Y sign</code>, and you paste the result back in. It needs OpenSSH 8.1 or newer.</p>
</section>
