<?php
declare(strict_types=1);

require_once __DIR__ . '/init_db.php';

// ── Demo Piyasa Verileri (2026) ───────────────────────────────────
$stocks = [
    ['symbol' => 'XU100', 'name' => 'BIST 100',           'price' => 12_485.60, 'change' => 1.23,  'abs' =>  151.10, 'volume' => '48.2M',  'color' => 'emerald'],
    ['symbol' => 'THYAO', 'name' => 'Türk Hava Yolları',   'price' => 285.40,    'change' => 2.45,  'abs' =>    6.82, 'volume' => '32.1M',  'color' => 'emerald'],
    ['symbol' => 'GARAN', 'name' => 'Garanti BBVA',        'price' => 112.30,    'change' => -0.89, 'abs' =>   -1.01, 'volume' => '28.4M',  'color' => 'red'],
    ['symbol' => 'ASELS', 'name' => 'Aselsan',             'price' => 78.90,     'change' => 3.12,  'abs' =>    2.38, 'volume' => '15.7M',  'color' => 'emerald'],
    ['symbol' => 'EREGL', 'name' => 'Ereğli Demir Çelik', 'price' => 52.60,     'change' => -1.34, 'abs' =>   -0.71, 'volume' => '22.3M',  'color' => 'red'],
    ['symbol' => 'KCHOL', 'name' => 'Koç Holding',         'price' => 165.50,    'change' => 0.76,  'abs' =>    1.25, 'volume' => '18.9M',  'color' => 'emerald'],
    ['symbol' => 'AKBNK', 'name' => 'Akbank',              'price' => 95.70,     'change' => 1.58,  'abs' =>    1.49, 'volume' => '24.6M',  'color' => 'emerald'],
    ['symbol' => 'SASA',  'name' => 'SASA Polyester',      'price' => 43.20,     'change' => -2.01, 'abs' =>   -0.89, 'volume' => '9.8M',   'color' => 'red'],
];

$gold = [
    ['name' => 'Gram Altın',         'buy' => 4_860.50, 'sell' => 4_880.00, 'change' =>  0.68],
    ['name' => 'Çeyrek Altın',       'buy' => 7_960.00, 'sell' => 8_010.00, 'change' =>  0.68],
    ['name' => 'Yarım Altın',        'buy' => 15_920.00,'sell' => 16_020.00,'change' =>  0.68],
    ['name' => 'Tam Altın',          'buy' => 31_840.00,'sell' => 32_040.00,'change' =>  0.68],
    ['name' => 'Cumhuriyet Altını',  'buy' => 34_200.00,'sell' => 34_400.00,'change' =>  0.52],
    ['name' => 'ONS / USD',          'buy' =>  2_685.40,'sell' =>  2_688.00,'change' =>  0.31],
];

$currencies = [
    ['pair' => 'USD/TRY', 'rate' => 38.45, 'change' =>  0.12, 'flag' => '🇺🇸'],
    ['pair' => 'EUR/TRY', 'rate' => 41.20, 'change' => -0.08, 'flag' => '🇪🇺'],
    ['pair' => 'GBP/TRY', 'rate' => 48.60, 'change' =>  0.23, 'flag' => '🇬🇧'],
    ['pair' => 'CHF/TRY', 'rate' => 43.10, 'change' =>  0.15, 'flag' => '🇨🇭'],
    ['pair' => 'JPY/TRY', 'rate' =>  0.254,'change' => -0.05, 'flag' => '🇯🇵'],
    ['pair' => 'SAR/TRY', 'rate' => 10.24, 'change' =>  0.03, 'flag' => '🇸🇦'],
];

