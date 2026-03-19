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
        $pdo->prepare("INSERT INTO transactions (date, description, amount, type, category) VALUES (?,?,?,?,?)")
            ->execute([$date, mb_substr($description, 0, 255), $amount, $type, mb_substr($category, 0, 50)]);
        header('Location: ' . url('/transactions.php') . '?tab=list&ok=add');
        exit;
    }
    $notice = ['type' => 'error', 'msg' => 'Açıklama ve tutar zorunludur.'];
}

// ── İşlem sil ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$id]);
    $tab = htmlspecialchars($_POST['tab'] ?? 'list', ENT_QUOTES);
    header('Location: ' . url('/transactions.php') . "?tab={$tab}&ok=delete&p=" . (int)($_POST['p'] ?? 1));
    exit;
}

$ok_map = [
    'add'    => ['type' => 'success', 'msg' => 'İşlem başarıyla eklendi.'],
    'delete' => ['type' => 'success', 'msg' => 'İşlem silindi.'],
];
if (isset($_GET['ok']) && isset($ok_map[$_GET['ok']])) {
    $notice = $ok_map[$_GET['ok']];
}

// ── Filtreler + Sayfalama ─────────────────────────────────────────
$filter_type     = in_array($_GET['type'] ?? '', ['income','expense','all']) ? ($_GET['type'] ?? 'all') : 'all';
$filter_category = trim($_GET['category'] ?? 'all');
$page            = max(1, (int)($_GET['p'] ?? 1));
$per_page        = 15;
$active_tab      = in_array($_GET['tab'] ?? '', ['manual','pdf','expenses','list']) ? ($_GET['tab'] ?? 'manual') : 'manual';

