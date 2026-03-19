<?php
declare(strict_types=1);

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit(['error' => 'Yalnızca POST desteklenir.'], 405);
}

require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

// Hem FormData hem JSON body desteği
$raw = $_POST['transactions'] ?? '';
if (empty($raw)) {
    $body = json_decode(file_get_contents('php://input'), true);
    $raw  = $body['transactions'] ?? '';
}

$transactions = is_string($raw) ? json_decode($raw, true) : $raw;

if (!is_array($transactions) || empty($transactions)) {
    json_exit(['error' => 'Kaydedilecek işlem bulunamadı.']);
}

$stmt  = $pdo->prepare(
    "INSERT INTO transactions (date, description, amount, type, category) VALUES (:date, :description, :amount, :type, :category)"
);
$saved   = 0;
$skipped = 0;

foreach ($transactions as $tx) {
    $amount = abs((float)($tx['amount'] ?? 0));
    if ($amount <= 0) { $skipped++; continue; }

    $date = (string)($tx['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $stmt->execute([
        ':date'        => $date,
        ':description' => mb_substr(trim($tx['description'] ?? 'Bilinmeyen'), 0, 255) ?: 'Bilinmeyen',
        ':amount'      => $amount,
        ':type'        => in_array($tx['type'] ?? '', ['income', 'expense']) ? $tx['type'] : 'expense',
        ':category'    => mb_substr(trim($tx['category'] ?? 'Diğer'), 0, 50) ?: 'Diğer',
    ]);
    $saved++;
}

// Abonelik/taksit işaretlilerini subscriptions tablosuna ekle (aynı isim yoksa)
$sub_saved = 0;
$sub_check = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE name=? AND type=?");
$sub_stmt  = $pdo->prepare(
    "INSERT INTO subscriptions (name, amount, category, type, start_date, auto_detected)
     VALUES (:name, :amount, :category, :type, :start_date, 1)"
);
foreach ($transactions as $tx) {
    if (!empty($tx['is_subscription']) || !empty($tx['is_installment'])) {
        $sub_type = !empty($tx['is_installment']) ? 'installment' : 'subscription';
        $name     = mb_substr(trim($tx['description'] ?? ''), 0, 100) ?: 'Bilinmeyen';
        // Aynı isim+tip varsa tekrar ekleme
        $sub_check->execute([$name, $sub_type]);
        if ((int)$sub_check->fetchColumn() === 0) {
            $sub_stmt->execute([
                ':name'       => $name,
                ':amount'     => abs((float)($tx['amount'] ?? 0)),
                ':category'   => $sub_type === 'installment' ? 'Taksit' : 'Abonelik',
                ':type'       => $sub_type,
                ':start_date' => date('Y-m-d'),
            ]);
            $sub_saved++;
        }
    }
}

json_exit([
    'success'   => true,
    'saved'     => $saved,
    'skipped'   => $skipped,
    'sub_saved' => $sub_saved,
]);
