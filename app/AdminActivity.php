<?php
namespace App;

use ORM;

/**
 * How the service has been used over time, month by month: sign-ins, the
 * people and clients behind them, who was new, which providers they used,
 * and how many developers and clients registered.
 *
 * logins is never pruned, so this is built to count each month once. Months
 * are folded in order, oldest first: a month that has ended is counted with
 * range queries on logins.date, kept in Redis for good, and the people who
 * signed in that month are added to a set of everyone seen so far. Whether
 * someone is new in a later month is then a lookup in that set, rather than
 * a search back through every earlier sign-in, which on a large table takes
 * longer than a page load can.
 *
 * Only the month in progress is counted again, at most every CURRENT_TTL
 * seconds. The first time, every month back to the first sign-in has to be
 * folded before the recent ones can be: a page load does up to BUDGET seconds
 * of that and says how far it got, and bin/count-activity does all of it.
 */
class AdminActivity {

  // Bump this if what a month's figures mean changes, so that everything is
  // counted again under the new meaning
  const PREFIX = 'indielogin:admin:activity:v1';

  const CURRENT_TTL = 600;

  // Seconds a page load may spend folding months that have not been counted
  const BUDGET = 20;

  // Long enough to fold one month on a large table
  const LOCK_TTL = 120;

  // A code is good for 60 seconds, so a sign-in at the very end of a month
  // can still be completed just after it. Give it that long before keeping
  // the month for good.
  const SETTLE = 120;

  // The providers that get a series of their own, in the order their colors
  // are assigned. Each keeps its color whatever range is shown; any other
  // provider, past or future, is counted as "other".
  const PROVIDERS = [
    'indieauth' => 'IndieAuth',
    'github' => 'GitHub',
    'email' => 'Email',
    'session' => 'Remembered',
    'twitter' => 'Twitter',
    'gitlab' => 'GitLab',
    'codeberg' => 'Codeberg',
    'atproto' => 'ATProto',
  ];

  // Spellings that are the same provider for this purpose
  const PROVIDER_ALIASES = [
    'atproto_rel' => 'atproto',
  ];

  const RANGES = [12, 24, 60];

  /**
   * @param int|string $range a number of months, or 'all'
   */
  public function report($range) {
    $first = $this->_firstMonth();
    $months = $this->_months($range, $first);

    // Months have to be folded in order, so anything still to do comes
    // before the ones on screen
    $this->advance(self::BUDGET);
    $pending = $this->pending();

    $rows = [];
    if(!$pending) {
      $stored = redis()->hgetall($this->_key('months'));
      foreach($months as $month) {
        $rows[$month] = isset($stored[$month])
          ? json_decode($stored[$month], true)
          : $this->_live($month);
      }

      $registered = $this->_registeredPerMonth($months);
      foreach($rows as $month => &$row) {
        $row['new_developers'] = $registered['users'][$month] ?? 0;
        $row['new_clients'] = $registered['clients'][$month] ?? 0;
      }
      unset($row);
    }

    return [
      'range' => $range,
      'ranges' => self::RANGES,
      'first_month' => $first,
      'months' => $rows,
      'pending' => $pending,
      'counted' => count($this->_settledMonths()) - $pending,
      'providers' => self::PROVIDERS,
      'current_month' => $this->_currentMonth(),
      'current_ttl' => self::CURRENT_TTL,
    ];
  }

  /**
   * Fold months that have ended, oldest first, for up to $seconds. Returns
   * how many it folded, or false if another process is already folding.
   */
  public function advance($seconds, ?callable $progress = null) {
    $lock = $this->_key('lock');
    $token = random_string();

    // Held for a short while and renewed after every month, so that a run
    // that dies leaves it behind for no longer than that
    if(!redis()->set($lock, $token, 'EX', self::LOCK_TTL, 'NX'))
      return false;

    $start = microtime(true);
    $folded = 0;

    try {
      foreach($this->_unfolded() as $month) {
        if(microtime(true) - $start > $seconds)
          break;

        $this->_fold($month);
        $folded++;

        if(redis()->get($lock) === $token)
          redis()->expire($lock, self::LOCK_TTL);

        if($progress)
          $progress($month);
      }
    } finally {
      // Only release the lock if it is still ours
      if(redis()->get($lock) === $token)
        redis()->del($lock);
    }

    return $folded;
  }

  /**
   * How many months that have ended are not counted yet.
   */
  public function pending() {
    return count($this->_unfolded());
  }

  /**
   * Count one month that has ended, and add the people in it to the set of
   * everyone seen.
   */
  private function _fold($month) {
    [$from, $to] = $this->_bounds($month);

    $row = $this->_totals($from, $to);
    $people = $this->_peopleIn($from, $to);
    $row['new_people'] = $this->_countUnseen($people);

    foreach(array_chunk($people, 1000) as $chunk)
      redis()->sadd($this->_key('seen'), $chunk);

    redis()->hset($this->_key('months'), $month, json_encode($row));
    redis()->set($this->_key('through'), $month);
  }

  /**
   * A month that is not folded: the one in progress, or for a couple of
   * minutes after a month ends, the one just gone. Counted at most every
   * CURRENT_TTL seconds.
   */
  private function _live($month) {
    $key = $this->_key('live:'.$month);

    $cached = redis()->get($key);
    if($cached && is_array($row = json_decode($cached, true)))
      return $row;

    [$from, $to] = $this->_bounds($month);

    $row = $this->_totals($from, $to);
    $row['new_people'] = $this->_countUnseen($this->_peopleIn($from, $to));

    redis()->setex($key, self::CURRENT_TTL, json_encode($row));

    return $row;
  }

