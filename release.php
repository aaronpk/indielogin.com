<?php

include('vendor/autoload.php');
$userlog = make_logger('release');
initdb();

if(empty(getenv('APP_NAME')) || empty(getenv('BASE_URL')) || !defined('DB_SETUP')) {
  $userlog->error("Unable to release without APP_NAME, BASE_URL and valid DB_ ENV");
  exit(-1);
}
try {
  installDB();
} catch(\Exception $e) {
  $userlog->error(
    'Schema restore / migration failed'
  );
  exit(-1);
}
$userlog->info('Release success');
exit(0);
