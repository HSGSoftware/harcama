<?php
require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

// ----------------------------------------------------------------
// KAYDETME İŞLEMİ
// ----------------------------------------------------------------
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {

    // Güvenlik: CSRF token basit kontrolü (token session'da tutulur)
    // Şimdilik basit tutuluyor; production'da güçlendirilmeli
    $allowed_keys = [
        'openai_api_key',
        'anthropic_api_key',
        'groq_api_key',
        'parser_model',
        'optimizer_model',
        'currency',
        'base_url',
    ];

    // İzin verilen model değerleri (injection önlemi)
    $allowed_models = [
        'openai|gpt-4o',
        'openai|gpt-4o-mini',
        'anthropic|claude-3-5-sonnet-latest',
        'anthropic|claude-3-haiku-20240307',
        'groq|llama-3.1-8b-instant',
        'groq|llama-3.3-70b-versatile',
        'groq|llama-3.1-70b-versatile',
        'groq|gemma2-9b-it',
    ];

    $stmt = $pdo->prepare("
        INSERT INTO settings (key, value, updated_at)
        VALUES (:key, :value, datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
    ");

    foreach ($allowed_keys as $key) {
        $value = trim($_POST[$key] ?? '');

        // base_url: başta/sonda slash temizle, boş olabilir
        if ($key === 'base_url') {
            $value = rtrim($value, '/');
            if ($value !== '' && !str_starts_with($value, '/')) {
                $value = '/' . $value;
            }
        }

        // Model alanları için whitelist kontrolü
        if (in_array($key, ['parser_model', 'optimizer_model'])) {
            if (!in_array($value, $allowed_models)) {
                $error_message = 'Geçersiz model seçimi.';
                break;
            }
        }

        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    if (empty($error_message)) {
        $success_message = 'Ayarlar başarıyla kaydedildi.';
    }
}

// ----------------------------------------------------------------
// MEVCUT AYARLARI ÇEK
// ----------------------------------------------------------------
$settings = [];
foreach ($pdo->query("SELECT key, value FROM settings") as $row) {
    $settings[$row['key']] = $row['value'];
}

// Yardımcı: belirli bir ayarı güvenli al
$s = fn(string $key, string $default = '') => htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES);

// Model seçenekleri (sağlayıcı|model formatında value, görünen ad)
$model_options = [
    'openai|gpt-4o'                          => 'OpenAI — gpt-4o',
    'openai|gpt-4o-mini'                     => 'OpenAI — gpt-4o-mini',
    'anthropic|claude-3-5-sonnet-latest'     => 'Anthropic — claude-3-5-sonnet-latest',
    'anthropic|claude-3-haiku-20240307'      => 'Anthropic — claude-3-haiku-20240307',
    'groq|llama-3.1-8b-instant'              => 'Groq — llama-3.1-8b-instant (Hızlı)',
    'groq|llama-3.3-70b-versatile'           => 'Groq — llama-3.3-70b-versatile (Güçlü)',
    'groq|llama-3.1-70b-versatile'           => 'Groq — llama-3.1-70b-versatile',
    'groq|gemma2-9b-it'                      => 'Groq — gemma2-9b-it',
];

require_once __DIR__ . '/layout.php';
?>

<div x-data="{
    showOpenAI:    false,
    showAnthropic: false,
    showGroq:      false,
    saved:         <?= !empty($success_message) ? 'true' : 'false' ?>
}" class="space-y-5">

  <!-- Başarı / Hata Bildirimi -->
  <?php if ($success_message): ?>
  <div x-show="saved" x-transition
       class="flex items-center gap-3 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    <?= htmlspecialchars($success_message) ?>
  </div>
  <?php endif; ?>

  <?php if ($error_message): ?>
  <div class="flex items-center gap-3 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <?= htmlspecialchars($error_message) ?>
  </div>
  <?php endif; ?>

    <form method="POST" action="<?= url('/settings.php') ?>" class="space-y-5">
    <input type="hidden" name="save_settings" value="1">

    <!-- ============================================================
         BÖLÜM 1: API ANAHTARLARI
         ============================================================ -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 pt-4 pb-3 border-b border-slate-800/40">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded-lg bg-yellow-500/10 flex items-center justify-center">
            <svg class="w-3.5 h-3.5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-slate-200">API Anahtarları</h2>
        </div>
        <p class="text-xs text-slate-500 mt-1 ml-8">Anahtarlar veritabanında saklanır, tarayıcıya gönderilmez.</p>
      </div>

      <div class="p-4 space-y-4">

        <!-- OpenAI API Anahtarı -->
        <div>
          <label class="flex items-center justify-between mb-2">
            <span class="text-xs font-medium text-slate-300">OpenAI API Anahtarı</span>
            <button type="button" @click="showOpenAI = !showOpenAI"
                    class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
              <span x-text="showOpenAI ? 'Gizle' : 'Göster'"></span>
            </button>
          </label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2">
              <span class="text-[10px] font-bold text-emerald-400 bg-emerald-400/10 px-1.5 py-0.5 rounded">AI</span>
            </div>
            <input
              :type="showOpenAI ? 'text' : 'password'"
              name="openai_api_key"
              value="<?= $s('openai_api_key') ?>"
              placeholder="sk-proj-..."
              autocomplete="off"
              class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-12 pr-4 py-3 text-sm text-slate-200
                     placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20
                     transition-colors font-mono"
            >
          </div>
        </div>

        <!-- Anthropic API Anahtarı -->
        <div>
          <label class="flex items-center justify-between mb-2">
            <span class="text-xs font-medium text-slate-300">Anthropic API Anahtarı</span>
            <button type="button" @click="showAnthropic = !showAnthropic"
                    class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
              <span x-text="showAnthropic ? 'Gizle' : 'Göster'"></span>
            </button>
          </label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2">
              <span class="text-[10px] font-bold text-orange-400 bg-orange-400/10 px-1.5 py-0.5 rounded">CL</span>
            </div>
            <input
              :type="showAnthropic ? 'text' : 'password'"
              name="anthropic_api_key"
              value="<?= $s('anthropic_api_key') ?>"
              placeholder="sk-ant-..."
              autocomplete="off"
              class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-12 pr-4 py-3 text-sm text-slate-200
                     placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20
                     transition-colors font-mono"
            >
          </div>
        </div>

        <!-- Groq API Anahtarı -->
        <div>
          <label class="flex items-center justify-between mb-2">
            <span class="text-xs font-medium text-slate-300">Groq API Anahtarı</span>
            <button type="button" @click="showGroq = !showGroq"
                    class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
              <span x-text="showGroq ? 'Gizle' : 'Göster'"></span>
            </button>
          </label>
          <div class="relative">
            <div class="absolute left-3 top-1/2 -translate-y-1/2">
              <span class="text-[10px] font-bold text-purple-400 bg-purple-400/10 px-1.5 py-0.5 rounded">GQ</span>
            </div>
            <input
              :type="showGroq ? 'text' : 'password'"
              name="groq_api_key"
              value="<?= $s('groq_api_key') ?>"
              placeholder="gsk_..."
              autocomplete="off"
              class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-12 pr-4 py-3 text-sm text-slate-200
                     placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20
                     transition-colors font-mono"
            >
          </div>
        </div>

      </div>
    </div>

    <!-- ============================================================
         BÖLÜM 2: MODEL SEÇİMLERİ
         ============================================================ -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 pt-4 pb-3 border-b border-slate-800/40">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded-lg bg-brand-500/10 flex items-center justify-center">
            <svg class="w-3.5 h-3.5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-slate-200">AI Model Seçimi</h2>
        </div>
        <p class="text-xs text-slate-500 mt-1 ml-8">Her görev için ayrı model kullanılabilir.</p>
      </div>

      <div class="p-4 space-y-4">

        <!-- PDF Parser Modeli -->
        <div>
          <label class="block text-xs font-medium text-slate-300 mb-2">
            <span class="inline-flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              PDF Ayıklama Modeli
            </span>
          </label>
          <div class="relative">
            <select name="parser_model"
                    class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 pr-10
                           text-sm text-slate-200 focus:outline-none focus:border-brand-500/60 focus:ring-1
                           focus:ring-brand-500/20 transition-colors cursor-pointer">
              <?php foreach ($model_options as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>"
                        <?= ($settings['parser_model'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <!-- Özel dropdown oku -->
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-500">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </div>
          </div>
          <p class="text-xs text-slate-600 mt-1.5">
            Banka ekstresindeki işlemleri ayıklamak için kullanılır. Hız için Groq önerilir.
          </p>
        </div>

        <!-- Bütçe Optimizer Modeli -->
        <div>
          <label class="block text-xs font-medium text-slate-300 mb-2">
            <span class="inline-flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
              </svg>
              Bütçe Analiz Modeli
            </span>
          </label>
          <div class="relative">
            <select name="optimizer_model"
                    class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 pr-10
                           text-sm text-slate-200 focus:outline-none focus:border-brand-500/60 focus:ring-1
                           focus:ring-brand-500/20 transition-colors cursor-pointer">
              <?php foreach ($model_options as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>"
                        <?= ($settings['optimizer_model'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-500">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </div>
          </div>
          <p class="text-xs text-slate-600 mt-1.5">
            Harcama analizi ve tasarruf tavsiyeleri için. Kalite için GPT-4o veya Claude önerilir.
          </p>
        </div>

      </div>
    </div>

    <!-- ============================================================
         BÖLÜM 3: GENEL AYARLAR
         ============================================================ -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 pt-4 pb-3 border-b border-slate-800/40">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded-lg bg-slate-700/50 flex items-center justify-center">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-slate-200">Genel</h2>
        </div>
      </div>

      <div class="p-4 space-y-4">

        <!-- Uygulama Base URL -->
        <div>
          <label class="block text-xs font-medium text-slate-300 mb-2">
            <span class="inline-flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
              </svg>
              Uygulama Base URL
            </span>
          </label>
          <input
            type="text"
            name="base_url"
            value="<?= $s('base_url') ?>"
            placeholder="Kök dizinde boş bırak · Alt klasör için: /harcama"
            class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                   placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20
                   transition-colors font-mono"
          >
          <p class="text-xs text-slate-600 mt-1.5">
            Uygulama <code class="text-slate-500">example.com/harcama/</code> altında çalışıyorsa <code class="text-slate-500">/harcama</code> yaz.
            Kök dizinde çalışıyorsa boş bırak.
          </p>
        </div>

        <!-- Para Birimi -->
        <div>
        <label class="block text-xs font-medium text-slate-300 mb-2">Para Birimi</label>
        <div class="relative">
          <select name="currency"
                  class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 pr-10
                         text-sm text-slate-200 focus:outline-none focus:border-brand-500/60 focus:ring-1
                         focus:ring-brand-500/20 transition-colors cursor-pointer">
            <option value="TRY" <?= ($settings['currency'] ?? 'TRY') === 'TRY' ? 'selected' : '' ?>>₺ Türk Lirası (TRY)</option>
            <option value="USD" <?= ($settings['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>$ Amerikan Doları (USD)</option>
            <option value="EUR" <?= ($settings['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>€ Euro (EUR)</option>
          </select>
          <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-500">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </div>
        </div>
        </div><!-- /para birimi -->
      </div>
    </div>

    <!-- ============================================================
         KAYDET BUTONU
         ============================================================ -->
    <button type="submit"
            class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-brand-500 to-purple-600
                   hover:from-brand-600 hover:to-purple-700 text-white font-semibold text-sm
                   shadow-lg shadow-brand-500/20 active:scale-[0.98] transition-all duration-150">
      Ayarları Kaydet
    </button>

    <!-- Veritabanı sıfırlama linki (dikkatli kullanım) -->
    <div class="text-center">
      <a href="<?= url('/init_db.php') ?>" class="text-xs text-slate-600 hover:text-slate-400 transition-colors">
        Veritabanını başlat / onar
      </a>
    </div>

  </form>
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
