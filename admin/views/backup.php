<?php
defined('ADMIN') || exit;
require_admin();

function dir_size(string $dir): array
{
    $n = 0; $bytes = 0;
    foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f) && basename($f) !== '.htaccess') { $n++; $bytes += filesize($f); }
    return [$n, $bytes];
}

function human_size(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
}

/** Consistent copy of the live database (safe while the site is in use). */
function snapshot_db(): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'dkdb');
    unlink($tmp);
    try {
        db()->exec('VACUUM INTO ' . db()->quote($tmp));
    } catch (PDOException) {
        copy(DATA_DIR . '/site.sqlite', $tmp);
    }
    return $tmp;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_now') {
    $made = run_auto_backup(true);
    $made ? flash('Backup toiri hoyeche: ' . $made) : flash('Backup kora jay nai — data/ folder e lekhar permission check korun.', 'err');
    if ($made) log_activity('Ran backup now', $made);
    redirect(aurl('backup'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get') {
    $name = basename((string)($_POST['file'] ?? ''));
    $path = BACKUP_DIR . '/' . $name;
    if (!preg_match('/^(db|full)-\d{4}-\d{2}-\d{2}\.(sqlite|zip)$/', $name) || !is_file($path)) redirect(aurl('backup'));
    log_activity('Downloaded backup', 'automatic: ' . $name);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="dakpion-' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(300);
    $stamp = date('Y-m-d-Hi');
    $db = snapshot_db();
    $type = $_POST['type'] ?? 'full';
    if ($type === 'db' || !class_exists('ZipArchive')) {
        log_activity('Downloaded backup', 'database');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="dakpion-database-' . $stamp . '.sqlite"');
        header('Content-Length: ' . filesize($db));
        readfile($db);
        unlink($db);
        exit;
    }
    $zipPath = tempnam(sys_get_temp_dir(), 'dkzip');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFile($db, 'data/site.sqlite');
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $f) if (is_file($f) && basename($f) !== '.htaccess') $zip->addFile($f, 'uploads/' . basename($f));
    $zip->addFromString('README.txt', "Dakpion website backup — $stamp\n\nRestore: upload data/site.sqlite into the site's data/ folder and\nthe uploads/ files into uploads/, replacing what is there.\n");
    $zip->close();
    unlink($db);
    log_activity('Downloaded backup', 'full (database + images)');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="dakpion-backup-' . $stamp . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

[$files, $bytes] = dir_size(UPLOAD_DIR);
$dbSize = (int)@filesize(DATA_DIR . '/site.sqlite');
$last = db()->query("SELECT created_at, username FROM activity WHERE action = 'Downloaded backup' ORDER BY id DESC LIMIT 1")->fetch();
$autos = array_merge(glob(BACKUP_DIR . '/full-*.zip') ?: [], glob(BACKUP_DIR . '/db-*.sqlite') ?: []);
$bday = fn(string $f) => preg_match('/(\d{4}-\d{2}-\d{2})/', basename($f), $m) ? $m[1] : '';
usort($autos, fn($a, $b) => [$bday($b), basename($a)] <=> [$bday($a), basename($b)]);
$lastAuto = setting('_backup_day');

admin_head('Backup', 'backup');
?>
<div class="grid2">
  <section class="card">
    <h2>Full backup</h2>
    <p class="muted">Database (shob text, settings, messages, users, blog) + shob chobi, ekta .zip file e.</p>
    <dl class="facts"><dt>Database</dt><dd><?= human_size($dbSize) ?></dd><dt>Images</dt><dd><?= $files ?> files · <?= human_size($bytes) ?></dd><dt>Last backup</dt><dd><?= $last ? e(ago($last['created_at'])) . ' · ' . e($last['username']) : 'Never' ?></dd></dl>
    <form method="post"><?= csrf() ?><input type="hidden" name="type" value="full"><button class="btn btn--main"><?= aicon('download', 16) ?>Download full backup<?= class_exists('ZipArchive') ? '' : ' (database only — server e zip nai)' ?></button></form>
  </section>
  <section class="card">
    <h2>Database only</h2>
    <p class="muted">Choto file — shudhu text, settings ar messages. Chobi nai.</p>
    <form method="post"><?= csrf() ?><input type="hidden" name="type" value="db"><button class="btn"><?= aicon('download', 16) ?>Download database</button></form>
    <h2 class="mt">Restore korte</h2>
    <p class="muted">Zip ta khule <code>data/site.sqlite</code> ar <code>uploads/</code> folder hosting er File Manager diye site e replace korun.</p>
  </section>
</div>
<section class="card">
  <div class="card-head"><h2>Automatic backups</h2>
    <form method="post"><?= csrf() ?><input type="hidden" name="action" value="auto_now"><button class="btn btn--sm"><?= aicon('download', 15) ?>Back up now</button></form></div>
  <p class="muted">Protidin nijei database er copy rakha hoy (shesh <?= BACKUP_KEEP_DAILY ?> din), ar proti Robibar chobi soho full backup (shesh <?= BACKUP_KEEP_WEEKLY ?> ta). Server er <code>data/backups/</code> folder e thake — website theke keu khulte pare na.</p>
  <p class="small">Last automatic backup: <b><?= $lastAuto ? e(date('d M Y', strtotime($lastAuto))) : 'ekhono hoy nai — site e prothom visit er por hobe' ?></b></p>
<?php if ($autos): ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Type</th><th>Size</th><th></th></tr></thead>
    <tbody>
<?php foreach ($autos as $f): $n = basename($f); $full = str_starts_with($n, 'full-'); ?>
      <tr><td><?= e(date('D, d M Y', strtotime($bday($f)))) ?></td><td><?= $full ? 'Full (database + images)' : 'Database' ?></td><td><?= human_size((int)filesize($f)) ?></td>
        <td><form method="post"><?= csrf() ?><input type="hidden" name="action" value="get"><input type="hidden" name="file" value="<?= e($n) ?>"><button class="btn btn--sm btn--ghost"><?= aicon('download', 14) ?>Download</button></form></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<p class="muted small">Tip: server nosto hole server er backup o jabe — mashe ekbar full backup download kore Google Drive e rakhun.</p>
<?php admin_foot();
