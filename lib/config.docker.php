<?php

define('APP_BASE', getenv('APP_BASE', true));
define('APP_NAME', getenv('APP_NAME', true));
define('APP_USERAGENT', getenv('APP_USERAGENT', true));

define('GITHUB_ID', getenv('GITHUB_ID', true));
define('GITHUB_SECRET', getenv('GITHUB_SECRET', true));

define('TWITTER_ID', getenv('TWITTER_ID', true));
define('TWITTER_SECRET', getenv('TWITTER_SECRET', true));

define('MAILGUN_KEY', getenv('MAILGUN_KEY', true));
define('MAILGUN_DOMAIN', getenv('MAILGUN_DOMAIN', true));
define('MAILGUN_FROM', getenv('MAILGUN_FROM', true));

define('PGP_API', 'http://pgp:9009');
define('REDIS_API', 'tcp://redis:6379');

define('MYSQL_HOST', 'database');
define('MYSQL_DATABASE', getenv('MYSQL_DATABASE', true));
define('MYSQL_USER', getenv('MYSQL_USER', true));
define('MYSQL_PASSWORD', getenv('MYSQL_PASSWORD', true));


class Config {
  public static $base = APP_BASE;
  public static $name = APP_NAME;
  public static $useragent = APP_USERAGENT;

  public static $githubClientID = GITHUB_ID;
  public static $githubClientSecret = GITHUB_SECRET;

  public static $twitterClientID = TWITTER_ID;
  public static $twitterClientSecret = TWITTER_SECRET;

  public static $mailgun = [
    'key' => MAILGUN_KEY,
    'domain' => MAILGUN_DOMAIN,
    'from' => MAILGUN_FROM
  ];

  public static $pgpVerificationAPI = PGP_API;
  public static $redisAPI = REDIS_API;

  public static $db = [
    'host' => MYSQL_HOST,
    'database' => MYSQL_DATABASE,
    'username' => MYSQL_USER,
    'password' => MYSQL_PASSWORD
  ];
}
