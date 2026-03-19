<?php
declare(strict_types=1);

require_once __DIR__ . '/init_db.php';

$pdo = db_connect();

$notice = ['type' => '', 'msg' => ''];

// ── Manuel işlem kaydet ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $date        = trim($_POST['date'] ?? date('Y-m-d'));
    $description = trim($_POST['description'] ?? '');
    $amount      = abs((float)($_POST['amount'] ?? 0));
    $type        = in_array($_POST['type'] ?? '', ['income', 'expense']) ? $_POST['type'] : 'expense';
    $category    = trim($_POST['category'] ?? 'Diğer');

    if ($amount > 0 && $description !== '') {
        $pdo->prepare("INSERT INTO transactions (date, description, amount, type, category) VALUES (?, ?, ?, ?, ?)")
            ->execute([$date, mb_substr($description, 0, 255), $amount, $type, mb_substr($category, 0, 50)]);
        header('Location: ' . url('/transactions.php') . '?tab=list&ok=add');
        exit;
    }
    $notice = ['type' => 'error', 'msg' => 'Açıklama ve tutar alanları zorunludur.'];
}

// ── İşlem sil ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM transactions WHERE id = ?")->execute([$id]);
    }
    $tab = htmlspecialchars($_POST['tab'] ?? 'list', ENT_QUOTES);
    header('Location: ' . url('/transactions.php') . "?tab={$tab}&ok=delete&p=" . (int)($_POST['p'] ?? 1));
    exit;
}

// ── Bildirim (GET param) ──────────────────────────────────────────
$ok_map = [
    'add'    => ['type' => 'success', 'msg' => 'İşlem başarıyla eklendi.'],
    'delete' => ['type' => 'success', 'msg' => 'İşlem silindi.'],
];
if (isset($_GET['ok']) && isset($ok_map[$_GET['ok']])) {
    $notice = $ok_map[$_GET['ok']];
}

// ── Filtreler + Sayfalama ─────────────────────────────────────────
$filter_type     = in_array($_GET['type']     ?? '', ['income', 'expense', 'all']) ? ($_GET['type'] ?? 'all') : 'all';
$filter_category = trim($_GET['category'] ?? 'all');
$page            = max(1, (int)($_GET['p'] ?? 1));
$per_page        = 15;
$active_tab      = in_array($_GET['tab'] ?? '', ['manual', 'pdf', 'list']) ? ($_GET['tab'] ?? 'manual') : 'manual';

