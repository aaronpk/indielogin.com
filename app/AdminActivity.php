<?php
namespace App;

use ORM;

/**
 * How the service has been used over time, month by month: sign-ins, the
 * people and clients behind them, who was new, which providers they used,
 * and how many developers and clients registered.
 *
 * logins is never pruned, so this is built to count each month once. Months
 * are folded in order, oldest first. A month that has ended is counted with
 * range queries on logins.date into a row of activity_months, and the people
 * who signed in that month go into activity_people, in the same transaction.
 * activity_people has one row per person ever seen, so whether someone is new
 * is whether inserting them adds a row, rather than a search back through
 * every earlier sign-in, which on a large table takes longer than a page
 * load can.
 *
 * Only a month that is not folded, the one in progress, is counted again, at
 * most every LIVE_TTL seconds, and that count is the one thing kept in Redis.
 * The first time, every month back to the first sign-in has to be folded
 * before the recent ones can be: a page load does up to BUDGET seconds of
 * that and says how far it got, and bin/count-activity does all of it.
 *
 * Everything here is derived from logins. To count it all again, empty
 * activity_months and activity_people.
 */
class AdminActivity {

  const LIVE_TTL = 600;

  // Seconds a page load may spend folding months that have not been counted
  const BUDGET = 20;

  // A MySQL named lock, which the server releases by itself if whatever held
  // it goes away
  const LOCK = 'indielogin.admin.activity';

  // A code is good for 60 seconds, so a sign-in at the very end of a month
  // can still be completed just after it. Give it that long before keeping
  // the month for good.
  const SETTLE = 120;

  // The providers that get a series of their own, in the order their colors
  // are assigned. The first eight take the eight colors of the palette; PGP,
  // the ninth, is a near-black neutral set apart from all of them by
  // lightness rather than by a ninth hue, which would be mistaken for one of
  // the others. Each keeps its color whatever range is shown; any other
  // provider, past or future, is counted as "other".
  //
  // Months keep their sign-ins by provider exactly as recorded, and are
  // grouped into these when shown, so this list can change without counting
  // anything again.
  const PROVIDERS = [
    'indieauth' => 'IndieAuth',
    'github' => 'GitHub',
    'email' => 'Email',
    'session' => 'Remembered',
    'twitter' => 'Twitter',
    'gitlab' => 'GitLab',
    'codeberg' => 'Codeberg',
    'atproto' => 'ATProto',
    'pgp' => 'PGP',
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
      $stored = [];
      foreach(ORM::for_table('activity_months')->where_gte('month', reset($months))->find_array() as $row)
        $stored[$row['month']] = $row;

      foreach($months as $month) {
        $row = isset($stored[$month]) ? $this->_fromRow($stored[$month]) : $this->_live($month);
        $row['providers'] = $this->_series($row['providers']);
        $rows[$month] = $row;
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
      'current_ttl' => self::LIVE_TTL,
    ];
  }

