<?php
use Michelf\MarkdownExtra;

$this->layout('layout', ['title' => $title]);
?>

<div class="container container-narrow api-docs">
<?php ob_start() ?>

# <?= get_setting('name') ?> FAQ


### Why does <?= get_setting('name') ?> ask for permission to read my tweets? {#twitter-permissions}

If you choose to authenticate with Twitter, <?= get_setting('name') ?> uses the Twitter API to verify your account. The least amount of permissions this site can request from the Twitter API is to read your tweets. This site does not actually read your tweets or access your Twitter account in any way other than to verify your Twitter username after you authenticate. Your Twitter tokens are never stored by this site or provided to the site you're logging in to.

If you are uncomfortable with the permissions requested, you can choose to authenticate using a <a href="/setup">different provider</a> such as your IndieAuth server, GitHub, email, or PGP key.


### Why does <?= get_setting('name') ?> ask for permission to read my public data in my GitHub account? {#github-permissions}

If you choose to authenticate with GitHub, <?= get_setting('name') ?> uses the GitHub API to verify your account. The least amount of permissions this site can request from GitHub is accessing your public data. This site does not actually access your GitHub account other than to verify your username. Your GitHub token is never stored by this site or provided to the site you're logging in to.

If you are uncomfortable with the permissions requested, you can choose to authenticate using a <a href="/setup">different provider</a> such as your IndieAuth server, Twitter, email, or PGP key.


### What is the difference between <?= get_setting('name') ?> and IndieAuth? {#difference-indieauth}

<?= get_setting('name') ?> is a service for developers who want to offload logging in users to an external service, and implements web sign-in by consuming IndieAuth, other OAuth APIs, as well as email and PGP verification.

IndieAuth is a protocol that lets your website be its own identity while supporting OAuth 2.0.

If you'd like to let people log in to your website or application, you can implement web sign-in yourself, or you can delegate that to <?= get_setting('name') ?>.

If you'd like to add IndieAuth support to your website, visit <a href="https://indieweb.org/IndieAuth">indieweb.org/IndieAuth</a> for links to services, plugins, and other documentation you can use to get started.


### Can I run my own instance of <?= get_setting('name') ?>? {#can-i-run-my-own-instance}

Absolutely yes! In fact, if you're developing an application, you're absolutely encouraged to implement web sign-in yourself, or run a copy of this code on your own. You can find the source code to <?= get_setting('name') ?> <a href="https://github.com/aaronpk/IndieLogin.com">on GitHub</a>.


<?php
$markdown = ob_get_clean();
echo MarkdownExtra::defaultTransform($markdown);
?>
</div>