$where  = [];
$params = [];
if ($filter_type !== 'all') { $where[] = 'type=?'; $params[] = $filter_type; }
if ($filter_category !== 'all' && $filter_category !== '') { $where[] = 'category=?'; $params[] = $filter_category; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$total_count = $pdo->prepare("SELECT COUNT(*) FROM transactions {$where_sql}");
$total_count->execute($params);
$total       = (int)$total_count->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM transactions {$where_sql} ORDER BY date DESC, created_at DESC LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$all_categories = $pdo->query("SELECT DISTINCT category FROM transactions ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// ── Harcamalarım verileri ─────────────────────────────────────────
$month_cats = $pdo->query("
    SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
    FROM transactions
    WHERE type='expense' AND strftime('%Y-%m', date)=strftime('%Y-%m','now')
    GROUP BY category ORDER BY total DESC
")->fetchAll();

$month_total_exp = array_sum(array_column($month_cats, 'total'));

$last_month_cats = $pdo->query("
    SELECT category, SUM(amount) AS total
    FROM transactions
    WHERE type='expense' AND strftime('%Y-%m', date)=strftime('%Y-%m', date('now','-1 month'))
    GROUP BY category
")->fetchAll();
$last_month_map = array_column($last_month_cats, 'total', 'category');

$top5 = $pdo->query("
    SELECT description, amount, category, date
    FROM transactions
    WHERE type='expense' AND strftime('%Y-%m', date)=strftime('%Y-%m','now')
    ORDER BY amount DESC LIMIT 5
")->fetchAll();

// Abonelik özeti
$sub_monthly = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscriptions WHERE active=1")->fetchColumn();

$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
    FROM transactions WHERE date>=date('now','-30 days')
")->fetch();

$currency = $pdo->query("SELECT value FROM settings WHERE key='currency'")->fetchColumn() ?: 'TRY';
$symbol   = match($currency) { 'USD'=>'$','EUR'=>'€', default=>'₺' };

// ── Kategori renk / ikon eşlemi ───────────────────────────────────
$cat_meta = [
    'Market'    => ['color'=>'emerald', 'icon'=>'🛒'],
    'Restoran'  => ['color'=>'amber',   'icon'=>'🍽'],
    'Kafe'      => ['color'=>'yellow',  'icon'=>'☕'],
    'Fast Food' => ['color'=>'orange',  'icon'=>'🍔'],
    'Ulaşım'    => ['color'=>'blue',    'icon'=>'🚗'],
    'Yakıt'     => ['color'=>'orange',  'icon'=>'⛽'],
    'Taksi'     => ['color'=>'yellow',  'icon'=>'🚕'],
    'Fatura'    => ['color'=>'sky',     'icon'=>'📄'],
    'Abonelik'  => ['color'=>'purple',  'icon'=>'🔄'],
    'Telefon'   => ['color'=>'sky',     'icon'=>'📱'],
    'İnternet'  => ['color'=>'cyan',    'icon'=>'🌐'],
    'Teknoloji' => ['color'=>'cyan',    'icon'=>'💻'],
    'Sağlık'    => ['color'=>'red',     'icon'=>'💊'],
    'Eczane'    => ['color'=>'red',     'icon'=>'🏥'],
    'Eğitim'    => ['color'=>'lime',    'icon'=>'📚'],
    'Giyim'     => ['color'=>'rose',    'icon'=>'👕'],
    'Eğlence'   => ['color'=>'pink',    'icon'=>'🎮'],
    'Spor'      => ['color'=>'green',   'icon'=>'🏋'],
    'Kira'      => ['color'=>'slate',   'icon'=>'🏠'],
    'Ev'        => ['color'=>'stone',   'icon'=>'🏡'],
    'Bakım'     => ['color'=>'fuchsia', 'icon'=>'💇'],
    'Seyahat'   => ['color'=>'indigo',  'icon'=>'✈'],
    'Hediye'    => ['color'=>'violet',  'icon'=>'🎁'],
    'Taksit'    => ['color'=>'blue',    'icon'=>'💳'],
    'Sigorta'   => ['color'=>'teal',    'icon'=>'🛡'],
    'Yatırım'   => ['color'=>'teal',    'icon'=>'📈'],
    'Maaş'      => ['color'=>'emerald', 'icon'=>'💰'],
    'Diğer'     => ['color'=>'slate',   'icon'=>'📌'],
];
$cat_color = fn(string $c) => ($cat_meta[$c]['color'] ?? 'slate');
$cat_icon  = fn(string $c) => ($cat_meta[$c]['icon']  ?? '📌');

$categories_list = array_keys($cat_meta);
$page_url = fn(int $p) => url('/transactions.php').'?'.http_build_query(['tab'=>'list','type'=>$filter_type,'category'=>$filter_category,'p'=>$p]);

require_once __DIR__ . '/layout.php';
?>

<?php /* ── JSON verisini script tag'e taşı: x-data attribute'u içinde json_encode
          kullanmak HTML attribute parser'ını kırar (çift tırnak çakışması) ── */ ?>
<script>
window.__txCatList = <?= json_encode(array_keys($cat_meta), JSON_UNESCAPED_UNICODE) ?>;
</script>

<div x-data="{
  activeTab: '<?= $active_tab ?>',
  txType: 'expense',

  /* ── PDF Akışı ── */
  pdfState: 'idle',
  pdfProgress: '',
  pdfError: '',
  pdfResults: [],
  dragOver: false,

  /* ── Clarification (sıralı popup) ── */
  clarifyQueue: [],
  clarifyIndex: 0,
  clarifyItem: null,
  showClarify: false,
  catList: window.__txCatList || [],

  isUnclear(tx) {
    const d = tx.date || '';
    const noDate = !d || d === 'null' || !/^\d{4}-\d{2}-\d{2}$/.test(d);
    const noDesc = (tx.description || '').trim().length < 3;
    const noCat  = !tx.category || tx.category === 'Diğer';
    return noDate || noDesc || noCat;
  },

  async handleFile(file) {
    if (!file || file.type !== 'application/pdf') {
      this.pdfError = 'Lütfen geçerli bir PDF dosyası seçin.';
      this.pdfState = 'error'; return;
    }
    this.pdfState = 'extracting';
    this.pdfError = '';
    this.pdfProgress = 'PDF sayfaları okunuyor...';
    try {
      const ab  = await file.arrayBuffer();
      const pdf = await pdfjsLib.getDocument({ data: ab }).promise;
      let text  = '';
      for (let i = 1; i <= pdf.numPages; i++) {
        this.pdfProgress = `Sayfa ${i} / ${pdf.numPages} işleniyor...`;
        const page    = await pdf.getPage(i);
        const content = await page.getTextContent();
        text += content.items.map(x => x.str).join(' ') + '\n';
      }
      this.pdfProgress = 'AI işlemleri analiz ediyor...';
      this.pdfState = 'analyzing';

      const form = new FormData();
      form.append('text', text);
      const resp = await fetch('<?= url('/process_ai.php') ?>', { method:'POST', body:form });
      const data = await resp.json();

      if (data.error) { this.pdfError = data.error; this.pdfState = 'error'; return; }

      this.pdfResults  = data.transactions || [];
      this.clarifyQueue = this.pdfResults
        .map((tx, i) => ({...tx, _idx: i}))
        .filter(tx => this.isUnclear(tx));
      this.clarifyIndex = 0;

      if (this.clarifyQueue.length > 0) {
        this.clarifyItem  = {...this.clarifyQueue[0]};
        this.pdfState     = 'clarifying';
        this.showClarify  = true;
      } else {
        this.pdfState = 'review';
      }
    } catch(e) { this.pdfError = 'Hata: ' + e.message; this.pdfState = 'error'; }
  },

  confirmClarify() {
    const i = this.clarifyItem._idx;
    // Object.assign Alpine proxy'yi tetiklemeyebilir; index ataması kesinlikle reaktif
    this.pdfResults[i] = {
      ...this.pdfResults[i],
      date:        this.clarifyItem.date,
      description: this.clarifyItem.description,
      amount:      parseFloat(this.clarifyItem.amount) || 0,
      type:        this.clarifyItem.type,
      category:    this.clarifyItem.category,
      unclear:     false,
    };
    this.clarifyIndex++;
    if (this.clarifyIndex < this.clarifyQueue.length) {
      this.clarifyItem = {...this.clarifyQueue[this.clarifyIndex]};
    } else {
      this.showClarify = false;
      this.pdfState    = 'review';
    }
  },

  skipClarify() {
    this.clarifyIndex++;
    if (this.clarifyIndex < this.clarifyQueue.length) {
      this.clarifyItem = {...this.clarifyQueue[this.clarifyIndex]};
    } else {
      this.showClarify = false;
      this.pdfState    = 'review';
    }
  },

  async savePdfResults() {
    this.pdfState = 'saving';
    try {
      const form = new FormData();
      form.append('transactions', JSON.stringify(this.pdfResults));
      const resp = await fetch('<?= url('/save_transactions.php') ?>', { method:'POST', body:form });
      const data = await resp.json();
      if (data.error) { this.pdfError = data.error; this.pdfState = 'error'; return; }
      this.pdfState = 'done';
      this._savedCount  = data.saved;
      this._subCount    = data.sub_saved;
    } catch(e) { this.pdfError = 'Kayıt hatası: ' + e.message; this.pdfState = 'error'; }
  },

  get unclearCount() { return this.pdfResults.filter(t => this.isUnclear(t)).length; },
  _savedCount: 0,
  _subCount: 0,
}" class="space-y-4">

  <!-- ── Özet Bant ── -->
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
              <?= $notice['type']==='success' ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border border-red-500/20 text-red-400' ?>">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="<?= $notice['type']==='success' ? 'M5 13l4 4L19 7' : 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/>
    </svg>
    <?= htmlspecialchars($notice['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- ── Sekme Başlıkları ── -->
  <div class="grid grid-cols-4 bg-slate-900/60 border border-slate-800/60 rounded-xl p-1 gap-1">
    <?php
    $tabs = [
      ['id'=>'manual',   'label'=>'Manuel',     'icon'=>'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
      ['id'=>'pdf',      'label'=>'PDF',         'icon'=>'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
      ['id'=>'expenses', 'label'=>'Harcamalar', 'icon'=>'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
      ['id'=>'list',     'label'=>'Geçmiş',     'icon'=>'M4 6h16M4 10h16M4 14h16M4 18h16'],
    ];
    foreach ($tabs as $t): ?>
      <button @click="activeTab='<?= $t['id'] ?>'"
              :class="activeTab==='<?= $t['id'] ?>' ? 'bg-brand-500 text-white shadow-md shadow-brand-500/20' : 'text-slate-400 hover:text-slate-200'"
              class="flex flex-col items-center justify-center gap-1 py-2.5 rounded-lg text-[10px] font-semibold transition-all duration-200">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $t['icon'] ?>"/>
        </svg>
        <?= $t['label'] ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════════════════════════
       SEKME: MANUEL
       ═══════════════════════════════ -->
  <div x-show="activeTab==='manual'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
    <form method="POST" action="<?= url('/transactions.php') ?>" class="space-y-4">
      <input type="hidden" name="action" value="add">

      <div class="flex bg-slate-900/60 border border-slate-800/60 rounded-xl p-1 gap-1">
        <button type="button" @click="txType='expense'"
                :class="txType==='expense' ? 'bg-red-500/20 text-red-400 border border-red-500/30' : 'text-slate-500 hover:text-slate-300'"
                class="flex-1 py-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg> Gider
        </button>
        <button type="button" @click="txType='income'"
                :class="txType==='income' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'text-slate-500 hover:text-slate-300'"
                class="flex-1 py-3 rounded-lg text-sm font-semibold transition-all flex items-center justify-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg> Gelir
        </button>
      </div>
      <input type="hidden" name="type" :value="txType">

      <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4 space-y-3">
        <div>
          <label class="text-xs font-medium text-slate-400 mb-1.5 block">Tutar</label>
          <div class="relative">
            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold text-lg"><?= $symbol ?></span>
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0,00"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl pl-10 pr-4 py-3.5 text-xl font-bold text-slate-100
                          placeholder-slate-700 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
          </div>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-400 mb-1.5 block">Açıklama</label>
          <input type="text" name="description" required maxlength="255" placeholder="Migros, Elektrik faturası..."
                 class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                        placeholder-slate-600 focus:outline-none focus:border-brand-500/60 focus:ring-1 focus:ring-brand-500/20 transition-colors">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-slate-400 mb-1.5 block">Tarih</label>
            <input type="date" name="date" value="<?= date('Y-m-d') ?>"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                          focus:outline-none focus:border-brand-500/60 transition-colors">
          </div>
          <div>
            <label class="text-xs font-medium text-slate-400 mb-1.5 block">Kategori</label>
            <div class="relative">
              <select name="category"
                      class="w-full appearance-none bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 pr-8 text-sm text-slate-200
                             focus:outline-none focus:border-brand-500/60 transition-colors">
                <?php foreach ($categories_list as $cat): ?>
                  <option value="<?= htmlspecialchars($cat) ?>"><?= ($cat_meta[$cat]['icon'] ?? '') . ' ' . htmlspecialchars($cat) ?></option>
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
              class="w-full py-4 rounded-2xl font-bold text-sm text-white transition-all active:scale-[0.98] shadow-lg"
              :class="txType==='expense' ? 'bg-gradient-to-r from-red-600 to-rose-600 shadow-red-500/20' : 'bg-gradient-to-r from-emerald-600 to-teal-600 shadow-emerald-500/20'">
        <span x-text="txType==='expense' ? '− Gider Ekle' : '+ Gelir Ekle'"></span>
      </button>
    </form>
  </div>

  <!-- ═══════════════════════════════
       SEKME: PDF
       ═══════════════════════════════ -->
  <div x-show="activeTab==='pdf'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <!-- Boşta / Hata -->
    <div x-show="pdfState==='idle' || pdfState==='error'" class="space-y-4">
      <label for="pdfInput"
             @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false"
             @drop.prevent="dragOver=false; handleFile($event.dataTransfer.files[0])"
             :class="dragOver ? 'border-brand-400 bg-brand-500/10' : 'border-slate-700/60 hover:border-slate-600'"
             class="flex flex-col items-center justify-center gap-3 p-8 rounded-2xl border-2 border-dashed bg-slate-900/40 cursor-pointer transition-all">
        <div class="w-14 h-14 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
          <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <div class="text-center">
          <p class="text-sm font-semibold text-slate-200">Banka ekstrenizi yükleyin</p>
          <p class="text-xs text-slate-500 mt-1">PDF sürükleyin veya tıklayın · Metin tabanlı olmalı</p>
        </div>
        <span class="text-xs text-brand-400 border border-brand-500/30 bg-brand-500/10 rounded-full px-3 py-1">Dosya Seç</span>
      </label>
      <input id="pdfInput" type="file" accept="application/pdf" class="hidden" @change="handleFile($event.target.files[0])">

      <div x-show="pdfState==='error'" class="flex items-start gap-2.5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span x-text="pdfError"></span>
      </div>
    </div>

    <!-- Yükleniyor -->
    <div x-show="pdfState==='extracting' || pdfState==='analyzing'" class="flex flex-col items-center justify-center py-12 gap-4">
      <div class="relative w-16 h-16">
        <div class="absolute inset-0 rounded-full border-2 border-brand-500/20"></div>
        <div class="absolute inset-0 rounded-full border-2 border-t-brand-400 border-transparent animate-spin"></div>
        <div class="absolute inset-3 rounded-full border-2 border-t-purple-400 border-transparent animate-spin" style="animation-duration:.75s;animation-direction:reverse"></div>
      </div>
      <div class="text-center">
        <p class="text-sm font-semibold text-slate-200" x-text="pdfState==='extracting' ? 'PDF Okunuyor' : 'AI Analiz Ediyor'"></p>
        <p class="text-xs text-slate-500 mt-1" x-text="pdfProgress"></p>
      </div>
    </div>

    <!-- İnceleme (review) ekranı -->
    <div x-show="pdfState==='review' || pdfState==='saving'" class="space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold text-slate-200"><span x-text="pdfResults.length"></span> işlem bulundu</p>
          <p class="text-xs text-slate-500 mt-0.5">Değerleri düzenleyebilir, sonra kaydedebilirsiniz.</p>
        </div>
        <div x-show="unclearCount > 0"
             class="flex items-center gap-1.5 text-xs text-amber-400 bg-amber-500/10 border border-amber-500/20 px-2.5 py-1.5 rounded-lg cursor-pointer"
             @click="clarifyIndex=0; clarifyItem={...clarifyQueue[0]}; showClarify=true; pdfState='clarifying'">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span x-text="unclearCount + ' belirsiz'"></span>
        </div>
      </div>

      <!-- Düzenlenebilir liste -->
      <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
        <template x-for="(tx, i) in pdfResults" :key="i">
          <div class="bg-slate-900/60 border rounded-xl p-3 space-y-2"
               :class="isUnclear(tx) ? 'border-amber-500/30' : 'border-slate-800/60'">
            <div class="flex items-center gap-2">
              <!-- Belirsiz uyarısı -->
              <span x-show="isUnclear(tx)" class="text-[9px] text-amber-400 bg-amber-500/10 border border-amber-500/20 px-1.5 py-0.5 rounded shrink-0">?</span>
              <!-- Açıklama -->
              <input type="text" x-model="tx.description"
                     class="flex-1 bg-slate-800/50 border border-slate-700/40 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-brand-500/50 transition-colors">
              <!-- Tutar -->
              <input type="number" x-model.number="tx.amount" step="0.01" min="0"
                     class="w-20 bg-slate-800/50 border border-slate-700/40 rounded-lg px-2 py-1.5 text-xs text-slate-200 text-right focus:outline-none focus:border-brand-500/50 transition-colors">
            </div>
            <div class="flex items-center gap-2">
              <!-- Tarih -->
              <input type="date" x-model="tx.date"
                     :class="!tx.date ? 'border-amber-500/50 bg-amber-500/5' : 'border-slate-700/40 bg-slate-800/50'"
                     class="flex-1 border rounded-lg px-2 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-brand-500/50 transition-colors">
              <!-- Kategori -->
              <select x-model="tx.category"
                      class="flex-1 appearance-none bg-slate-800/50 border border-slate-700/40 rounded-lg px-2 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-brand-500/50 transition-colors">
                <template x-for="c in catList" :key="c">
                  <option :value="c" :selected="tx.category===c" x-text="c"></option>
                </template>
              </select>
              <!-- Tip toggle -->
              <button type="button" @click="tx.type = tx.type==='expense' ? 'income' : 'expense'"
                      class="shrink-0 px-2 py-1.5 rounded-lg text-[10px] font-semibold transition-colors"
                      :class="tx.type==='expense' ? 'bg-red-500/15 text-red-400' : 'bg-emerald-500/15 text-emerald-400'">
                <span x-text="tx.type==='expense' ? '↓ Gider' : '↑ Gelir'"></span>
              </button>
            </div>
          </div>
        </template>
      </div>

      <button @click="savePdfResults()" :disabled="pdfState==='saving'"
              class="w-full py-4 rounded-2xl font-bold text-sm text-white bg-gradient-to-r from-brand-500 to-purple-600
                     hover:from-brand-600 hover:to-purple-700 shadow-lg shadow-brand-500/20 active:scale-[0.98] transition-all
                     disabled:opacity-60 flex items-center justify-center gap-2">
        <template x-if="pdfState!=='saving'">
          <span class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
            <span x-text="pdfResults.length + ' İşlemi Kaydet'"></span>
          </span>
        </template>
        <template x-if="pdfState==='saving'">
          <span class="flex items-center gap-2">
            <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Kaydediliyor...
          </span>
        </template>
      </button>
    </div>

    <!-- Tamamlandı -->
    <div x-show="pdfState==='done'" class="space-y-4">
      <div class="flex items-center gap-3 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
        <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-emerald-400">Kaydedildi!</p>
          <p class="text-xs text-emerald-400/70 mt-0.5">
            <span x-text="_savedCount"></span> işlem eklendi
            <template x-if="_subCount > 0">
              <span> · <span x-text="_subCount"></span> abonelik/taksit tespit edildi</span>
            </template>
          </p>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <button @click="pdfState='idle'; pdfResults=[]; pdfError=''"
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

  <!-- ═══════════════════════════════
       SEKME: HARCAMALARIM
       ═══════════════════════════════ -->
  <div x-show="activeTab==='expenses'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
       class="space-y-4">

    <?php if ($month_total_exp <= 0): ?>
      <div class="flex flex-col items-center justify-center py-14 gap-4 text-center">
        <div class="w-16 h-16 rounded-2xl bg-slate-800/60 flex items-center justify-center">
          <span class="text-3xl">📊</span>
        </div>
        <div>
          <p class="text-sm font-medium text-slate-400">Bu ay henüz gider yok</p>
          <p class="text-xs text-slate-600 mt-1">İşlem ekleyince harcama analizi burada görünür.</p>
        </div>
      </div>
    <?php else: ?>

    <!-- Bu Ay Toplam -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl p-4">
      <div class="flex items-center justify-between mb-1">
        <p class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Bu Ay Toplam Gider</p>
        <p class="text-xs text-slate-600"><?= date('F Y') ?></p>
      </div>
      <p class="text-2xl font-black text-slate-100"><?= $symbol ?><?= number_format($month_total_exp, 2, ',', '.') ?></p>

      <?php if ($sub_monthly > 0): ?>
      <div class="mt-2 flex items-center gap-2 text-xs text-purple-400">
        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Aylık sabit yükümlülük: <?= $symbol ?><?= number_format($sub_monthly, 2, ',', '.') ?> (abonelik & taksit)
      </div>
      <?php endif; ?>
    </div>

    <!-- Kategori Kartları -->
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-800/40">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Kategorilere Göre</h3>
        <p class="text-[10px] text-slate-600 mt-0.5">Çubuk genişliği toplam içindeki payı gösterir</p>
      </div>
      <div class="divide-y divide-slate-800/30">
        <?php foreach ($month_cats as $cat):
          $pct      = $month_total_exp > 0 ? round($cat['total'] / $month_total_exp * 100, 1) : 0;
          $last     = $last_month_map[$cat['category']] ?? 0;
          $trend    = $last > 0 ? round((($cat['total'] - $last) / $last) * 100, 1) : null;
          $color    = $cat_color($cat['category']);
          $icon     = $cat_icon($cat['category']);
        ?>
          <div class="px-4 py-3">
            <div class="flex items-center gap-3 mb-1.5">
              <span class="text-base shrink-0"><?= $icon ?></span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-medium text-slate-200"><?= htmlspecialchars($cat['category']) ?></span>
                  <div class="flex items-center gap-2">
                    <?php if ($trend !== null): ?>
                      <span class="text-[10px] font-semibold <?= $trend > 0 ? 'text-red-400' : 'text-emerald-400' ?>">
                        <?= $trend > 0 ? '↑' : '↓' ?><?= abs($trend) ?>%
                      </span>
                    <?php endif; ?>
                    <span class="text-sm font-bold text-slate-100"><?= $symbol ?><?= number_format($cat['total'], 0, ',', '.') ?></span>
                  </div>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <div class="flex-1 bg-slate-800/60 rounded-full h-1.5 overflow-hidden">
                <div class="h-full rounded-full bg-<?= $color ?>-500/70 transition-all"
                     style="width: <?= $pct ?>%"></div>
              </div>
              <span class="text-[10px] text-slate-500 shrink-0 w-8 text-right"><?= $pct ?>%</span>
            </div>
            <p class="text-[10px] text-slate-600 mt-0.5"><?= $cat['cnt'] ?> işlem</p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- En Yüksek 5 Harcama -->
    <?php if (!empty($top5)): ?>
    <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-800/40">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">En Yüksek 5 Harcama</h3>
        <p class="text-[10px] text-slate-600 mt-0.5">Bu ayın en büyük tekil giderleri</p>
      </div>
      <div class="divide-y divide-slate-800/30">
        <?php foreach ($top5 as $i => $tx): ?>
          <div class="flex items-center gap-3 px-4 py-3">
            <div class="w-6 h-6 rounded-lg bg-slate-800/60 border border-slate-700/30 flex items-center justify-center shrink-0">
              <span class="text-[10px] font-bold text-slate-500"><?= $i+1 ?></span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($tx['description']) ?></p>
              <p class="text-[10px] text-slate-500"><?= $cat_icon($tx['category']) ?> <?= htmlspecialchars($tx['category']) ?> · <?= date('d M', strtotime($tx['date'])) ?></p>
            </div>
            <p class="text-sm font-bold text-red-400 shrink-0"><?= $symbol ?><?= number_format($tx['amount'], 2, ',', '.') ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Abonelikler Linki -->
    <a href="<?= url('/subscriptions.php') ?>"
       class="flex items-center gap-3 p-4 rounded-xl bg-purple-500/10 border border-purple-500/20 hover:border-purple-500/40 transition-colors">
      <div class="w-10 h-10 rounded-xl bg-purple-500/20 flex items-center justify-center shrink-0">
        <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </div>
      <div class="flex-1">
        <p class="text-sm font-semibold text-purple-300">Abonelik & Taksit Yönetimi</p>
        <p class="text-xs text-purple-400/60 mt-0.5">
          Aylık sabit yükümlülük: <?= $symbol ?><?= number_format($sub_monthly, 2, ',', '.') ?>
        </p>
      </div>
      <svg class="w-4 h-4 text-purple-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>

    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════
       SEKME: GEÇMİŞ
       ═══════════════════════════════ -->
  <div x-show="activeTab==='list'" x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">

    <form method="GET" action="<?= url('/transactions.php') ?>" class="flex gap-2 mb-4">
      <input type="hidden" name="tab" value="list">
      <div class="relative flex-1">
        <select name="type" onchange="this.form.submit()"
                class="w-full appearance-none bg-slate-900/60 border border-slate-800/60 rounded-xl px-3 py-2.5 pr-7 text-xs text-slate-300 focus:outline-none focus:border-brand-500/50 transition-colors cursor-pointer">
          <option value="all"     <?= $filter_type==='all'     ? 'selected':'' ?>>Tümü</option>
          <option value="expense" <?= $filter_type==='expense' ? 'selected':'' ?>>Gider</option>
          <option value="income"  <?= $filter_type==='income'  ? 'selected':'' ?>>Gelir</option>
        </select>
        <div class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
      </div>
      <div class="relative flex-1">
        <select name="category" onchange="this.form.submit()"
                class="w-full appearance-none bg-slate-900/60 border border-slate-800/60 rounded-xl px-3 py-2.5 pr-7 text-xs text-slate-300 focus:outline-none focus:border-brand-500/50 transition-colors cursor-pointer">
          <option value="all">Tüm Kategoriler</option>
          <?php foreach ($all_categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_category===$cat ? 'selected':'' ?>>
              <?= $cat_icon($cat) ?> <?= htmlspecialchars($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
      </div>
    </form>

    <?php if (empty($transactions)): ?>
      <div class="flex flex-col items-center justify-center py-14 gap-3 text-center">
        <span class="text-5xl">🧾</span>
        <p class="text-sm font-medium text-slate-400">İşlem bulunamadı</p>
        <button @click="activeTab='manual'" class="text-xs text-brand-400 hover:text-brand-300 transition-colors">İlk işlemi ekle →</button>
      </div>
    <?php else: ?>
      <?php
        $grouped = [];
        foreach ($transactions as $tx) $grouped[$tx['date']][] = $tx;
      ?>
      <div class="space-y-3">
        <?php foreach ($grouped as $date => $group): ?>
          <div>
            <p class="text-[10px] font-semibold text-slate-600 uppercase tracking-wider mb-2 px-1">
              <?= date('d F Y', strtotime($date)) ?>
            </p>
            <div class="bg-slate-900/60 border border-slate-800/60 rounded-2xl overflow-hidden divide-y divide-slate-800/30">
              <?php foreach ($group as $tx):
                $color = $cat_color($tx['category']);
                $icon  = $cat_icon($tx['category']);
              ?>
                <div class="flex items-center gap-3 px-4 py-3">
                  <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0
                              <?= $tx['type']==='income' ? 'bg-emerald-500/10' : 'bg-red-500/10' ?>">
                    <?php if ($tx['type']==='income'): ?>
                      <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                    <?php else: ?>
                      <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                    <?php endif; ?>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-200 truncate"><?= htmlspecialchars($tx['description']) ?></p>
                    <span class="inline-flex items-center gap-1 mt-0.5 text-[10px] px-1.5 py-0.5 rounded-md bg-<?= $color ?>-500/10 text-<?= $color ?>-400 border border-<?= $color ?>-500/20">
                      <?= $icon ?> <?= htmlspecialchars($tx['category']) ?>
                    </span>
                  </div>
                  <div class="text-right shrink-0">
                    <p class="text-sm font-semibold <?= $tx['type']==='income' ? 'text-emerald-400' : 'text-red-400' ?>">
                      <?= $tx['type']==='income' ? '+' : '−' ?><?= $symbol ?><?= number_format($tx['amount'], 2, ',', '.') ?>
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

      <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-center gap-2 pt-2">
          <?php if ($page > 1): ?>
            <a href="<?= $page_url($page-1) ?>" class="w-8 h-8 rounded-lg bg-slate-800/60 border border-slate-700/50 flex items-center justify-center text-slate-400 hover:text-white transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
          <?php endif; ?>
          <span class="text-xs text-slate-500">Sayfa <?= $page ?> / <?= $total_pages ?> · <?= $total ?> işlem</span>
          <?php if ($page < $total_pages): ?>
            <a href="<?= $page_url($page+1) ?>" class="w-8 h-8 rounded-lg bg-slate-800/60 border border-slate-700/50 flex items-center justify-center text-slate-400 hover:text-white transition-colors">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════
       CLARIFICATION POPUP (modal)
       ═══════════════════════════════ -->
  <!-- x-cloak: Alpine başlayana kadar gizli tut (style="display:none" x-show ile çakışır) -->
  <div x-cloak x-show="showClarify"
       x-transition:enter="transition-opacity ease-out duration-200"
       x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
       x-transition:leave="transition-opacity ease-in duration-150"
       x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
       class="fixed inset-0 z-50 flex items-end justify-center">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="skipClarify()"></div>

    <!-- Sheet -->
    <div class="relative bg-slate-900 border-t border-slate-700/60 rounded-t-2xl w-full max-w-lg p-5 space-y-4"
         x-show="showClarify" x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">

      <!-- Başlık -->
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs text-amber-400 font-semibold uppercase tracking-wider">
            Belirsiz İşlem <span x-text="clarifyIndex+1"></span> / <span x-text="clarifyQueue.length"></span>
          </p>
          <h3 class="text-sm font-bold text-slate-100 mt-0.5">Bu işlemi tanımlayın</h3>
        </div>
        <button @click="skipClarify()" class="text-slate-500 hover:text-slate-300 transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- İlerleme çubuğu -->
      <div class="bg-slate-800/60 rounded-full h-1 overflow-hidden">
        <div class="h-full bg-amber-400 rounded-full transition-all"
             :style="`width: ${((clarifyIndex) / clarifyQueue.length) * 100}%`"></div>
      </div>

      <template x-if="clarifyItem">
        <div class="space-y-3">
          <!-- Açıklama -->
          <div>
            <label class="text-xs text-slate-400 mb-1.5 block">Açıklama / İşlem Adı</label>
            <input type="text" x-model="clarifyItem.description"
                   :placeholder="clarifyItem.description || 'Örn: Migros market alışveriş'"
                   class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-4 py-3 text-sm text-slate-200
                          placeholder-slate-600 focus:outline-none focus:border-amber-500/60 focus:ring-1 focus:ring-amber-500/20 transition-colors">
          </div>

          <div class="grid grid-cols-2 gap-3">
            <!-- Tarih -->
            <div>
              <label class="text-xs text-slate-400 mb-1.5 flex items-center gap-1 block">
                Tarih
                <span x-show="!clarifyItem.date" class="text-amber-400 text-[9px]">⚠ eksik</span>
              </label>
              <input type="date" x-model="clarifyItem.date"
                     :class="!clarifyItem.date ? 'border-amber-500/50' : 'border-slate-700/50'"
                     class="w-full bg-slate-800/60 border rounded-xl px-3 py-3 text-sm text-slate-200
                            focus:outline-none focus:border-amber-500/60 transition-colors">
            </div>
            <!-- Tutar -->
            <div>
              <label class="text-xs text-slate-400 mb-1.5 block">Tutar (<?= $symbol ?>)</label>
              <input type="number" x-model.number="clarifyItem.amount" step="0.01" min="0"
                     class="w-full bg-slate-800/60 border border-slate-700/50 rounded-xl px-3 py-3 text-sm text-slate-200
                            focus:outline-none focus:border-amber-500/60 transition-colors">
            </div>
          </div>

          <!-- Kategori -->
          <div>
            <label class="text-xs text-slate-400 mb-1.5 flex items-center gap-1 block">
              Kategori
              <span x-show="clarifyItem.category==='Diğer'" class="text-amber-400 text-[9px]">⚠ genel</span>
            </label>
            <div class="grid grid-cols-4 gap-1.5">
              <template x-for="c in ['Market','Restoran','Ulaşım','Fatura','Abonelik','Teknoloji','Sağlık','Giyim','Eğlence','Spor','Taksit','Diğer']" :key="c">
                <button type="button" @click="clarifyItem.category=c"
                        :class="clarifyItem.category===c ? 'bg-amber-500/20 border-amber-500/50 text-amber-300' : 'bg-slate-800/40 border-slate-700/30 text-slate-400'"
                        class="py-1.5 px-1 rounded-lg border text-[10px] font-medium transition-colors text-center truncate"
                        x-text="c">
                </button>
              </template>
            </div>
          </div>

          <!-- Tip toggle -->
          <div class="flex gap-2">
            <button type="button" @click="clarifyItem.type='expense'"
                    :class="clarifyItem.type==='expense' ? 'bg-red-500/20 border-red-500/40 text-red-400' : 'bg-slate-800/40 border-slate-700/30 text-slate-500'"
                    class="flex-1 py-2.5 rounded-xl border text-xs font-semibold transition-colors">↓ Gider</button>
            <button type="button" @click="clarifyItem.type='income'"
                    :class="clarifyItem.type==='income' ? 'bg-emerald-500/20 border-emerald-500/40 text-emerald-400' : 'bg-slate-800/40 border-slate-700/30 text-slate-500'"
                    class="flex-1 py-2.5 rounded-xl border text-xs font-semibold transition-colors">↑ Gelir</button>
          </div>

          <!-- Butonlar -->
          <div class="grid grid-cols-2 gap-3 pt-1">
            <button @click="skipClarify()"
                    class="py-3 rounded-xl bg-slate-800/60 border border-slate-700/50 text-sm font-medium text-slate-400 hover:text-slate-200 transition-colors">
              Geç →
            </button>
            <button @click="confirmClarify()"
                    class="py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-sm font-bold text-white transition-colors">
              Onayla ✓
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
  if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  }
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
