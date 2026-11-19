<?php
/* Send old or mistyped addresses to the right page (301 permanent / 302 temporary), and see which addresses are broken. */
defined('ADMIN') || exit;

/** Clean the "to" side: a path inside the site ("/services") or a full https:// link. */
function redirect_target(string $to): string
{
    $to = trim($to);
    if (preg_match('~^https?://[^\s]+$~i', $to)) {
        // A link to this same site is stored as a path, so it keeps working if the domain changes.
        $host = strtolower((string)parse_url($to, PHP_URL_HOST));
        $self = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $self)) {
            $to = (string)parse_url($to, PHP_URL_PATH) . (($q = parse_url($to, PHP_URL_QUERY)) ? '?' . $q : '') . (($f = parse_url($to, PHP_URL_FRAGMENT)) ? '#' . $f : '');
        } else {
            return $to;
        }
    }
    if ($to === '' || preg_match('/\s/', $to)) return '';
    return '/' . ltrim($to, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $from = norm_path((string)($_POST['from'] ?? ''));
        $to = redirect_target((string)($_POST['to'] ?? ''));
        $code = ($_POST['code'] ?? '') === '302' ? 302 : 301;
        $dupe = db()->prepare('SELECT id FROM redirects WHERE from_path = ? AND id <> ?');
        $dupe->execute([$from, $id]);
        $error = match (true) {
            trim((string)($_POST['from'] ?? '')) === '' || $from === '/' => 'Kon address theke pathaben seta din (home page "/" bade).',
            str_starts_with($from, '/admin') => 'Admin er address redirect kora jabe na.',
            $to === '' => 'Kothay pathaben seta din — jemon /services ba https://…',
            norm_path(explode('?', $to)[0]) === $from && !preg_match('~^https?://~i', $to) => 'Ekoi address e ferot pathale loop hobe.',
            (bool)$dupe->fetchColumn() => 'Ei address er redirect already ache — sheta edit korun.',
            default => '',
        };
        if ($error) {
            flash($error, 'err');
            redirect(aurl('redirects') . '?' . http_build_query(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '', 'edit' => $id ?: null]));
        }
        if ($id) {
            db()->prepare('UPDATE redirects SET from_path = ?, to_url = ?, code = ? WHERE id = ?')->execute([$from, $to, $code, $id]);
            log_activity('Edited redirect', "$from → $to");
        } else {
            insert_row(db(), 'redirects', ['from_path' => $from, 'to_url' => $to, 'code' => $code, 'created_at' => now()]);
            log_activity('Added redirect', "$from → $to");
        }
        db()->prepare('DELETE FROM not_found WHERE path = ?')->execute([$from]);
        flash("Redirect saved: $from → $to");
    } elseif ($action === 'delete') {
        $st = db()->prepare('SELECT * FROM redirects WHERE id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        if ($r = $st->fetch()) {
            db()->prepare('DELETE FROM redirects WHERE id = ?')->execute([$r['id']]);
            log_activity('Deleted redirect', $r['from_path'] . ' → ' . $r['to_url']);
            flash('Redirect deleted.');
        }
    } elseif ($action === 'ignore') {
        db()->prepare('DELETE FROM not_found WHERE path = ?')->execute([(string)($_POST['path'] ?? '')]);
    } elseif ($action === 'clear404') {
        db()->exec('DELETE FROM not_found');
        flash('Broken link list khali kora hoyeche.');
    }
    redirect(aurl('redirects'));
}

$rows = db()->query('SELECT * FROM redirects ORDER BY created_at DESC, id DESC')->fetchAll();
$broken = db()->query('SELECT * FROM not_found ORDER BY hits DESC, last_seen DESC LIMIT 25')->fetchAll();
$editing = null;
if (($eid = (int)($_GET['edit'] ?? 0)) > 0) foreach ($rows as $r) if ((int)$r['id'] === $eid) $editing = $r;
$fFrom = (string)($_GET['from'] ?? ($editing['from_path'] ?? ''));
$fTo = (string)($_GET['to'] ?? ($editing['to_url'] ?? ''));
$fCode = (int)($editing['code'] ?? 301);

