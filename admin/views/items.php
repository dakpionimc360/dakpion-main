<?php
/* One view for every collection: list (drag to reorder, search, show/hide), add, edit, delete. */
defined('ADMIN') || exit;

$key = $seg[0];
$col = COLLECTIONS[$key];
$table = 'c_' . $key;
$base = aurl($key);
$sub = $seg[1] ?? '';

function find_row(string $table, int $id): ?array
{
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$label_of = function (array $row) use ($col): string {
    foreach ($col['list'] as $f) if ($col['fields'][$f]['type'] !== 'image' && trim((string)$row[$f]) !== '') return mb_strimwidth((string)$row[$f], 0, 60, '…');
    return '#' . $row['id'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'order') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $st = db()->prepare("UPDATE $table SET sort = ? WHERE id = ?");
        db()->beginTransaction();
        foreach ($ids as $n => $rid) $st->execute([$n + 1, $rid]);
        db()->commit();
        log_activity('Reordered', $col['label']);
        json_out(['ok' => true]);
    }

    if ($action === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $do = (string)($_POST['bulk'] ?? '');
        if (!$ids || !in_array($do, ['show', 'hide', 'delete'], true)) { flash('Age kichu select korun, tarpor action.', 'err'); redirect($base); }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = count($ids);
        if ($do === 'delete') {
            $q = db()->prepare("SELECT * FROM $table WHERE id IN ($in)");
            $q->execute($ids);
            foreach ($q->fetchAll() as $gone) save_revision('item', $key, (int)$gone['id'], $label_of($gone), 'delete', $gone);
            db()->prepare("DELETE FROM $table WHERE id IN ($in)")->execute($ids);
            log_activity('Deleted ' . strtolower($col['label']), "$n items");
            flash("$n ta delete hoyeche.");
        } else {
            db()->prepare("UPDATE $table SET active = ? WHERE id IN ($in)")->execute([$do === 'show' ? 1 : 0, ...$ids]);
            log_activity(($do === 'show' ? 'Showed ' : 'Hid ') . strtolower($col['label']), "$n items");
            flash("$n ta " . ($do === 'show' ? 'show' : 'hide') . ' kora hoyeche.');
        }
        redirect($base);
    }

    if ($action === 'save') {
        $old = $id ? find_row($table, $id) : null;
        if ($id && !$old) redirect($base);
        try {
            $vals = collect_fields($col['fields']);
            if ($id) {
                $diff = array_diff_assoc(array_map('strval', $vals), array_map('strval', array_intersect_key($old, $vals)));
                if ($diff) save_revision('item', $key, $id, $label_of($old), 'edit', $old);
                $set = implode(', ', array_map(fn($c) => '"' . $c . '" = ?', array_keys($vals)));
                db()->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
                log_activity('Edited ' . strtolower($col['singular']), $label_of($vals + ['id' => $id]));
            } else {
                $vals['sort'] = (int)db()->query("SELECT COALESCE(MAX(sort), 0) + 1 FROM $table")->fetchColumn();
                $id = insert_row(db(), $table, $vals);
                log_activity('Added ' . strtolower($col['singular']), $label_of($vals + ['id' => $id]));
            }
            flash($col['singular'] . ' saved.');
            redirect(isset($_POST['and_new']) ? "$base/new" : $base);
        } catch (RuntimeException $ex) {
            flash($ex->getMessage(), 'err');
            $draft = $old ?: [];
            foreach ($_POST['f'] ?? [] as $k => $v) if (isset($col['fields'][$k])) $draft[$k] = is_array($v) ? implode(',', array_filter($v)) : $v;
            $sub = $id ? (string)$id : 'new';
        }
    } elseif ($row = find_row($table, $id)) {
        if ($action === 'delete') {
            save_revision('item', $key, $id, $label_of($row), 'delete', $row);
            db()->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            log_activity('Deleted ' . strtolower($col['singular']), $label_of($row));
            flash($col['singular'] . ' deleted.');
        } elseif ($action === 'toggle') {
            db()->prepare("UPDATE $table SET active = 1 - active WHERE id = ?")->execute([$id]);
            log_activity($row['active'] ? 'Hid ' . strtolower($col['singular']) : 'Showed ' . strtolower($col['singular']), $label_of($row));
        } elseif ($action === 'duplicate') {
            $copy = array_intersect_key($row, $col['fields']);
            $copy['sort'] = (int)db()->query("SELECT COALESCE(MAX(sort), 0) + 1 FROM $table")->fetchColumn();
            $copy['active'] = 0;
            $nid = insert_row(db(), $table, $copy);
            log_activity('Duplicated ' . strtolower($col['singular']), $label_of($row));
            flash('Copy toiri hoyeche (hidden). Edit kore show korun.');
            redirect("$base/$nid");
        }
        redirect($base);
    }
}

