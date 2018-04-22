<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="/assets/bootstrap-4.1.0/css/bootstrap.min.css" rel="stylesheet">

  <link href="/assets/styles.css" rel="stylesheet" type="text/css">

  <script defer src="https://use.fontawesome.com/releases/v5.0.8/js/all.js"></script>
  <script src="/assets/jquery-3.3.1.min.js"></script>
  <script src="/assets/bootstrap/js/bootstrap.min.js"></script>
  <script src="/assets/script.js"></script>

  <?php include('views/components/favicon.php') ?>

  <title><?= e($title) ?></title>
</head>
<body>

  <nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <a class="navbar-brand" href="/">IndieLogin.com</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarCollapse">
      <ul class="navbar-nav mr-auto">
        <li class="nav-item">
          <a class="nav-link" href="/">Home <span class="sr-only">(current)</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="/setup">Setup</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="/api">Developers</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="/faq">FAQ</a>
        </li>
      </ul>
    </div>
  </nav>

  <?= $this->section('content')?>

</body>
</html>
