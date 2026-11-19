<?php
/*
 * Two-step login with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).
 * Standard TOTP (RFC 6238): 6 digits, 30-second steps, SHA-1. No outside service is involved.
 */

function base32_encode(string $bin): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $abc[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $b32): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($abc, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_at(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The matching time step (allowing ±30 s clock drift), or null. */
function totp_match(string $secret, string $code): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) if (hash_equals(totp_at($secret, $now + $d), $code)) return $now + $d;
    return null;
}

function totp_uri(string $secret, string $account): string
{
    $issuer = setting('site_name', 'Dakpion') . ' Admin';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/** 8 one-time recovery codes like "4F7K-9QX2". Returns [plain codes to show once, hashes to store]. */
function recovery_codes_new(): array
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $plain = [];
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $plain[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$plain, array_map(fn($c) => hash('sha256', $c), $plain)];
}

/**
 * Check a code for a user row (with totp_secret, totp_last, recovery_codes). A TOTP code can't be used twice;
 * a recovery code is crossed off. Returns 'totp', 'recovery' or '' (wrong).
 */
function second_factor_check(array $u, string $code): string
{
    $step = totp_match((string)$u['totp_secret'], $code);
    if ($step !== null && $step > (int)$u['totp_last']) {
        db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $u['id']]);
        return 'totp';
    }
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($norm) === 8) {
        $hash = hash('sha256', substr($norm, 0, 4) . '-' . substr($norm, 4));
        $left = json_decode((string)$u['recovery_codes'], true) ?: [];
        if (($i = array_search($hash, $left, true)) !== false) {
            unset($left[$i]);
            db()->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([json_encode(array_values($left)), $u['id']]);
            return 'recovery';
        }
    }
    return '';
}