admin_head('Redirects', 'redirects');
?>
<p class="muted intro">Purono ba vul address e keu ashle take thik page e pathay. Google o purono link er "rank" notun page e diye dey (301). Site er address bodlale, blog post er link bodlale, ba purono website theke ashle kaje lage.</p>
<div class="grid2">
  <form class="card form" method="post" id="redirect-form">
    <?= csrf() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <h2><?= $editing ? 'Edit redirect' : 'New redirect' ?></h2>
    <div class="field"><label for="rf">From (purono address)</label><input id="rf" name="from" required placeholder="/old-page" value="<?= e($fFrom) ?>"><small>Shudhu path — jemon <code>/digital-marketing</code> ba <code>/blog/old-post</code>. Boro-choto hater lekha ar shesher / matter kore na.</small></div>
    <div class="field"><label for="rt">To (notun jayga)</label><input id="rt" name="to" required placeholder="/services" value="<?= e($fTo) ?>"><small>Site er page (<code>/services</code>, <code>/contact</code>) ba onno website er full link.</small></div>
    <div class="field"><label for="rc">Type</label><select id="rc" name="code"><option value="301"<?= $fCode === 301 ? ' selected' : '' ?>>301 — Permanent (shadharonoto eta)</option><option value="302"<?= $fCode === 302 ? ' selected' : '' ?>>302 — Temporary</option></select></div>
    <div class="actions"><button class="btn btn--main" type="submit"><?= $editing ? 'Save changes' : 'Add redirect' ?></button><?php if ($editing || $fFrom !== ''): ?><a class="btn btn--ghost" href="<?= aurl('redirects') ?>">Cancel</a><?php endif; ?></div>
  </form>
  <section class="card">
    <div class="card-head"><h2>Broken links <small>404 — keu khulte chaise, page nai</small></h2>
<?php if ($broken): ?>      <form method="post"><?= csrf() ?><button class="btn btn--sm btn--ghost" name="action" value="clear404" data-confirm="Puro list khali korben?">Clear list</button></form><?php endif; ?></div>
<?php if (!$broken): ?>
    <p class="muted small">Ekhono kono broken link dhora pore nai. Keu emon address e gele jeta nai, ekhane dekhabe.</p>
<?php else: ?>
    <ul class="leadlist leadlist--plain">
<?php foreach ($broken as $b): ?>
      <li><span><b><?= e($b['path']) ?></b><small><?= (int)$b['hits'] ?>× · last <?= e(ago($b['last_seen'])) ?><?= $b['referrer'] ? ' · from ' . e(mb_strimwidth((string)parse_url($b['referrer'], PHP_URL_HOST), 0, 30, '…')) : '' ?></small></span>
        <span class="row-actions"><a class="btn btn--sm" href="<?= aurl('redirects') ?>?from=<?= rawurlencode($b['path']) ?>#redirect-form">Redirect</a>
        <form method="post"><?= csrf() ?><input type="hidden" name="path" value="<?= e($b['path']) ?>"><button class="link-btn" name="action" value="ignore">Ignore</button></form></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
</div>

<?php if ($rows): ?>
<div class="card table-wrap">
<table class="table">
  <thead><tr><th>From</th><th>To</th><th>Type</th><th>Used</th><th class="act"></th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="rowlink" href="<?= e(url(ltrim($r['from_path'], '/'))) ?>" target="_blank" rel="noopener"><?= e($r['from_path']) ?></a></td>
      <td><?= e($r['to_url']) ?></td>
      <td><span class="badge"><?= (int)$r['code'] ?></span></td>
      <td class="nowrap muted"><?= (int)$r['hits'] ?>×<?= $r['last_hit'] ? ' · ' . e(ago($r['last_hit'])) : '' ?></td>
      <td class="act">
        <a class="ib" href="<?= aurl('redirects') ?>?edit=<?= $r['id'] ?>#redirect-form" title="Edit" aria-label="Edit"><?= aicon('edit', 17) ?></a>
        <form method="post"><?= csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="ib danger" title="Delete" aria-label="Delete" data-confirm="Redirect ta delete korben?"><?= aicon('trash', 17) ?></button></form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
admin_foot();
