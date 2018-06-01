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

  public static $allowedClientIDHosts = [
    'indielogin.com',
  ];
}
