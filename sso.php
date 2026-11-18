<?php
// Version 1.0
// Dieses Secret muss auf allen Seiten identisch sein und darf nicht öffentlich sein.
const SSO_SECRET = '1765155531a13340d6fa98239cea896510ef81905a493b74350178fd50bde3da';

function sso_create_token(array $data, int $ttl = 60): string
{
    $payload = base64_encode(json_encode([
        'data' => $data,
        'exp'  => time() + $ttl,
    ]));
    $sig = hash_hmac('sha256', $payload, SSO_SECRET);
    return $payload . '.' . $sig;
}

function sso_verify_token(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$payload, $sig] = $parts;

    if (!hash_equals(hash_hmac('sha256', $payload, SSO_SECRET), $sig)) {
        return null; // Manipuliert
    }

    $decoded = json_decode(base64_decode($payload), true);
    if (!$decoded || $decoded['exp'] < time()) {
        return null; // Abgelaufen
    }
    return $decoded['data'];
}
// Version 1.0