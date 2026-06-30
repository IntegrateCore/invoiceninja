@php
  $publicIndexPath = public_path('index.html');
  $headHtml = '';

  if (file_exists($publicIndexPath)) {
      $publicIndex = file_get_contents($publicIndexPath);

      if ($publicIndex !== false && preg_match('/<head[^>]*>(.*)<\/head>/is', $publicIndex, $matches)) {
          $headHtml = trim($matches[1]);
      }
  }

  if ($headHtml === '') {
      $headHtml = <<<HTML
<link rel="stylesheet" href="/rsms/inter.css" />
<link rel="icon" href="/favicon.ico?v=integratecore" />
<link rel="apple-touch-icon" href="/logo180.png?v=integratecore" />
<link rel="manifest" href="/manifest.json?v=integratecore" />
HTML;
  }
@endphp

{!! $headHtml !!}
