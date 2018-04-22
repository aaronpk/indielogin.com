<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="/assets/bulma-0.6.2/css/bulma.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/featherlight-1.5.0/featherlight.min.css">

  <link href="/assets/styles.css" rel="stylesheet" type="text/css">

  <script defer src="https://use.fontawesome.com/releases/v5.0.8/js/all.js"></script>
  <script src="/assets/jquery-3.3.1.min.js"></script>
  <script src="/assets/featherlight-1.5.0/featherlight.min.js"></script>
  <script src="/assets/script.js"></script>

  <?php include('views/components/favicon.php') ?>

  <title><?= e($title) ?></title>
</head>
<body>

  <?= $this->section('content')?>

</body>
</html>
