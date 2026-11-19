<?php
/* Contact-form leads: pipeline status, notes, follow-ups, CSV export. */
defined('ADMIN') || exit;

const LEAD_FIELDS = ['name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', 'services' => 'Needs',
    'industry' => 'Industry', 'budget' => 'Budget', 'message' => 'Message'];

$sub = $seg[1] ?? '';

if ($sub === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="messages-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 (৳, Bangla) only with a BOM
    fputcsv($out, ['Date', ...array_values(LEAD_FIELDS), 'Status', 'Follow-up', 'Source', 'Medium', 'Campaign']);
    foreach (db()->query('SELECT * FROM leads ORDER BY id DESC') as $l) {
        $row = [$l['created_at'], ...array_map(fn($k) => $l[$k], array_keys(LEAD_FIELDS)), LEAD_STATUSES[$l['status'] ?: 'new'] ?? $l['status'], $l['follow_up'], $l['source'], $l['medium'], $l['campaign']];
        // A leading = + - @ would run as a formula in Excel
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v, $row));
    }
    log_activity('Exported messages', 'CSV');
    exit;
}

/** Active staff who can own a lead: id => name. */
function staff(): array
{
    static $s = null;
    return $s ??= db()->query("SELECT id, COALESCE(NULLIF(name, ''), username) FROM users WHERE active = 1 ORDER BY name, username")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Give a lead to someone, log it, and email them (unless they assigned it to themselves). */
function assign_lead(array $l, ?int $uid): bool
{
    $uid = $uid && isset(staff()[$uid]) ? $uid : null;
    if ($uid === ($l['assigned_to'] !== null ? (int)$l['assigned_to'] : null)) return false;
    db()->prepare('UPDATE leads SET assigned_to = ?, updated_at = ? WHERE id = ?')->execute([$uid, now(), $l['id']]);
    $who = $l['name'] . ' (' . $l['company'] . ')';
    log_activity($uid ? 'Assigned lead → ' . staff()[$uid] : 'Unassigned lead', $who);
    $me = current_user();
    if ($uid && $uid !== (int)$me['id']) {
        $st = db()->prepare('SELECT email, name, username FROM users WHERE id = ?');
        $st->execute([$uid]);
        $to = $st->fetch();
        if ($to && filter_var($to['email'], FILTER_VALIDATE_EMAIL)) {
            send_mail($to['email'], 'Lead assigned to you: ' . $l['name'] . ' — ' . $l['company'],
                'Hi ' . ($to['name'] ?: $to['username']) . ",\n\n" . ($me['name'] ?: $me['username']) . " ei lead ta apnake diyeche:\n\n"
                . $l['name'] . ' · ' . $l['company'] . "\n" . $l['email'] . ($l['phone'] ? ' · ' . $l['phone'] : '') . "\n\n"
                . mb_strimwidth((string)$l['message'], 0, 400, '…') . "\n\nOpen: " . abs_url('admin/messages/' . $l['id']) . "\n");
        }
    }
    return true;
}

function lead(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM leads WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $l = $id ? lead($id) : null;
    if ($action === 'read_all') {
        db()->exec('UPDATE leads SET is_read = 1');
        redirect(aurl('messages'));
    }
    if ($action === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $do = (string)($_POST['bulk'] ?? '');
        $back = aurl('messages') . (($r = (string)($_POST['return'] ?? '')) !== '' && str_starts_with($r, '?') ? $r : '');
        if (!$ids) { flash('Age kichu message select korun.', 'err'); redirect($back); }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = count($ids);
        if ($do === 'read' || $do === 'unread') {
            db()->prepare("UPDATE leads SET is_read = ? WHERE id IN ($in)")->execute([$do === 'read' ? 1 : 0, ...$ids]);
            flash("$n message " . ($do === 'read' ? 'read' : 'unread') . ' kora hoyeche.');
        } elseif ($do === 'delete') {
            db()->prepare("DELETE FROM leads WHERE id IN ($in)")->execute($ids);
            db()->prepare("DELETE FROM lead_notes WHERE lead_id IN ($in)")->execute($ids);
            log_activity('Deleted messages', "$n messages");
            flash("$n message delete hoyeche.");
        } elseif (str_starts_with($do, 'status:') && isset(LEAD_STATUSES[$st = substr($do, 7)])) {
            db()->prepare("UPDATE leads SET status = ?, updated_at = ? WHERE id IN ($in)")->execute([$st, now(), ...$ids]);
            log_activity('Lead status → ' . LEAD_STATUSES[$st], "$n messages");
            flash("$n message → " . LEAD_STATUSES[$st]);
        } elseif (str_starts_with($do, 'assign:')) {
            $uid = (int)substr($do, 7) ?: null;
            $q = db()->prepare("SELECT * FROM leads WHERE id IN ($in)");
            $q->execute($ids);
            $c = 0;
            foreach ($q->fetchAll() as $row) $c += assign_lead($row, $uid) ? 1 : 0;
            flash($uid ? "$c message " . staff()[$uid] . ' ke deya hoyeche.' : "$c message unassigned.");
        }
        redirect($back);
    }
    if (!$l) redirect(aurl('messages'));
    $who = $l['name'] . ' (' . $l['company'] . ')';
    switch ($action) {
        case 'update':
            $status = isset(LEAD_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : ($l['status'] ?: 'new');
            $fu = (string)($_POST['follow_up'] ?? '');
            $fu = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fu) ? $fu : null;
            db()->prepare('UPDATE leads SET status = ?, follow_up = ?, updated_at = ? WHERE id = ?')->execute([$status, $fu, now(), $id]);
            if (array_key_exists('assigned_to', $_POST)) assign_lead($l, (int)$_POST['assigned_to'] ?: null);
            if ($status !== ($l['status'] ?: 'new')) log_activity('Lead status → ' . LEAD_STATUSES[$status], $who);
            elseif ($fu !== $l['follow_up']) log_activity('Set follow-up', $who, (string)$fu);
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note !== '') {
                $u = current_user();
                insert_row(db(), 'lead_notes', ['lead_id' => $id, 'user_id' => $u['id'], 'username' => $u['name'] ?: $u['username'],
                    'note' => mb_substr($note, 0, 4000), 'created_at' => now()]);
                log_activity('Added note', $who);
            }
            flash('Saved.');
            redirect(aurl('messages/' . $id));
        case 'unread':
            db()->prepare('UPDATE leads SET is_read = 0 WHERE id = ?')->execute([$id]);
            redirect(aurl('messages'));
        case 'delete':
            db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
            db()->prepare('DELETE FROM lead_notes WHERE lead_id = ?')->execute([$id]);
            log_activity('Deleted message', $who);
            flash('Message deleted.');
            redirect(aurl('messages'));
        case 'delete_note':
            db()->prepare('DELETE FROM lead_notes WHERE id = ? AND lead_id = ?')->execute([(int)($_POST['note_id'] ?? 0), $id]);
            redirect(aurl('messages/' . $id));
    }
    redirect(aurl('messages'));
}

