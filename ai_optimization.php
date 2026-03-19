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

// Ek DB verileri (AI sonucu ile birleştirmek için)
$top_tx = $pdo->query("
    SELECT description, amount, category FROM transactions
    WHERE type='expense' AND date>=date('now','-30 days')
    ORDER BY amount DESC LIMIT 1
")->fetch();

$daily_avg = $summary['expense'] > 0 ? round($summary['expense'] / 30, 2) : 0;
$sub_load  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscriptions WHERE active=1")->fetchColumn();

require_once __DIR__ . '/layout.php';
?>

<?php /* ── Aynı html-attribute/json_encode çakışmasını önle ── */ ?>
<script>
window.__aiLastAnalysis = <?= $cached_result
    ? json_encode($cached_result, JSON_UNESCAPED_UNICODE)
    : 'null' ?>;
</script>

<div x-data="{
  state: 'idle',
  result: window.__aiLastAnalysis,
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
    const subs = this.result?.subscriptions ?? [];
    return subs.reduce((a, b) => a + (b.amount ?? 0), 0);
  },
  get savingsPotential() {
    const top = Object.values(this.result?.category_totals ?? {}).sort((a,b)=>b-a).slice(0,2);
    return Math.round(top.reduce((a,b) => a+b, 0) * 0.20);
  },
  get healthScore() {
    let score = 65;
    const inc = <?= (float)$summary['income'] ?>;
    const exp = this.totalExpense;
    if (inc > 0) {
      const rate = (inc - exp) / inc;
      if (rate > 0.25) score += 25;
      else if (rate > 0.1) score += 12;
      else if (rate < 0) score -= 30;
      else if (rate < 0.05) score -= 15;
    }
    const subPct = exp > 0 ? this.topSubAmount / exp : 0;
    if (subPct > 0.35) score -= 15;
    else if (subPct > 0.2) score -= 7;
    const cats = Object.values(this.result?.category_totals ?? {});
    if (cats.length > 0) {
      const topPct = Math.max(...cats) / (exp || 1);
      if (topPct > 0.5) score -= 10;
    }
    return Math.max(0, Math.min(100, score));
  },
  get healthLabel() {
    const s = this.healthScore;
    if (s >= 80) return { txt: 'Mükemmel', color: 'emerald' };
    if (s >= 60) return { txt: 'İyi',      color: 'green'   };
    if (s >= 40) return { txt: 'Orta',     color: 'yellow'  };
    if (s >= 20) return { txt: 'Dikkat',   color: 'orange'  };
    return             { txt: 'Kritik',    color: 'red'     };
  },
  get personalityType() {
    const cats = this.result?.category_totals ?? {};
    const top  = Object.keys(cats).sort((a,b) => cats[b]-cats[a])[0] ?? '';
    const map  = {
      'Market':'🏡 Ev Ekonomisti','Restoran':'🍽 Yemek Aşığı','Kafe':'☕ Kafe Tutkunу',
      'Ulaşım':'🚗 Yolcu','Teknoloji':'💻 Dijital Vatandaş','Abonelik':'📱 Abonelik Uzmanı',
      'Seyahat':'✈ Gezgin','Eğlence':'🎮 Eğlence Tutkunu','Giyim':'👗 Moda Takipçisi',
      'Sağlık':'💊 Sağlık Odaklı','Spor':'🏋 Sporsever',
    };
    return map[top] ?? '⚖ Dengeli Harcayan';
  }
}" x-init="if (result) { $nextTick(() => renderCharts(result)); state = 'done'; }">

  <!-- ── Başlık + Model + Analiz Butonu ── -->
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

    <!-- Finansal Sağlık Skoru -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Finansal Sağlık Skoru</h3>
          <p class="text-[10px] text-slate-600 mt-0.5">Gelir-gider dengesi, abonelik yükü ve çeşitliliğe göre</p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-black" :class="`text-${healthLabel.color}-400`" x-text="healthScore"></p>
          <p class="text-xs font-semibold" :class="`text-${healthLabel.color}-400/70`" x-text="healthLabel.txt"></p>
        </div>
      </div>
      <div class="bg-slate-800/60 rounded-full h-3 overflow-hidden">
        <div class="h-full rounded-full transition-all duration-700"
             :class="`bg-${healthLabel.color}-500`"
             :style="`width: ${healthScore}%`"></div>
      </div>
      <div class="flex justify-between mt-1">
        <span class="text-[9px] text-slate-600">Kritik</span>
        <span class="text-[9px] text-slate-600">Mükemmel</span>
      </div>
    </div>

    <!-- Özet 4lü grid -->
    <div class="grid grid-cols-2 gap-2.5">
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3">
        <p class="text-[10px] text-slate-500 mb-1">Harcama Karakteri</p>
        <p class="text-sm font-bold text-slate-200" x-text="personalityType"></p>
        <p class="text-[10px] text-slate-600 mt-0.5">AI'ın senin profilini tanımlaması</p>
      </div>
      <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3">
        <p class="text-[10px] text-emerald-400/70 mb-1">Tasarruf Potansiyeli</p>
        <p class="text-sm font-bold text-emerald-400"><?= $symbol ?><span x-text="savingsPotential.toLocaleString('tr-TR')"></span></p>
        <p class="text-[10px] text-slate-600 mt-0.5">En yüksek 2 kategoride %20 kısma</p>
      </div>
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3">
        <p class="text-[10px] text-slate-500 mb-1">Günlük Ort. Harcama</p>
        <p class="text-sm font-bold text-slate-200"><?= $symbol ?><?= number_format($daily_avg, 2, ',', '.') ?></p>
        <p class="text-[10px] text-slate-600 mt-0.5">Son 30 gün ortalaması</p>
      </div>
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-xl p-3">
        <p class="text-[10px] text-slate-500 mb-1">En Büyük Gider</p>
        <p class="text-sm font-bold text-red-400 truncate"><?= $top_tx ? $symbol.number_format($top_tx['amount'],2,',','.') : '—' ?></p>
        <p class="text-[10px] text-slate-600 mt-0.5 truncate"><?= htmlspecialchars($top_tx['description'] ?? '—') ?></p>
      </div>
    </div>

    <!-- Kategori Bar Chart -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4" x-show="result?.category_totals">
      <div class="flex items-center justify-between mb-1">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Harcama Dağılımı</h3>
        <p class="text-[10px] text-slate-600">Son 30 gün, kategori bazlı</p>
      </div>
      <p class="text-[10px] text-slate-600 mb-3">Çubuğa tıklayarak detaylı tutarı görebilirsin</p>
      <div class="relative h-56">
        <canvas id="aiBarChart"></canvas>
      </div>
    </div>

    <!-- Abonelik Yükü Analizi -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden"
         x-show="result?.subscriptions?.length > 0">
      <div class="px-4 py-3 border-b border-slate-800/40">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <div class="w-5 h-5 rounded-md bg-purple-500/10 flex items-center justify-center">
              <svg class="w-3 h-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
            <h3 class="text-xs font-semibold text-slate-300">AI Tespit Ettiği Abonelikler</h3>
          </div>
          <span class="text-xs font-bold text-purple-400"
                x-text="'<?= $symbol ?>' + topSubAmount.toLocaleString('tr-TR', {maximumFractionDigits:0}) + '/ay'"></span>
        </div>
        <p class="text-[10px] text-slate-600 mt-1 ml-7">Düzenli görünen ödemeler — abonelik sayfasına ekleyebilirsin</p>
      </div>
      <div class="divide-y divide-slate-800/30">
        <template x-for="(sub, i) in result?.subscriptions ?? []" :key="i">
          <div class="flex items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
              <span class="text-base">🔄</span>
              <div>
                <p class="text-sm font-medium text-slate-200" x-text="sub.name"></p>
                <p class="text-[10px] text-slate-600">Aylık düzenli ödeme</p>
              </div>
            </div>
            <div class="text-right">
              <p class="text-sm font-semibold text-purple-400"
                 x-text="'<?= $symbol ?>' + parseFloat(sub.amount ?? 0).toLocaleString('tr-TR', {minimumFractionDigits:2})"></p>
              <a href="<?= url('/subscriptions.php') ?>" class="text-[10px] text-brand-400 hover:underline">+ Ekle</a>
            </div>
          </div>
        </template>
      </div>
      <!-- Toplam abonelik yük göstergesi -->
      <div class="px-4 py-3 bg-slate-800/30 border-t border-slate-800/40">
        <div class="flex items-center justify-between mb-1.5">
          <p class="text-[10px] text-slate-500">Abonelik yükü (toplam giderin yüzdesi)</p>
          <p class="text-[10px] font-semibold"
             :class="topSubAmount/Math.max(totalExpense,1) > 0.3 ? 'text-red-400' : 'text-emerald-400'"
             x-text="totalExpense > 0 ? Math.round(topSubAmount/totalExpense*100) + '%' : '—'"></p>
        </div>
        <div class="bg-slate-700/40 rounded-full h-1.5">
          <div class="h-full rounded-full transition-all"
               :class="topSubAmount/Math.max(totalExpense,1) > 0.3 ? 'bg-red-400' : 'bg-purple-400'"
               :style="`width: ${Math.min(100, Math.round(topSubAmount/Math.max(totalExpense,1)*100))}%`"></div>
        </div>
        <p class="text-[10px] text-slate-600 mt-1">
          <span x-show="topSubAmount/Math.max(totalExpense,1) > 0.3">⚠ Abonelik yükün yüksek — gözden geçir</span>
          <span x-show="topSubAmount/Math.max(totalExpense,1) <= 0.3">✓ Abonelik yükün makul seviyede</span>
        </p>
      </div>
    </div>

    <!-- AI Tasarruf Tavsiyeleri -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden"
         x-show="result?.advice?.length > 0">
      <div class="px-4 py-3 border-b border-slate-800/40">
        <div class="flex items-center gap-2">
          <div class="w-5 h-5 rounded-md bg-brand-500/10 flex items-center justify-center">
            <svg class="w-3 h-3 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
          </div>
          <h3 class="text-xs font-semibold text-slate-300">Kişisel Tasarruf Tavsiyeleri</h3>
        </div>
        <p class="text-[10px] text-slate-600 mt-1 ml-7">AI senin harcama geçmişine göre bu önerileri hazırladı</p>
      </div>
      <div class="p-4 space-y-2.5">
        <template x-for="(advice, i) in result?.advice ?? []" :key="i">
          <div class="flex items-start gap-3 p-3.5 rounded-xl bg-slate-800/30 border border-slate-700/20">
            <div class="w-7 h-7 rounded-xl flex items-center justify-center shrink-0 mt-0.5 font-bold text-xs"
                 :class="[
                   i===0 ? 'bg-red-500/15 border border-red-500/30 text-red-400' :
                   i===1 ? 'bg-amber-500/15 border border-amber-500/30 text-amber-400' :
                   'bg-brand-500/15 border border-brand-500/30 text-brand-400'
                 ]"
                 x-text="i===0 ? '!' : (i===1 ? '→' : i+1)">
            </div>
            <div>
              <p class="text-[9px] font-semibold uppercase tracking-wider mb-1"
                 :class="i===0 ? 'text-red-400/70' : (i===1 ? 'text-amber-400/70' : 'text-brand-400/70')"
                 x-text="i===0 ? 'Öncelikli' : (i===1 ? 'Önerilen' : 'İpucu')"></p>
              <p class="text-sm text-slate-200 leading-relaxed" x-text="advice"></p>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Abonelik Yönetim Linki -->
    <a href="<?= url('/subscriptions.php') ?>"
       class="flex items-center gap-3 p-4 rounded-xl bg-purple-500/10 border border-purple-500/20 hover:border-purple-500/40 transition-colors">
      <div class="w-10 h-10 rounded-xl bg-purple-500/20 flex items-center justify-center shrink-0">
        <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </div>
      <div class="flex-1">
        <p class="text-sm font-semibold text-purple-300">Abonelik & Taksit Yönetimi</p>
        <p class="text-xs text-purple-400/60 mt-0.5">AI tespitlerini kaydet, taksitlerini takip et</p>
      </div>
      <svg class="w-4 h-4 text-purple-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>

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
