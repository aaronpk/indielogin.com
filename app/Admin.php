<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;

use ORM;

/**
 * The admin section: how the service as a whole is doing, who owns what, and
 * what happened when someone tried to sign in.
 *
 * Who may see it is set by ADMIN_USERS, matched against the URL someone signed
 * in to the developer area with. For everyone else, signed in or not, every
 * path here is a 404, so the section leaves no trace for the people it is not
 * for. To get in, sign in at /developers first.
 *
 * Unlike webmention.io's, this one can change things: a client's active flag,
 * whether it must use PKCE, and its registered redirect URIs, all of which
 * otherwise had to be set by hand in the database. Every change is a POST
 * checked against the session's CSRF token and written to the user log.
 */
class Admin {

  const PER_PAGE = 50;
  const RECENT_LOGINS = 25;
  const TOP_CLIENTS = 20;
  const DAYS = 30;

  // The overview counts touch every recent row of logins, and nothing on it
  // needs to be fresher than this
  const OVERVIEW_CACHE_KEY = 'indielogin:admin:overview';
  const OVERVIEW_CACHE_TTL = 60;

  public function overview(ServerRequestInterface $request): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    return $this->_page('admin/overview', 'Admin', 'overview', $admin, $this->_overviewStats());
  }

  public function clients(ServerRequestInterface $request): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $params = $request->getQueryParams();
    $q = trim((string)($params['q'] ?? ''));
    $page = $this->_pageNumber($params);

    $query = ORM::for_table('clients')
      ->select('clients.*')
      ->select('users.url', 'owner_url')
      ->left_outer_join('users', ['users.id', '=', 'clients.user_id']);

    if($q !== '') {
      $like = '%'.$this->_escapeLike($q).'%';
      $query->where_raw('(clients.client_id LIKE ? OR users.url LIKE ? OR clients.email LIKE ? OR users.email LIKE ?)', [$like, $like, $like, $like]);
    }

    $clients = $query
      ->order_by_expr('clients.date_last_used IS NULL')
      ->order_by_desc('clients.date_last_used')
      ->order_by_desc('clients.id')
      ->limit(self::PER_PAGE + 1)
      ->offset(($page - 1) * self::PER_PAGE)
      ->find_array();

    $more = count($clients) > self::PER_PAGE;
    $clients = array_slice($clients, 0, self::PER_PAGE);

    $recent = $this->_loginCountsByClient(array_column($clients, 'client_id'));
    foreach($clients as &$client)
      $client['recent_logins'] = $recent[$client['client_id']] ?? 0;
    unset($client);

    return $this->_page('admin/clients', 'Clients · Admin', 'clients', $admin, [
      'q' => $q,
      'clients' => $clients,
      'page' => $page,
      'more' => $more,
      'days' => self::DAYS,
    ]);
  }

  public function client(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $client = $this->_findClient($args);
    if(!$client)
      return $this->_notFound();

    $owner = $client->user_id
      ? ORM::for_table('users')->where('id', $client->user_id)->find_one()
      : false;

    return $this->_page('admin/client', $client->client_id.' · Admin', 'clients', $admin, [
      'client' => $client,
      'owner' => $owner,
      'is_self' => $client->client_id === getenv('BASE_URL'),
      'redirect_uris' => ORM::for_table('redirect_uris')
        ->where('client_id', $client->id)
        ->order_by_asc('redirect_uri')
        ->find_many(),
      'logins' => ORM::for_table('logins')
        ->where('client_id', $client->client_id)
        ->order_by_desc('id')
        ->limit(self::RECENT_LOGINS)
        ->find_many(),
      'recent_logins' => $this->_loginCountsByClient([$client->client_id])[$client->client_id] ?? 0,
      'days' => self::DAYS,
    ]);
  }

  public function client_active(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($client = $this->_clientForWrite($request, $args, $admin)) instanceof ResponseInterface)
      return $client;

    $active = ($request->getParsedBody()['active'] ?? '') === '1';

    // Deactivating this site's own client would take the developer area down
    // with it, and with it the only way back in to undo this
    if(!$active && $client->client_id === getenv('BASE_URL'))
      return $this->_clientFlash($client, 'admin_error', '<code>'.e($client->client_id).'</code> is this site itself and cannot be deactivated here.');

    $client->active = $active ? 1 : 0;
    $client->save();

    make_logger('user')->info('Admin '.($active ? 'activated' : 'deactivated').' a client', ['admin' => $admin->url, 'client_id' => $client->client_id]);

    return $this->_clientFlash($client, 'admin_success', ($active ? 'Activated' : 'Deactivated').' <code>'.e($client->client_id).'</code>');
  }

  public function client_pkce(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($client = $this->_clientForWrite($request, $args, $admin)) instanceof ResponseInterface)
      return $client;

    $required = ($request->getParsedBody()['pkce_required'] ?? '') === '1';

    $client->pkce_required = $required ? 1 : 0;
    $client->save();

    make_logger('user')->info('Admin '.($required ? 'required' : 'stopped requiring').' PKCE for a client', ['admin' => $admin->url, 'client_id' => $client->client_id]);

    return $this->_clientFlash($client, 'admin_success', 'PKCE is '.($required ? 'now required' : 'no longer required').' for <code>'.e($client->client_id).'</code>');
  }

  public function redirect_uri_add(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($client = $this->_clientForWrite($request, $args, $admin)) instanceof ResponseInterface)
      return $client;

    // Kept exactly as typed: the authorization endpoint compares a registered
    // redirect URI byte for byte with the one in the request
    $redirect_uri = trim((string)($request->getParsedBody()['redirect_uri'] ?? ''));

    if(!preg_match('/^https?:\/\//i', $redirect_uri) || !\p3k\url\is_url($redirect_uri))
      return $this->_clientFlash($client, 'admin_error', 'That is not a complete http or https URL.');

    $exists = ORM::for_table('redirect_uris')
      ->where('client_id', $client->id)
      ->where('redirect_uri', $redirect_uri)
      ->find_one();

    if($exists)
      return $this->_clientFlash($client, 'admin_error', '<code>'.e($redirect_uri).'</code> is already registered for this client.');

    $row = ORM::for_table('redirect_uris')->create();
    $row->client_id = $client->id;
    $row->redirect_uri = $redirect_uri;
    $row->save();

    make_logger('user')->info('Admin added a redirect URI', ['admin' => $admin->url, 'client_id' => $client->client_id, 'redirect_uri' => $redirect_uri]);

    return $this->_clientFlash($client, 'admin_success', 'Added <code>'.e($redirect_uri).'</code>');
  }

  public function redirect_uri_delete(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($client = $this->_clientForWrite($request, $args, $admin)) instanceof ResponseInterface)
      return $client;

    $row = ORM::for_table('redirect_uris')
      ->where('id', (int)($request->getParsedBody()['id'] ?? 0))
      ->where('client_id', $client->id)
      ->find_one();

    if(!$row)
      return $this->_clientFlash($client, 'admin_error', 'That redirect URI was not found.');

    $redirect_uri = $row->redirect_uri;
    $row->delete();

    make_logger('user')->info('Admin removed a redirect URI', ['admin' => $admin->url, 'client_id' => $client->client_id, 'redirect_uri' => $redirect_uri]);

    return $this->_clientFlash($client, 'admin_success', 'Removed <code>'.e($redirect_uri).'</code>');
  }

  public function users(ServerRequestInterface $request): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $params = $request->getQueryParams();
    $q = trim((string)($params['q'] ?? ''));
    $page = $this->_pageNumber($params);

    $query = ORM::for_table('users')
      ->select('users.*')
      ->select_expr('(SELECT COUNT(*) FROM clients WHERE clients.user_id = users.id)', 'clients');

    if($q !== '') {
      $like = '%'.$this->_escapeLike($q).'%';
      $query->where_raw('(users.url LIKE ? OR users.email LIKE ?)', [$like, $like]);
    }

    $users = $query
      ->order_by_expr('COALESCE(users.date_last_login, users.date_created) IS NULL')
      ->order_by_expr('COALESCE(users.date_last_login, users.date_created) DESC')
      ->order_by_desc('users.id')
      ->limit(self::PER_PAGE + 1)
      ->offset(($page - 1) * self::PER_PAGE)
      ->find_array();

    $more = count($users) > self::PER_PAGE;

    return $this->_page('admin/users', 'Users · Admin', 'users', $admin, [
      'q' => $q,
      'users' => array_slice($users, 0, self::PER_PAGE),
      'page' => $page,
      'more' => $more,
    ]);
  }

  public function user(ServerRequestInterface $request, array $args): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $user = ORM::for_table('users')->where('id', (int)($args['id'] ?? 0))->find_one();
    if(!$user)
      return $this->_notFound();

    $clients = ORM::for_table('clients')
      ->where('user_id', $user->id)
      ->order_by_desc('date_last_used')
      ->order_by_desc('id')
      ->find_array();

    $recent = $this->_loginCountsByClient(array_column($clients, 'client_id'));
    foreach($clients as &$client)
      $client['recent_logins'] = $recent[$client['client_id']] ?? 0;
    unset($client);

    return $this->_page('admin/user', $user->url.' · Admin', 'users', $admin, [
      'user' => $user,
      'is_admin_user' => is_admin_url($user->url),
      'clients' => $clients,
      'logins' => ORM::for_table('logins')
        ->where('me_resolved', $user->url)
        ->order_by_desc('id')
        ->limit(self::RECENT_LOGINS)
        ->find_many(),
      'days' => self::DAYS,
    ]);
  }

  public function logins(ServerRequestInterface $request): ResponseInterface {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $params = $request->getQueryParams();
    $page = $this->_pageNumber($params);

    $filters = [
      'client_id' => trim((string)($params['client_id'] ?? '')),
      'me' => trim((string)($params['me'] ?? '')),
      'provider' => trim((string)($params['provider'] ?? '')),
      'complete' => in_array($params['complete'] ?? '', ['0', '1'], true) ? $params['complete'] : '',
    ];

    // Each filter is an exact match, so that it can use an index on a table
    // that is never pruned
    $query = ORM::for_table('logins');

    if($filters['client_id'] !== '')
      $query->where('client_id', $filters['client_id']);

    if($filters['me'] !== '') {
      // Accept the URL however it was typed, as well as the form a sign-in
      // would have stored it in
      $me = array_values(array_unique(array_filter([$filters['me'], normalize_me_url($filters['me'])])));
      $query->where_in('me_resolved', $me);
    }

    if($filters['provider'] !== '')
      $query->where('authn_provider', $filters['provider']);

    if($filters['complete'] !== '')
      $query->where('complete', (int)$filters['complete']);

    $logins = $query
      ->order_by_desc('id')
      ->limit(self::PER_PAGE + 1)
      ->offset(($page - 1) * self::PER_PAGE)
      ->find_many();

    $more = count($logins) > self::PER_PAGE;
    $logins = array_slice($logins, 0, self::PER_PAGE);

    return $this->_page('admin/logins', 'Sign-ins · Admin', 'logins', $admin, [
      'filters' => $filters,
      'logins' => $logins,
      'client_ids' => $this->_clientIdsByUrl(array_map(fn($l) => $l->client_id, $logins)),
      'page' => $page,
      'more' => $more,
    ]);
  }

  /**
   * The signed-in admin, or the response to send instead: a 404 for anyone
   * who is not one, signed in or not, so that the section is invisible rather
   * than merely closed.
   */
  private function _admin() {
    if(!client_registration_enabled())
      return $this->_notFound();

    session_start();

    $user = current_developer();
    if(!$user || !is_admin_url($user->url))
      return $this->_notFound();

    return $user;
  }

  /**
   * Everything a change to a client has to get past before it is made: an
   * admin, a valid CSRF token, and a client that exists. Returns the client,
   * or the response to send instead.
   */
  private function _clientForWrite(ServerRequestInterface $request, array $args, &$admin) {
    if(($admin = $this->_admin()) instanceof ResponseInterface)
      return $admin;

    $client = $this->_findClient($args);
    if(!$client)
      return $this->_notFound();

    if(!csrf_valid($request->getParsedBody()['csrf'] ?? null))
      return $this->_clientFlash($client, 'admin_error', 'Your session expired. Please try again.');

    return $client;
  }

  private function _findClient(array $args) {
    return ORM::for_table('clients')->where('id', (int)($args['id'] ?? 0))->find_one();
  }

  private function _overviewStats() {
    $cached = redis()->get(self::OVERVIEW_CACHE_KEY);
    if($cached && is_array($stats = json_decode($cached, true)))
      return $stats;

    $now = time();
    $since = date('Y-m-d H:i:s', $now - 86400 * self::DAYS);

    $windows = [];
    foreach([
      'Today' => date('Y-m-d 00:00:00', $now),
      'Last 7 days' => date('Y-m-d H:i:s', $now - 86400 * 7),
      'Last '.self::DAYS.' days' => $since,
    ] as $label => $from) {
      $row = ORM::for_table('logins')
        ->select_expr('COUNT(*)', 'total')
        ->select_expr('COALESCE(SUM(complete), 0)', 'completed')
        ->where_gte('date', $from)
        ->find_one();
      $windows[] = ['label' => $label, 'total' => (int)$row->total, 'completed' => (int)$row->completed];
    }

    // One row for every day, including the ones nobody signed in on
    $days = [];
    for($i = self::DAYS - 1; $i >= 0; $i--)
      $days[date('Y-m-d', $now - 86400 * $i)] = ['total' => 0, 'completed' => 0];

    $rows = ORM::for_table('logins')
      ->select_expr('DATE(date)', 'day')
      ->select_expr('COUNT(*)', 'total')
      ->select_expr('COALESCE(SUM(complete), 0)', 'completed')
      ->where_gte('date', date('Y-m-d 00:00:00', $now - 86400 * (self::DAYS - 1)))
      ->group_by_expr('DATE(date)')
      ->find_array();
    foreach($rows as $row) {
      if(isset($days[$row['day']]))
        $days[$row['day']] = ['total' => (int)$row['total'], 'completed' => (int)$row['completed']];
    }

    $providers = [];
    $rows = ORM::for_table('logins')
      ->select('authn_provider')
      ->select_expr('COUNT(*)', 'total')
      ->select_expr('COALESCE(SUM(complete), 0)', 'completed')
      ->where_gte('date', $since)
      ->group_by('authn_provider')
      ->order_by_desc('total')
      ->find_array();
    foreach($rows as $row)
      $providers[] = ['provider' => (string)$row['authn_provider'], 'total' => (int)$row['total'], 'completed' => (int)$row['completed']];

    $top = [];
    $rows = ORM::for_table('logins')
      ->select('client_id')
      ->select_expr('COUNT(*)', 'total')
      ->select_expr('COALESCE(SUM(complete), 0)', 'completed')
      ->where_gte('date', $since)
      ->group_by('client_id')
      ->order_by_desc('total')
      ->limit(self::TOP_CLIENTS)
      ->find_array();
    $ids = $this->_clientIdsByUrl(array_column($rows, 'client_id'));
    foreach($rows as $row)
      $top[] = ['client_id' => (string)$row['client_id'], 'id' => $ids[$row['client_id']] ?? null, 'total' => (int)$row['total'], 'completed' => (int)$row['completed']];

    $stats = [
      'days' => self::DAYS,
      'users' => [
        'total' => ORM::for_table('users')->count(),
        'recent' => ORM::for_table('users')->where_gte('date_last_login', $since)->count(),
      ],
      'clients' => [
        'total' => ORM::for_table('clients')->count(),
        'inactive' => ORM::for_table('clients')->where('active', 0)->count(),
        'recent' => ORM::for_table('clients')->where_gte('date_last_used', $since)->count(),
        'no_pkce' => ORM::for_table('clients')->where('pkce_required', 0)->count(),
      ],
      'windows' => $windows,
      'per_day' => $days,
      'providers' => $providers,
      'top_clients' => $top,
      'counted_at' => date('Y-m-d H:i:s', $now),
    ];

    redis()->setex(self::OVERVIEW_CACHE_KEY, self::OVERVIEW_CACHE_TTL, json_encode($stats));

    return $stats;
  }

  /**
   * Sign-ins over the last DAYS days for each of these client IDs, in one
   * query. Clients with none are left out.
   */
  private function _loginCountsByClient(array $client_ids) {
    $client_ids = array_values(array_unique(array_filter($client_ids, 'strlen')));

    if(!$client_ids)
      return [];

    $rows = ORM::for_table('logins')
      ->select('client_id')
      ->select_expr('COUNT(*)', 'total')
      ->where_in('client_id', $client_ids)
      ->where_gte('date', date('Y-m-d H:i:s', time() - 86400 * self::DAYS))
      ->group_by('client_id')
      ->find_array();

    return array_column(array_map(fn($r) => [$r['client_id'], (int)$r['total']], $rows), 1, 0);
  }

  /**
   * client_id URL => clients.id for the ones that are registered, so that a
   * client ID on a page of sign-ins can link to its client.
   */
  private function _clientIdsByUrl(array $client_ids) {
    $client_ids = array_values(array_unique(array_filter($client_ids, fn($c) => is_string($c) && $c !== '')));

    if(!$client_ids)
      return [];

    $rows = ORM::for_table('clients')
      ->select('id')
      ->select('client_id')
      ->where_in('client_id', $client_ids)
      ->find_array();

    return array_column($rows, 'id', 'client_id');
  }

  private function _page($template, $title, $tab, $admin, array $data) {
    return new HtmlResponse(view($template, array_merge([
      'title' => $title,
      'tab' => $tab,
      'admin' => $admin,
      'csrf' => csrf_token(),
      'error' => $this->_takeFlash('admin_error'),
      'success' => $this->_takeFlash('admin_success'),
    ], $data)));
  }

  private function _pageNumber(array $params) {
    return max(1, (int)($params['page'] ?? 1));
  }

  private function _escapeLike($text) {
    return addcslashes($text, '\\%_');
  }

  private function _notFound(): ResponseInterface {
    return new HtmlResponse(view('http-error', [
      'title' => '404 Not Found',
      'status' => 404,
      'message' => 'Not Found',
    ]), 404);
  }

  private function _clientFlash($client, $key, $message) {
    $_SESSION[$key] = $message;
    return redirect_response('/admin/clients/'.$client->id);
  }

  private function _takeFlash($key) {
    $message = $_SESSION[$key] ?? false;
    unset($_SESSION[$key]);
    return $message;
  }

}
