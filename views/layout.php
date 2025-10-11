<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="/assets/bootstrap-4.1.0/css/bootstrap.min.css" rel="stylesheet">

  <link href="/assets/styles.css" rel="stylesheet" type="text/css">

  <script defer src="https://use.fontawesome.com/releases/v7.1.0/js/all.js"></script>
  <script src="/assets/jquery-3.3.1.min.js"></script>
  <script src="/assets/bootstrap-4.1.0/js/bootstrap.min.js"></script>
  <script src="/assets/script.js"></script>

  <?php require __DIR__ . '/components/favicon.php' ?>

  <title><?= e($title) ?></title>
</head>
<body>

  <nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <a class="navbar-brand" href="/"><?= e(getenv('APP_NAME')) ?></a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarCollapse">
      <ul class="navbar-nav mr-auto">
        <li class="nav-item">
          <a class="nav-link" href="/">Home</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="/setup">Setup</a>
        </li>
        <!--
        <li class="nav-item">
          <a class="nav-link" href="/api">Developers</a>
        </li>
        -->
        <li class="nav-item">
          <a class="nav-link" href="/faq">FAQ</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="/privacy-policy">Privacy Policy</a>
        </li>
      </ul>
    </div>
  </nav>

  <?= $this->section('content')?>

  <div class="alert alert-info" style="margin: 1em;">If you have any trouble using this service, please open an issue <a href="https://github.com/aaronpk/IndieLogin.com">on GitHub</a>.</div>

  <?php if(!isset($nofooter)): ?>
  <footer class="footer">
    <div class="row">
      <div class="col-md-4">
        <ul>
          <li><a href="/">Home</a></li>
          <li><a href="/setup">Setup</a></li>
          <li><a href="/api">Developers</a></li>
          <li><a href="/faq">FAQ</a></li>
          <li><a href="/privacy-policy">Privacy Policy</a></li>
        </ul>
      </div>
      <div class="col-md-4">
        <ul>
          <li><a href="https://github.com/aaronpk/IndieLogin.com">Source Code</a></li>
          <li><a href="https://github.com/aaronpk/IndieLogin.com/issues">Issues</a></li>
        </ul>
      </div>
    </div>
  </footer>
  <?php endif ?>

</body>
</html>
