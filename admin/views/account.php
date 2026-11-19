<?php
defined('ADMIN') || exit;

$user = current_user();
$st = db()->prepare('SELECT totp_secret, recovery_codes, pass_hash FROM users WHERE id = ?');
$st->execute([$user['id']]);
$sec = $st->fetch();
$has2fa = (string)$sec['totp_secret'] !== '';

/* ── Two-step login ── */
$tf = $_POST['tf'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tf !== '') {
    $showCodes = function (): void {
        [$plain, $hashes] = recovery_codes_new();
        db()->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([json_encode($hashes), current_user()['id']]);
        $_SESSION['show_codes'] = $plain;
    };
    if ($tf === 'start' && !$has2fa) {
        $_SESSION['tf_secret'] = totp_new_secret();
    } elseif ($tf === 'confirm' && !$has2fa && ($pending = $_SESSION['tf_secret'] ?? '')) {
        if (($step = totp_match($pending, (string)($_POST['code'] ?? ''))) === null) {
            flash('Code ta mileni — app e ei account er ekhonkar code ta din.', 'err');
        } else {
            db()->prepare('UPDATE users SET totp_secret = ?, totp_last = ? WHERE id = ?')->execute([$pending, $step, $user['id']]);
            unset($_SESSION['tf_secret']);
            $showCodes();
            log_activity('Turned on two-step login');
            flash('Two-step login chalu hoyeche. Niche recovery code gula ekhoni save korun.');
        }
    } elseif ($tf === 'cancel') {
        unset($_SESSION['tf_secret']);
    } elseif (in_array($tf, ['off', 'codes'], true) && $has2fa) {
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$sec['pass_hash'])) {
            flash('Password vul.', 'err');
        } elseif ($tf === 'off') {
            db()->prepare("UPDATE users SET totp_secret = NULL, totp_last = NULL, recovery_codes = NULL WHERE id = ?")->execute([$user['id']]);
            log_activity('Turned off two-step login');
            flash('Two-step login bondho kora hoyeche.');
        } else {
            $showCodes();
            log_activity('Made new recovery codes');
            flash('Notun recovery code — purono gula ar kaj korbe na.');
        }
    }
    redirect(aurl('account') . '#two-step');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $new = (string)($_POST['new'] ?? '');
    $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $hash = (string)$st->fetchColumn();

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Email ta thik na.', 'err');
    elseif ($new !== '' && !password_verify((string)($_POST['current'] ?? ''), $hash)) flash('Current password vul.', 'err');
    elseif ($new !== '' && strlen($new) < 8) flash('Notun password kom kore 8 character.', 'err');
    elseif ($new !== '' && $new !== ($_POST['new2'] ?? '')) flash('Duita notun password mile nai.', 'err');
    else {
        db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $user['id']]);
        if ($new !== '') {
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            log_activity('Changed own password');
        }
        flash('Saved.');
    }
    redirect(aurl('account'));
}

admin_head('My account', 'account');
?>
<form class="card form narrow" method="post">
  <?= csrf() ?>
  <h2>Profile</h2>
  <p class="muted">Username: <b><?= e($user['username']) ?></b> · Role: <b><?= e(ROLES[$user['role']] ?? $user['role']) ?></b></p>
  <div class="field"><label for="nm">Name</label><input id="nm" name="name" value="<?= e($user['name']) ?>" autocomplete="name"></div>
  <div class="field"><label for="em">Email</label><input id="em" type="email" name="email" value="<?= e($user['email']) ?>" autocomplete="email"><small>Email diyeo login kora jabe. Password bhule gele reset link ei email e jabe.</small></div>
  <h2 class="mt">Change password</h2>
  <p class="muted">Password na bodlate chaile khali rakhun.</p>
  <div class="field"><label for="c">Current password</label><input id="c" type="password" name="current" autocomplete="current-password"></div>
  <div class="field"><label for="n">New password</label><input id="n" type="password" name="new" minlength="8" autocomplete="new-password"></div>
  <div class="field"><label for="n2">New password again</label><input id="n2" type="password" name="new2" minlength="8" autocomplete="new-password"></div>
  <div class="actions"><button class="btn btn--main" type="submit">Save</button></div>
</form>

<?php $pending = $_SESSION['tf_secret'] ?? ''; $codes = $_SESSION['show_codes'] ?? null; unset($_SESSION['show_codes']);
$left = count(json_decode((string)$sec['recovery_codes'], true) ?: []); ?>
<section class="card form narrow" id="two-step">
  <h2>Two-step login <?= $has2fa ? '<span class="badge badge--won">On</span>' : '<span class="badge">Off</span>' ?></h2>
<?php if ($codes): ?>
  <div class="flash flash--ok" role="status"><b>Recovery codes — ekhoni copy kore kothao rakhun.</b> Phone hariye gele protita code ekbar login e kaj korbe. Ei page theke gele ar dekhabe na.</div>
  <pre class="codes"><?= e(implode("\n", $codes)) ?></pre>
  <p><button class="btn btn--sm" type="button" data-copy="<?= e(implode("\n", $codes)) ?>"><?= aicon('copy', 15) ?>Copy codes</button></p>
<?php endif; ?>
<?php if (!$has2fa && !$pending): ?>
  <p class="muted">Password er pashapashi phone er authenticator app er 6-digit code lagbe. Password chori holeo keu dhukte parbe na.</p>
  <form method="post"><?= csrf() ?><button class="btn btn--main" name="tf" value="start">Turn on</button></form>
<?php elseif (!$has2fa): ?>
  <ol class="steps">
    <li>Phone e <b>Google Authenticator</b> ba <b>Microsoft Authenticator</b> install korun.</li>
    <li>App e <b>+</b> → <b>Scan QR code</b> diye niche scan korun.</li>
    <li>App je 6-digit code dekhay seta niche din.</li>
  </ol>
  <div class="qr-wrap"><div id="qr" data-qr="<?= e(totp_uri($pending, $user['email'] ?: $user['username'])) ?>"></div>
    <p class="small muted">Scan na hole app e "Enter a setup key" diye ei key din:<br><code class="key"><?= e(trim(chunk_split($pending, 4, ' '))) ?></code></p></div>
  <form method="post" class="inline-form"><?= csrf() ?>
    <div class="field"><label for="tfc">6-digit code</label><input id="tfc" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456"></div>
    <div class="actions"><button class="btn btn--main" name="tf" value="confirm">Confirm &amp; turn on</button><button class="btn btn--ghost" name="tf" value="cancel" formnovalidate>Cancel</button></div>
  </form>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>document.addEventListener('DOMContentLoaded', function () { var el = document.getElementById('qr'); if (window.QRCode && el) new QRCode(el, { text: el.dataset.qr, width: 184, height: 184, correctLevel: QRCode.CorrectLevel.M }); });</script>
<?php else: ?>
  <p class="muted">Login er shomoy authenticator app er code lage. Recovery code baki: <b><?= $left ?></b>.</p>
  <form method="post"><?= csrf() ?>
    <div class="field"><label for="tfp">Password (nishchit korte)</label><input id="tfp" type="password" name="current" required autocomplete="current-password"></div>
    <div class="actions"><button class="btn" name="tf" value="codes">New recovery codes</button><button class="btn btn--danger-text" name="tf" value="off" data-confirm="Two-step login bondho korben?">Turn off</button></div>
  </form>
<?php endif; ?>
</section>
<?php admin_foot();