  private function _totals($from, $to) {
    $totals = ORM::for_table('logins')
      ->select_expr('COUNT(*)', 'signins')
      ->select_expr('COALESCE(SUM(complete), 0)', 'completed')
      ->select_expr("COUNT(DISTINCT NULLIF(me_resolved, ''))", 'people')
      ->select_expr("COUNT(DISTINCT NULLIF(client_id, ''))", 'clients')
      ->where_gte('date', $from)
      ->where_lt('date', $to)
      ->find_one();

    $providers = array_fill_keys(array_keys(self::PROVIDERS), 0);
    $providers['other'] = 0;

    $rows = ORM::for_table('logins')
      ->select('authn_provider')
      ->select_expr('COUNT(*)', 'n')
      ->where_gte('date', $from)
      ->where_lt('date', $to)
      ->group_by('authn_provider')
      ->find_array();

    foreach($rows as $r) {
      $name = strtolower((string)$r['authn_provider']);
      $name = self::PROVIDER_ALIASES[$name] ?? $name;
      $providers[isset(self::PROVIDERS[$name]) ? $name : 'other'] += (int)$r['n'];
    }

    return [
      'signins' => (int)$totals->signins,
      'completed' => (int)$totals->completed,
      'people' => (int)$totals->people,
      'clients' => (int)$totals->clients,
      'providers' => $providers,
    ];
  }

  /**
   * Everyone who signed in between these times, as the short hashes the set
   * of everyone seen is kept in. A URL can be long, and the set only ever
   * has to answer whether one is in it.
   */
  private function _peopleIn($from, $to) {
    $rows = ORM::for_table('logins')
      ->distinct()
      ->select('me_resolved')
      ->where_gte('date', $from)
      ->where_lt('date', $to)
      ->where_not_equal('me_resolved', '')
      ->find_array();

    return array_values(array_unique(array_map(fn($r) => substr(sha1($r['me_resolved']), 0, 16), $rows)));
  }

  private function _countUnseen(array $people) {
    $unseen = 0;

    foreach(array_chunk($people, 1000) as $chunk) {
      foreach(redis()->smismember($this->_key('seen'), ...$chunk) as $seen)
        if(!$seen) $unseen++;
    }

    return $unseen;
  }

  /**
   * Developer accounts and clients created per month. Both tables are small,
   * so these are counted fresh every time.
   */
  private function _registeredPerMonth(array $months) {
    if(!$months)
      return ['users' => [], 'clients' => []];

    $from = reset($months).'-01 00:00:00';
    $out = [];

    foreach(['users', 'clients'] as $table) {
      $rows = ORM::for_table($table)
        ->select_expr("DATE_FORMAT(date_created, '%Y-%m')", 'month')
        ->select_expr('COUNT(*)', 'n')
        ->where_gte('date_created', $from)
        ->group_by_expr("DATE_FORMAT(date_created, '%Y-%m')")
        ->find_array();
      $out[$table] = array_column(array_map(fn($r) => [$r['month'], (int)$r['n']], $rows), 1, 0);
    }

    return $out;
  }

  /**
   * Months that have ended and settled but are not folded yet, oldest first.
   */
  private function _unfolded() {
    $through = redis()->get($this->_key('through'));

    return array_values(array_filter($this->_settledMonths(), fn($m) => !$through || $m > $through));
  }

  /**
   * Every month from the first sign-in up to the last one that has ended and
   * settled.
   */
  private function _settledMonths() {
    $months = [];
    $current = $this->_currentMonth();

    for($t = strtotime($this->_firstMonth().'-01 00:00:00 UTC'); gmdate('Y-m', $t) < $current; $t = strtotime('+1 month', $t)) {
      $month = gmdate('Y-m', $t);
      if($this->_bounds($month, true)[1] + self::SETTLE <= time())
        $months[] = $month;
    }

    return $months;
  }

  /**
   * The month of the oldest sign-in on record, or this month if there are
   * none. Ids and dates grow together, so the lowest id is the oldest row.
   */
  private function _firstMonth() {
    static $first = null;

    if($first === null) {
      $row = ORM::for_table('logins')->select('date')->where_not_null('date')->order_by_asc('id')->limit(1)->find_one();
      $first = ($row && $row->date) ? substr($row->date, 0, 7) : $this->_currentMonth();
    }

    return $first;
  }

  /**
   * The months to show, oldest first, ending with the current one and never
   * reaching back before the first sign-in.
   */
  private function _months($range, $first) {
    $current = $this->_currentMonth();

    if($range === 'all') {
      $start = $first;
    } else {
      $start = gmdate('Y-m', strtotime($current.'-01 00:00:00 UTC -'.((int)$range - 1).' months'));
      if($start < $first)
        $start = $first;
    }

    $months = [];
    for($t = strtotime($start.'-01 00:00:00 UTC'); gmdate('Y-m', $t) <= $current; $t = strtotime('+1 month', $t))
      $months[] = gmdate('Y-m', $t);

    return $months;
  }

  /**
   * The first moment of a month and of the one after it, as datetimes for a
   * query or, with $timestamps, as Unix times.
   */
  private function _bounds($month, $timestamps = false) {
    $from = strtotime($month.'-01 00:00:00 UTC');
    $to = strtotime('+1 month', $from);

    return $timestamps ? [$from, $to] : [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)];
  }

  private function _currentMonth() {
    return gmdate('Y-m');
  }

  private function _key($name) {
    return self::PREFIX.':'.$name;
  }

}
