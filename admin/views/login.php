<?php
defined('ADMIN') || exit;

if (current_user()) redirect(aurl());

$setup = !has_users();
$error = '';

// Step 2 of a login with two-step on: password was right, waiting for the 6-digit code (5 minutes).
if (isset($_GET['cancel'])) { unset($_SESSION['mfa']); redirect(aurl('login')); }
$mfa = $_SESSION['mfa'] ?? null;
if ($mfa && time() - $mfa['at'] > 300) { unset($_SESSION['mfa']); $mfa = null; $error = 'Somoy shesh — abar login korun.'; }
if ($mfa && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    check_csrf();
    $st = db()->prepare('SELECT id, username, totp_secret, totp_last, recovery_codes, active FROM users WHERE id = ?');
    $st->execute([$mfa['uid']]);
    $row = $st->fetch();
    if (too_many_attempts()) {
        $error = 'Onek bar vul hoyeche. 15 minute por abar try korun.';
    } elseif ($row && (int)$row['active'] && ($how = second_factor_check($row, (string)$_POST['code']))) {
        unset($_SESSION['mfa']);
        log_in($row);
        if ($how === 'recovery') {
            $left = count(json_decode((string)db()->query('SELECT recovery_codes FROM users WHERE id = ' . (int)$row['id'])->fetchColumn(), true) ?: []);
            log_activity('Logged in with a recovery code', '', "$left left");
            flash("Recovery code diye login hoyeche — ar $left ta baki. Phone hariye gele My account e giye two-step notun kore set korun.", $left <= 2 ? 'err' : 'ok');
        }
        redirect(str_starts_with((string)$mfa['next'], aurl()) ? $mfa['next'] : aurl());
    } else {
        db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '', time()]);
        $error = 'Code ta mileni. App er notun code ta din (proti 30 second e bodlay).';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['code'])) {
    check_csrf();
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if ($setup) {
        if (!preg_match('/^[\w.@-]{3,40}$/', $user)) $error = 'Username 3–40 character er hote hobe (letter, number, . _ - @).';
        elseif (strlen($pass) < 8) $error = 'Password kom kore 8 character.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $error = 'Duita password mile nai.';
        elseif (has_users()) $error = 'Admin already toiri kora ache — login korun.';
        else {
            $id = insert_row(db(), 'users', ['username' => $user, 'name' => trim((string)($_POST['name'] ?? '')), 'role' => 'admin', 'active' => 1,
                'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => now()]);
            log_in(['id' => $id, 'username' => $user]);
            flash('Admin account toiri hoyeche. Shagotom!');
            redirect(aurl());
        }
    } elseif (too_many_attempts()) {
        $error = 'Onek bar vul hoyeche. 15 minute por abar try korun.';
    } else {
        $st = db()->prepare('SELECT id, username, pass_hash, active, totp_secret FROM users WHERE username = ? OR (email = ? AND email <> \'\')');
        $st->execute([$user, $user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, $row['pass_hash'])) {
            if (!(int)$row['active']) {
                $error = 'Ei account ta bondho kora ache. Admin er shathe kotha bolun.';
            } else {
                if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
                }
                $next = (string)($_GET['next'] ?? '');
                if ((string)$row['totp_secret'] !== '') {
                    // Password OK — now ask for the code from the app.
                    session_regenerate_id(true);
                    $_SESSION['mfa'] = ['uid' => (int)$row['id'], 'at' => time(), 'next' => $next];
                    redirect(aurl('login'));
                }
                log_in($row);
                redirect(str_starts_with($next, aurl()) ? $next : aurl());
            }
        } else {
            db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '', time()]);
            $error = 'Username ba password vul.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $setup ? 'Setup' : 'Login' ?> — Admin</title>
<link rel="icon" href="<?= url('assets/images/favicon.png') ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('admin/admin.css') ?>">
</head>
<body class="auth">
<div class="auth-wrap">
  <div class="auth-side" aria-hidden="true">
    <div class="auth-ring"><b>360°</b></div>
    <p>Website, leads, blog — sob ek jaygay.</p>
  </div>
  <form class="auth-card" method="post">
    <img src="<?= url('assets/images/logo-dark.webp') ?>" alt="Dakpion IMC">
<?php if ($mfa): ?>
    <h1>Two-step code</h1>
    <p class="muted">Authenticator app e <b><?= e(setting('site_name', 'Dakpion')) ?> Admin</b> er 6-digit code ta din.</p>
    <?php if ($error): ?><div class="flash flash--err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf() ?>
    <div class="field"><label for="code">Code</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" autofocus maxlength="12"></div>
    <button class="btn btn--main btn--block" type="submit">Verify</button>
    <p class="muted small">Phone kache nai? Recovery code (jemon <code>4F7K-9QX2</code>) eki box e din.</p>
    <a class="auth-back" href="<?= aurl('login') ?>?cancel=1">← Onno account e login</a>
  </form>
</div>
</body>
</html>
<?php return; endif; ?>
    <h1><?= $setup ? 'Admin account toiri korun' : 'Welcome back' ?></h1>
    <p class="muted"><?= $setup ? 'Prothom bar — ekhane je username ar password diben, sheta diyei pore login korben.' : 'Admin panel e login korun.' ?></p>
    <?php if ($error): ?><div class="flash flash--err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf() ?>
    <?php if ($setup): ?><div class="field"><label for="n">Your name</label><input id="n" name="name" autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></div><?php endif; ?>
    <div class="field"><label for="u">Username<?= $setup ? '' : ' or email' ?></label><input id="u" name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" autofocus></div>
    <div class="field"><label for="p">Password</label><input id="p" type="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"<?= $setup ? ' minlength="8"' : '' ?>></div>
    <?php if ($setup): ?><div class="field"><label for="p2">Password abar</label><input id="p2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div><?php endif; ?>
    <button class="btn btn--main btn--block" type="submit"><?= $setup ? 'Create account' : 'Log in' ?></button>
    <?php if (!$setup): ?><a class="auth-back" href="<?= aurl('reset') ?>">Password bhule gechen?</a><?php endif; ?>
    <a class="auth-back" href="<?= url() ?>">← Back to website</a>
  </form>
</div>
</body>
</html>
