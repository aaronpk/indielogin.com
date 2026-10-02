<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * The sign-in log.
 *
 * @var array $filters    client_id, me, provider, complete
 * @var array $logins
 * @var array $client_ids client_id URL => clients.id
 * @var int   $page
 * @var bool  $more
 */
$filtered = array_filter($filters, fn($v) => $v !== '');
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <form action="/admin/logins" method="get" class="mb-3">
    <div class="form-row">
      <div class="col-md-4 mb-2">
        <label class="small mb-0" for="client_id">Client ID</label>
        <input id="client_id" type="text" name="client_id" class="form-control form-control-sm" value="<?= e($filters['client_id']) ?>" placeholder="https://example.com/">
      </div>
      <div class="col-md-4 mb-2">
        <label class="small mb-0" for="me">Signed in as</label>
        <input id="me" type="text" name="me" class="form-control form-control-sm" value="<?= e($filters['me']) ?>" placeholder="https://person.example/">
      </div>
      <div class="col-md-2 mb-2">
        <label class="small mb-0" for="provider">Provider</label>
        <input id="provider" type="text" name="provider" class="form-control form-control-sm" value="<?= e($filters['provider']) ?>" placeholder="github">
      </div>
      <div class="col-md-2 mb-2">
        <label class="small mb-0" for="complete">Completed</label>
        <select id="complete" name="complete" class="form-control form-control-sm">
          <option value="">Either</option>
          <option value="1"<?= $filters['complete'] === '1' ? ' selected' : '' ?>>Yes</option>
          <option value="0"<?= $filters['complete'] === '0' ? ' selected' : '' ?>>No</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
    <?php if($filtered): ?>
      <a href="/admin/logins" class="btn btn-sm btn-link">Clear</a>
    <?php endif ?>
    <span class="small text-muted ml-2">Each filter is an exact match.</span>
  </form>

  <?php $this->insert('admin/_logins', compact('logins', 'client_ids')) ?>

  <?php $this->insert('admin/_pager', compact('page', 'more')) ?>

</div>
