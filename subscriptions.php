<?php
declare(strict_types=1);

require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

$notice = ['type' => '', 'msg' => ''];

// ── Abonelik / Taksit Ekle ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name       = trim($_POST['name'] ?? '');
    $amount     = abs((float)($_POST['amount'] ?? 0));
    $type       = in_array($_POST['type'] ?? '', ['subscription', 'installment']) ? $_POST['type'] : 'subscription';
    $billing_day= min(28, max(1, (int)($_POST['billing_day'] ?? 1)));
    $category   = trim($_POST['category'] ?? ($type === 'installment' ? 'Taksit' : 'Abonelik'));
    $total_inst = $type === 'installment' ? max(1, (int)($_POST['total_installments'] ?? 1)) : null;
    $paid_inst  = $type === 'installment' ? min($total_inst, max(0, (int)($_POST['paid_installments'] ?? 0))) : 0;
    $start_date = trim($_POST['start_date'] ?? date('Y-m-d'));
    $notes      = mb_substr(trim($_POST['notes'] ?? ''), 0, 255);

    if ($name && $amount > 0) {
        $pdo->prepare("
            INSERT INTO subscriptions (name, amount, billing_day, category, type,
                total_installments, paid_installments, start_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$name, $amount, $billing_day, $category, $type, $total_inst, $paid_inst, $start_date, $notes]);
        header('Location: ' . url('/subscriptions.php') . '?ok=add');
        exit;
    }
    $notice = ['type' => 'error', 'msg' => 'Ad ve tutar zorunludur.'];
}

// ── Ödeme Güncelle (taksit ilerleme) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("
            UPDATE subscriptions
            SET paid_installments = MIN(paid_installments + 1, total_installments),
                active = CASE WHEN paid_installments + 1 >= total_installments THEN 0 ELSE 1 END
            WHERE id = ? AND type = 'installment'
        ")->execute([$id]);
    }
    header('Location: ' . url('/subscriptions.php') . '?ok=pay');
    exit;
}

// ── Sil ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $pdo->prepare("DELETE FROM subscriptions WHERE id=?")->execute([$id]);
    header('Location: ' . url('/subscriptions.php') . '?ok=delete');
    exit;
}

// ── Toggle Aktif ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $pdo->prepare("UPDATE subscriptions SET active = 1-active WHERE id=?")->execute([$id]);
    header('Location: ' . url('/subscriptions.php'));
    exit;
}

$ok_map = [
    'add'    => ['type' => 'success', 'msg' => 'Eklendi.'],
    'pay'    => ['type' => 'success', 'msg' => 'Taksit ödeme kaydedildi.'],
    'delete' => ['type' => 'success', 'msg' => 'Silindi.'],
];
if (isset($_GET['ok']) && isset($ok_map[$_GET['ok']])) {
    $notice = $ok_map[$_GET['ok']];
}

$currency = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol   = match($currency) { 'USD' => '$', 'EUR' => '€', default => '₺' };

// Tüm abonelik ve taksitler
$all = $pdo->query("SELECT * FROM subscriptions ORDER BY active DESC, type, billing_day")->fetchAll();

$subscriptions = array_filter($all, fn($r) => $r['type'] === 'subscription');
$installments  = array_filter($all, fn($r) => $r['type'] === 'installment');

$monthly_sub   = array_sum(array_map(fn($r) => $r['active'] ? $r['amount'] : 0, $subscriptions));
$monthly_inst  = array_sum(array_map(fn($r) => $r['active'] ? $r['amount'] : 0, $installments));
$monthly_total = $monthly_sub + $monthly_inst;

// Taksit biten tarihi hesapla
$calc_end = function(array $r): string {
    if (!$r['total_installments']) return '—';
    $remaining = max(0, $r['total_installments'] - $r['paid_installments']);
    if ($remaining === 0) return 'Tamamlandı';
    $end = new DateTime($r['start_date'] ?: date('Y-m-d'));
    $end->modify("+{$r['total_installments']} months");
    return $end->format('M Y');
};

