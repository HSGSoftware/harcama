  </main><!-- /main -->

  <!-- Bottom Navigation Bar -->
  <nav class="bottom-nav fixed bottom-0 left-0 right-0 z-50 bg-slate-900/90 border-t border-slate-800/60 pb-safe">
    <div class="max-w-lg mx-auto">
      <div class="flex items-center justify-around px-2 h-16">

        <!-- Dashboard -->
        <a href="<?= url('/index.php') ?>"
           class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl transition-all duration-200
                  <?= $current_page === 'index' ? 'text-brand-400' : 'text-slate-500 hover:text-slate-300' ?>">
          <?php if ($current_page === 'index'): ?>
            <span class="w-5 h-0.5 rounded-full bg-brand-400 absolute -top-1"></span>
          <?php endif; ?>
          <svg class="w-5 h-5" fill="<?= $current_page === 'index' ? 'currentColor' : 'none' ?>" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">Özet</span>
        </a>

        <!-- İşlemler -->
        <a href="<?= url('/transactions.php') ?>"
           class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl transition-all duration-200
                  <?= $current_page === 'transactions' ? 'text-brand-400' : 'text-slate-500 hover:text-slate-300' ?>">
          <svg class="w-5 h-5" fill="<?= $current_page === 'transactions' ? 'currentColor' : 'none' ?>" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">İşlemler</span>
        </a>

        <!-- AI Analiz - Merkez Aksiyon Butonu -->
        <a href="<?= url('/ai_optimization.php') ?>"
           class="relative flex flex-col items-center gap-1 -mt-5">
          <div class="w-14 h-14 rounded-2xl shadow-lg shadow-brand-500/30 flex items-center justify-center transition-transform active:scale-95
                      <?= $current_page === 'ai_optimization' ? 'bg-brand-500' : 'bg-gradient-to-br from-brand-500 to-purple-600' ?>">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
          </div>
          <span class="text-[10px] font-medium leading-none mt-1 <?= $current_page === 'ai_optimization' ? 'text-brand-400' : 'text-slate-500' ?>">AI Analiz</span>
        </a>

        <!-- Yatırımlar -->
        <a href="<?= url('/investments.php') ?>"
           class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl transition-all duration-200
                  <?= $current_page === 'investments' ? 'text-brand-400' : 'text-slate-500 hover:text-slate-300' ?>">
          <svg class="w-5 h-5" fill="<?= $current_page === 'investments' ? 'currentColor' : 'none' ?>" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">Yatırım</span>
        </a>

        <!-- Ayarlar -->
        <a href="<?= url('/settings.php') ?>"
           class="flex flex-col items-center gap-1 px-3 py-2 rounded-xl transition-all duration-200
                  <?= $current_page === 'settings' ? 'text-brand-400' : 'text-slate-500 hover:text-slate-300' ?>">
          <svg class="w-5 h-5" fill="<?= $current_page === 'settings' ? 'currentColor' : 'none' ?>" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span class="text-[10px] font-medium leading-none">Ayarlar</span>
        </a>

      </div>
    </div>
  </nav>

  <!-- Service Worker Kaydı -->
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
          .catch(() => {}); // Sessizce başarısız ol
      });
    }
  </script>

</body>
</html>
