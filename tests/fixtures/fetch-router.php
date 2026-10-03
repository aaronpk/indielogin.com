<?php
// A small site for tests/fetch.php, served with php -S. Each path answers
// with a redirect or a page, so a redirect chain can be followed hop by hop.
switch(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
  case '/permanent':
    header('Location: /temporary', true, 301);
    break;
  case '/temporary':
    header('Location: /final', true, 302);
    break;
  case '/final':
    header('Link: </auth>; rel="authorization_endpoint"');
    echo '<link rel="ssh-key" href="/keys.pub">final page';
    break;
  case '/to-metadata':
    header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
    break;
  case '/to-loopback-name':
    header('Location: http://localhost:1/', true, 302);
    break;
  case '/loop':
    header('Location: /loop', true, 302);
    break;
  case '/missing':
    http_response_code(404);
    echo 'not here';
    break;
  default:
    echo 'home';
}