/* ── Add / edit form ── */
if ($sub !== '') {
    $id = $sub === 'new' ? 0 : (int)$sub;
    $row = $id ? find_row($table, $id) : [];
    if ($id && !$row) redirect($base);
    $row = array_merge($row ?: [], $draft ?? []);
    $title = $id ? 'Edit ' . strtolower($col['singular']) : 'New ' . strtolower($col['singular']);
    $hist = '';
    if ($id) {
        $hc = db()->prepare("SELECT COUNT(*) FROM revisions WHERE kind = 'item' AND ref = ? AND row_id = ? AND action = 'edit'");
        $hc->execute([$key, $id]);
        if ($n = (int)$hc->fetchColumn()) $hist = '<a class="btn btn--ghost" href="' . aurl('history') . '?tab=edits&kind=item&ref=' . $key . '&row=' . $id . '">' . aicon('activity', 16) . '<span>History (' . $n . ')</span></a>';
    }
    admin_head($title, 'c-' . $key, [$col['label'] => $base], $hist);
    ?>
<form class="card form form-grid" method="post" enctype="multipart/form-data" data-dirty>
  <?= csrf() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= $id ?>">
<?php foreach ($col['fields'] as $k => $def) echo render_field($k, $def, $row[$k] ?? ''); ?>
  <div class="actions wide sticky-actions">
    <button class="btn btn--main" type="submit">Save</button>
<?php if (!$id): ?>    <button class="btn" type="submit" name="and_new" value="1">Save &amp; add another</button>
<?php endif; ?>
    <a class="btn btn--ghost" href="<?= $base ?>">Cancel</a>
  </div>
</form>
<?php
    admin_foot();
    return;
}

/* ── List ── */
$rows = db()->query("SELECT * FROM $table ORDER BY sort, id")->fetchAll();
$linkCol = current(array_filter($col['list'], fn($f) => $col['fields'][$f]['type'] !== 'image'));
$hidden = count(array_filter($rows, fn($r) => !$r['active']));
$btn = fn(array $r, string $a, string $icon, string $title, string $cls = '', string $confirm = '') =>
    '<form method="post">' . csrf() . '<input type="hidden" name="action" value="' . $a . '"><input type="hidden" name="id" value="' . $r['id'] . '">'
    . '<button class="ib ' . $cls . '" title="' . $title . '" aria-label="' . $title . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . aicon($icon, 17) . '</button></form>';

admin_head($col['label'], 'c-' . $key, [], '<a class="btn btn--main" href="' . $base . '/new">' . aicon('plus', 16) . '<span>Add ' . e(strtolower($col['singular'])) . '</span></a>');
?>
<div class="toolbar">
  <label class="search"><?= aicon('search', 16) ?><input type="search" placeholder="Search <?= e(strtolower($col['label'])) ?>…" data-filter-rows="#rows" aria-label="Search"></label>
  <p class="muted"><?= count($rows) ?> items<?= $hidden ? " · $hidden hidden" : '' ?> · <?= aicon('drag', 14) ?> dhore drag kore order bodlan</p>
</div>
<?php if (!$rows): ?>
<div class="card empty"><h2>Ekhono kichu nai</h2><p class="muted">Prothom <?= e(strtolower($col['singular'])) ?> add korun.</p><a class="btn btn--main" href="<?= $base ?>/new"><?= aicon('plus', 16) ?>Add <?= e(strtolower($col['singular'])) ?></a></div>
<?php else: ?>
<form method="post" id="bulk-form" data-bulk><?= csrf() ?><input type="hidden" name="action" value="bulk">
  <div class="bulkbar" hidden><b data-bulk-count>0</b> selected
    <button class="btn btn--sm" name="bulk" value="show"><?= aicon('eye', 15) ?>Show</button>
    <button class="btn btn--sm" name="bulk" value="hide"><?= aicon('eye-off', 15) ?>Hide</button>
    <button class="btn btn--sm btn--danger-text" name="bulk" value="delete" data-confirm="Select kora shob gula delete korben? Trash & history theke ferot ana jabe."><?= aicon('trash', 15) ?>Delete</button>
    <button class="btn btn--sm btn--ghost" type="button" data-bulk-clear>Clear</button>
  </div>
</form>
<div class="card table-wrap">
<table class="table">
  <thead><tr><th class="check-col"><input type="checkbox" data-bulk-all aria-label="Select all"></th><th class="handle-col"></th><?php foreach ($col['list'] as $f): ?><th><?= e(preg_replace('/\s*[(—].*$/u', '', $col['fields'][$f]['label'])) ?></th><?php endforeach; ?><th class="act"></th></tr></thead>
  <tbody id="rows" data-sortable="<?= $base ?>">
<?php foreach ($rows as $r): ?>
    <tr data-id="<?= $r['id'] ?>" class="<?= $r['active'] ? '' : 'off' ?>">
      <td class="check-col"><input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" form="bulk-form" data-bulk-item aria-label="Select"></td>
      <td class="handle-col"><span class="handle" title="Drag to reorder"><?= aicon('drag', 16) ?></span></td>
<?php foreach ($col['list'] as $f): ?>
      <td><?= $f === $linkCol ? '<a class="rowlink" href="' . $base . '/' . $r['id'] . '">' . (cell($col['fields'][$f], $r[$f]) ?: '(blank)') . '</a>' . ($r['active'] ? '' : ' <span class="badge">Hidden</span>') : cell($col['fields'][$f], $r[$f]) ?></td>
<?php endforeach; ?>
      <td class="act">
        <?= $btn($r, 'toggle', $r['active'] ? 'eye' : 'eye-off', $r['active'] ? 'Visible — click to hide' : 'Hidden — click to show') ?>
        <a class="ib" href="<?= $base . '/' . $r['id'] ?>" title="Edit" aria-label="Edit"><?= aicon('edit', 17) ?></a>
        <?= $btn($r, 'duplicate', 'copy', 'Duplicate') ?>
        <?= $btn($r, 'delete', 'trash', 'Delete', 'danger', 'Delete korben? Trash & history theke ferot ana jabe.') ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
admin_foot();
