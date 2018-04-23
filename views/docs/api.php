<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <h1><?= Config::$name ?></h1>

  <p>If you are building a website and need to sign people in, you can use <?= Config::$name ?> to handle all the complicated parts.</p>

  <p>Users will identify themselves with their domain name, and can authenticate using one of the <a href="/setup">supported authentication providers</a> such as Twitter, GitHub, or email. The user ID returned to you will be their domain name, ensuring that you don't end up creating multiple accounts depending on how the user authenticates.</p>

  <h2>1. Create a Web Sign-In form</h2>

  <? $base = Config::$base; ?>
  <pre><code><?= e(<<<EOT
<form action="${base}auth" method="get">
  <label for="url">Web Address:</label>
  <input id="url" type="text" name="me" placeholder="yourdomain.com" />
  <p><button type="submit">Sign In</button></p>
  <input type="hidden" name="client_id" value="https://example.com/" />
  <input type="hidden" name="redirect_uri" value="https://example.com/redirect" />
  <input type="hidden" name="state" value="jwiusuerujs" />
</form>
EOT
  ) ?></code></pre>

  <h3>Parameters</h3>

  <ul>
    <li><b>action</b>: Set the action of the form to this service (<code><?= Config::$base ?>auth</code>) or <a href="https://github.com/aaronpk/IndieLogin.com">download the source</a> and run your own server.</li>
    <li><b>me</b>: The "me" parameter is the URL that the user enters.</li>
    <li><b>client_id</b>: Set the client_id in a hidden field to let this site know the home page of the application the user is signing in to.</li>
    <li><b>redirect_uri</b>: Set the redirect_uri in a hidden field to let this site know where to redirect back to after authentication is complete. It must be on the same domain as the client_id.</li>
    <li><b>state</b>: You should generate a random value that you will check after the user is redirected back, in order to prevent certain attacks.</li>
  </ul>


  <h2>2. The user logs in with their domain</h2>

  <p>After the user enters their domain in the sign-in form and submits, <?= Config::$name ?> will scan their website looking for <code>rel="me"</code> links from providers it knows about (see <a href="/setup">Supported Providers</a>).</p>


  <h2>3. The user is redirected back to your site</h2>

  <p><pre>https://example.com/callback?state=jwiusuerujs&amp;code=gk7n4opsyuUxhvF4</pre></p>

  <p>If everything is successful, the user will be redirected back to the <code>redirect_uri</code> you specified in the form. You'll see two parameters in the query string, <code>state</code> and <code>code</code>. Check that the state matches the value you set originally before continuing.</p>


  <h2>4. Verify the authorization code with <?= Config::$name ?></h2>

  <p>At this point you need to verify the code which will also return the domain name of the authenticated user. Make a POST request to <code><?= Config::$base ?>auth</code> with the code, client_id and redirect_uri, and you will get back the full domain name of the authenticated user.</p>

  <p><pre>POST <?= Config::$base?>auth HTTP/1.1
Content-Type: application/x-www-form-urlencoded;charset=UTF-8
Accept: application/json

code=gk7n4opsyuUxhvF4&amp;
redirect_uri=https://example.com/callback&amp;
client_id=https://example.com/</pre></p>


  <p>An example successful response:</p>

  <p><pre>HTTP/1.1 200 OK
Content-Type: application/json

{
  "me": "https://aaronparecki.com/"
}</pre></p>

  <p>An example error response:</p>

  <p><pre>HTTP/1.1 400 Bad Request
Content-Type: application/json

{
  "error": "invalid_request",
  "error_description": "The code provided was not valid"
}</pre></p>


  <h2>You're Done!</h2>

  <p>At this point you know the domain belonging to the authenticated user.</p>

  <p>You can store the domain secure session and log the user in with their domain name identity. You don't need to worry about whether they authenticated with Google, Twitter or Github, their identity is their domain name! You won't have to worry about merging duplicate accounts or handling error cases when Twitter is offline.</p>


</div>