  /**
   * Fold months that have ended, oldest first, for up to $seconds. Returns
   * how many it folded, or false if another process is already folding.
   */
  public function advance($seconds, ?callable $progress = null) {
    $db = ORM::get_db();

    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([self::LOCK]);
    if((int)$lock->fetchColumn() !== 1)
      return false;

    $start = microtime(true);
    $folded = 0;

    try {
      foreach($this->_unfolded() as $month) {
        if(microtime(true) - $start > $seconds)
          break;

        $this->_fold($month);
        $folded++;

        if($progress)
          $progress($month);
      }
    } finally {
      $db->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK]);
    }

    return $folded;
  }

  /**
   * What is stored so far: how many months, the first and last of them, and
   * when the latest was counted. Null if nothing is.
   */
  public function stored() {
    $row = ORM::for_table('activity_months')
      ->select_expr('COUNT(*)', 'months')
      ->select_expr('MIN(month)', 'first')
      ->select_expr('MAX(month)', 'last')
      ->select_expr('MAX(date_counted)', 'counted')
      ->find_one();

    if(!$row || !(int)$row->months)
      return null;

    return [
      'months' => (int)$row->months,
      'first' => $row->first,
      'last' => $row->last,
      'counted' => $row->counted,
    ];
  }

  /**
   * How many months that have ended are not counted yet.
   */
  public function pending() {
    return count($this->_unfolded());
  }

  /**
   * Count one month that has ended: its row in activity_months and its new
   * people in activity_people, together or not at all.
   */
  private function _fold($month) {
    [$from, $to] = $this->_bounds($month);
    $db = ORM::get_db();

    $row = $this->_totals($from, $to);
    $people = $this->_peopleIn($from, $to);

    $db->beginTransaction();
    try {
      // INSERT IGNORE skips anyone already seen, so the rows it adds are
      // exactly this month's new people
      $new = 0;
      foreach(array_chunk($people, 1000) as $chunk) {
        $insert = $db->prepare('INSERT IGNORE INTO activity_people (person, first_month) VALUES '
          .implode(',', array_fill(0, count($chunk), '(UNHEX(?), ?)')));
        $params = [];
        foreach($chunk as $person)
          array_push($params, $person, $month);
        $insert->execute($params);
        $new += $insert->rowCount();
      }

      $insert = $db->prepare('INSERT INTO activity_months
        (month, signins, completed, people, new_people, clients, providers, date_counted)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
      $insert->execute([
        $month, $row['signins'], $row['completed'], $row['people'], $new, $row['clients'],
        json_encode($row['providers']), gmdate('Y-m-d H:i:s'),
      ]);

      $db->commit();
    } catch(\Throwable $e) {
      $db->rollBack();
      throw $e;
    }
  }

  /**
   * A month that is not folded: the one in progress, or for a couple of
   * minutes after a month ends, the one just gone. Counted at most every
   * LIVE_TTL seconds.
   */
  private function _live($month) {
    $key = 'indielogin:admin:activity:live:'.$month;

    $cached = redis()->get($key);
    if($cached && is_array($row = json_decode($cached, true)))
      return $row;

    [$from, $to] = $this->_bounds($month);

    $row = $this->_totals($from, $to);
    $row['new_people'] = $this->_countUnseen($this->_peopleIn($from, $to));

    redis()->setex($key, self::LIVE_TTL, json_encode($row));

    return $row;
  }

  /**
   * A stored month in the shape the page uses.
   */
  private function _fromRow(array $row) {
    return [
      'signins' => (int)$row['signins'],
      'completed' => (int)$row['completed'],
      'people' => (int)$row['people'],
      'new_people' => (int)$row['new_people'],
      'clients' => (int)$row['clients'],
      'providers' => json_decode($row['providers'], true) ?: [],
    ];
  }

  /**
   * Sign-ins by provider as recorded, grouped into the chart's series.
   */
  private function _series(array $raw) {
    $series = array_fill_keys(array_keys(self::PROVIDERS), 0);
    $series['other'] = 0;

    foreach($raw as $name => $n) {
      $name = strtolower((string)$name);
      $name = self::PROVIDER_ALIASES[$name] ?? $name;
      $series[isset(self::PROVIDERS[$name]) ? $name : 'other'] += (int)$n;
    }

    return $series;
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

    $providers = [];
    $rows = ORM::for_table('logins')
      ->select('authn_provider')
      ->select_expr('COUNT(*)', 'n')
      ->where_gte('date', $from)
      ->where_lt('date', $to)
      ->group_by('authn_provider')
      ->find_array();

    foreach($rows as $r) {
      $name = strtolower((string)$r['authn_provider']);
      $providers[$name] = ($providers[$name] ?? 0) + (int)$r['n'];
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
   * Everyone who signed in between these times, as the hex of the short
   * hashes activity_people keeps. A URL can be long, and the table only ever
   * has to answer whether one is in it.
   *
   * Each is lowercased and trimmed before it is hashed. MySQL compares
   * me_resolved without regard to case or trailing spaces, so it counts
   * https://Example.com/ and https://example.com/ as one person in a month;
   * hashed as written they would be two people across months, and the same
   * person would be counted as new again.
   */
  private function _peopleIn($from, $to) {
    $rows = ORM::for_table('logins')
      ->distinct()
      ->select('me_resolved')
      ->where_gte('date', $from)
      ->where_lt('date', $to)
      ->where_not_equal('me_resolved', '')
      ->find_array();

    return array_values(array_unique(array_map(fn($r) => substr(sha1(rtrim(mb_strtolower($r['me_resolved']))), 0, 16), $rows)));
  }

  private function _countUnseen(array $people) {
    $seen = 0;
    $db = ORM::get_db();

    foreach(array_chunk($people, 1000) as $chunk) {
      $query = $db->prepare('SELECT COUNT(*) FROM activity_people WHERE person IN ('
        .implode(',', array_fill(0, count($chunk), 'UNHEX(?)')).')');
      $query->execute($chunk);
      $seen += (int)$query->fetchColumn();
    }

    return count($people) - $seen;
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
   * Months are only ever folded in order, so the latest one stored is how far
   * folding has got.
   */
  private function _unfolded() {
    $through = ORM::for_table('activity_months')->max('month');

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

}
