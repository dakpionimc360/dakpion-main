<?php
defined('ADMIN') || exit;

$tab = $seg[1] ?? 'contact';
if (!isset(SETTINGS[$tab])) redirect(aurl('settings/contact'));
if ($tab !== 'popup') require_admin(); // editors may run offers, not change site settings
$group = SETTINGS[$tab];

$current = [];
foreach ($group['fields'] as $k => $_) $current[$k] = setting($k);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_mail' && $tab === 'email') {
    $me = current_user();
    $to = filter_var($me['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $me['email'] : setting('notify_email');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('Test pathanor jonno My account e apnar email din (ba Contact form e notify email).', 'err');
    } else {
        $err = send_mail($to, 'Test email from ' . setting('site_name', 'the website'), "Ei email ta pouchale website theke mail pathano thik ache.\n\nSent " . now() . ' via ' . (smtp_ready() ? 'SMTP (' . setting('smtp_host') . ')' : 'server mail()'));
        $err === '' ? flash("Test email pathano hoyeche: $to — inbox (ar spam) check korun.") : flash('Pathano jay nai: ' . $err, 'err');
        log_activity('Sent test email', $to, $err);
    }
    redirect(aurl('settings/email'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $vals = collect_fields($group['fields']);
        $st = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $changed = [];
        foreach ($vals as $k => $v) {
            if ($group['fields'][$k]['type'] === 'password' && $v === '') continue; // empty = keep the saved one
            if ($v !== $current[$k]) $changed[] = $group['fields'][$k]['label'];
            $st->execute([$k, $v]);
        }
        if ($changed) log_activity('Updated settings', $group['label'], implode(', ', $changed));
        flash('Saved.');
        redirect(aurl('settings/' . $tab));
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
        foreach ($_POST['f'] ?? [] as $k => $v) if (isset($current[$k]) && !is_array($v)) $current[$k] = $v;
    }
}

$preview = $tab === 'popup' ? '<a class="btn btn--ghost" href="' . url() . '" target="_blank" rel="noopener">' . aicon('eye', 16) . '<span>Preview</span></a>' : '';
admin_head($group['label'], 'set-' . $tab, $tab === 'popup' ? ['Website' => null] : ['Settings' => null]);
?>
<?php if (is_admin() && $tab !== 'popup'): ?>
<nav class="tabs"><?php foreach (SETTINGS as $k => $g): if ($k === 'popup') continue; ?><a href="<?= aurl('settings/' . $k) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= e($g['label']) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<?php if ($tab === 'popup'): ?>
<p class="muted intro">Top bar ta site er upore shob page e dekhay. Popup ta visitor ke ekta somoy por dekhay — ekbar bondho korle setting onujayi abar dekhabe. Text bodlale shobai notun kore dekhbe.</p>
<?php endif; ?>
<form class="card form form-grid" method="post" enctype="multipart/form-data">
  <?= csrf() ?>
<?php foreach ($group['fields'] as $k => $def) echo render_field($k, $def, $current[$k]); ?>
  <div class="actions wide"><button class="btn btn--main" type="submit">Save changes</button><?= $preview ?></div>
</form>
<?php if ($tab === 'email'): $last = json_decode(setting('_mail_last') ?: 'null', true); ?>
<section class="card form">
  <h2>Test</h2>
  <p class="muted">Save korar por test pathan — apnar account er email e jabe. Contact form er notun message ar password reset ei setting diyei jabe.</p>
  <p class="small"><?= smtp_ready() ? '✓ SMTP chalu: ' . e(setting('smtp_host')) : '⚠ SMTP set kora nai — ekhon server er mail() diye jacche, ja prayi spam e jay ba pouchay na.' ?></p>
<?php if ($last): ?>
  <p class="small muted">Last email: <?= e(ago($last['at'])) ?> → <?= e($last['to']) ?> via <?= e($last['via']) ?> — <?= $last['ok'] ? '<b>sent</b>' : '<b class="overdue">failed</b>: ' . e($last['error']) ?></p>
<?php endif; ?>
  <form method="post"><?= csrf() ?><input type="hidden" name="action" value="test_mail"><button class="btn"><?= aicon('mail', 16) ?>Send test email</button></form>
</section>
<?php endif; ?>
<?php admin_foot();
