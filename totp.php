<?php
// Version 1.0
// TOTP für Authenticator-Apps (z. B. Google Authenticator), ohne externe Bibliothek. Backup-Codes für Notfälle.

const TOTP_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0');
        $out .= TOTP_B32[bindec($chunk)];
    }
    return $out;
}

function b32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) {
        $bits .= str_pad(decbin((int)strpos(TOTP_B32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) $out .= chr(bindec($b));
    }
    return $out;
}

function totp_new_secret(): string
{
    return b32_encode(random_bytes(20));
}

function totp_at(string $secret, int $counter): string
{
    $key = b32_decode($secret);
    $h = hash_hmac('sha1', pack('J', $counter), $key, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | ((ord($h[$o + 1]) & 0xFF) << 16) | ((ord($h[$o + 2]) & 0xFF) << 8) | (ord($h[$o + 3]) & 0xFF);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Erlaubt eine Abweichung von 30 Sekunden in beide Richtungen
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $c = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_at($secret, $c + $i), $code)) return true;
    }
    return false;
}

function totp_uri(string $secret, string $user): string
{
    return 'otpauth://totp/' . rawurlencode('KittysRaetsel:' . $user) . '?secret=' . $secret . '&issuer=KittysRaetsel';
}

// 8 Backup-Codes, je 8 Zeichen
function backup_make(): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

// Gespeichert werden nur die Hashes der Codes
function backup_hashes(array $codes): string
{
    return json_encode(array_map(fn($c) => hash('sha256', $c), $codes));
}

// Einen Backup-Code verbrauchen. Gibt die neue Liste zurück oder null, wenn der Code nicht passt.
function backup_use(string $json, string $code): ?string
{
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    $hashes = json_decode($json, true) ?: [];
    $h = hash('sha256', $code);
    $idx = array_search($h, $hashes, true);
    if ($idx === false) return null;
    unset($hashes[$idx]);
    return json_encode(array_values($hashes));
}
// Version 1.0