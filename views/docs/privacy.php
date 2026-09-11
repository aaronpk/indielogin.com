<?php
use Michelf\MarkdownExtra;

$this->layout('layout', ['title' => $title]);
?>

<div class="container container-narrow api-docs">
<?php ob_start() ?>

# Privacy Policy

### Logs

<?= getenv('APP_NAME') ?> keeps logs of attempted and successful authentications. Properties of each log record are:

* the date of the authentication attempt
* the application identifier
* the redirect URL of the application
* the name of the provider used to authenticate (Twitter, GitHub, GitLab, Codeberg, email, etc)
* the profile URL, email address, or PGP key URL used to authenticate
* the profile URL the user entered
* the profile URL the user's website returned
* the date of the completed authentication

Things that are not stored or logged in any way:

* users' access tokens from Twitter, GitHub, GitLab, or Codeberg
* user-entered data other than their public website URL and, for developers who register an application, the details below


### Developer Accounts

Developers who register an application at [/developers](/developers) sign in with their own website, and the following is stored for them:

* the website URL they signed in with
* the email address they provide, which is used only to contact them about the applications they have registered
* the URL of each application they register, and the dates it was registered and last used

This information is not shared with anyone, and is not shown to the users who sign in to those applications.


### Data Provided to Developers

Since <?= getenv('APP_NAME') ?> is a service for developers wishing to easily implement logging in to apps, this service provides some information to the developers of the applications users log in to.

After a successful authentication, this website returns the fully resolved profile URL of the user who authenticated to the developer.

If the user authenticates with an OAuth provider such as Twitter, GitHub, GitLab, or Codeberg, the access tokens and profile information from those providers are never shared with the developer. Developers may be able to see which provider and the username at that provider you used to authenticate (e.g. Twitter, GitHub, GitLab, Codeberg, email), but will not be able to access any information in those accounts.


<?php
$markdown = ob_get_clean();
echo MarkdownExtra::defaultTransform($markdown);
?>
</div>
