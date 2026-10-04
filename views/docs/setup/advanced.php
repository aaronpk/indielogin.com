<?php $this->layout('docs/setup/_layout', compact('title', 'page', 'pages', 'previous', 'next')) ?>

<h1>Advanced Options</h1>

<section id="hidden-links">
  <h3>Hidden Links</h3>

  <p>If you don't want visible links to your profiles, you can use an invisible <code>&lt;link&gt;</code> tag instead. For example:</p>

  <p><pre><?= e('<link href="https://github.com/aaronpk" rel="me">') ?></pre></p>
</section>

<section id="multiple-domains">
  <h3>Multiple Domains</h3>

  <p>If you have multiple domains, or want your GitHub/GitLab/Codeberg profile to link to something that is not your main website, you can alternatively put one or more URLs in your "bio" field on GitHub, GitLab, and Codeberg. This allows you to use one GitHub/GitLab/Codeberg account to authenticate multiple domains.</p>
</section>

<section id="choosing-auth-providers">
  <h3>Explicitly Choosing Auth Providers</h3>

  <p>If you don't want <?= getenv('APP_NAME') ?> to consider <i>all</i> your <code>rel="me"</code> links as possible authentication options, you can choose which ones specifically by using <code>rel="me authn"</code> instead. This allows you to, for example, only use providers that support two-factor authorization, while still linking to your existing profiles using <code>rel="me"</code>.</p>

  <p><pre><?= e('<a href="https://twitter.com/aaronpk" rel="me">twitter.com/aaronpk</a>
<a href="https://github.com/aaronpk" rel="me authn">github.com/aaronpk</a>') ?></pre></p>

  <p>If <i>any</i> of your <code>rel="me"</code> links also include <code>authn</code> in the list of rels, then <?= getenv('APP_NAME') ?> will <i>only</i> use the links with <code>authn</code>, and will no longer consider your plain <code>rel="me"</code> links as authentication options.</p>

  <p>The same goes for a link to a key: <code>rel="ssh-key authn"</code> or <code>rel="pgpkey authn"</code> keeps it in the list when you use <code>authn</code>.</p>
</section>