/* ── Detail ── */
if (ctype_digit($sub)) {
    $l = lead((int)$sub);
    if (!$l) redirect(aurl('messages'));
    $id = (int)$l['id'];
    db()->prepare('UPDATE leads SET is_read = 1 WHERE id = ?')->execute([$id]);
    $notes = db()->prepare('SELECT * FROM lead_notes WHERE lead_id = ? ORDER BY id DESC');
    $notes->execute([$id]);
    $notes = $notes->fetchAll();
    $wa = preg_replace('/\D/', '', (string)$l['phone']);
    if (str_starts_with($wa, '01')) $wa = '88' . $wa;
    $status = $l['status'] ?: 'new';
    admin_head($l['name'], 'messages', ['Messages' => aurl('messages')]);
    ?>
<div class="split-form">
  <section class="card lead">
    <div class="lead-head">
      <span class="av av--lg"><?= e(initials($l['name'])) ?></span>
      <div><h2><?= e($l['name']) ?></h2><p class="muted"><?= e($l['company']) ?> · <?= e(date('d M Y, g:i a', strtotime($l['created_at']))) ?></p></div>
      <span class="badge badge--<?= e($status) ?>"><?= e(LEAD_STATUSES[$status]) ?></span>
    </div>
    <dl>
<?php foreach (LEAD_FIELDS as $k => $label): if ((string)$l[$k] === '' || in_array($k, ['name', 'company'], true)) continue; ?>
      <dt><?= $label ?></dt><dd><?= $k === 'email' ? '<a href="mailto:' . e($l[$k]) . '">' . e($l[$k]) . '</a>' : ($k === 'phone' ? '<a href="' . e(tel_href($l[$k])) . '">' . e($l[$k]) . '</a>' : e($l[$k])) ?></dd>
<?php endforeach; if ((string)$l['source'] !== ''): ?>
      <dt>Came from</dt><dd><?= e($l['source']) ?><?= $l['medium'] !== '' && $l['medium'] !== 'none' ? ' · ' . e($l['medium']) : '' ?><?= $l['campaign'] !== '' ? ' · <b>' . e($l['campaign']) . '</b>' : '' ?></dd>
<?php endif; ?>
    </dl>
    <div class="actions">
      <a class="btn btn--main" href="mailto:<?= e($l['email']) ?>?subject=<?= rawurlencode('Re: your enquiry — ' . setting('site_name', 'Dakpion IMC')) ?>"><?= aicon('mail', 16) ?>Reply by email</a>
<?php if (strlen($wa) >= 10): ?>      <a class="btn" href="https://wa.me/<?= $wa ?>" target="_blank" rel="noopener"><?= aicon('chat', 16) ?>WhatsApp</a>
<?php endif; if ($l['phone']): ?>      <a class="btn" href="<?= e(tel_href($l['phone'])) ?>">Call</a>
<?php endif; ?>
      <form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost" name="action" value="unread">Mark unread</button></form>
      <form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--danger-text" name="action" value="delete" data-confirm="Message ta delete korben?">Delete</button></form>
    </div>
  </section>

  <aside class="side-stack">
    <form class="card side-card" method="post">
      <?= csrf() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="update">
      <h2>Pipeline</h2>
      <div class="status-pick">
<?php foreach (LEAD_STATUSES as $k => $label): ?>
        <label><input type="radio" name="status" value="<?= $k ?>"<?= $k === $status ? ' checked' : '' ?>><span class="badge badge--<?= $k ?>"><?= $label ?></span></label>
<?php endforeach; ?>
      </div>
      <div class="field"><label for="as">Handled by</label><select id="as" name="assigned_to"><option value="">— Nobody yet —</option>
<?php foreach (staff() as $sid => $sname): ?>        <option value="<?= $sid ?>"<?= (int)$l['assigned_to'] === $sid ? ' selected' : '' ?>><?= e($sname) ?><?= $sid === (int)current_user()['id'] ? ' (me)' : '' ?></option>
<?php endforeach; ?>      </select><small>Onno kauke dile take email e janano hobe.</small></div>
      <div class="field"><label for="fu">Follow-up date</label><input id="fu" type="date" name="follow_up" value="<?= e($l['follow_up']) ?>"></div>
      <div class="field"><label for="nt">Add a note</label><textarea id="nt" name="note" rows="3" placeholder="Call korsi, proposal pathabo…"></textarea></div>
      <button class="btn btn--main btn--block" type="submit">Save</button>
    </form>
    <section class="card side-card">
      <h2>Notes</h2>
<?php if (!$notes): ?>      <p class="muted small">Ekhono kono note nai.</p>
<?php endif; foreach ($notes as $n): ?>
      <div class="note-item">
        <p><?= nl2br(e($n['note'])) ?></p>
        <small><?= e($n['username']) ?> · <?= e(ago($n['created_at'])) ?></small>
        <form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note_id" value="<?= $n['id'] ?>"><button class="link-btn" name="action" value="delete_note" data-confirm="Note ta delete korben?">Delete</button></form>
      </div>
<?php endforeach; ?>
    </section>
  </aside>
</div>
<?php
    admin_foot();
    return;
}

