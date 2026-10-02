<?php
/**
 * A table of sign-ins.
 *
 * @var array $logins     logins rows.
 * @var array $client_ids Optional: client_id URL => clients.id, for linking.
 */
$client_ids = $client_ids ?? [];
?>
<?php if(!count($logins)): ?>
  <p class="text-muted">No sign-ins.</p>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm admin-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Client</th>
          <th>Signed in as</th>
          <th>Provider</th>
          <th>Completed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($logins as $login): ?>
          <tr>
            <td class="text-nowrap"><?= $login->date ? e(display_date('Y-m-d H:i', $login->date)) : '' ?></td>
            <td>
              <?php if(isset($client_ids[$login->client_id])): ?>
                <a href="/admin/clients/<?= e($client_ids[$login->client_id]) ?>"><?= e($login->client_id ?? '') ?></a>
              <?php else: ?>
                <a href="/admin/logins?<?= e(http_build_query(['client_id' => $login->client_id])) ?>"><?= e($login->client_id ?? '') ?></a>
              <?php endif ?>
              <div class="small text-muted"><?= e($login->redirect_uri ?? '') ?></div>
            </td>
            <td>
              <a href="/admin/logins?<?= e(http_build_query(['me' => $login->me_resolved])) ?>"><?= e($login->me_resolved ?? '') ?></a>
              <?php if($login->me_entered && $login->me_entered !== $login->me_resolved): ?>
                <div class="small text-muted">entered <?= e($login->me_entered) ?></div>
              <?php endif ?>
            </td>
            <td>
              <?= e($login->authn_provider ?: '—') ?>
              <?php if($login->authn_profile): ?>
                <div class="small text-muted"><?= e($login->authn_profile) ?></div>
              <?php endif ?>
            </td>
            <td>
              <?php if($login->complete): ?>
                <span class="text-success" title="<?= e($login->date_complete ?? '') ?>">yes</span>
              <?php else: ?>
                <span class="text-muted">no</span>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
