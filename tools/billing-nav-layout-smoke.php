<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$cssPath = dirname(__DIR__) . '/css/portal.css';
$css = file_get_contents($cssPath);

$responsiveGridNav = is_string($css)
    && preg_match('/\.billing-shell-nav\s*\{[^}]*display:\s*grid/s', $css) === 1
    && preg_match('/\.billing-shell-nav\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fit/s', $css) === 1
    && preg_match('/\.billing-shell-nav\s*\{[^}]*overflow:\s*visible/s', $css) === 1;
$arrowIsCssBorder = is_string($css)
    && preg_match('/\.billing-nav-title::after\s*\{[^}]*border-right/s', $css) === 1;
$oldGarbledArrowRemoved = is_string($css)
    && !str_contains($css, 'content: "Ã¢Å’â€"')
    && !str_contains($css, 'content: "âŒ„"');

$mobileCompactNav = is_string($css)
    && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.billing-shell-nav\s*\{[\s\S]*?display:\s*flex/s', $css) === 1
    && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.billing-shell-nav\s*\{[\s\S]*?overflow-x:\s*auto/s', $css) === 1
    && preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.billing-nav-group\s*\{[\s\S]*?flex:\s*0\s+0/s', $css) === 1;
$hiddenMobileScrollbars = is_string($css)
    && preg_match('/\.billing-shell-nav::-\s*webkit-scrollbar\s*\{[\s\S]*?display:\s*none/s', $css) === 1
    && preg_match('/\.billing-nav-group\[open\]\s+\.billing-nav-links::-\s*webkit-scrollbar\s*\{[\s\S]*?display:\s*none/s', $css) === 1
    && preg_match('/\.billing-shell-nav\s*\{[\s\S]*?scrollbar-width:\s*none/s', $css) === 1
    && preg_match('/\.billing-nav-group\[open\]\s+\.billing-nav-links\s*\{[\s\S]*?scrollbar-width:\s*none/s', $css) === 1;

$ok = is_string($css)
    && str_contains($css, '.billing-shell-nav')
    && $responsiveGridNav
    && $arrowIsCssBorder
    && $oldGarbledArrowRemoved
    && $mobileCompactNav
    && $hiddenMobileScrollbars;

echo json_encode([
    'status' => $ok ? 'ok' : 'failed',
    'checks' => [
        'responsive_grid_nav' => $responsiveGridNav,
        'mobile_compact_nav' => $mobileCompactNav,
        'hidden_mobile_scrollbars' => $hiddenMobileScrollbars,
        'css_arrow_not_garbled' => $arrowIsCssBorder,
        'old_garbled_arrow_removed' => $oldGarbledArrowRemoved,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