$where  = [];
$params = [];
if ($filter_type !== 'all') {
    $where[]  = 'type = ?';
    $params[] = $filter_type;
}
if ($filter_category !== 'all' && $filter_category !== '') {
    $where[]  = 'category = ?';
    $params[] = $filter_category;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_count = $pdo->prepare("SELECT COUNT(*) FROM transactions {$where_sql}");
$total_count->execute($params);
$total       = (int)$total_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM transactions {$where_sql} ORDER BY date DESC, created_at DESC LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Filtre dropdown için mevcut kategoriler
$all_categories = $pdo->query("SELECT DISTINCT category FROM transactions ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Özet
$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
    FROM transactions
    WHERE date >= date('now', '-30 days')
")->fetch();

$currency = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol   = match($currency) { 'USD' => '$', 'EUR' => '€', default => '₺' };

// Kategori listesi (form için)
$categories_list = [
    'Market','Restoran','Kafe','Ulaşım','Yakıt','Fatura','Abonelik',
    'Teknoloji','Sağlık','Eğitim','Giyim','Eğlence','Spor','Kira',
    'Ev','Bakım','Seyahat','Hediye','Yatırım','Maaş','Diğer',
];

// Kategori renk eşlemi
$cat_colors = [
    'Market'    => 'emerald', 'Restoran'  => 'amber',  'Kafe'       => 'yellow',
    'Ulaşım'    => 'blue',    'Yakıt'     => 'orange', 'Fatura'     => 'sky',
    'Abonelik'  => 'purple',  'Teknoloji' => 'cyan',   'Sağlık'     => 'red',
    'Eğitim'    => 'lime',    'Giyim'     => 'rose',   'Eğlence'    => 'pink',
    'Spor'      => 'green',   'Kira'      => 'slate',  'Ev'         => 'stone',
    'Bakım'     => 'fuchsia', 'Seyahat'   => 'indigo', 'Hediye'     => 'violet',
    'Yatırım'   => 'teal',    'Maaş'      => 'emerald','Diğer'      => 'slate',
];

$cat_color_fn = fn(string $cat) => $cat_colors[$cat] ?? 'slate';

// Sayfalama URL yardımcısı
$page_url = fn(int $p) => url('/transactions.php') . '?' . http_build_query([
    'tab'      => 'list',
    'type'     => $filter_type,
    'category' => $filter_category,
    'p'        => $p,
]);

require_once __DIR__ . '/layout.php';
?>

<div x-data="{
  activeTab: '<?= $active_tab ?>',
  txType: 'expense',
  pdfState: 'idle',
  pdfProgress: '',
  pdfCount: 0,
  pdfError: '',
  pdfResults: [],
  dragOver: false,

  async handleFile(file) {
    if (!file || file.type !== 'application/pdf') {
      this.pdfError = 'Lütfen geçerli bir PDF dosyası seçin.';
      this.pdfState = 'error';
      return;
    }
    this.pdfState = 'extracting';
    this.pdfError = '';
    this.pdfProgress = 'PDF sayfaları okunuyor...';
    try {
      const arrayBuffer = await file.arrayBuffer();
      const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
      let fullText = '';
      for (let i = 1; i <= pdf.numPages; i++) {
        this.pdfProgress = `Sayfa ${i} / ${pdf.numPages} işleniyor...`;
        const page    = await pdf.getPage(i);
        const content = await page.getTextContent();
        fullText += content.items.map(item => item.str).join(' ') + '\n';
      }
      this.pdfProgress = 'AI analiz ediyor, lütfen bekleyin...';
      this.pdfState = 'analyzing';

      const form = new FormData();
      form.append('text', fullText);
      const resp = await fetch('<?= url('/process_ai.php') ?>', { method: 'POST', body: form });
      const data = await resp.json();

      if (data.error) {
        this.pdfError = data.error;
        this.pdfState = 'error';
      } else {
        this.pdfCount   = data.saved;
        this.pdfResults = data.transactions || [];
        this.pdfState   = 'done';
      }
    } catch (e) {
      this.pdfError = 'Hata: ' + e.message;
      this.pdfState = 'error';
    }
  }
}" class="space-y-4">

  <!-- Özet Bantı -->
  <div class="grid grid-cols-2 gap-3">
    <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-xl px-4 py-3">
      <p class="text-[10px] text-emerald-400/70 font-medium uppercase tracking-wider">30 Gün Gelir</p>
      <p class="text-lg font-bold text-emerald-400 mt-0.5"><?= $symbol ?><?= number_format($summary['income'], 0, ',', '.') ?></p>
    </div>
    <div class="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
      <p class="text-[10px] text-red-400/70 font-medium uppercase tracking-wider">30 Gün Gider</p>
      <p class="text-lg font-bold text-red-400 mt-0.5"><?= $symbol ?><?= number_format($summary['expense'], 0, ',', '.') ?></p>
    </div>
  </div>

  <!-- Bildirim -->
  <?php if ($notice['msg']): ?>
  <div class="flex items-center gap-2.5 p-3 rounded-xl text-sm
              <?= $notice['type'] === 'success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400' ?>">
    <?php if ($notice['type'] === 'success'): ?>
      <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <?php else: ?>
      <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php endif; ?>
    <?= htmlspecialchars($notice['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- Sekme Başlıkları -->
  <div class="flex bg-slate-900/60 border border-slate-800/60 rounded-xl p-1 gap-1">
    <button @click="activeTab='manual'"
            :class="activeTab==='manual' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
      Manuel
    </button>
    <button @click="activeTab='pdf'"
            :class="activeTab==='pdf' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      PDF Yükle
    </button>
    <button @click="activeTab='list'"
            :class="activeTab==='list' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
            class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg text-xs font-semibold transition-all duration-200">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
      Geçmiş <span class="ml-1 text-[10px] opacity-70"><?= $total ?></span>
    </button>
  </div>

  <!-- ═══════════════════════════════════════
       SEKME 1: MANUEL İŞLEM EKLEsekme
       ═══════════════════════════════════════ -->
  <div x-show="activeTab==='manual'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <form method="POST" action="<?= url('/transactions.php') ?>" class="space-y-4">
      <input type="hidden" name="action" value="add">

      <!-- Gelir / Gider Toggle -->
      <div class="flex bg-slate-900/60 border border-slate-800/60 rounded-xl p-1 gap-1">
        <button type="button" @click="txType='expense'"
                :class="txType==='expense' ? 'bg-red-500/20 text-red-400 border border-red-500/30' : 'text-slate-500 hover:text-slate-300'"
                class="flex-1 py-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
          Gider
        </button>
        <button type="button" @click="txType='income'"
                :class="txType==='income' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'text-slate-500 hover:text-slate-300'"
                class="flex-1 py-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
          Gelir
        </button>
      </div>
      <input type="hidden" name="type" :value="txType">

      <!-- Tutar + Açıklama -->
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4 space-y-3">

        <div>
          <label class="text-xs font-medium text-slate-400 mb-1.5 block">Tutar</label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold text-lg"><?= $symbol ?></span>
            <input type="number" name="amount" step="0.01" min="0.01" required
                   placeholder="0,00"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-10 pr-4 py-3.5
                          text-xl font-bold text-slate-100 placeholder-slate-700
                          focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
          </div>
        </div>

        <div>
          <label class="text-xs font-medium text-slate-400 mb-1.5 block">Açıklama</label>
          <input type="text" name="description" required maxlength="255"
                 placeholder="Örn: Migros alışveriş, Elektrik faturası..."
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                        placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-slate-400 mb-1.5 block">Tarih</label>
            <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                          focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
          </div>
          <div>
            <label class="text-xs font-medium text-slate-400 mb-1.5 block">Kategori</label>
            <div class="relative">
              <select name="category"
                      class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 pr-8
                             text-sm text-slate-200 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
                <?php foreach ($categories_list as $cat): ?>
                  <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
          </div>
        </div>
      </div>

      <button type="submit"
              class="w-full py-4 rounded-2xl font-bold text-sm text-white transition-all active:scale-[0.98]
                     shadow-lg"
              :class="txType==='expense'
                ? 'bg-gradient-to-r from-red-600 to-rose-600 shadow-red-500/20 hover:from-red-700 hover:to-rose-700'
                : 'bg-gradient-to-r from-emerald-600 to-teal-600 shadow-emerald-500/20 hover:from-emerald-700 hover:to-teal-700'">
        <span x-text="txType==='expense' ? '− Gider Ekle' : '+ Gelir Ekle'"></span>
      </button>
    </form>
  </div>

  <!-- ═══════════════════════════════════════
       SEKME 2: PDF YÜKLE
       ═══════════════════════════════════════ -->
  <div x-show="activeTab==='pdf'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <!-- Boşta -->
    <div x-show="pdfState==='idle' || pdfState==='error'" class="space-y-4">
      <!-- Sürükle-bırak alanı -->
      <label for="pdfInput"
             @dragover.prevent="dragOver=true"
             @dragleave.prevent="dragOver=false"
             @drop.prevent="dragOver=false; handleFile($event.dataTransfer.files[0])"
             :class="dragOver ? 'border-brand-400 bg-brand-500/10' : 'border-slate-700/60 hover:border-slate-600'"
             class="flex flex-col items-center justify-center gap-3 p-8 rounded-2xl border-2 border-dashed
                    bg-slate-900/40 cursor-pointer transition-all duration-200">
        <div class="w-14 h-14 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
          <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <div class="text-center">
          <p class="text-sm font-semibold text-slate-200">Banka ekstrenizi yükleyin</p>
          <p class="text-xs text-slate-500 mt-1">PDF formatında sürükleyin veya tıklayın</p>
        </div>
        <span class="text-xs text-brand-400 border border-brand-500/30 bg-brand-500/10 rounded-full px-3 py-1">
          Dosya Seç
        </span>
      </label>
      <input id="pdfInput" type="file" accept="application/pdf" class="hidden"
             @change="handleFile($event.target.files[0])">

      <!-- Hata mesajı -->
      <div x-show="pdfState==='error'" class="flex items-start gap-2.5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span x-text="pdfError"></span>
      </div>

      <!-- Bilgi notu -->
      <div class="flex items-start gap-2 p-3 rounded-xl bg-slate-800/40 border border-slate-700/30">
        <svg class="w-4 h-4 text-brand-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-xs text-slate-500">
          PDF metin içermeli. Tarama (görsel) PDF'ler desteklenmez.
          İşlemler AI tarafından otomatik kategorize edilir.
        </p>
      </div>
    </div>

    <!-- Yükleniyor / İşleniyor -->
    <div x-show="pdfState==='extracting' || pdfState==='analyzing'"
         class="flex flex-col items-center justify-center gap-5 py-12">
      <div class="relative">
        <div class="w-16 h-16 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center">
          <svg class="w-7 h-7 text-brand-400 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
          </svg>
        </div>
        <div class="absolute -top-1 -right-1 w-4 h-4">
          <div class="w-full h-full rounded-full bg-brand-400 animate-ping opacity-75"></div>
        </div>
      </div>
      <div class="text-center">
        <p class="text-sm font-semibold text-slate-200" x-text="pdfState==='extracting' ? 'PDF Okunuyor' : 'AI Analiz Ediyor'"></p>
        <p class="text-xs text-slate-500 mt-1" x-text="pdfProgress"></p>
      </div>
    </div>

    <!-- Tamamlandı -->
    <div x-show="pdfState==='done'" class="space-y-4">
      <div class="flex items-center gap-3 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
        <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-emerald-400">İşlem Tamamlandı</p>
          <p class="text-xs text-emerald-400/70 mt-0.5"><span x-text="pdfCount"></span> işlem veritabanına kaydedildi.</p>
        </div>
      </div>

      <!-- Önizleme listesi (ilk 5) -->
      <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider px-4 py-3 border-b border-slate-800/40">Bulunan İşlemler</p>
        <template x-for="(tx, i) in pdfResults.slice(0, 8)" :key="i">
          <div class="flex items-center gap-3 px-4 py-2.5 border-b border-slate-800/30 last:border-0">
            <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0"
                 :class="tx.type==='income' ? 'bg-emerald-500/10' : 'bg-red-500/10'">
              <svg class="w-3 h-3" :class="tx.type==='income' ? 'text-emerald-400' : 'text-red-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                  :d="tx.type==='income' ? 'M5 10l7-7m0 0l7 7m-7-7v18' : 'M19 14l-7 7m0 0l-7-7m7 7V3'"/>
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-xs font-medium text-slate-300 truncate" x-text="tx.description"></p>
              <p class="text-[10px] text-slate-600" x-text="tx.category + ' · ' + tx.date"></p>
            </div>
            <p class="text-xs font-semibold shrink-0"
               :class="tx.type==='income' ? 'text-emerald-400' : 'text-red-400'"
               x-text="(tx.type==='income' ? '+' : '-') + '<?= $symbol ?>' + parseFloat(tx.amount).toLocaleString('tr-TR', {minimumFractionDigits:2})"></p>
          </div>
        </template>
        <div x-show="pdfResults.length > 8" class="px-4 py-2 text-xs text-slate-600 text-center">
          + <span x-text="pdfResults.length - 8"></span> işlem daha
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <button @click="pdfState='idle'; pdfResults=[]; pdfCount=0"
                class="py-3 rounded-xl bg-slate-800/60 border border-slate-700/50 text-sm font-medium text-slate-300 hover:text-white transition-colors">
          Yeni PDF Yükle
        </button>
        <button @click="activeTab='list'"
                class="py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-sm font-medium text-white transition-colors">
          Geçmişe Git →
        </button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════
       SEKME 3: GEÇMİŞ
       ═══════════════════════════════════════ -->
  <div x-show="activeTab==='list'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <!-- Filtreler -->
    <form method="GET" action="<?= url('/transactions.php') ?>" class="flex gap-2 mb-4">
      <input type="hidden" name="tab" value="list">
      <div class="relative flex-1">
        <select name="type" onchange="this.form.submit()"
                class="w-full appearance-none bg-slate-900/60 border border-slate-800/60 rounded-xl px-3 py-2.5 pr-7
                       text-xs text-slate-300 focus:outline-none focus:border-brand-500/50 transition-colors cursor-pointer">
          <option value="all"     <?= $filter_type === 'all'     ? 'selected' : '' ?>>Tümü</option>
          <option value="expense" <?= $filter_type === 'expense' ? 'selected' : '' ?>>Gider</option>
          <option value="income"  <?= $filter_type === 'income'  ? 'selected' : '' ?>>Gelir</option>
        </select>
        <div class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
      </div>
      <div class="relative flex-1">
        <select name="category" onchange="this.form.submit()"
                class="w-full appearance-none bg-slate-900/60 border border-slate-800/60 rounded-xl px-3 py-2.5 pr-7
                       text-xs text-slate-300 focus:outline-none focus:border-brand-500/50 transition-colors cursor-pointer">
          <option value="all">Tüm Kategoriler</option>
          <?php foreach ($all_categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category === $cat ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
      </div>
    </form>

    <!-- İşlem Listesi -->
    <?php if (empty($transactions)): ?>
      <div class="flex flex-col items-center justify-center py-16 gap-4">
        <div class="w-16 h-16 rounded-2xl bg-slate-800/60 flex items-center justify-center">
          <svg class="w-7 h-7 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
        </div>
        <div class="text-center">
          <p class="text-sm font-medium text-slate-400">İşlem bulunamadı</p>
          <p class="text-xs text-slate-600 mt-1">Filtre değiştirin veya yeni işlem ekleyin.</p>
        </div>
        <button @click="activeTab='manual'" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">
          İlk işlemi ekle →
        </button>
      </div>
    <?php else: ?>
      <?php
        $grouped = [];
        foreach ($transactions as $tx) {
            $grouped[$tx['date']][] = $tx;
        }
      ?>
      <div class="space-y-3">
        <?php foreach ($grouped as $date => $group): ?>
          <div>
            <p class="text-[10px] font-semibold text-slate-600 uppercase tracking-wider mb-2 px-1">
              <?= date('d F Y', strtotime($date)) ?>
            </p>
            <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden divide-y divide-slate-800/40">
              <?php foreach ($group as $tx):
                $color = $cat_color_fn($tx['category']);
              ?>
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
                    <span class="inline-block mt-0.5 text-[10px] px-1.5 py-0.5 rounded-md bg-<?= $color ?>-500/10 text-<?= $color ?>-400 border border-<?= $color ?>-500/20">
                      <?= htmlspecialchars($tx['category']) ?>
                    </span>
                  </div>
                  <div class="text-right shrink-0">
                    <p class="text-sm font-semibold <?= $tx['type'] === 'income' ? 'text-emerald-400' : 'text-red-400' ?>">
                      <?= $tx['type'] === 'income' ? '+' : '−' ?><?= $symbol ?><?= number_format($tx['amount'], 2, ',', '.') ?>
                    </p>
                    <form method="POST" action="<?= url('/transactions.php') ?>" class="inline mt-0.5">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= $tx['id'] ?>">
                      <input type="hidden" name="tab"    value="list">
                      <input type="hidden" name="p"      value="<?= $page ?>">
                      <button type="submit" onclick="return confirm('Bu işlemi silmek istiyor musunuz?')"
                              class="text-slate-700 hover:text-red-400 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Sayfalama -->
      <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-center gap-2 pt-2">
          <?php if ($page > 1): ?>
            <a href="<?= $page_url($page - 1) ?>" class="w-8 h-8 rounded-lg bg-slate-800/60 border border-slate-700/50 flex items-center justify-center text-slate-400 hover:text-white hover:border-brand-500/50 transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
          <?php endif; ?>

          <span class="text-xs text-slate-500">
            Sayfa <?= $page ?> / <?= $total_pages ?>
            <span class="text-slate-700 mx-1">·</span>
            <?= $total ?> işlem
          </span>

          <?php if ($page < $total_pages): ?>
            <a href="<?= $page_url($page + 1) ?>" class="w-8 h-8 rounded-lg bg-slate-800/60 border border-slate-700/50 flex items-center justify-center text-slate-400 hover:text-white hover:border-brand-500/50 transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<!-- pdf.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
  if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc =
      'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  }
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
