<?php
declare(strict_types=1);

// PHP HTML hata çıktısını engelle — JSON endpoint
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $code = 200): never {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (\Throwable $e): void {
    json_exit(['error' => 'Sunucu hatası: ' . $e->getMessage()], 500);
});

require_once __DIR__ . '/init_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit(['error' => 'Yalnızca POST desteklenir.'], 405);
}

$pdo      = db_connect();
$rows     = $pdo->query("SELECT key, value FROM settings")->fetchAll();
$settings = array_column($rows, 'value', 'key');

$raw_model          = $settings['parser_model'] ?? 'groq|llama-3.1-8b-instant';
[$provider, $model] = array_pad(explode('|', $raw_model, 2), 2, '');

// PDF metnini al
$pdf_text = trim($_POST['text'] ?? '');
if (empty($pdf_text)) {
    $body_raw = json_decode(file_get_contents('php://input'), true);
    $pdf_text = trim($body_raw['text'] ?? '');
}

if (mb_strlen($pdf_text) < 30) {
    json_exit(['error' => 'PDF metni çok kısa veya boş. Geçerli bir banka ekstresi yükleyin.']);
}

$api_map = [
    'openai'    => ['url' => 'https://api.openai.com/v1/chat/completions',      'key' => $settings['openai_api_key']    ?? ''],
    'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages',           'key' => $settings['anthropic_api_key'] ?? ''],
    'groq'      => ['url' => 'https://api.groq.com/openai/v1/chat/completions', 'key' => $settings['groq_api_key']      ?? ''],
];
$api = $api_map[$provider] ?? $api_map['groq'];

if (empty($api['key'])) {
    json_exit(['error' => ucfirst($provider) . ' API anahtarı ayarlanmamış. Ayarlar sayfasına gidin.']);
}

// Sistem prompt — net örnek format ve strict dizi talebi
$system_prompt =
    'Sen bir finansal veri ayıklama asistanısın. '
    . 'Görevin, sana verilen banka ekstresi metninden tüm işlemleri çıkarmak ve '
    . 'SADECE geçerli bir JSON dizisi (array) döndürmektir. '
    . 'YASAK: markdown, kod bloğu (```), açıklama, sohbet, önsöz. '
    . 'Yanıtın doğrudan [ ile başlayıp ] ile bitmeli. '
    . "\n\nÖrnek çıktı:\n"
    . '[{"date":"2024-03-15","description":"Migros Market","amount":245.80,"type":"expense","category":"Market","is_subscription":false,"is_installment":false},'
    . '{"date":"2024-03-14","description":"Netflix","amount":79.99,"type":"expense","category":"Abonelik","is_subscription":true,"is_installment":false}]'
    . "\n\nAlan açıklamaları:\n"
    . '- date: YYYY-MM-DD. Belgede tarih yoksa null.\n'
    . '- description: İşlemin kısa anlamlı adı (marka adını koru).\n'
    . '- amount: Pozitif sayı (daima > 0).\n'
    . '- type: "expense" (gider) veya "income" (gelir/maaş).\n'
    . '- category: Şunlardan biri: Market, Restoran, Kafe, Ulaşım, Yakıt, Fatura, Abonelik, Teknoloji, Sağlık, Eğitim, Giyim, Eğlence, Spor, Kira, Taksit, Maaş, Diğer.\n'
    . '- is_subscription: Netflix/Spotify/YouTube gibi aylık yenilenenler için true, diğerleri false.\n'
    . '- is_installment: Taksitli kredi kartı ödemeleri için true, diğerleri false.';

$user_text = mb_substr($pdf_text, 0, 8000);

if ($provider === 'anthropic') {
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 4096,
        'system'     => $system_prompt,
        'messages'   => [['role' => 'user', 'content' => $user_text]],
    ]);
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $api['key'],
        'anthropic-version: 2023-06-01',
    ];
} else {
    $body = json_encode([
        'model'       => $model,
        'temperature' => 0.1,
        'messages'    => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $user_text],
        ],
    ]);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api['key'],
    ];
}

$ch = curl_init($api['url']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_err) {
    json_exit(['error' => 'Ağ hatası: ' . $curl_err]);
}

$api_data = json_decode($response, true);

if ($http_code >= 400) {
    $err_msg = $api_data['error']['message'] ?? $api_data['error']['msg'] ?? 'Bilinmeyen API hatası.';
    json_exit(['error' => "API Hatası ({$http_code}): {$err_msg}"]);
}

$content = $provider === 'anthropic'
    ? ($api_data['content'][0]['text'] ?? '')
    : ($api_data['choices'][0]['message']['content'] ?? '');

if (empty($content)) {
    json_exit(['error' => 'AI boş yanıt döndürdü.']);
}

