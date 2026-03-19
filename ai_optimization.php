<?php
declare(strict_types=1);

require_once __DIR__ . '/init_db.php';

// ── AJAX: AI Analiz Çalıştır ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {

    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $json_exit = function(array $data, int $code = 200): never {
        ob_end_clean();
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };

    set_exception_handler(function (\Throwable $e) use ($json_exit): void {
        $json_exit(['error' => 'Sunucu hatası: ' . $e->getMessage()], 500);
    });

    $pdo      = db_connect();
    $rows     = $pdo->query("SELECT key, value FROM settings")->fetchAll();
    $settings = array_column($rows, 'value', 'key');

    $raw_model          = $settings['optimizer_model'] ?? 'groq|llama3-70b-8192';
    [$provider, $model] = array_pad(explode('|', $raw_model, 2), 2, '');

    // Son 30 günlük işlemleri al
    $tx_list = $pdo->query("
        SELECT date, description, amount, type, category
        FROM transactions
        WHERE date >= date('now', '-30 days')
        ORDER BY date DESC
    ")->fetchAll();

    if (empty($tx_list)) {
        $json_exit(['error' => 'Son 30 günde hiç işlem bulunamadı. Önce işlem ekleyin veya PDF yükleyin.']);
    }

    // API haritası
    $api_map = [
        'openai'    => ['url' => 'https://api.openai.com/v1/chat/completions',      'key' => $settings['openai_api_key']    ?? ''],
        'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages',           'key' => $settings['anthropic_api_key'] ?? ''],
        'groq'      => ['url' => 'https://api.groq.com/openai/v1/chat/completions', 'key' => $settings['groq_api_key']      ?? ''],
    ];

    $api = $api_map[$provider] ?? $api_map['groq'];
    if (empty($api['key'])) {
        $json_exit(['error' => ucfirst($provider) . ' API anahtarı ayarlanmamış. Ayarlar sayfasına gidin.']);
    }

    $system_prompt =
        'Sen uzman bir finansal danışmansın. Sana kullanıcının son 1 aylık harcama işlemlerini JSON olarak vereceğim. '
        . 'SADECE geçerli bir JSON objesi döndür. Kesinlikle markdown veya sohbet kullanma. '
        . 'Format: {"category_totals": {"KategoriAdı": ToplamTutar}, '
        . '"subscriptions": [{"name": "Abonelik Adı", "amount": Tutar}], '
        . '"advice": ["Tasarruf tavsiyesi 1", "Tavsiye 2", "Tavsiye 3", "Tavsiye 4", "Tavsiye 5"]}. '
        . 'Tavsiyeler kullanıcının harcama alışkanlıklarına özel, net ve eyleme geçirilebilir olmalıdır '
        . '(örn: X aboneliğini iptal et, Y kategorisinde çok harcadın). '
        . 'subscriptions listesinde sadece düzenli aylık ödeme görünen kalemleri ekle.';

    $user_message = json_encode($tx_list, JSON_UNESCAPED_UNICODE);

    if ($provider === 'anthropic') {
        $body = json_encode([
            'model'      => $model,
            'max_tokens' => 2048,
            'system'     => $system_prompt,
            'messages'   => [['role' => 'user', 'content' => $user_message]],
        ]);
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $api['key'],
            'anthropic-version: 2023-06-01',
        ];
    } else {
        $body = json_encode([
            'model'       => $model,
            'temperature' => 0.3,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_message],
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
    ]);
    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err) {
        $json_exit(['error' => 'Ağ hatası: ' . $curl_err]);
    }

    $api_data = json_decode($response, true);

    if ($http_code >= 400) {
        $err_msg = $api_data['error']['message'] ?? 'API hatası';
        $json_exit(['error' => "API Hatası ({$http_code}): {$err_msg}"]);
    }

    $content = $provider === 'anthropic'
        ? ($api_data['content'][0]['text'] ?? '')
        : ($api_data['choices'][0]['message']['content'] ?? '');

    if (empty($content)) {
        $json_exit(['error' => 'AI boş yanıt döndürdü.']);
    }

    // Markdown temizle
    $content = preg_replace('/```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/```/', '', $content);
    $content = trim($content);

    $result = json_decode($content, true);

    if (!is_array($result)) {
        $json_exit(['error' => 'JSON parse hatası.', 'raw' => mb_substr($content, 0, 400)]);
    }

    // Analiz sonucunu settings tablosunda önbellekle
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('last_analysis', :v, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
        ->execute([':v' => json_encode($result, JSON_UNESCAPED_UNICODE)]);

    $json_exit(['success' => true, 'data' => $result, 'tx_count' => count($tx_list)]);
}