/* ── Inbox ── */
$filter = $_GET['status'] ?? 'all';
$q = trim((string)($_GET['q'] ?? ''));
$counts = db()->query("SELECT COALESCE(NULLIF(status, ''), 'new') s, COUNT(*) n FROM leads GROUP BY s")->fetchAll(PDO::FETCH_KEY_PAIR);
$where = [];
$args = [];
$me = (int)current_user()['id'];
if ($filter === 'mine') { $where[] = 'assigned_to = ? AND COALESCE(status, \'new\') NOT IN (\'won\', \'lost\')'; $args[] = $me; }
elseif ($filter === 'unassigned') { $where[] = 'assigned_to IS NULL AND COALESCE(status, \'new\') NOT IN (\'won\', \'lost\')'; }
elseif ($filter === 'followup') { $where[] = "follow_up IS NOT NULL AND follow_up <> '' AND follow_up <= ? AND COALESCE(status, 'new') NOT IN ('won', 'lost')"; $args[] = date('Y-m-d'); }
elseif (isset(LEAD_STATUSES[$filter])) { $where[] = "COALESCE(NULLIF(status, ''), 'new') = ?"; $args[] = $filter; }
if ($q !== '') { $where[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR message LIKE ?)'; array_push($args, ...array_fill(0, 5, "%$q%")); }
$st = db()->prepare('SELECT * FROM leads' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500');
$st->execute($args);
$rows = $st->fetchAll();
$due = db()->prepare("SELECT COUNT(*) FROM leads WHERE follow_up IS NOT NULL AND follow_up <> '' AND follow_up <= ? AND COALESCE(status, 'new') NOT IN ('won', 'lost')");
$due->execute([date('Y-m-d')]);
$due = (int)$due->fetchColumn();
$q2 = db()->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND COALESCE(status, 'new') NOT IN ('won', 'lost')");
$q2->execute([$me]);
$mine = (int)$q2->fetchColumn();
$unassigned = (int)db()->query("SELECT COUNT(*) FROM leads WHERE assigned_to IS NULL AND COALESCE(status, 'new') NOT IN ('won', 'lost')")->fetchColumn();
$link = fn(string $s) => aurl('messages') . '?' . http_build_query(array_filter(['status' => $s === 'all' ? null : $s, 'q' => $q ?: null]));

$actions = (unread_leads() ? '<form method="post">' . csrf() . '<button class="btn btn--ghost" name="action" value="read_all">' . aicon('check', 16) . '<span>Mark all read</span></button></form>' : '')
    . '<a class="btn btn--ghost" href="' . aurl('messages/export') . '">' . aicon('download', 16) . '<span>CSV</span></a>';
admin_head('Messages', 'messages', [], $actions);
?>
<div class="toolbar">
  <nav class="tabs tabs--inline">
    <a href="<?= e($link('all')) ?>"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>All <span><?= array_sum($counts) ?></span></a>
<?php foreach (LEAD_STATUSES as $k => $label): ?>
    <a href="<?= e($link($k)) ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?>><?= $label ?> <span><?= (int)($counts[$k] ?? 0) ?></span></a>
<?php endforeach; ?>
    <a href="<?= e($link('mine')) ?>"<?= $filter === 'mine' ? ' aria-current="page"' : '' ?>>Mine <span><?= $mine ?></span></a>
    <a href="<?= e($link('unassigned')) ?>"<?= $filter === 'unassigned' ? ' aria-current="page"' : '' ?>>Unassigned <span><?= $unassigned ?></span></a>
    <a href="<?= e($link('followup')) ?>"<?= $filter === 'followup' ? ' aria-current="page"' : '' ?> class="<?= $due ? 'warn' : '' ?>">Follow-up due <span><?= $due ?></span></a>
  </nav>
  <form method="get" class="search"><?php if ($filter !== 'all'): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?><?= aicon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, company, email…" aria-label="Search"></form>
</div>
<?php if (!$rows): ?>
<div class="card empty"><p class="muted"><?= $counts ? 'Ei filter e kichu nai.' : 'Ekhono kono message nai. Website er contact form theke message ashle ekhane dekhabe.' ?></p></div>
<?php else: ?>
<form method="post" id="bulk-form" data-bulk><?= csrf() ?><input type="hidden" name="action" value="bulk"><input type="hidden" name="return" value="<?= e($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '') ?>">
  <div class="bulkbar" hidden><b data-bulk-count>0</b> selected
    <select name="bulk" aria-label="Bulk action">
      <option value="">Choose action…</option>
      <optgroup label="Mark"><option value="read">Mark read</option><option value="unread">Mark unread</option></optgroup>
      <optgroup label="Status"><?php foreach (LEAD_STATUSES as $k => $label): ?><option value="status:<?= $k ?>">→ <?= e($label) ?></option><?php endforeach; ?></optgroup>
      <optgroup label="Handled by"><?php foreach (staff() as $sid => $sname): ?><option value="assign:<?= $sid ?>">→ <?= e($sname) ?></option><?php endforeach; ?><option value="assign:0">→ Nobody</option></optgroup>
      <optgroup label="Danger"><option value="delete">Delete</option></optgroup>
    </select>
    <button class="btn btn--sm btn--main" type="submit">Apply</button>
    <button class="btn btn--sm btn--ghost" type="button" data-bulk-clear>Clear</button>
  </div>
</form>
<div class="card table-wrap">
<table class="table leads">
  <thead><tr><th class="check-col"><input type="checkbox" data-bulk-all aria-label="Select all"></th><th>From</th><th>Needs</th><th>Budget</th><th>Status</th><th>Owner</th><th>Follow-up</th><th>Received</th></tr></thead>
  <tbody>
<?php foreach ($rows as $l): $s = $l['status'] ?: 'new'; $over = $l['follow_up'] && $l['follow_up'] <= date('Y-m-d') && !in_array($s, ['won', 'lost'], true); $owner = $l['assigned_to'] ? (staff()[(int)$l['assigned_to']] ?? '') : ''; ?>
    <tr class="<?= $l['is_read'] ? '' : 'new' ?>">
      <td class="check-col"><input type="checkbox" name="ids[]" value="<?= $l['id'] ?>" form="bulk-form" data-bulk-item aria-label="Select <?= e($l['name']) ?>"></td>
      <td><a class="rowlink" href="<?= aurl('messages/' . $l['id']) ?>"><?= e($l['name']) ?></a><small><?= e($l['company']) ?> · <?= e($l['email']) ?></small></td>
      <td><?= e(mb_strimwidth((string)$l['services'], 0, 46, '…')) ?></td>
      <td class="nowrap"><?= e($l['budget']) ?></td>
      <td><span class="badge badge--<?= e($s) ?>"><?= e(LEAD_STATUSES[$s] ?? $s) ?></span></td>
      <td class="nowrap"><?= $owner !== '' ? '<span class="av av--xs" title="' . e($owner) . '">' . e(initials($owner)) . '</span> ' . e(explode(' ', $owner)[0]) : '<span class="muted">—</span>' ?></td>
      <td class="nowrap<?= $over ? ' overdue' : '' ?>"><?= $l['follow_up'] ? e(date('d M', strtotime($l['follow_up']))) . ($over ? ' · due' : '') : '<span class="muted">—</span>' ?></td>
      <td class="nowrap muted" title="<?= e($l['created_at']) ?>"><?= e(ago($l['created_at'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
admin_foot();