// Sonraki ödeme günü
$next_payment = function(int $billing_day): string {
    $today    = (int)date('d');
    $thisMonth = (int)date('m');
    $thisYear  = (int)date('Y');
    if ($billing_day > $today) {
        return date('d M', mktime(0, 0, 0, $thisMonth, $billing_day, $thisYear));
    }
    $next = mktime(0, 0, 0, $thisMonth + 1, $billing_day, $thisYear);
    return date('d M', $next);
};

$categories_list = ['Abonelik','Taksit','Kira','Fatura','Sigorta','Eğitim','Spor','Eğlence','Teknoloji','Sağlık','Diğer'];

require_once __DIR__ . '/layout.php';
?>

<div x-data="{ showForm: false, formType: 'subscription' }" class="space-y-4">

  <!-- ── Aylık Yük Özeti ── -->
  <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-purple-600/80 to-brand-600/80 p-5 border border-purple-500/20">
    <div class="absolute -right-6 -top-6 w-32 h-32 rounded-full bg-white/5 pointer-events-none"></div>
    <p class="text-xs font-medium text-white/60 uppercase tracking-wider">Aylık Toplam Yükümlülük</p>
    <p class="text-3xl font-black text-white mt-1"><?= $symbol ?><?= number_format($monthly_total, 2, ',', '.') ?></p>
    <div class="flex gap-4 mt-3 pt-3 border-t border-white/15">
      <div>
        <p class="text-[10px] text-white/50">Abonelikler</p>
        <p class="text-sm font-bold text-purple-200"><?= $symbol ?><?= number_format($monthly_sub, 2, ',', '.') ?></p>
      </div>
      <div class="w-px bg-white/15"></div>
      <div>
        <p class="text-[10px] text-white/50">Taksitler</p>
        <p class="text-sm font-bold text-blue-200"><?= $symbol ?><?= number_format($monthly_inst, 2, ',', '.') ?></p>
      </div>
      <div class="w-px bg-white/15"></div>
      <div>
        <p class="text-[10px] text-white/50">Adet</p>
        <p class="text-sm font-bold text-white"><?= count(array_filter($all, fn($r) => $r['active'])) ?> aktif</p>
      </div>
    </div>
  </div>

  <!-- Bildirim -->
  <?php if ($notice['msg']): ?>
  <div class="flex items-center gap-2.5 p-3 rounded-xl text-sm
              <?= $notice['type'] === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400' ?>">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $notice['type'] === 'success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/>
    </svg>
    <?= htmlspecialchars($notice['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- ── Ekle Butonu ── -->
  <div class="flex gap-2">
    <button @click="showForm=!showForm; formType='subscription'"
            :class="formType==='subscription' && showForm ? 'bg-purple-500 text-white' : 'bg-slate-800/60 border border-slate-700/50 text-slate-300'"
            class="flex-1 py-2.5 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition-all">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Abonelik Ekle
    </button>
    <button @click="showForm=!showForm; formType='installment'"
            :class="formType==='installment' && showForm ? 'bg-blue-500 text-white' : 'bg-slate-800/60 border border-slate-700/50 text-slate-300'"
            class="flex-1 py-2.5 rounded-xl text-xs font-semibold flex items-center justify-center gap-1.5 transition-all">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      Taksit Ekle
    </button>
  </div>

  <!-- ── Form ── -->
  <div x-show="showForm" x-transition class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
    <div class="flex items-center gap-2 mb-4">
      <div class="w-5 h-5 rounded-md flex items-center justify-center"
           :class="formType==='subscription' ? 'bg-purple-500/20' : 'bg-blue-500/20'">
        <svg class="w-3 h-3" :class="formType==='subscription' ? 'text-purple-400' : 'text-blue-400'"
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            x-bind:d="formType==='subscription'
              ? 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'
              : 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'"/>
        </svg>
      </div>
      <h3 class="text-sm font-semibold text-slate-200"
          x-text="formType==='subscription' ? 'Yeni Abonelik' : 'Yeni Taksit'"></h3>
    </div>

    <form method="POST" action="<?= url('/subscriptions.php') ?>" class="space-y-3">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="type"   :value="formType">

      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">
          <label class="text-xs text-slate-400 mb-1 block">Ad / Açıklama</label>
          <input type="text" name="name" required placeholder="Netflix, Kredi Kartı Taksit 1..."
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        placeholder-slate-600 focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>
        <div>
          <label class="text-xs text-slate-400 mb-1 block">Aylık Tutar</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"><?= $symbol ?></span>
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0,00"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-7 pr-3 py-2.5 text-sm text-slate-200
                          focus:outline-none focus:border-brand-500/60 transition-colors">
          </div>
        </div>
        <div>
          <label class="text-xs text-slate-400 mb-1 block">Ödeme Günü</label>
          <input type="number" name="billing_day" min="1" max="28" placeholder="1-28" value="1"
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>

        <!-- Taksit'e özel alanlar -->
        <div x-show="formType==='installment'">
          <label class="text-xs text-slate-400 mb-1 block">Toplam Taksit</label>
          <input type="number" name="total_installments" min="1" placeholder="12"
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>
        <div x-show="formType==='installment'">
          <label class="text-xs text-slate-400 mb-1 block">Ödenen Taksit</label>
          <input type="number" name="paid_installments" min="0" value="0"
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>

        <div>
          <label class="text-xs text-slate-400 mb-1 block">Kategori</label>
          <div class="relative">
            <select name="category"
                    class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 pr-7
                           text-sm text-slate-200 focus:outline-none focus:border-brand-500/60 transition-colors">
              <?php foreach ($categories_list as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $c === 'Abonelik' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </div>
          </div>
        </div>
        <div>
          <label class="text-xs text-slate-400 mb-1 block">Başlangıç</label>
          <input type="date" name="start_date" value="<?= date('Y-m-d') ?>"
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>
        <div class="col-span-2">
          <label class="text-xs text-slate-400 mb-1 block">Notlar (isteğe bağlı)</label>
          <input type="text" name="notes" maxlength="255"
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-2.5 text-sm text-slate-200
                        placeholder-slate-600 focus:outline-none focus:border-brand-500/60 transition-colors">
        </div>
      </div>

      <button type="submit"
              class="w-full py-3 rounded-xl font-semibold text-sm text-white transition-all"
              :class="formType==='subscription' ? 'bg-purple-500 hover:bg-purple-600' : 'bg-blue-500 hover:bg-blue-600'">
        <span x-text="formType==='subscription' ? 'Abonelik Ekle' : 'Taksit Ekle'"></span>
      </button>
    </form>
  </div>

  <!-- ── ABONELİKLER ── -->
  <?php if (!empty($subscriptions)): ?>
  <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
      <div class="w-5 h-5 rounded-md bg-purple-500/10 flex items-center justify-center">
        <svg class="w-3 h-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </div>
      <h3 class="text-xs font-semibold text-slate-300">Abonelikler</h3>
      <span class="ml-auto text-xs text-slate-500"><?= $symbol ?><?= number_format($monthly_sub, 2, ',', '.') ?>/ay</span>
    </div>
    <div class="divide-y divide-slate-800/30">
      <?php foreach ($subscriptions as $r): ?>
        <div class="flex items-center gap-3 px-4 py-3 <?= !$r['active'] ? 'opacity-50' : '' ?>">
          <div class="w-9 h-9 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5">
              <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($r['name']) ?></p>
              <?php if ($r['auto_detected']): ?>
                <span class="text-[9px] text-brand-400 bg-brand-500/10 border border-brand-500/20 px-1 rounded">AI</span>
              <?php endif; ?>
            </div>
            <p class="text-[10px] text-slate-500 mt-0.5">
              Her ayın <?= $r['billing_day'] ?>. günü ·
              Sonraki: <span class="text-slate-400"><?= $next_payment($r['billing_day']) ?></span>
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-bold text-purple-400"><?= $symbol ?><?= number_format($r['amount'], 2, ',', '.') ?></p>
            <div class="flex items-center gap-1 mt-1 justify-end">
              <!-- Toggle -->
              <form method="POST" action="<?= url('/subscriptions.php') ?>" class="inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="text-[9px] px-1.5 py-0.5 rounded-md border transition-colors
                  <?= $r['active'] ? 'text-emerald-400 border-emerald-500/30 bg-emerald-500/10' : 'text-slate-500 border-slate-700/30 bg-slate-800/30' ?>">
                  <?= $r['active'] ? 'Aktif' : 'Pasif' ?>
                </button>
              </form>
              <!-- Sil -->
              <form method="POST" action="<?= url('/subscriptions.php') ?>" class="inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" onclick="return confirm('Silinsin mi?')" class="text-slate-600 hover:text-red-400 transition-colors ml-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── TAKSİTLER ── -->
  <?php if (!empty($installments)): ?>
  <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800/40 flex items-center gap-2">
      <div class="w-5 h-5 rounded-md bg-blue-500/10 flex items-center justify-center">
        <svg class="w-3 h-3 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      </div>
      <h3 class="text-xs font-semibold text-slate-300">Taksitler</h3>
      <span class="ml-auto text-xs text-slate-500"><?= $symbol ?><?= number_format($monthly_inst, 2, ',', '.') ?>/ay</span>
    </div>
    <div class="divide-y divide-slate-800/30">
      <?php foreach ($installments as $r):
        $total  = (int)($r['total_installments'] ?? 1);
        $paid   = (int)$r['paid_installments'];
        $remain = max(0, $total - $paid);
        $pct    = $total > 0 ? round($paid / $total * 100) : 0;
        $done   = $remain === 0;
      ?>
        <div class="px-4 py-3 <?= $done ? 'opacity-50' : '' ?>">
          <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($r['name']) ?></p>
                <?php if ($done): ?>
                  <span class="text-[9px] text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-1 rounded">✓ Bitti</span>
                <?php endif; ?>
              </div>
              <p class="text-[10px] text-slate-500 mt-0.5">
                Bitiş: <span class="text-slate-400"><?= $calc_end($r) ?></span>
                · Her ayın <?= $r['billing_day'] ?>. günü
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-sm font-bold text-blue-400"><?= $symbol ?><?= number_format($r['amount'], 2, ',', '.') ?></p>
              <p class="text-[10px] text-slate-500"><?= $paid ?>/<?= $total ?> ödendi</p>
            </div>
          </div>
          <!-- İlerleme çubuğu -->
          <div class="bg-slate-800/60 rounded-full h-1.5 overflow-hidden mb-2">
            <div class="h-full rounded-full transition-all duration-500
                        <?= $pct >= 80 ? 'bg-emerald-400' : ($pct >= 50 ? 'bg-blue-400' : 'bg-amber-400') ?>"
                 style="width: <?= $pct ?>%"></div>
          </div>
          <div class="flex items-center gap-2">
            <?php if (!$done): ?>
            <form method="POST" action="<?= url('/subscriptions.php') ?>" class="inline">
              <input type="hidden" name="action" value="pay">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button type="submit" class="text-xs text-blue-400 hover:text-blue-300 bg-blue-500/10 border border-blue-500/20 px-2 py-1 rounded-lg transition-colors">
                + Taksit Öde
              </button>
            </form>
            <span class="text-[10px] text-slate-600"><?= $remain ?> taksit kaldı · <?= $symbol ?><?= number_format($remain * $r['amount'], 2, ',', '.') ?> toplam</span>
            <?php endif; ?>
            <form method="POST" action="<?= url('/subscriptions.php') ?>" class="inline ml-auto">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button type="submit" onclick="return confirm('Silinsin mi?')" class="text-slate-600 hover:text-red-400 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Boş durum -->
  <?php if (empty($all)): ?>
  <div class="flex flex-col items-center justify-center py-14 gap-4 text-center">
    <div class="w-16 h-16 rounded-2xl bg-slate-800/60 flex items-center justify-center">
      <svg class="w-7 h-7 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
    </div>
    <div>
      <p class="text-sm font-medium text-slate-400">Henüz kayıt yok</p>
      <p class="text-xs text-slate-600 mt-1">Netflix, Spotify, kredi taksiti gibi düzenli ödemelerini ekle.</p>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