// ── Markdown / ön-ek temizleme ───────────────────────────────────
// 1. Kod bloğu: ``` veya ```json (baştaki ve sondaki, çok satırlı)
$content = preg_replace('/^```(?:json)?\s*/im', '', $content);
$content = preg_replace('/\s*```\s*$/im', '', $content);
// 2. Satır başı ve sonu gereksiz boşluk
$content = trim($content);

// Hata ayıklama: AI ham yanıtını logla (ilk 400 karakter)
error_log('[process_ai] raw content (first 400): ' . mb_substr($content, 0, 400));

// ── Sağlam JSON ayrıştırma (farklı AI yanıt formatlarını destekle) ─
$transactions = null;

// Deneme 1: Doğrudan parse (beklenen: [...])
$decoded = json_decode($content, true);
if (is_array($decoded)) {
    // Düz dizi mi, yoksa tek obje mi?
    if (isset($decoded[0]) || empty($decoded)) {
        $transactions = $decoded; // Düz dizi ✓
    } else {
        // Tek obje gelmiş olabilir: {"transactions":[...]}, {"data":[...]}, vs.
        foreach (['transactions', 'items', 'data', 'result', 'işlemler'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $transactions = $decoded[$key];
                break;
            }
        }
        // Objenin kendisi tek bir işlem mi?
        if ($transactions === null && isset($decoded['amount'])) {
            $transactions = [$decoded];
        }
    }
}

// Deneme 2: İçerikte JSON dizisi regex ile ara
if ($transactions === null) {
    if (preg_match('/(\[[\s\S]*?\])/u', $content, $m)) {
        $try = json_decode($m[1], true);
        if (is_array($try)) {
            $transactions = $try;
        }
    }
}

// Deneme 3: İlk { ... } bloğunu al ve diziye sar
if ($transactions === null) {
    if (preg_match('/(\{[\s\S]*?\})/u', $content, $m)) {
        $try = json_decode($m[1], true);
        if (is_array($try) && isset($try['amount'])) {
            $transactions = [$try];
        }
    }
}

if (!is_array($transactions) || empty($transactions)) {
    $raw_preview = mb_substr($content, 0, 300);
    json_exit(['error' => 'AI geçerli işlem döndürmedi. Ham yanıt: ' . $raw_preview]);
}

// ── Tarih normalizasyonu ──────────────────────────────────────────
// AI farklı formatlar döndürebilir: DD.MM.YYYY, DD/MM/YYYY, YYYY.MM.DD vb.
function normalize_date(?string $raw): ?string {
    if ($raw === null || trim($raw) === '' || trim($raw) === 'null') return null;
    $d = trim($raw);

    // Zaten doğru: YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;

    // DD.MM.YYYY | DD/MM/YYYY | DD-MM-YYYY
    if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    // YYYY.MM.DD | YYYY/MM/DD
    if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    // DD MM YYYY (boşluklu)
    if (preg_match('/^(\d{1,2})\s+(\d{1,2})\s+(\d{4})$/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    // PHP strtotime son çare
    $ts = @strtotime($d);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

// Veriyi normalize et; belirsiz olanları işaretle
$unclear_count = 0;
$normalized    = [];
foreach ($transactions as $tx) {
    // Tarih: çok formatlı dönüşüm
    $date = normalize_date($tx['date'] ?? null);
    $desc = trim($tx['description'] ?? '');
    $cat  = trim($tx['category']    ?? 'Diğer');

    $has_unclear_date = ($date === null);
    $has_unclear_desc = mb_strlen($desc) < 3 || in_array(strtolower($desc), ['pos', 'banka', 'işlem', 'transfer', 'havale', 'eft']);
    $has_unclear_cat  = $cat === 'Diğer' || empty($cat);

    $is_unclear = $has_unclear_date || $has_unclear_desc || $has_unclear_cat;
    if ($is_unclear) $unclear_count++;

    $normalized[] = [
        'date'            => $has_unclear_date ? null : $date,
        'description'     => $desc ?: 'Bilinmeyen',
        'amount'          => abs((float)($tx['amount'] ?? 0)),
        'type'            => in_array($tx['type'] ?? '', ['income', 'expense']) ? $tx['type'] : 'expense',
        'category'        => $has_unclear_cat ? 'Diğer' : $cat,
        'is_subscription' => (bool)($tx['is_subscription'] ?? false),
        'is_installment'  => (bool)($tx['is_installment']  ?? false),
        'unclear'         => $is_unclear,
    ];
}

// NOT: Bu endpoint artık kayıt yapmaz.
// Frontend review & clarification sonrası save_transactions.php'ye gönderilir.
json_exit([
    'success'       => true,
    'transactions'  => $normalized,
    'unclear_count' => $unclear_count,
    'total'         => count($normalized),
]);
