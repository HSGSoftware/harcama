<?php
// Mevcut sayfayı belirle (aktif nav öğesi için)
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Sayfa başlıklarını eşleştir
$page_titles = [
    'index'           => 'Dashboard',
    'transactions'    => 'İşlemler',
    'ai_optimization' => 'AI Analiz',
    'investments'     => 'Yatırımlar',
    'subscriptions'   => 'Abonelikler',
    'settings'        => 'Ayarlar',
];
$page_title = $page_titles[$current_page] ?? 'FinansAI';
?>
<!DOCTYPE html>
<html lang="tr" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#6366f1">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FinansAI">
  <link rel="manifest" href="/manifest.json">
  <link rel="apple-touch-icon" href="/icons/icon-192.png">
  <title><?= htmlspecialchars($page_title) ?> — FinansAI</title>

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            brand: {
              50:  '#eef2ff',
              100: '#e0e7ff',
              400: '#818cf8',
              500: '#6366f1',
              600: '#4f46e5',
              700: '#4338ca',
            }
          },
          fontFamily: {
            sans: ['Inter', 'system-ui', 'sans-serif'],
          }
        }
      }
    }
  </script>

  <!-- Alpine.js CDN -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <!-- Inter Fontu -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    /* Scrollbar gizle */
    ::-webkit-scrollbar { display: none; }
    * { -ms-overflow-style: none; scrollbar-width: none; }

    /* Safe area desteği (iOS notch) */
    .pb-safe { padding-bottom: env(safe-area-inset-bottom, 1rem); }

    /* Bottom nav blur efekti */
    .bottom-nav {
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
    }

    /* Aktif tab göstergesi animasyonu */
    .nav-indicator {
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Sayfa geçiş animasyonu */
    .page-enter {
      animation: fadeSlideUp 0.25s ease-out;
    }
    @keyframes fadeSlideUp {
      from { opacity: 0; transform: translateY(12px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Kart hover efekti */
    .card-hover {
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .card-hover:hover {
      transform: translateY(-2px);
    }
  </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-full flex flex-col" x-data="{ darkMode: true }">

  <!-- Üst Header -->
  <header class="sticky top-0 z-40 bg-slate-950/80 backdrop-blur-xl border-b border-slate-800/50">
    <div class="max-w-lg mx-auto px-4 h-14 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <!-- Logo -->
        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-brand-500 to-purple-600 flex items-center justify-center shadow-lg">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
        </div>
        <span class="font-semibold text-sm tracking-tight text-slate-100">FinansAI</span>
      </div>
      <h1 class="text-sm font-medium text-slate-300"><?= htmlspecialchars($page_title) ?></h1>
      <!-- Bildirim ikonu (gelecekte kullanılabilir) -->
      <button class="w-8 h-8 rounded-full bg-slate-800/60 flex items-center justify-center text-slate-400 hover:text-slate-200 hover:bg-slate-700/60 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
      </button>
    </div>
  </header>

  <!-- Ana İçerik -->
  <main class="flex-1 max-w-lg mx-auto w-full px-4 pt-4 pb-24 page-enter">
    <?php
    // İçerik sayfalar tarafından doldurulur
    // Bu dosya require ile dahil edilir, ardından içerik gelir
    ?>
