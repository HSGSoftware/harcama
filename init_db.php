<?php
/**
 * Veritabanı başlatma betiği.
 * Tarayıcıdan veya CLI'dan bir kez çalıştırılır; tablolar yoksa oluşturur.
 * Mevcut tabloları silmez (IF NOT EXISTS).
 */

define('DB_PATH', __DIR__ . '/database.sqlite');

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // WAL modu: eşzamanlı okuma/yazma performansını artırır
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    // ----------------------------------------------------------------
    // 1. AYARLAR TABLOSU
    // key-value çifti: API anahtarları, model seçimleri vb.
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY NOT NULL,
            value      TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // ----------------------------------------------------------------
    // 2. İŞLEMLER TABLOSU
    // Gelir ve gider kayıtları (manuel + PDF ayıklama)
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            amount      REAL    NOT NULL DEFAULT 0,
            type        TEXT    NOT NULL CHECK(type IN ('income', 'expense')),
            category    TEXT    NOT NULL DEFAULT 'Diğer',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // İndeks: tarih bazlı sorgular için (son 30 gün analizi vb.)
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_transactions_date
        ON transactions (date DESC)
    ");

    // İndeks: kategori bazlı gruplama için
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_transactions_category
        ON transactions (category)
    ");

    // ----------------------------------------------------------------
    // 3. VARSAYILAN AYARLAR
    // INSERT OR IGNORE: mevcut değerlerin üzerine yazmaz
    // ----------------------------------------------------------------
    $defaults = [
        'openai_api_key'    => '',
        'anthropic_api_key' => '',
        'groq_api_key'      => '',
        'parser_model'      => 'groq|llama-3.1-8b-instant',
        'optimizer_model'   => 'groq|llama-3.3-70b-versatile',
        'currency'          => 'TRY',
        // Alt klasörde çalışıyorsa: /harcama  |  Kök dizinde: (boş)
        'base_url'          => '',
    ];

    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)
    ");
    foreach ($defaults as $key => $value) {
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    // Kullanımdan kalkan Groq model değerlerini güncelle
    $deprecated = [
        'groq|llama3-8b-8192'    => 'groq|llama-3.1-8b-instant',
        'groq|llama3-70b-8192'   => 'groq|llama-3.3-70b-versatile',
        'groq|mixtral-8x7b-32768'=> 'groq|llama-3.3-70b-versatile',
    ];
    $upd = $pdo->prepare("UPDATE settings SET value = :new WHERE key IN ('parser_model','optimizer_model') AND value = :old");
    foreach ($deprecated as $old => $new) {
        $upd->execute([':old' => $old, ':new' => $new]);
    }

    // CLI'dan çalıştırıldığında bilgi ver
    if (php_sapi_name() === 'cli') {
        echo "✓ Veritabanı başarıyla oluşturuldu: " . DB_PATH . PHP_EOL;
        echo "✓ Tablolar: settings, transactions" . PHP_EOL;
        echo "✓ Varsayılan ayarlar yüklendi." . PHP_EOL;
    }

} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        echo "✗ Hata: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    // HTTP isteğinde sessizce logla; layout dahil edilmemiş olabilir
    error_log('init_db.php PDO Hatası: ' . $e->getMessage());
    http_response_code(500);
    exit('Veritabanı başlatılamadı.');
}

/**
 * Bu fonksiyon diğer PHP sayfalarından require ile çağrıldığında
 * $pdo değişkenini döndürmek yerine global bağlamda kullanılmasını sağlamak için
 * db_connect() yardımcısını da burada tanımlıyoruz.
 */
/**
 * Uygulama URL'lerini base_url ayarına göre oluşturur.
 * Örnek: url('/settings.php') → /harcama/settings.php
 */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        try {
            $raw  = db_connect()->query("SELECT value FROM settings WHERE key='base_url'")->fetchColumn();
            $base = rtrim((string)$raw, '/');
        } catch (\Throwable $e) {
            $base = '';
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function db_connect(): PDO
{
    static $instance = null;
    if ($instance === null) {
        $instance = new PDO('sqlite:' . DB_PATH);
        $instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $instance->exec('PRAGMA journal_mode=WAL');
        $instance->exec('PRAGMA foreign_keys=ON');
    }
    return $instance;
}

// Tarayıcıdan doğrudan ziyaret edildiğinde kurulum onayı göster
if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) === 'init_db.php') {
    require_once __DIR__ . '/layout.php';
?>
    <div class="flex flex-col items-center justify-center min-h-[60vh] gap-6 text-center">
      <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <h2 class="text-xl font-semibold text-slate-100 mb-2">Kurulum Tamamlandı</h2>
        <p class="text-slate-400 text-sm">Veritabanı ve tablolar başarıyla oluşturuldu.</p>
      </div>
      <div class="bg-slate-800/50 rounded-xl p-4 text-left text-sm font-mono text-slate-400 w-full max-w-sm">
        <p class="text-emerald-400">✓ settings tablosu</p>
        <p class="text-emerald-400">✓ transactions tablosu</p>
        <p class="text-emerald-400">✓ Varsayılan ayarlar</p>
      </div>
      <a href="<?= url('/settings.php') ?>"
         class="px-6 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-medium text-sm transition-colors">
        API Anahtarlarını Ayarla →
      </a>
    </div>
<?php
    require_once __DIR__ . '/layout_footer.php';
}
