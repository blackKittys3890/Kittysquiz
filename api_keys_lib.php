<?php
// Version 1.3
// API-Schlüssel pro Konto: verschlüsselt speichern (AES-256-GCM), nur serverseitig verwenden.
// Benötigt in config.php: define('API_KEY_SECRET', 'langer-zufälliger-text-mindestens-32-zeichen');
// Modelle (Standard, im Code änderbar): Groq openai/gpt-oss-120b, Gemini gemini-3.5-flash,
// Claude claude-haiku-5-5, OpenAI gpt-5.4-mini, DeepSeek deepseek-chat.

const AK_PROVIDERS = [
    'groq' => [
        'label' => 'Groq',
        'url' => 'https://console.groq.com/keys',
        'used_by' => 'KI-Fragen erstellen',
        'steps' => [
            'Auf console.groq.com mit E-Mail, Google oder GitHub anmelden.',
            'Im Menü „API Keys“ öffnen und „Create API Key“ wählen.',
            'Den Key kopieren. Er beginnt mit gsk_ und wird nur einmal angezeigt.',
        ],
    ],
    'gemini' => [
        'label' => 'Google Gemini',
        'url' => 'https://aistudio.google.com/apikey',
        'used_by' => 'KI-Fragen erstellen',
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
        'used_by' => 'KI-Fragen erstellen',
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
        'used_by' => 'KI-Fragen erstellen',
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
        'used_by' => 'KI-Fragen erstellen',
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

// Modelle für die KI-Funktionen
const AK_MODELS = [
    'groq' => 'openai/gpt-oss-120b',
    'gemini' => 'gemini-3.5-flash',
    'claude' => 'claude-haiku-5-5',
    'openai' => 'gpt-5.4-mini',
    'deepseek' => 'deepseek-chat',
];

function ak_is_ki_provider(string $provider): bool
{
    return isset(AK_PROVIDERS[$provider]) && AK_PROVIDERS[$provider]['used_by'] !== null;
}

function ak_post(string $url, array $headers, array $body): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Die KI-Anfrage ist fehlgeschlagen: ' . ($err ?: 'keine Verbindung.'));
    }
    if ($code >= 400) {
        // Fehlertext des Anbieters mit anzeigen, damit man die Ursache sieht
        $j = json_decode((string)$raw, true);
        $m = $j['error']['message'] ?? $j['error'] ?? $j['message'] ?? '';
        if (is_array($m)) $m = json_encode($m, JSON_UNESCAPED_UNICODE);
        $m = mb_substr(trim((string)$m) ?: strip_tags((string)$raw), 0, 300);
        throw new RuntimeException('Die KI-Anfrage ist fehlgeschlagen (HTTP ' . $code . ')' . ($m !== '' ? ': ' . $m : '.'));
    }
    return (string)$raw;
}

// Eine Anfrage an den gewählten Anbieter mit dem Key des Kontos. Gibt den Antworttext zurück.
function ak_chat(string $provider, string $apiKey, string $system, string $userText): string
{
    $model = AK_MODELS[$provider] ?? '';
    if ($model === '') throw new RuntimeException('Dieser Anbieter kann noch keine KI-Anfragen.');

    if ($provider === 'claude') {
        $raw = ak_post('https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            ['model' => $model, 'max_tokens' => 2000, 'system' => $system,
             'messages' => [['role' => 'user', 'content' => $userText]]]);
        $r = json_decode($raw, true);
        return (string)($r['content'][0]['text'] ?? '');
    }

    if ($provider === 'gemini') {
        $raw = ak_post('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
            ['x-goog-api-key: ' . $apiKey],
            ['systemInstruction' => ['parts' => [['text' => $system]]],
             'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
             'generationConfig' => ['temperature' => 0.7]]);
        $r = json_decode($raw, true);
        return (string)($r['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    // Groq, OpenAI und DeepSeek nutzen das gleiche Chat-Format
    $urls = [
        'groq' => 'https://api.groq.com/openai/v1/chat/completions',
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/chat/completions',
    ];
    $raw = ak_post($urls[$provider], ['Authorization: Bearer ' . $apiKey],
        ['model' => $model, 'temperature' => 0.7,
         'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userText]]]);
    $r = json_decode($raw, true);
    return (string)($r['choices'][0]['message']['content'] ?? '');
}