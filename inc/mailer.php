<?php
/*
 * Email sending. With SMTP filled in (Settings → Email sending) mail goes through a real mailbox
 * (Gmail, Zoho, the host's own mail…), which lands in inboxes far more reliably than PHP mail().
 * Without it, falls back to mail().
 */

function smtp_ready(): bool
{
    return setting('smtp_host') !== '' && setting('smtp_user') !== '' && setting('smtp_pass') !== '';
}

/**
 * Send a plain-text email. Returns '' on success, or a short reason it failed.
 * The outcome is remembered so Settings can show the last result.
 */
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): string
{
    $site = setting('site_name', 'Website');
    $host = preg_replace('/^www\./', '', preg_replace('/[^\w.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $fromEmail = setting('mail_from') ?: (smtp_ready() && filter_var(setting('smtp_user'), FILTER_VALIDATE_EMAIL) ? setting('smtp_user') : 'no-reply@' . $host);
    $fromName = setting('mail_from_name') ?: $site;
    $enc = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $clean = fn(string $s) => str_replace(["\r", "\n"], '', $s);
    $to = $clean($to);
    $replyTo = $clean($replyTo);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $enc($clean($fromName)) . ' <' . $clean($fromEmail) . '>',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? $host) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    $encodedBody = chunk_split(base64_encode($body));

    if (smtp_ready()) {
        try {
            smtp_send($fromEmail, $to, array_merge(['To: ' . $to, 'Subject: ' . $enc($clean($subject))], $headers), $encodedBody);
            $err = '';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } else {
        $ok = @mail($to, $enc($clean($subject)), $encodedBody, implode("\r\n", $headers));
        $err = $ok ? '' : 'Server mail() failed. Add SMTP in Settings → Email sending.';
    }
    try {
        db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['_mail_last', json_encode(['at' => now(), 'to' => $to, 'ok' => $err === '', 'error' => $err, 'via' => smtp_ready() ? 'SMTP' : 'mail()'])]);
    } catch (PDOException) {}
    return $err;
}

/** Minimal SMTP client: SSL (465) or STARTTLS (587), AUTH LOGIN. Throws RuntimeException with a readable reason. */
function smtp_send(string $from, string $to, array $headers, string $body): void
{
    $host = setting('smtp_host');
    $port = (int)(setting('smtp_port') ?: 587);
    $secure = setting('smtp_secure') ?: ($port === 465 ? 'ssl' : 'tls');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $addr = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($addr, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Could not connect to $host:$port ($errstr)");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int)substr($text, 0, 3), trim($text)];
    };
    $cmd = function (?string $line, array $ok, string $what) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $ok, true)) {
            $hint = $code === 535 ? ' — username ba password vul (Gmail hole App password lagbe)' : '';
            throw new RuntimeException($what . ' failed' . $hint . ': ' . (preg_replace('/\s+/', ' ', $text) ?: 'no answer from server'));
        }
        return $text;
    };

    try {
        $me = preg_replace('/[^\w.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $cmd(null, [220], 'Connect');
        $ehlo = $cmd("EHLO $me", [250], 'EHLO');
        if ($secure === 'tls') {
            if (!str_contains(strtoupper($ehlo), 'STARTTLS')) throw new RuntimeException('Server does not offer STARTTLS on this port — try port 465 with SSL.');
            $cmd('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS handshake failed.');
            $cmd("EHLO $me", [250], 'EHLO');
        }
        $cmd('AUTH LOGIN', [334], 'Login');
        $cmd(base64_encode(setting('smtp_user')), [334], 'Login (username)');
        $cmd(base64_encode(setting('smtp_pass')), [235], 'Login');
        $cmd('MAIL FROM:<' . $from . '>', [250], 'Sender');
        $cmd('RCPT TO:<' . $to . '>', [250, 251], 'Recipient');
        $cmd('DATA', [354], 'DATA');
        $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
        $cmd($data . "\r\n.", [250], 'Sending');
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
