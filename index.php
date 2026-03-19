<?php
require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

// Son 30 günlük özet veriler
$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS total_expense,
        COUNT(*) AS total_count
    FROM transactions
    WHERE date >= date('now', '-30 days')
")->fetch();

$balance    = $summary['total_income'] - $summary['total_expense'];
$currency   = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol     = match($currency) { 'USD' => '$', 'EUR' => '€', default => '₺' };

// Son 5 işlem
$recent = $pdo->query("
    SELECT * FROM transactions ORDER BY date DESC, created_at DESC LIMIT 5
")->fetchAll();

// Kategori dağılımı (grafik için)
$categories = $pdo->query("
    SELECT category, SUM(amount) AS total
    FROM transactions
    WHERE type='expense' AND date >= date('now', '-30 days')
    GROUP BY category
    ORDER BY total DESC
    LIMIT 6
")->fetchAll();

require_once __DIR__ . '/layout.php';
?>

<div class="space-y-5">

  <!-- Bakiye Kartı -->
  <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 to-purple-700 p-5 shadow-xl shadow-brand-500/20">
    <div class="absolute -right-6 -top-6 w-32 h-32 rounded-full bg-white/5"></div>
    <div class="absolute -right-2 -bottom-8 w-24 h-24 rounded-full bg-white/5"></div>
    <p class="text-xs font-medium text-brand-100/70 mb-1">Aylık Bakiye</p>
    <p class="text-3xl font-bold text-white tracking-tight">
      <?= $symbol ?><?= number_format(abs($balance), 2, ',', '.') ?>
      <?php if ($balance < 0): ?>
        <span class="text-lg text-red-300">-</span>
      <?php endif; ?>
    </p>
    <p class="text-xs text-brand-100/60 mt-1">Son 30 günlük özet</p>

    <div class="flex gap-4 mt-4 pt-4 border-t border-white/10">
      <div>
        <p class="text-xs text-brand-100/60">Gelir</p>
        <p class="text-sm font-semibold text-emerald-300"><?= $symbol ?><?= number_format($summary['total_income'], 2, ',', '.') ?></p>
      </div>
      <div>
        <p class="text-xs text-brand-100/60">Gider</p>
        <p class="text-sm font-semibold text-red-300"><?= $symbol ?><?= number_format($summary['total_expense'], 2, ',', '.') ?></p>
      </div>
      <div>
        <p class="text-xs text-brand-100/60">İşlem</p>
        <p class="text-sm font-semibold text-white"><?= $summary['total_count'] ?></p>
      </div>
    </div>
  </div>

  <!-- Hızlı Eylemler -->
  <div class="grid grid-cols-2 gap-3">
    <a href="/transactions.php"
       class="flex items-center gap-3 p-4 rounded-xl bg-slate-900/60 border border-slate-800/60
              hover:border-brand-500/30 transition-colors card-hover">
      <div class="w-9 h-9 rounded-lg bg-brand-500/10 flex items-center justify-center shrink-0">
        <svg class="w-4.5 h-4.5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
      </div>
      <div>
        <p class="text-xs font-medium text-slate-200">İşlem Ekle</p>
        <p class="text-[10px] text-slate-500">Manuel veya PDF</p>
      </div>
    </a>

    <a href="/ai_optimization.php"
       class="flex items-center gap-3 p-4 rounded-xl bg-slate-900/60 border border-slate-800/60
              hover:border-purple-500/30 transition-colors card-hover">
      <div class="w-9 h-9 rounded-lg bg-purple-500/10 flex items-center justify-center shrink-0">
        <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
        </svg>
      </div>
      <div>
        <p class="text-xs font-medium text-slate-200">AI Analiz</p>
        <p class="text-[10px] text-slate-500">Bütçeni optimize et</p>
      </div>
    </a>
  </div>

  <!-- Kategori Grafik -->
  <?php if (!empty($categories)): ?>
  <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
    <h3 class="text-xs font-semibold text-slate-400 mb-3 uppercase tracking-wider">Harcama Dağılımı</h3>
    <div class="relative h-44">
      <canvas id="categoryChart"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <!-- Son İşlemler -->
  <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800/40 flex items-center justify-between">
      <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Son İşlemler</h3>
      <a href="/transactions.php" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">Tümü →</a>
    </div>

    <?php if (empty($recent)): ?>
      <div class="p-8 text-center">
        <p class="text-slate-500 text-sm">Henüz işlem yok.</p>
        <a href="/transactions.php" class="text-brand-400 text-xs hover:underline mt-1 inline-block">
          İlk işlemi ekle →
        </a>
      </div>
    <?php else: ?>
      <div class="divide-y divide-slate-800/40">
        <?php foreach ($recent as $tx): ?>
          <div class="flex items-center gap-3 px-4 py-3">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0
                        <?= $tx['type'] === 'income' ? 'bg-emerald-500/10' : 'bg-red-500/10' ?>">
              <?php if ($tx['type'] === 'income'): ?>
                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                </svg>
              <?php else: ?>
                <svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                </svg>
              <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($tx['description']) ?></p>
              <p class="text-xs text-slate-500"><?= htmlspecialchars($tx['category']) ?> · <?= htmlspecialchars($tx['date']) ?></p>
            </div>
            <p class="text-sm font-semibold shrink-0 <?= $tx['type'] === 'income' ? 'text-emerald-400' : 'text-red-400' ?>">
              <?= $tx['type'] === 'income' ? '+' : '-' ?><?= $symbol ?><?= number_format($tx['amount'], 2, ',', '.') ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php if (!empty($categories)): ?>
<script>
(function() {
  const ctx = document.getElementById('categoryChart');
  if (!ctx) return;

  const labels = <?= json_encode(array_column($categories, 'category')) ?>;
  const data   = <?= json_encode(array_map(fn($c) => round($c['total'], 2), $categories)) ?>;
  const colors = ['#6366f1','#a855f7','#ec4899','#f59e0b','#10b981','#3b82f6'];

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: colors,
        borderWidth: 0,
        hoverOffset: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '65%',
      plugins: {
        legend: {
          position: 'right',
          labels: {
            color: '#94a3b8',
            font: { size: 10, family: 'Inter' },
            boxWidth: 10,
            padding: 8
          }
        },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.label}: <?= $symbol ?>${ctx.parsed.toLocaleString('tr-TR', {minimumFractionDigits: 2})}`
          }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
