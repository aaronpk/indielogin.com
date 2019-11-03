<?php

class Config {
  public static $base = '';
  public static $name = '';
  public static $useragent = '';

  public static $githubClientID = '';
  public static $githubClientSecret = '';

  public static $twitterClientID = '';
  public static $twitterClientSecret = '';

  public static $mailgun = [
    'key' => '',
    'domain' => '',
    'from' => ''
  ];

  public static $pgpVerificationAPI = '';
  public static $redisAPI = '';

  public static $db = [
    'host' => '',
    'database' => '',
    'username' => '',
    'password' => ''
  ];
}

Config::$base = getenv('APP_BASE', true);
Config::$name =  getenv('APP_NAME', true);
Config::$useragent = getenv('APP_USERAGENT', true);

Config::$githubClientID = getenv('GITHUB_ID', true);
Config::$githubClientSecret = getenv('GITHUB_SECRET', true);

Config::$twitterClientID = getenv('TWITTER_ID', true);
Config::$twitterClientSecret = getenv('TWITTER_SECRET', true);

Config::$mailgun = [
    'key' => getenv('MAILGUN_KEY', true),
    'domain' => getenv('MAILGUN_DOMAIN', true),
    'from' => getenv('MAILGUN_FROM', true)
];

Config::$pgpVerificationAPI = getenv('PGP_API', true);
Config::$redisAPI = getenv('REDIS_API', true);

Config::$mailgun = [
    'host' => getenv('MYSQL_HOST', true),
    'database' => getenv('MYSQL_DATABASE', true),
    'username' => getenv('MYSQL_USER', true),
    'password' => getenv('MYSQL_PASSWORD', true)
];
