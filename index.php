<?php
declare(strict_types=1);

require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

// 30 günlük özet
$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense,
        COUNT(*) AS total_count
    FROM transactions
    WHERE date >= date('now', '-30 days')
")->fetch();

$currency = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol   = match($currency) { 'USD' => '$', 'EUR' => '€', default => '₺' };
$balance  = $summary['income'] - $summary['expense'];

// Kategori dağılımı (gider, grafik için)
$categories = $pdo->query("
    SELECT category, SUM(amount) AS total
    FROM transactions
    WHERE type='expense' AND date >= date('now', '-30 days')
    GROUP BY category ORDER BY total DESC LIMIT 6
")->fetchAll();

// Günlük gider akışı (son 14 gün, sparkline için)
$daily = $pdo->query("
    SELECT date, SUM(amount) AS total
    FROM transactions
    WHERE type='expense' AND date >= date('now', '-14 days')
    GROUP BY date ORDER BY date ASC
")->fetchAll();

// Son 5 işlem
$recent = $pdo->query("
    SELECT * FROM transactions ORDER BY date DESC, created_at DESC LIMIT 5
")->fetchAll();

// Toplam işlem sayısı
$total_tx = (int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();

// Bu ay ve geçen ay karşılaştırma
$this_month = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS exp
    FROM transactions WHERE strftime('%Y-%m', date) = strftime('%Y-%m', 'now')
")->fetchColumn();

$last_month = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS exp
    FROM transactions WHERE strftime('%Y-%m', date) = strftime('%Y-%m', date('now', '-1 month'))
")->fetchColumn();

$month_change = $last_month > 0 ? round((($this_month - $last_month) / $last_month) * 100, 1) : 0;

require_once __DIR__ . '/layout.php';
?>

<div class="space-y-4">

  <!-- ── Bakiye Kartı ── -->
  <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 via-brand-500 to-purple-600 p-5 shadow-xl shadow-brand-500/25">
    <!-- Dekoratif daireler -->
    <div class="absolute -right-8 -top-8 w-40 h-40 rounded-full bg-white/5 pointer-events-none"></div>
    <div class="absolute -right-2 bottom-0 w-28 h-28 rounded-full bg-white/5 pointer-events-none"></div>
    <div class="absolute left-1/2 top-0 w-20 h-20 rounded-full bg-white/5 pointer-events-none"></div>

    <div class="relative">
      <p class="text-xs font-medium text-white/60 tracking-wider uppercase">Aylık Net Bakiye</p>
      <div class="flex items-end gap-3 mt-1">
        <p class="text-4xl font-black text-white tracking-tight">
          <?= $symbol ?><?= number_format(abs($balance), 0, ',', '.') ?>
        </p>
        <?php if ($balance < 0): ?>
          <span class="text-red-300 text-sm font-semibold mb-1">Açık</span>
        <?php elseif ($balance > 0): ?>
          <span class="text-emerald-300 text-sm font-semibold mb-1">Artı</span>
        <?php endif; ?>
      </div>

      <?php if ($last_month > 0 && $month_change != 0): ?>
        <div class="flex items-center gap-1.5 mt-1.5">
          <div class="flex items-center gap-0.5 text-xs <?= $month_change <= 0 ? 'text-emerald-300' : 'text-red-300' ?>">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="<?= $month_change <= 0 ? 'M5 10l7-7m0 0l7 7' : 'M19 14l-7 7m0 0l-7-7' ?>"/>
            </svg>
            %<?= abs($month_change) ?> geçen aya göre
          </div>
        </div>
      <?php endif; ?>

      <!-- Gelir / Gider / Sayaç -->
      <div class="flex items-center gap-1 mt-4 pt-3.5 border-t border-white/15">
        <div class="flex-1 min-w-0">
          <p class="text-[10px] text-white/50 font-medium uppercase tracking-wide">Gelir</p>
          <p class="text-sm font-bold text-emerald-300 mt-0.5"><?= $symbol ?><?= number_format($summary['income'], 0, ',', '.') ?></p>
        </div>
        <div class="w-px h-8 bg-white/15"></div>
        <div class="flex-1 min-w-0 px-3">
          <p class="text-[10px] text-white/50 font-medium uppercase tracking-wide">Gider</p>
          <p class="text-sm font-bold text-red-300 mt-0.5"><?= $symbol ?><?= number_format($summary['expense'], 0, ',', '.') ?></p>
        </div>
        <div class="w-px h-8 bg-white/15"></div>
        <div class="flex-1 min-w-0 text-right">
          <p class="text-[10px] text-white/50 font-medium uppercase tracking-wide">İşlem</p>
          <p class="text-sm font-bold text-white mt-0.5"><?= $summary['total_count'] ?></p>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Hızlı Eylemler ── -->
  <div class="grid grid-cols-4 gap-2">
    <a href="<?= url('/transactions.php') ?>?tab=manual"
       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-slate-900/60 border border-slate-800/60 hover:border-brand-500/40 transition-all active:scale-95">
      <div class="w-10 h-10 rounded-xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center">
        <svg class="w-4.5 h-4.5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      </div>
      <p class="text-[10px] font-medium text-slate-400 text-center leading-tight">Ekle</p>
    </a>
    <a href="<?= url('/transactions.php') ?>?tab=pdf"
       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-slate-900/60 border border-slate-800/60 hover:border-red-500/40 transition-all active:scale-95">
      <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
        <svg class="w-4.5 h-4.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <p class="text-[10px] font-medium text-slate-400 text-center leading-tight">PDF</p>
    </a>
    <a href="<?= url('/ai_optimization.php') ?>"
       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-slate-900/60 border border-slate-800/60 hover:border-purple-500/40 transition-all active:scale-95">
      <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center">
        <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
      </div>
      <p class="text-[10px] font-medium text-slate-400 text-center leading-tight">Analiz</p>
    </a>
    <a href="<?= url('/investments.php') ?>"
       class="flex flex-col items-center gap-2 p-3 rounded-xl bg-slate-900/60 border border-slate-800/60 hover:border-emerald-500/40 transition-all active:scale-95">
      <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-4.5 h-4.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
      </div>
      <p class="text-[10px] font-medium text-slate-400 text-center leading-tight">Piyasa</p>
    </a>
  </div>

  <?php if ($total_tx === 0): ?>
    <!-- ── Onboarding Boş Durum ── -->
    <div class="bg-slate-900/60 border border-slate-800/60 border-dashed rounded-2xl p-6 text-center space-y-4">
      <div class="w-16 h-16 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center mx-auto">
        <svg class="w-7 h-7 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
      </div>
      <div>
        <h3 class="text-sm font-bold text-slate-200">Başlayalım</h3>
        <p class="text-xs text-slate-500 mt-1 leading-relaxed">
          İlk işlemini ekle veya banka ekstreni yükle.<br>AI otomatik kategorize eder.
        </p>
      </div>
      <div class="flex gap-2 justify-center">
        <a href="<?= url('/transactions.php') ?>?tab=manual"
           class="px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">
          Manuel Ekle
        </a>
        <a href="<?= url('/transactions.php') ?>?tab=pdf"
           class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700/50 text-slate-300 text-xs font-semibold transition-colors">
          PDF Yükle
        </a>
      </div>
    </div>

  <?php else: ?>

    <!-- ── Günlük Gider Grafiği ── -->
    <?php if (!empty($daily)): ?>
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Son 14 Gün Gider</h3>
        <span class="text-xs text-slate-600"><?= $symbol ?><?= number_format(array_sum(array_column($daily, 'total')), 0, ',', '.') ?></span>
      </div>
      <div class="relative h-32">
        <canvas id="dailyChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Kategori Dağılımı ── -->
    <?php if (!empty($categories)): ?>
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
      <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Kategori Dağılımı</h3>
      <div class="relative h-44">
        <canvas id="categoryChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Son İşlemler ── -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-800/40 flex items-center justify-between">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Son İşlemler</h3>
        <a href="<?= url('/transactions.php') ?>?tab=list" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
          Tümü (<?= $total_tx ?>) →
        </a>
      </div>
      <div class="divide-y divide-slate-800/30">
        <?php foreach ($recent as $tx): ?>
          <div class="flex items-center gap-3 px-4 py-3">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0
                        <?= $tx['type'] === 'income' ? 'bg-emerald-500/10' : 'bg-red-500/10' ?>">
              <?php if ($tx['type'] === 'income'): ?>
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
              <?php else: ?>
                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
              <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($tx['description']) ?></p>
              <p class="text-[10px] text-slate-500 mt-0.5">
                <?= htmlspecialchars($tx['category']) ?> · <?= date('d.m.Y', strtotime($tx['date'])) ?>
              </p>
            </div>
            <p class="text-sm font-semibold shrink-0 <?= $tx['type'] === 'income' ? 'text-emerald-400' : 'text-red-400' ?>">
              <?= $tx['type'] === 'income' ? '+' : '−' ?><?= $symbol ?><?= number_format($tx['amount'], 2, ',', '.') ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>

</div>

<?php if (!empty($categories) || !empty($daily)): ?>
<script>
(function() {
  const sym = '<?= $symbol ?>';

  <?php if (!empty($daily)): ?>
  // Günlük gider bar chart
  (function() {
    const ctx = document.getElementById('dailyChart');
    if (!ctx) return;

    // Son 14 günün tüm tarihlerini oluştur
    const allDates = [];
    for (let i = 13; i >= 0; i--) {
      const d = new Date(); d.setDate(d.getDate() - i);
      allDates.push(d.toISOString().slice(0, 10));
    }
    const dataMap = {};
    <?php foreach ($daily as $d): ?>
      dataMap['<?= $d['date'] ?>'] = <?= round($d['total'], 2) ?>;
    <?php endforeach; ?>

    const labels = allDates.map(d => {
      const dt = new Date(d); return (dt.getDate()) + '.' + (dt.getMonth() + 1);
    });
    const values = allDates.map(d => dataMap[d] ?? 0);

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: values.map(v => v > 0 ? 'rgba(99,102,241,0.5)' : 'rgba(99,102,241,0.1)'),
          borderColor: 'rgba(99,102,241,0.8)',
          borderWidth: 1,
          borderRadius: 4,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => ` ${sym}${c.parsed.y.toLocaleString('tr-TR', {minimumFractionDigits:2})}` } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#475569', font: { size: 9 } } },
          y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#475569', font: { size: 9 },
               callback: v => sym + v.toLocaleString('tr-TR') } }
        }
      }
    });
  })();
  <?php endif; ?>

  <?php if (!empty($categories)): ?>
  // Kategori donut
  (function() {
    const ctx = document.getElementById('categoryChart');
    if (!ctx) return;
    const labels = <?= json_encode(array_column($categories, 'category')) ?>;
    const data   = <?= json_encode(array_map(fn($c) => round($c['total'], 2), $categories)) ?>;
    const colors = ['#6366f1','#a855f7','#ec4899','#f59e0b','#10b981','#3b82f6'];

    new Chart(ctx, {
      type: 'doughnut',
      data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0, hoverOffset: 4 }] },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: {
          legend: { position: 'right', labels: { color: '#94a3b8', font: { size: 10, family: 'Inter' }, boxWidth: 10, padding: 8 } },
          tooltip: { callbacks: { label: c => ` ${c.label}: ${sym}${c.parsed.toLocaleString('tr-TR', {minimumFractionDigits:2})}` } }
        }
      }
    });
  })();
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
