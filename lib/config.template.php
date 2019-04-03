<?php
class Config {
  public static $base = 'https://indielogin.com/';
  public static $name = 'IndieLogin.com';
  public static $useragent = '';

  public static $githubClientID = '';
  public static $githubClientSecret = '';

  public static $twitterClientID = '';
  public static $twitterClientSecret = '';

  public static $mailgun = [
    'key' => 'key-',
    'domain' => 'mail.indielogin.com',
    'from' => '"indielogin.com" <login@indielogin.com>'
  ];

  public static $pgpVerificationAPI = 'http://127.0.0.1:9009';
  public static $redisAPI = 'tcp://127.0.0.1:6379';

  public static $db = [
    'host' => '127.0.0.1',
    'database' => 'indielogin',
    'username' => 'indielogin',
    'password' => 'indielogin'
  ];
}
