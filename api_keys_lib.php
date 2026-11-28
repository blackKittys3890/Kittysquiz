<?php
// Version 1.0
// API-Schlüssel pro Konto: verschlüsselt speichern (AES-256-GCM), nur serverseitig verwenden.
// Benötigt in config.php: define('API_KEY_SECRET', 'langer-zufälliger-text-mindestens-32-zeichen');

const AK_PROVIDERS = [
    'xai' => [
        'label' => 'xAI (Grok)',
        'url' => 'https://console.x.ai/team/default/api-keys',
        'used_by' => 'KI-Fragen erstellen',
        'steps' => [
            'Auf console.x.ai ein Konto anlegen oder mit Google anmelden.',
            'Guthaben aufladen. Die API wird nach Verbrauch abgerechnet, ein monatliches Limit ist empfehlenswert.',
            'Links auf „API Keys“ klicken, dann „Create API Key“.',
            'Den Key kopieren. Er wird nur einmal angezeigt. Hier einfügen und speichern.',
        ],
    ],
    'groq' => [
        'label' => 'Groq',
        'url' => 'https://console.groq.com/keys',
        'used_by' => null,
        'steps' => [
            'Auf console.groq.com mit E-Mail, Google oder GitHub anmelden.',
            'Im Menü „API Keys“ öffnen und „Create API Key“ wählen.',
            'Den Key kopieren. Er beginnt mit gsk_ und wird nur einmal angezeigt.',
        ],
    ],
    'gemini' => [
        'label' => 'Google Gemini',
        'url' => 'https://aistudio.google.com/apikey',
        'used_by' => null,
        'steps' => [
            'Auf aistudio.google.com mit einem Google-Konto anmelden.',
            'Im Menü „API Keys“ öffnen und „Create API key“ wählen.',
            'Ein Projekt auswählen. Bei neuen Konten wird eines automatisch angelegt.',
            'Den Key kopieren und hier einfügen.',
        ],
    ],
    'claude' => [
        'label' => 'Claude (Anthropic)',
        'url' => 'https://platform.claude.com/settings/keys',
        'used_by' => null,
        'steps' => [
            'Auf platform.claude.com ein Konto anlegen oder anmelden.',
            'Abrechnung einrichten und Guthaben aufladen, die API ist kostenpflichtig.',
            'Unter Settings → API keys „Create Key“ wählen.',
            'Den Key kopieren. Er beginnt mit sk-ant- und wird nur einmal angezeigt.',
        ],
    ],
    'openai' => [
        'label' => 'ChatGPT (OpenAI)',
        'url' => 'https://platform.openai.com/api-keys',
        'used_by' => null,
        'steps' => [
            'Auf platform.openai.com ein Konto anlegen oder anmelden.',
            'Abrechnung einrichten und Guthaben aufladen.',
            'Unter „API keys“ die Schaltfläche „Create new secret key“ wählen.',
            'Den Key kopieren. Er wird nur einmal angezeigt.',
        ],
    ],
    'deepseek' => [
        'label' => 'DeepSeek',
        'url' => 'https://platform.deepseek.com/api_keys',
        'used_by' => null,
        'steps' => [
            'Auf platform.deepseek.com ein Konto anlegen oder anmelden.',
            'Guthaben aufladen, die API ist kostenpflichtig.',
            'Unter „API keys“ die Schaltfläche „Create new API key“ wählen.',
            'Den Key kopieren und hier einfügen.',
        ],
    ],
];

function ak_material(): string
{
    if (!defined('API_KEY_SECRET') || strlen((string)API_KEY_SECRET) < 32) {
        throw new RuntimeException('API_KEY_SECRET fehlt oder ist kürzer als 32 Zeichen in config.php.');
    }
    return hash('sha256', (string)API_KEY_SECRET, true);
}

function ak_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ak_material(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
    return base64_encode($iv . $tag . $cipher);
}

function ak_decrypt(string $enc): ?string
{
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', ak_material(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

// Gibt den entschlüsselten Key eines Kontos zurück oder null, wenn keiner hinterlegt ist.
function ak_get(string $userKey, string $provider): ?string
{
    $st = db()->prepare('SELECT key_enc FROM kr_api_keys WHERE user_key = ? AND provider = ?');
    $st->execute([$userKey, $provider]);
    $enc = $st->fetchColumn();
    return $enc ? ak_decrypt((string)$enc) : null;
}

// Übersicht ohne Klartext: Anbieter => letzte 4 Zeichen und Zeitpunkt
function ak_overview(string $userKey): array
{
    $st = db()->prepare('SELECT provider, key_hint, created_at FROM kr_api_keys WHERE user_key = ?');
    $st->execute([$userKey]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['provider']] = ['hint' => $r['key_hint'], 'created' => $r['created_at']];
    }
    return $out;
}

function ak_save(string $userKey, string $provider, string $plain): void
{
    if (!isset(AK_PROVIDERS[$provider])) throw new RuntimeException('Unbekannter Anbieter.');
    $plain = trim($plain);
    if (strlen($plain) < 16 || strlen($plain) > 300 || preg_match('/\s/', $plain)) {
        throw new RuntimeException('Der Schlüssel sieht ungültig aus. Bitte den vollständigen Key ohne Leerzeichen einfügen.');
    }
    db()->prepare('INSERT INTO kr_api_keys (user_key, provider, key_enc, key_hint) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE key_enc = VALUES(key_enc), key_hint = VALUES(key_hint), created_at = CURRENT_TIMESTAMP')
        ->execute([$userKey, $provider, ak_encrypt($plain), substr($plain, -4)]);
}

function ak_delete(string $userKey, string $provider): void
{
    db()->prepare('DELETE FROM kr_api_keys WHERE user_key = ? AND provider = ?')->execute([$userKey, $provider]);
}