// ── Sayfa Yüklemesi ───────────────────────────────────────────────
$pdo = db_connect();

// 30 günlük özet
$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense,
        COUNT(*) AS tx_count
    FROM transactions
    WHERE date >= date('now', '-30 days')
")->fetch();

$currency = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol   = match($currency) { 'USD' => '$', 'EUR' => '€', default => '₺' };

// Seçili optimizer model
$model_raw          = $pdo->query("SELECT value FROM settings WHERE key='optimizer_model'")->fetchColumn() ?: 'groq|llama3-70b-8192';
[$opt_provider, $opt_model] = array_pad(explode('|', $model_raw, 2), 2, '');

$provider_labels = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'groq' => 'Groq'];
$provider_colors = ['openai' => 'emerald', 'anthropic' => 'orange', 'groq' => 'purple'];

// Önbelleklenmiş son analiz
$cached_raw    = $pdo->query("SELECT value FROM settings WHERE key='last_analysis'")->fetchColumn();
$cached_result = $cached_raw ? json_decode($cached_raw, true) : null;

require_once __DIR__ . '/layout.php';
?>

<div x-data="{
  state: 'idle',
  result: <?= $cached_result ? json_encode($cached_result, JSON_UNESCAPED_UNICODE) : 'null' ?>,
  txCount: 0,
  errorMsg: '',

  async runAnalysis() {
    this.state = 'loading';
    this.errorMsg = '';
    try {
      const resp = await fetch('<?= url('/ai_optimization.php') ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await resp.json();
      if (data.error) {
        this.errorMsg = data.error;
        this.state = 'error';
      } else {
        this.result  = data.data;
        this.txCount = data.tx_count;
        this.state   = 'done';
        this.$nextTick(() => renderCharts(this.result));
      }
    } catch(e) {
      this.errorMsg = 'Bağlantı hatası: ' + e.message;
      this.state = 'error';
    }
  },

  get topCategory() {
    if (!this.result?.category_totals) return '—';
    const cats = this.result.category_totals;
    return Object.keys(cats).reduce((a, b) => cats[a] > cats[b] ? a : b, Object.keys(cats)[0] ?? '—');
  },
  get totalExpense() {
    if (!this.result?.category_totals) return 0;
    return Object.values(this.result.category_totals).reduce((a, b) => a + b, 0);
  },
  get topSubAmount() {
    if (!this.result?.subscriptions?.length) return 0;
    return this.result.subscriptions.reduce((a, b) => a + (b.amount ?? 0), 0);
  }
}" x-init="if (result) { $nextTick(() => renderCharts(result)); state = 'done'; }">

  <!-- Model Bilgisi ve Başlat Butonu -->
  <div class="bg-gradient-to-br from-slate-900 to-slate-900/60 border border-slate-800/60 rounded-2xl p-5 space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="text-sm font-bold text-slate-100">AI Bütçe Analizi</h2>
        <p class="text-xs text-slate-500 mt-0.5">Son 30 günlük harcamalarını analiz et</p>
      </div>
      <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-<?= $provider_colors[$opt_provider] ?? 'slate' ?>-500/10 border border-<?= $provider_colors[$opt_provider] ?? 'slate' ?>-500/20">
        <div class="w-1.5 h-1.5 rounded-full bg-<?= $provider_colors[$opt_provider] ?? 'slate' ?>-400"></div>
        <span class="text-[10px] font-semibold text-<?= $provider_colors[$opt_provider] ?? 'slate' ?>-400"><?= htmlspecialchars($opt_model) ?></span>
      </div>
    </div>

    <!-- 30 Gün Özeti -->
    <div class="grid grid-cols-3 gap-2">
      <div class="bg-slate-800/40 rounded-xl p-3 text-center">
        <p class="text-lg font-bold text-slate-100"><?= $summary['tx_count'] ?></p>
        <p class="text-[10px] text-slate-500">İşlem</p>
      </div>
      <div class="bg-emerald-500/10 rounded-xl p-3 text-center">
        <p class="text-sm font-bold text-emerald-400"><?= $symbol ?><?= number_format($summary['income'], 0, ',', '.') ?></p>
        <p class="text-[10px] text-emerald-400/60">Gelir</p>
      </div>
      <div class="bg-red-500/10 rounded-xl p-3 text-center">
        <p class="text-sm font-bold text-red-400"><?= $symbol ?><?= number_format($summary['expense'], 0, ',', '.') ?></p>
        <p class="text-[10px] text-red-400/60">Gider</p>
      </div>
    </div>

    <button @click="runAnalysis()" :disabled="state==='loading'"
            class="w-full py-4 rounded-xl font-bold text-sm text-white transition-all active:scale-[0.98]
                   bg-gradient-to-r from-brand-500 to-purple-600 hover:from-brand-600 hover:to-purple-700
                   shadow-lg shadow-brand-500/20 disabled:opacity-60 disabled:cursor-not-allowed
                   flex items-center justify-center gap-2">
      <template x-if="state !== 'loading'">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
          </svg>
          <span x-text="result ? 'Yeniden Analiz Et' : 'Analizi Başlat'"></span>
        </span>
      </template>
      <template x-if="state === 'loading'">
        <span class="flex items-center gap-2">
          <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          AI Analiz Ediyor...
        </span>
      </template>
    </button>
  </div>

  <!-- Hata -->
  <div x-show="state==='error'" class="flex items-start gap-2.5 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
    <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span x-text="errorMsg"></span>
  </div>

  <!-- Yükleniyor animasyonu -->
  <div x-show="state==='loading'" class="flex flex-col items-center justify-center py-10 gap-4">
    <div class="relative w-16 h-16">
      <div class="absolute inset-0 rounded-full border-2 border-brand-500/20"></div>
      <div class="absolute inset-0 rounded-full border-2 border-transparent border-t-brand-400 animate-spin"></div>
      <div class="absolute inset-2 rounded-full border-2 border-transparent border-t-purple-400 animate-spin" style="animation-duration:0.75s; animation-direction:reverse;"></div>
      <div class="absolute inset-4 rounded-full bg-brand-500/10 flex items-center justify-center">
        <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
        </svg>
      </div>
    </div>
    <div class="text-center">
      <p class="text-sm font-semibold text-slate-300">AI harcamalarınızı inceliyor</p>
      <p class="text-xs text-slate-600 mt-1">Bu işlem 10-30 saniye sürebilir...</p>
    </div>
  </div>

  <!-- ── SONUÇLAR ── -->
  <div x-show="result !== null" x-transition class="space-y-4">

    <!-- Özet Kartlar -->
    <div class="grid grid-cols-3 gap-2.5">
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3 text-center">
        <div class="w-7 h-7 rounded-lg bg-red-500/10 flex items-center justify-center mx-auto mb-2">
          <svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        </div>
        <p class="text-xs font-bold text-slate-200"
           x-text="'<?= $symbol ?>' + totalExpense.toLocaleString('tr-TR', {maximumFractionDigits:0})"></p>
        <p class="text-[10px] text-slate-600 mt-0.5">Toplam Gider</p>
      </div>
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3 text-center">
        <div class="w-7 h-7 rounded-lg bg-amber-500/10 flex items-center justify-center mx-auto mb-2">
          <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
        </div>
        <p class="text-xs font-bold text-slate-200 truncate" x-text="topCategory"></p>
        <p class="text-[10px] text-slate-600 mt-0.5">En Yüksek</p>
      </div>
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3 text-center">
        <div class="w-7 h-7 rounded-lg bg-purple-500/10 flex items-center justify-center mx-auto mb-2">
          <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </div>
        <p class="text-xs font-bold text-slate-200"
           x-text="'<?= $symbol ?>' + topSubAmount.toLocaleString('tr-TR', {maximumFractionDigits:0})"></p>
        <p class="text-[10px] text-slate-600 mt-0.5">Abonelik</p>
      </div>
    </div>

    <!-- Kategori Grafik -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4" x-show="result?.category_totals">
      <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Harcama Dağılımı</h3>
      <div class="relative h-52">
        <canvas id="aiBarChart"></canvas>
      </div>
    </div>

    <!-- Abonelikler -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden"
         x-show="result?.subscriptions?.length > 0">
      <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
        <div class="w-5 h-5 rounded-md bg-purple-500/10 flex items-center justify-center">
          <svg class="w-3 h-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </div>
        <h3 class="text-xs font-semibold text-slate-300">Tespit Edilen Abonelikler</h3>
      </div>
      <div class="divide-y divide-slate-800/30">
        <template x-for="(sub, i) in result?.subscriptions ?? []" :key="i">
          <div class="flex items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
              <div class="w-7 h-7 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
                <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <p class="text-sm font-medium text-slate-200" x-text="sub.name"></p>
            </div>
            <p class="text-sm font-semibold text-purple-400"
               x-text="'<?= $symbol ?>' + parseFloat(sub.amount ?? 0).toLocaleString('tr-TR', {minimumFractionDigits:2})"></p>
          </div>
        </template>
      </div>
    </div>

    <!-- AI Tavsiyeleri -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden"
         x-show="result?.advice?.length > 0">
      <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
        <div class="w-5 h-5 rounded-md bg-brand-500/10 flex items-center justify-center">
          <svg class="w-3 h-3 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
          </svg>
        </div>
        <h3 class="text-xs font-semibold text-slate-300">AI Tasarruf Tavsiyeleri</h3>
      </div>
      <div class="p-4 space-y-3">
        <template x-for="(advice, i) in result?.advice ?? []" :key="i">
          <div class="flex items-start gap-3 p-3 rounded-xl bg-slate-800/30 border border-slate-700/20">
            <div class="w-6 h-6 rounded-lg bg-brand-500/15 border border-brand-500/20 flex items-center justify-center shrink-0 mt-0.5">
              <span class="text-[10px] font-bold text-brand-400" x-text="i + 1"></span>
            </div>
            <p class="text-sm text-slate-300 leading-relaxed" x-text="advice"></p>
          </div>
        </template>
      </div>
    </div>

  </div><!-- /result -->

</div>

<script>
function renderCharts(result) {
  const barCtx = document.getElementById('aiBarChart');
  if (!barCtx || !result?.category_totals) return;

  // Önceki chart varsa yok et
  if (barCtx._chart) barCtx._chart.destroy();

  const cats   = result.category_totals;
  const labels = Object.keys(cats);
  const values = Object.values(cats);

  const palette = ['#6366f1','#a855f7','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#14b8a6','#f97316','#8b5cf6'];

  barCtx._chart = new Chart(barCtx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map((_, i) => palette[i % palette.length] + '99'),
        borderColor:      labels.map((_, i) => palette[i % palette.length]),
        borderWidth: 1,
        borderRadius: 6,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` <?= $symbol ?>${ctx.parsed.x.toLocaleString('tr-TR', {minimumFractionDigits:2})}`
          }
        }
      },
      scales: {
        x: {
          grid:  { color: 'rgba(255,255,255,0.04)' },
          ticks: { color: '#64748b', font: { size: 10 },
                   callback: (v) => '<?= $symbol ?>' + v.toLocaleString('tr-TR') }
        },
        y: {
          grid:  { display: false },
          ticks: { color: '#94a3b8', font: { size: 11 } }
        }
      }
    }
  });
}
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