$kap_news = [
    ['symbol' => 'THYAO', 'title' => 'Şubat 2026 yolcu sayısı 7.2 milyona ulaştı, yıllık %14 büyüme', 'time' => '2 saat önce',  'tag' => 'Finansal', 'positive' => true],
    ['symbol' => 'GARAN', 'title' => 'YK hisse başına 3,20 TL nakit temettü dağıtımı kararı aldı',      'time' => '4 saat önce',  'tag' => 'Temettü',  'positive' => true],
    ['symbol' => 'ASELS', 'title' => 'Malezya Havacılık Endüstrisi ile 450M USD savunma sözleşmesi imzalandı', 'time' => '6 saat önce', 'tag' => 'Sözleşme', 'positive' => true],
    ['symbol' => 'EREGL', 'title' => '2025 yıllık bağımsız denetim raporu ve faaliyet raporu yayımlandı', 'time' => '1 gün önce', 'tag' => 'Rapor',    'positive' => null],
    ['symbol' => 'SASA',  'title' => 'Kapasite artırım yatırımı için SPK onayı alındı, 2026 Q3 devreye alınacak', 'time' => '1 gün önce', 'tag' => 'Yatırım', 'positive' => true],
    ['symbol' => 'AKBNK', 'title' => 'Q4 2025 net kârı 18,4 milyar TL — piyasa beklentilerinin %8 üzerinde', 'time' => '2 gün önce', 'tag' => 'Finansal', 'positive' => true],
    ['symbol' => 'KCHOL', 'title' => 'Koç Holding yeni enerji yatırımlarına 2026 yılında 3,2 milyar TL ayırıyor', 'time' => '2 gün önce', 'tag' => 'Yatırım', 'positive' => true],
    ['symbol' => 'XU100', 'title' => 'Endeks Mart ayında %5,2 değer kazandı, yıllık bazda %28 yükseldi',         'time' => '3 gün önce', 'tag' => 'Endeks', 'positive' => true],
];

// Piyasa durumu
$market_open = (date('N') <= 5 && date('H:i') >= '10:00' && date('H:i') <= '18:10');

require_once __DIR__ . '/layout.php';
?>

