<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;

use ORM;

class Developers {

  /**
   * The developer docs, and the sign-in form for the client registration area.
   */
  public function docs(ServerRequestInterface $request): ResponseInterface {
    session_start();

    return new HtmlResponse(view('docs/developers', [
      'title' => getenv('APP_NAME').' for Developers',
      'registration_enabled' => client_registration_enabled(),
      'user' => client_registration_enabled() ? $this->_currentUser() : false,
      'error' => $this->_takeFlash('developer_error'),
    ]));
  }

  /**
   * Start an authorization request against this site, so that a developer
   * signs in to the registration area the same way their own users will sign
   * in to their app.
   */
  public function login(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $params = $request->getQueryParams();

    if(empty($params['me']))
      return $this->_flash('developer_error', 'Enter the URL of your website to sign in.', '/developers');

    // Unlike the demo on the home page, this starts a session first, so the
    // state is actually stored and can be checked when we get back, and the
    // code challenge is a real S256 hash of the verifier
    return redirect_response('/authorize?'.http_build_query([
      'client_id' => getenv('BASE_URL'),
      'redirect_uri' => getenv('BASE_URL').'developers/redirect',
      'state' => generate_state('developer'),
      'code_challenge' => pkce_code_challenge(generate_pkce_code_verifier()),
      'code_challenge_method' => 'S256',
      'me' => $params['me'],
    ]));
  }

  public function redirect(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $params = $request->getQueryParams();
    $userlog = make_logger('user');

    // Check the state before anything else. Without this, someone could walk
    // a person's browser through a login of their own choosing and leave that
    // browser signed in to the registration area as somebody else.
    $expected = $_SESSION['developer.state'] ?? false;
    unset($_SESSION['developer.state']);

    if(!$expected || !is_string($params['state'] ?? null) || !hash_equals($expected, $params['state'])) {
      $userlog->warning('Developer sign-in state did not match');
      return $this->_flash('developer_error', 'The sign-in session expired or did not match. Please try again.', '/developers');
    }

    if(empty($params['code']))
      return $this->_flash('developer_error', 'No authorization code was returned. Please try again.', '/developers');

    // Read the result straight out of redis rather than making an HTTP
    // request back to ourselves, the same shortcut Controller::demo_redirect
    // takes. Unlike the demo, the code is consumed so it cannot be replayed.
    $login = redis()->get('indielogin:code:'.$params['code']);

    if(!$login)
      return $this->_flash('developer_error', 'The sign-in session expired. Please try again.', '/developers');

    redis()->del('indielogin:code:'.$params['code']);

    $login = json_decode($login, true);

    // Make sure this code was issued to us and not to some other application
    if(($login['client_id'] ?? null) !== getenv('BASE_URL') || empty($login['me'])) {
      $userlog->warning('Developer sign-in got a code issued to another client');
      return $this->_flash('developer_error', 'Something went wrong signing you in. Please try again.', '/developers');
    }

    $log = ORM::for_table('logins')->where('code', $params['code'])->find_one();
    if($log) {
      $log->complete = 1;
      $log->date_complete = date('Y-m-d H:i:s');
      $log->code = '';
      $log->save();
    }

    $user = $this->_findOrCreateUser($login['me']);

    // Deliberately not $_SESSION['me'], which is set by any completed login
    // and cleared by prompt=login, so it cannot stand in for an account
    $_SESSION['developer_user_id'] = $user->id;

    $userlog->info('Developer signed in', ['url' => $user->url]);

    return redirect_response('/developers/clients');
  }

  public function logout(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $params = $request->getParsedBody();

    if(csrf_valid($params['csrf'] ?? null))
      unset($_SESSION['developer_user_id']);

    return redirect_response('/developers');
  }

  public function clients(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $user = $this->_currentUser();
    if(!$user)
      return redirect_response('/developers');

    return new HtmlResponse(view('developers/clients', [
      'title' => 'Your Applications',
      'user' => $user,
      'clients' => ORM::for_table('clients')
        ->where('user_id', $user->id)
        ->order_by_desc('date_last_used')
        ->order_by_desc('id')
        ->find_many(),
      'csrf' => csrf_token(),
      'error' => $this->_takeFlash('developer_error'),
      'success' => $this->_takeFlash('developer_success'),
    ]));
  }

  public function register(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $user = $this->_currentUser();
    if(!$user)
      return redirect_response('/developers');

    $params = $request->getParsedBody();

    if(!csrf_valid($params['csrf'] ?? null))
      return $this->_registerError('Your session expired. Please try again.');

    if(empty($user->email))
      return $this->_registerError('Add your email address before registering an application.');

    $client_id = $this->_canonicalizeClientId($params['client_id'] ?? '');

    if($client_id === false)
      return $this->_registerError('That does not look like a URL. Enter the address of your application, such as https://example.com/');

    // The same rule the authorization endpoint applies, so that anything
    // registered here is something that will actually work there
    if(strpos($client_id, '.') === false && parse_url($client_id, PHP_URL_HOST) != 'localhost')
      return $this->_registerError('The client ID must be a full URL including a domain name.');

    if(ORM::for_table('clients')->where('client_id', $client_id)->find_one())
      return $this->_registerError('<code>'.e($client_id).'</code> is already registered.');

    $client = ORM::for_table('clients')->create();
    $client->client_id = $client_id;
    $client->user_id = $user->id;
    $client->date_created = date('Y-m-d H:i:s');
    $client->active = 1;
    $client->save();

    make_logger('user')->info('Developer registered a client', ['url' => $user->url, 'client_id' => $client_id]);

    return $this->_flash('developer_success', 'Registered <code>'.e($client_id).'</code>', '/developers/clients');
  }

