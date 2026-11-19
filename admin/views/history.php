<?php
/* Trash & version history: bring back anything deleted, or put an earlier version back. Kept for REVISION_DAYS days. */
defined('ADMIN') || exit;

const KIND_LABELS = ['item' => null, 'post' => 'Blog post', 'lead' => 'Message', 'page' => 'Page text'];

function rev_kind_label(array $r): string
{
    if ($r['kind'] === 'item') return COLLECTIONS[$r['ref']]['singular'] ?? ucfirst($r['ref']);
    return KIND_LABELS[$r['kind']] ?? $r['kind'];
}

/** Where the restored thing can be opened in the admin. */
function rev_link(array $r, int $id): string
{
    return match ($r['kind']) {
        'item' => aurl($r['ref'] . '/' . $id),
        'post' => aurl('blog/' . $id),
        'lead' => aurl('messages/' . $id),
        'page' => aurl('pages/' . $r['ref']),
        default => aurl('history'),
    };
}

/** Insert $row into $table, keeping its old id when that id is free. Only real columns are written. */
function reinsert(string $table, array $row): int
{
    $cols = array_column(db()->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $row = array_intersect_key($row, array_flip($cols));
    if (isset($row['id'])) {
        $st = db()->prepare("SELECT 1 FROM $table WHERE id = ?");
        $st->execute([$row['id']]);
        if ($st->fetchColumn()) unset($row['id']);
    }
    $id = insert_row(db(), $table, $row);
    return isset($row['id']) ? (int)$row['id'] : $id;
}

/** Put an older version of a row back (keeping the current one in history too). */
function overwrite(string $kind, string $ref, string $table, int $id, array $old, string $label): int
{
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    $cur = $st->fetch();
    if (!$cur) return reinsert($table, $old);
    save_revision($kind, $ref, $id, $label, 'edit', $cur);
    $cols = array_column(db()->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $vals = array_intersect_key($old, array_flip(array_diff($cols, ['id'])));
    $set = implode(', ', array_map(fn($c) => '"' . $c . '" = ?', array_keys($vals)));
    db()->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $st = db()->prepare('SELECT * FROM revisions WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    $r = $st->fetch();
    if (!$r) redirect(aurl('history'));
    $data = json_decode((string)$r['data'], true) ?: [];
    $isDelete = $r['action'] === 'delete';
    try {
        db()->beginTransaction();
        switch ($r['kind']) {
            case 'item':
                if (!isset(COLLECTIONS[$r['ref']])) throw new RuntimeException('Ei section ta ar nai.');
                $table = 'c_' . $r['ref'];
                $id = $isDelete ? reinsert($table, $data) : overwrite('item', $r['ref'], $table, (int)$r['row_id'], $data, $r['label']);
                break;
            case 'post':
                // Another post may have taken the link in the meantime.
                $slug = (string)($data['slug'] ?? '');
                $chk = db()->prepare('SELECT COUNT(*) FROM posts WHERE slug = ? AND id <> ?');
                for ($n = 2, $try = $slug; $slug !== ''; $n++, $try = "$slug-$n") {
                    $chk->execute([$try, (int)$r['row_id']]);
                    if (!$chk->fetchColumn()) { $data['slug'] = $try; break; }
                }
                $id = $isDelete ? reinsert('posts', $data) : overwrite('post', 'posts', 'posts', (int)$r['row_id'], $data, $r['label']);
                break;
            case 'lead':
                $notes = $data['_notes'] ?? [];
                unset($data['_notes']);
                $id = reinsert('leads', $data);
                foreach ($notes as $nt) { unset($nt['id']); $nt['lead_id'] = $id; insert_row(db(), 'lead_notes', $nt); }
                break;
            case 'page':
                if (!isset(PAGES[$r['ref']])) throw new RuntimeException('Ei page ta ar nai.');
                $key = $r['ref'];
                $cur = [];
                foreach (PAGES[$key]['fields'] as $f => $_) if (($v = setting("$key.$f")) !== '') $cur[$f] = $v;
                save_revision('page', $key, 0, $r['label'], 'edit', $cur);
                $del = db()->prepare('DELETE FROM settings WHERE key = ?');
                $set = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
                foreach (PAGES[$key]['fields'] as $f => $_) $del->execute(["$key.$f"]);
                foreach ($data as $f => $v) if (isset(PAGES[$key]['fields'][$f])) $set->execute(["$key.$f", $v]);
                $id = 0;
                break;
            default:
                throw new RuntimeException('Restore kora jay na.');
        }
        db()->prepare('UPDATE revisions SET restored_at = ? WHERE id = ?')->execute([now(), $r['id']]);
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('Restore hoy nai: ' . ($ex instanceof RuntimeException ? $ex->getMessage() : 'database error'), 'err');
        redirect(aurl('history'));
    }
    log_activity($isDelete ? 'Restored deleted ' . strtolower(rev_kind_label($r)) : 'Restored earlier version', $r['label']);
    flash($isDelete ? '"' . $r['label'] . '" ferot ana hoyeche' . (($r['kind'] === 'item' && empty($data['active'])) ? ' (hidden chilo — dorkar hole show korun).' : '.') : 'Ager version ferot deya hoyeche. Ekhonkar ta history te rakha ache.');
    redirect(rev_link($r, $id));
}

$tab = ($_GET['tab'] ?? '') === 'edits' ? 'edits' : 'deleted';
$where = $tab === 'deleted' ? "action = 'delete' AND restored_at IS NULL" : "action = 'edit'";
$args = [];
foreach (['kind', 'ref'] as $k) if (($v = (string)($_GET[$k] ?? '')) !== '') { $where .= " AND $k = ?"; $args[] = $v; }
if (isset($_GET['row'])) { $where .= ' AND row_id = ?'; $args[] = (int)$_GET['row']; }
$st = db()->prepare("SELECT id, kind, ref, row_id, label, action, username, created_at, restored_at FROM revisions WHERE $where ORDER BY id DESC LIMIT 200");
$st->execute($args);
$rows = $st->fetchAll();
$counts = db()->query("SELECT SUM(action = 'delete' AND restored_at IS NULL), SUM(action = 'edit') FROM revisions")->fetch(PDO::FETCH_NUM);
$filtered = $args ? $rows[0]['label'] ?? 'this item' : '';

admin_head('Trash & history', 'history');
?>
<p class="muted intro">Delete kora jinis ar ager version <?= REVISION_DAYS ?> din porjonto ekhane thake — ek click e ferot ana jay. (Portfolio, Team, shob section, blog post, page text ar messages.)</p>
<nav class="tabs">
  <a href="<?= aurl('history') ?>"<?= $tab === 'deleted' ? ' aria-current="page"' : '' ?>>Deleted <span><?= (int)$counts[0] ?></span></a>
  <a href="<?= aurl('history') ?>?tab=edits"<?= $tab === 'edits' ? ' aria-current="page"' : '' ?>>Earlier versions <span><?= (int)$counts[1] ?></span></a>
</nav>
<?php if ($filtered): ?><p class="small">Showing: <b><?= e($filtered) ?></b> · <a href="<?= aurl('history') ?>?tab=<?= $tab ?>">show all</a></p><?php endif; ?>
<?php if (!$rows): ?>
<div class="card empty"><p class="muted"><?= $tab === 'deleted' ? 'Trash khali — kichu delete kora hoy nai.' : 'Ekhono kono edit history nai. Kichu edit kore save korle ager version ekhane thakbe.' ?></p></div>
<?php else: ?>
<div class="card table-wrap">
<table class="table">
  <thead><tr><th>What</th><th>Type</th><th><?= $tab === 'deleted' ? 'Deleted' : 'Replaced' ?></th><th class="act"></th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><b><?= e($r['label'] ?: '(no title)') ?></b><?php if ($r['restored_at']): ?> <span class="badge">restored <?= e(ago($r['restored_at'])) ?></span><?php endif; ?></td>
      <td><?= e(rev_kind_label($r)) ?></td>
      <td class="nowrap muted" title="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?><?= $r['username'] ? ' · ' . e($r['username']) : '' ?></td>
      <td class="act"><form method="post"><?= csrf() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button class="btn btn--sm" data-confirm="<?= $tab === 'deleted' ? 'Ferot anben?' : 'Ei ager version ta ferot dile ekhonkar ta history te chole jabe. Korben?' ?>"><?= $tab === 'deleted' ? 'Restore' : 'Use this version' ?></button></form></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
admin_foot();