<div x-data="{ activeTab: 'stocks' }" class="space-y-4">

  <!-- Üst Bant: BIST100 + USD + Gram Altın -->
  <div class="grid grid-cols-3 gap-2.5">
    <div class="bg-gradient-to-br from-brand-500/15 to-brand-600/5 border border-brand-500/20 rounded-xl p-3">
      <p class="text-[10px] font-semibold text-brand-400/70 uppercase tracking-wide">BIST 100</p>
      <p class="text-base font-bold text-slate-100 mt-0.5">12.485</p>
      <p class="text-[10px] text-emerald-400 flex items-center gap-0.5 mt-0.5">
        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
        %1,23
      </p>
    </div>
    <div class="bg-gradient-to-br from-sky-500/15 to-sky-600/5 border border-sky-500/20 rounded-xl p-3">
      <p class="text-[10px] font-semibold text-sky-400/70 uppercase tracking-wide">USD/TRY</p>
      <p class="text-base font-bold text-slate-100 mt-0.5">38,45</p>
      <p class="text-[10px] text-emerald-400 flex items-center gap-0.5 mt-0.5">
        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
        %0,12
      </p>
    </div>
    <div class="bg-gradient-to-br from-yellow-500/15 to-yellow-600/5 border border-yellow-500/20 rounded-xl p-3">
      <p class="text-[10px] font-semibold text-yellow-400/70 uppercase tracking-wide">Gram Altın</p>
      <p class="text-sm font-bold text-slate-100 mt-0.5">4.860₺</p>
      <p class="text-[10px] text-emerald-400 flex items-center gap-0.5 mt-0.5">
        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
        %0,68
      </p>
    </div>
  </div>

  <!-- Piyasa Durumu -->
  <div class="flex items-center justify-between px-4 py-2.5 rounded-xl bg-slate-900/60 border border-slate-800/40">
    <div class="flex items-center gap-2">
      <div class="w-2 h-2 rounded-full <?= $market_open ? 'bg-emerald-400 animate-pulse' : 'bg-slate-600' ?>"></div>
      <span class="text-xs text-slate-400"><?= $market_open ? 'Piyasa Açık' : 'Piyasa Kapalı' ?></span>
    </div>
    <span class="text-xs text-slate-600"><?= date('d.m.Y H:i') ?> · Demo Verisi</span>
  </div>

  <!-- Sekme Başlıkları -->
  <div class="flex bg-slate-900/60 border border-slate-800/60 rounded-xl p-1 gap-1">
    <button @click="activeTab='stocks'"
            :class="activeTab==='stocks' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
      Hisseler
    </button>
    <button @click="activeTab='gold'"
            :class="activeTab==='gold' ? 'bg-yellow-500 text-white shadow-md shadow-yellow-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
      Altın & Döviz
    </button>
    <button @click="activeTab='kap'"
            :class="activeTab==='kap' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
      KAP
    </button>
  </div>

  <!-- ═══════ HISSE SENETLERİ ═══════ -->
  <div x-show="activeTab==='stocks'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <!-- Piyasa Özet Grafiği -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4 mb-3">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">BIST 100 — Son 30 Gün</h3>
        <span class="text-xs text-emerald-400 font-semibold">+5,2%</span>
      </div>
      <div class="relative h-28">
        <canvas id="bistChart"></canvas>
      </div>
    </div>

    <div class="space-y-2">
      <?php foreach ($stocks as $s): ?>
        <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl px-4 py-3 flex items-center gap-3">
          <!-- Sembol -->
          <div class="w-10 h-10 rounded-xl bg-slate-800/80 border border-slate-700/40 flex flex-col items-center justify-center shrink-0">
            <span class="text-[9px] font-bold text-slate-300 leading-none"><?= htmlspecialchars($s['symbol']) ?></span>
          </div>
          <!-- İsim + Hacim -->
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-slate-200 truncate"><?= htmlspecialchars($s['name']) ?></p>
            <p class="text-[10px] text-slate-600 mt-0.5">Hacim: <?= $s['volume'] ?></p>
          </div>
          <!-- Sparkline -->
          <div class="w-16 h-9 shrink-0">
            <canvas id="spark-<?= htmlspecialchars($s['symbol']) ?>" class="w-full h-full"></canvas>
          </div>
          <!-- Fiyat + Değişim -->
          <div class="text-right shrink-0">
            <p class="text-sm font-bold text-slate-100">
              <?= number_format($s['price'], $s['price'] > 1000 ? 0 : 2, ',', '.') ?>
            </p>
            <p class="text-[11px] font-semibold flex items-center justify-end gap-0.5
                       <?= $s['change'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?>">
              <?php if ($s['change'] >= 0): ?>
                <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
              <?php else: ?>
                <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7"/></svg>
              <?php endif; ?>
              %<?= number_format(abs($s['change']), 2, ',', '.') ?>
            </p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ═══════ ALTIN & DÖVİZ ═══════ -->
  <div x-show="activeTab==='gold'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
       class="space-y-4">

    <!-- Altın -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
        <div class="w-5 h-5 rounded-md bg-yellow-500/10 flex items-center justify-center">
          <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
        </div>
        <h3 class="text-xs font-semibold text-slate-300">Altın Fiyatları</h3>
      </div>
      <div class="divide-y divide-slate-800/30">
        <?php foreach ($gold as $g): ?>
          <div class="flex items-center justify-between px-4 py-3">
            <div>
              <p class="text-sm font-medium text-slate-200"><?= htmlspecialchars($g['name']) ?></p>
              <p class="text-[10px] text-slate-600 mt-0.5">
                Alış: <span class="text-yellow-400">₺<?= number_format($g['buy'], 2, ',', '.') ?></span>
              </p>
            </div>
            <div class="text-right">
              <p class="text-sm font-bold text-slate-100">₺<?= number_format($g['sell'], 2, ',', '.') ?></p>
              <p class="text-[11px] font-semibold <?= $g['change'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?> flex items-center justify-end gap-0.5 mt-0.5">
                <?php if ($g['change'] >= 0): ?>
                  <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
                <?php else: ?>
                  <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7"/></svg>
                <?php endif; ?>
                %<?= number_format(abs($g['change']), 2, ',', '.') ?>
              </p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Döviz -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
        <div class="w-5 h-5 rounded-md bg-sky-500/10 flex items-center justify-center">
          <svg class="w-3 h-3 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
        </div>
        <h3 class="text-xs font-semibold text-slate-300">Döviz Kurları</h3>
      </div>
      <div class="divide-y divide-slate-800/30">
        <?php foreach ($currencies as $c): ?>
          <div class="flex items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
              <span class="text-xl"><?= $c['flag'] ?></span>
              <p class="text-sm font-semibold text-slate-200"><?= htmlspecialchars($c['pair']) ?></p>
            </div>
            <div class="text-right">
              <p class="text-sm font-bold text-slate-100">
                <?= number_format($c['rate'], $c['rate'] < 1 ? 4 : 2, ',', '.') ?> ₺
              </p>
              <p class="text-[11px] font-semibold <?= $c['change'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?> flex items-center justify-end gap-0.5 mt-0.5">
                <?php if ($c['change'] >= 0): ?>
                  <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7"/></svg>
                <?php else: ?>
                  <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7"/></svg>
                <?php endif; ?>
                %<?= number_format(abs($c['change']), 2, ',', '.') ?>
              </p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ═══════ KAP HABERLERİ ═══════ -->
  <div x-show="activeTab==='kap'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
       class="space-y-3">

    <div class="flex items-center justify-between px-1">
      <p class="text-xs text-slate-500">Kamuoyu Aydınlatma Platformu bildirimleri</p>
      <span class="text-[10px] text-slate-600 bg-slate-800/60 px-2 py-1 rounded-md">Demo</span>
    </div>

    <?php foreach ($kap_news as $news): ?>
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
        <div class="flex items-start justify-between gap-3 mb-2">
          <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-brand-400 bg-brand-500/10 border border-brand-500/20 px-2 py-0.5 rounded-md">
              <?= htmlspecialchars($news['symbol']) ?>
            </span>
            <span class="text-[10px] font-medium
              <?php
                if ($news['positive'] === true)      echo 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20';
                elseif ($news['positive'] === false)  echo 'text-red-400 bg-red-500/10 border-red-500/20';
                else                                  echo 'text-slate-400 bg-slate-800/60 border-slate-700/30';
              ?>
              border px-1.5 py-0.5 rounded-md">
              <?= htmlspecialchars($news['tag']) ?>
            </span>
          </div>
          <span class="text-[10px] text-slate-600 shrink-0"><?= htmlspecialchars($news['time']) ?></span>
        </div>
        <p class="text-sm text-slate-300 leading-relaxed"><?= htmlspecialchars($news['title']) ?></p>
        <?php if ($news['positive'] === true): ?>
          <div class="flex items-center gap-1 mt-2">
            <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
            <span class="text-[10px] text-emerald-400">Pozitif Gelişme</span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
(function() {
  // Deterministik sahte veri üretici (sparkline için)
  function seededRand(seed) {
    let s = seed & 0xffffffff;
    return () => {
      s = (Math.imul(1664525, s) + 1013904223) | 0;
      return (s >>> 0) / 0xffffffff;
    };
  }

  function genSparkline(price, positive, days = 25) {
    const rnd  = seededRand(Math.round(price * 100));
    const data = [price];
    const bias = positive ? 0.54 : 0.46;
    for (let i = 1; i < days; i++) {
      const pct  = (rnd() - bias) * 0.025;
      data.push(Math.max(0.01, data[data.length - 1] * (1 + pct)));
    }
    return data;
  }

  // Hisse Sparklineleri
  const stocks = <?= json_encode(array_map(fn($s) => ['symbol' => $s['symbol'], 'price' => $s['price'], 'positive' => $s['change'] >= 0], $stocks)) ?>;

  stocks.forEach(s => {
    const ctx = document.getElementById('spark-' + s.symbol);
    if (!ctx) return;
    const data  = genSparkline(s.price, s.positive);
    const color = s.positive ? '#10b981' : '#ef4444';
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.map((_, i) => i),
        datasets: [{ data, borderColor: color, borderWidth: 1.5, pointRadius: 0, fill: false, tension: 0.4 }]
      },
      options: {
        responsive: false, animation: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales:  { x: { display: false }, y: { display: false } }
      }
    });
  });

  // BIST 100 Ana Grafik
  const bistCtx = document.getElementById('bistChart');
  if (bistCtx) {
    const data   = genSparkline(12485.60, true, 30);
    const labels = Array.from({length: 30}, (_, i) => {
      const d = new Date(); d.setDate(d.getDate() - (29 - i));
      return d.getDate() + '.' + (d.getMonth() + 1);
    });
    new Chart(bistCtx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          data,
          borderColor: '#6366f1',
          borderWidth: 2,
          pointRadius: 0,
          fill: true,
          tension: 0.4,
          backgroundColor: (ctx) => {
            const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 112);
            g.addColorStop(0, 'rgba(99,102,241,0.25)');
            g.addColorStop(1, 'rgba(99,102,241,0)');
            return g;
          }
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
                   tooltip: { callbacks: { label: (c) => ' ' + c.parsed.y.toLocaleString('tr-TR', {maximumFractionDigits: 0}) } } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#475569', font: { size: 9 }, maxTicksLimit: 6 } },
          y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#475569', font: { size: 9 }, maxTicksLimit: 4,
                callback: (v) => v.toLocaleString('tr-TR', {maximumFractionDigits: 0}) } }
        }
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
