<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/init_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Yalnızca POST desteklenir.']);
    exit;
}

$pdo = db_connect();

// Tüm ayarları yükle
$rows     = $pdo->query("SELECT key, value FROM settings")->fetchAll();
$settings = array_column($rows, 'value', 'key');

// Parser model seç
$raw_model              = $settings['parser_model'] ?? 'groq|llama3-8b-8192';
[$provider, $model]     = array_pad(explode('|', $raw_model, 2), 2, '');

// PDF metnini al (FormData veya JSON body)
$pdf_text = trim($_POST['text'] ?? '');
if (empty($pdf_text)) {
    $json_body = json_decode(file_get_contents('php://input'), true);
    $pdf_text  = trim($json_body['text'] ?? '');
}

if (mb_strlen($pdf_text) < 30) {
    echo json_encode(['error' => 'PDF metni çok kısa veya boş. Lütfen geçerli bir banka ekstresi yükleyin.']);
    exit;
}

// API uç noktası ve anahtar eşlemi
$api_map = [
    'openai'    => ['url' => 'https://api.openai.com/v1/chat/completions',      'key' => $settings['openai_api_key']    ?? ''],
    'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages',           'key' => $settings['anthropic_api_key'] ?? ''],
    'groq'      => ['url' => 'https://api.groq.com/openai/v1/chat/completions', 'key' => $settings['groq_api_key']      ?? ''],
];

$api = $api_map[$provider] ?? $api_map['groq'];

if (empty($api['key'])) {
    echo json_encode(['error' => ucfirst($provider) . ' API anahtarı ayarlanmamış. Ayarlar sayfasına gidin.']);
    exit;
}

$system_prompt =
    'Sen bir finansal veri ayıklama asistanısın. Sana karmaşık bir banka ekstresi metni vereceğim. '
    . 'Görevin bu metinden işlemleri bulmak ve SADECE geçerli bir JSON dizisi (Array) döndürmektir. '
    . 'Kesinlikle markdown kullanma, sohbet etme. '
    . 'JSON formatı şu objelerden oluşmalı: '
    . '{"date": "YYYY-MM-DD", "description": "temizlenmiş harcama adı", "amount": float(sadece pozitif sayı), '
    . '"type": "income" veya "expense", '
    . '"category": "harcamaya en uygun tek kelimelik Türkçe kategori (örn: Market, Abonelik, Teknoloji, Sağlık)"}.';

// Metin çok uzunsa ilk 8000 karakteri gönder
$user_text = mb_substr($pdf_text, 0, 8000);

// Sağlayıcıya göre istek gövdesi ve başlıkları hazırla
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
    // OpenAI veya Groq (OpenAI uyumlu arayüz)
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

// cURL isteği gönder
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
    echo json_encode(['error' => 'Ağ hatası: ' . $curl_err]);
    exit;
}

$api_data = json_decode($response, true);

if ($http_code >= 400) {
    $err_msg = $api_data['error']['message'] ?? $api_data['error']['msg'] ?? 'Bilinmeyen API hatası.';
    echo json_encode(['error' => "API Hatası ({$http_code}): {$err_msg}"]);
    exit;
}

// Sağlayıcıya göre yanıt içeriğini çıkar
if ($provider === 'anthropic') {
    $content = $api_data['content'][0]['text'] ?? '';
} else {
    $content = $api_data['choices'][0]['message']['content'] ?? '';
}

if (empty($content)) {
    echo json_encode(['error' => 'AI boş yanıt döndürdü.']);
    exit;
}

// Markdown kod blokları temizle (```json ... ``` veya ``` ... ```)
$content = preg_replace('/```(?:json)?\s*/i', '', $content);
$content = preg_replace('/```/', '', $content);
$content = trim($content);

$transactions = json_decode($content, true);

if (!is_array($transactions) || empty($transactions)) {
    echo json_encode(['error' => 'Geçerli işlem bulunamadı veya JSON parse hatası.', 'raw' => mb_substr($content, 0, 500)]);
    exit;
}

// Veritabanına kaydet
$stmt  = $pdo->prepare(
    "INSERT INTO transactions (date, description, amount, type, category) VALUES (:date, :description, :amount, :type, :category)"
);
$saved   = 0;
$skipped = 0;

foreach ($transactions as $tx) {
    if (empty($tx['date']) || !isset($tx['amount'])) {
        $skipped++;
        continue;
    }

    // Tarih YYYY-MM-DD formatında değilse bugünü kullan
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($tx['date'] ?? ''))
        ? $tx['date']
        : date('Y-m-d');

    $stmt->execute([
        ':date'        => $date,
        ':description' => mb_substr(trim($tx['description'] ?? 'Bilinmeyen'), 0, 255) ?: 'Bilinmeyen',
        ':amount'      => abs((float)($tx['amount'] ?? 0)),
        ':type'        => in_array($tx['type'] ?? '', ['income', 'expense']) ? $tx['type'] : 'expense',
        ':category'    => mb_substr(trim($tx['category'] ?? 'Diğer'), 0, 50) ?: 'Diğer',
    ]);
    $saved++;
}

echo json_encode([
    'success'      => true,
    'saved'        => $saved,
    'skipped'      => $skipped,
    'transactions' => $transactions,
]);
