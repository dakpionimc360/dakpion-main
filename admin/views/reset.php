<?php
/* Forgotten password: email a one-time link (valid 1 hour), then set a new password with it. */
defined('ADMIN') || exit;

if (current_user()) redirect(aurl('account'));

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$sent = false;

function reset_row(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = db()->prepare('SELECT r.*, u.username FROM password_resets r JOIN users u ON u.id = r.user_id
        WHERE r.token_hash = ? AND r.used = 0 AND r.expires_at > ? AND u.active = 1');
    $st->execute([hash('sha256', $token), time()]);
    return $st->fetch() ?: null;
}

$row = $token !== '' ? reset_row($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($token !== '') {
        // Step 2: choose the new password.
        $pass = (string)($_POST['password'] ?? '');
        if (!$row) $error = 'Link ta ar kaj korche na (1 ghonta por expire hoy ba already use hoyeche). Notun link nin.';
        elseif (strlen($pass) < 8) $error = 'Password kom kore 8 character.';
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $error = 'Duita password mile nai.';
        else {
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);
            db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$row['user_id']]);
            $GLOBALS['ACTOR'] = ['id' => $row['user_id'], 'username' => $row['username']];
            log_activity('Reset password by email');
            flash('Password bodlano hoyeche — notun password diye login korun.');
            redirect(aurl('login'));
        }
    } elseif (too_many_attempts()) {
        $error = 'Onek bar try kora hoyeche. 15 minute por abar try korun.';
    } else {
        // Step 1: ask for the link. Same answer whether or not the account exists.
        db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '', time()]);
        $who = trim((string)($_POST['username'] ?? ''));
        $st = db()->prepare("SELECT id, username, name, email FROM users WHERE active = 1 AND email <> '' AND (username = ? OR email = ?)");
        $st->execute([$who, $who]);
        if ($who !== '' && ($u = $st->fetch())) {
            $raw = bin2hex(random_bytes(32));
            db()->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?')->execute([$u['id'], time()]);
            insert_row(db(), 'password_resets', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $raw), 'expires_at' => time() + 3600, 'created_at' => now()]);
            $link = abs_url('admin/reset') . '?token=' . $raw;
            $body = 'Hi ' . ($u['name'] ?: $u['username']) . ",\n\nAdmin panel er password notun korte ei link e jan (1 ghonta kaj korbe, ekbar):\n\n$link\n\n"
                . "Apni na chaile ei email ta ignore korun — password bodlabe na.\n\n— " . setting('site_name', 'Website') . ' admin';
            send_mail($u['email'], 'Password reset — ' . setting('site_name', 'Website') . ' admin', $body);
        }
        $sent = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Reset password — Admin</title>
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
<?php if ($token !== ''): ?>
    <h1>Notun password</h1>
<?php if (!$row): ?>
    <div class="flash flash--err" role="alert">Link ta ar kaj korche na (1 ghonta por expire hoy ba already use hoyeche).</div>
    <a class="btn btn--main btn--block" href="<?= aurl('reset') ?>">Notun link nin</a>
<?php else: ?>
    <p class="muted">Account: <b><?= e($row['username']) ?></b></p>
    <?php if ($error): ?><div class="flash flash--err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field"><label for="p">New password</label><input id="p" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></div>
    <div class="field"><label for="p2">New password abar</label><input id="p2" type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
    <button class="btn btn--main btn--block" type="submit">Save password</button>
<?php endif; ?>
<?php elseif ($sent): ?>
    <h1>Email check korun</h1>
    <p class="muted">Ei username/email er account thakle, ar tate email deya thakle, ekta reset link pathano hoyeche. Inbox ar spam duitai dekhun — link 1 ghonta kaj korbe.</p>
    <p class="muted small">Email ashche na? Admin ke bolun Users page theke apnar password bodle dite.</p>
<?php else: ?>
    <h1>Password bhule gechen?</h1>
    <p class="muted">Apnar username ba email din — password notun korar link email e pathabo.</p>
    <?php if ($error): ?><div class="flash flash--err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf() ?>
    <div class="field"><label for="u">Username or email</label><input id="u" name="username" required autocomplete="username" autofocus></div>
    <button class="btn btn--main btn--block" type="submit">Send reset link</button>
<?php endif; ?>
    <a class="auth-back" href="<?= aurl('login') ?>">← Back to login</a>
  </form>
</div>
</body>
</html>
