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

// Geliştirilmiş sistem prompt: tarih, kategori ve abonelik/taksit tespiti
$system_prompt =
    'Sen bir finansal veri ayıklama asistanısın. Sana bir banka ekstresi metni vereceğim. '
    . 'Görevin işlemleri bulmak ve SADECE geçerli bir JSON dizisi döndürmektir. '
    . 'Kesinlikle markdown kullanma, sohbet etme. '
    . 'Her işlem için şu alanları doldur: '
    . '{'
    . '"date": "YYYY-MM-DD formatında tarih. PDF\'de tarih varsa onu çevir. Yoksa veya bulamazsan null yaz", '
    . '"description": "İşlemin temizlenmiş, anlamlı adı. Mümkünse marka/firma adını koru", '
    . '"amount": pozitif float sayı, '
    . '"type": "income" veya "expense", '
    . '"category": "Şu listeden en uygun kategori: Market, Restoran, Kafe, Ulaşım, Yakıt, Fatura, Abonelik, Teknoloji, Sağlık, Eğitim, Giyim, Eğlence, Spor, Kira, Ev, Bakım, Seyahat, Hediye, Taksit, Sigorta, Maaş, Diğer", '
    . '"is_subscription": true eğer düzenli aylık abonelik görünüyorsa (Netflix, Spotify vb.), yoksa false, '
    . '"is_installment": true eğer taksitli ödeme görünüyorsa (kredi kartı taksit, BNPL), yoksa false'
    . '}.';

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

// Markdown temizle
$content = preg_replace('/```(?:json)?\s*/i', '', $content);
$content = preg_replace('/```/', '', $content);
$content = trim($content);

$transactions = json_decode($content, true);

if (!is_array($transactions) || empty($transactions)) {
    json_exit(['error' => 'Geçerli işlem bulunamadı.', 'raw' => mb_substr($content, 0, 500)]);
}

// Veriyi normalize et; belirsiz olanları işaretle
$unclear_count = 0;
$normalized    = [];
foreach ($transactions as $tx) {
    $date = (string)($tx['date'] ?? '');
    $desc = trim($tx['description'] ?? '');
    $cat  = trim($tx['category']    ?? 'Diğer');

    $has_unclear_date = !$date || $date === 'null' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
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