  /**
   * Delete a client that has never been used.
   *
   * Every condition is re-checked here rather than trusted from the form,
   * since the button being absent from a row is only a hint.
   */
  public function delete(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $user = $this->_currentUser();
    if(!$user)
      return redirect_response('/developers');

    $params = $request->getParsedBody();

    if(!csrf_valid($params['csrf'] ?? null))
      return $this->_registerError('Your session expired. Please try again.');

    $client = ORM::for_table('clients')
      ->where('id', (int)($params['id'] ?? 0))
      ->where('user_id', $user->id)
      ->find_one();

    if(!$client)
      return $this->_registerError('That application was not found.');

    // Deleting this would take the developer area and the home page demo
    // down with it, and it could not be registered again without signing in
    if($client->client_id === getenv('BASE_URL'))
      return $this->_registerError('<code>'.e($client->client_id).'</code> is this site itself and cannot be deleted here.');

    if($client->date_last_used)
      return $this->_registerError('<code>'.e($client->client_id).'</code> has been used to sign someone in, so it can no longer be deleted.');

    $client_id = $client->client_id;

    ORM::for_table('redirect_uris')->where('client_id', $client->id)->delete_many();
    $client->delete();

    make_logger('user')->info('Developer deleted a client', ['url' => $user->url, 'client_id' => $client_id]);

    return $this->_flash('developer_success', 'Deleted <code>'.e($client_id).'</code>', '/developers/clients');
  }

  public function profile(ServerRequestInterface $request): ResponseInterface {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $user = $this->_currentUser();
    if(!$user)
      return redirect_response('/developers');

    $params = $request->getParsedBody();

    if(!csrf_valid($params['csrf'] ?? null))
      return $this->_registerError('Your session expired. Please try again.');

    $email = trim($params['email'] ?? '');

    if(!filter_var($email, FILTER_VALIDATE_EMAIL))
      return $this->_registerError('That does not look like an email address.');

    $user->email = $email;
    $user->save();

    return $this->_flash('developer_success', 'Saved your email address.', '/developers/clients');
  }

  /**
   * What the developer area looks like when a deployment has turned
   * registration off: as though the routes were never mapped.
   */
  private function _notFound(): ResponseInterface {
    return new HtmlResponse(view('http-error', [
      'title' => '404 Not Found',
      'status' => 404,
      'message' => 'Not Found',
    ]), 404);
  }

  private function _currentUser() {
    if(empty($_SESSION['developer_user_id']))
      return false;

    return ORM::for_table('users')->where('id', $_SESSION['developer_user_id'])->find_one();
  }

  private function _findOrCreateUser($url) {
    // Match the form the existing client owners were migrated as, so that
    // clients registered by hand before this existed show up straight away
    $url = \IndieAuth\Client::normalizeMeURL($url);

    $user = ORM::for_table('users')->where('url', $url)->find_one();

    if(!$user) {
      $user = ORM::for_table('users')->create();
      $user->url = $url;
      $user->date_created = date('Y-m-d H:i:s');
    }

    $user->date_last_login = date('Y-m-d H:i:s');
    $user->save();

    return $user;
  }

  /**
   * The exact string an application will have to send as its client_id.
   *
   * Lookups at the authorization endpoint are an exact string match, so this
   * settles the spellings that would otherwise silently fail to match: a
   * missing scheme, a capitalised host, and an empty path. Every client ID
   * registered by hand so far already has a path, so adding the trailing
   * slash matches what is there.
   *
   * @return string|false
   */
  private function _canonicalizeClientId($url) {
    if(!is_string($url))
      return false;

    $url = trim($url);

    if($url === '')
      return false;

    if(strpos($url, '://') !== false) {
      // Anything that names a scheme has to name one we can actually fetch
      if(!preg_match('/^https?:\/\//i', $url))
        return false;
    } else {
      // No scheme, so assume https, the way the sign-in form does for a
      // person's own URL. Reject a scheme with no authority (javascript:,
      // mailto:, data:) rather than prefixing it into nonsense, while still
      // allowing a bare host:port.
      if(preg_match('/^[a-z][a-z0-9+.\-]*:(?!\d)/i', $url))
        return false;

      $url = 'https://'.$url;
    }

    $parts = parse_url($url);

    if($parts === false || empty($parts['host']))
      return false;

    $parts['scheme'] = strtolower($parts['scheme']);
    $parts['host'] = strtolower($parts['host']);

    if(empty($parts['path']))
      $parts['path'] = '/';

    // Fragments are never sent to a server, so they cannot be part of an
    // identifier that has to match byte for byte
    unset($parts['fragment']);

    $url = \p3k\url\build_url($parts);

    return \p3k\url\is_url($url) ? $url : false;
  }

  private function _registerError($message) {
    return $this->_flash('developer_error', $message, '/developers/clients');
  }

  private function _flash($key, $message, $url) {
    $_SESSION[$key] = $message;
    return redirect_response($url);
  }

  private function _takeFlash($key) {
    $message = $_SESSION[$key] ?? false;
    unset($_SESSION[$key]);
    return $message;
  }

}
