<?php

declare(strict_types=1);

/**
 * Dependency-free inline-SVG charts. The billing server has no outbound internet
 * (loopback gate), so a CDN chart library cannot be used — these render pure SVG
 * with the app's design tokens (var(--ui-color-*)) and scale responsively.
 */

if (!function_exists('e')) {
    // Safety net; the app defines e() in config.php. Charts must never emit raw input.
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Vertical bar chart. $series = list of ['label' => string, 'value' => float].
 * The last bar is highlighted (accent) to draw the eye to "today/latest".
 */
function app_chart_bars(array $series, array $opts = []): string
{
    $series = array_values(array_filter($series, 'is_array'));
    if ($series === []) {
        return '<div class="chart-empty">Melumat yoxdur</div>';
    }
    $height = (int) ($opts['height'] ?? 150);
    $money = (bool) ($opts['money'] ?? false);
    $vbW = 320;
    $vbH = 120;
    $padTop = 12;
    $padBottom = 20;
    $plotH = $vbH - $padTop - $padBottom;

    $values = array_map(static fn (array $r): float => (float) ($r['value'] ?? 0), $series);
    $max = max($values);
    $max = $max > 0 ? $max : 1.0;
    $count = count($series);
    $slot = $vbW / $count;
    $barW = min(26.0, $slot * 0.62);
    $lastIndex = $count - 1;

    $bars = '';
    $labels = '';
    foreach ($series as $i => $row) {
        $value = (float) ($row['value'] ?? 0);
        $barH = $value > 0 ? max(2.0, ($value / $max) * $plotH) : 0.0;
        $x = ($i * $slot) + (($slot - $barW) / 2);
        $y = $padTop + ($plotH - $barH);
        $fill = $i === $lastIndex ? 'var(--ui-color-accent, #f28a45)' : 'var(--ui-color-primary, #087783)';
        $valueText = $money
            ? number_format($value, 2, '.', ' ') . ' AZN'
            : number_format($value, 0, '.', ' ');
        $bars .= '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barW, 2)
            . '" height="' . round($barH, 2) . '" rx="2.5" fill="' . $fill . '">'
            . '<title>' . e((string) ($row['label'] ?? '')) . ': ' . e($valueText) . '</title></rect>';
        // Show every Nth label to avoid crowding.
        $step = (int) ceil($count / 8);
        if ($step < 1 || $i % $step === 0 || $i === $lastIndex) {
            $labels .= '<text x="' . round(($i * $slot) + ($slot / 2), 2) . '" y="' . ($vbH - 6)
                . '" text-anchor="middle" class="chart-axis">' . e((string) ($row['label'] ?? '')) . '</text>';
        }
    }
    $baseline = '<line x1="0" y1="' . ($padTop + $plotH) . '" x2="' . $vbW . '" y2="' . ($padTop + $plotH)
        . '" class="chart-baseline" />';

    return '<svg class="chart-svg" viewBox="0 0 ' . $vbW . ' ' . $vbH . '" role="img" preserveAspectRatio="none"'
        . ' style="height:' . $height . 'px" xmlns="http://www.w3.org/2000/svg">'
        . $baseline . $bars . $labels . '</svg>';
}

/**
 * Donut chart. $segments = list of ['label','value','color'(CSS)]. Renders the
 * ring plus a centered total and a legend beside it.
 */
function app_chart_donut(array $segments, array $opts = []): string
{
    $segments = array_values(array_filter($segments, static fn ($s): bool => is_array($s) && (float) ($s['value'] ?? 0) >= 0));
    $total = 0.0;
    foreach ($segments as $s) {
        $total += (float) ($s['value'] ?? 0);
    }
    $centerLabel = (string) ($opts['center_label'] ?? 'Cemi');
    $size = 120;
    $r = 46;
    $cx = 60;
    $cy = 60;
    $circ = 2 * M_PI * $r;
    $stroke = 16;

    $ring = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="var(--ui-color-surface-muted, #eef2f5)" stroke-width="' . $stroke . '" />';
    $offset = 0.0;
    $legend = '';
    if ($total > 0) {
        foreach ($segments as $s) {
            $value = (float) ($s['value'] ?? 0);
            if ($value <= 0) {
                $legend .= '<li><span class="chart-dot" style="background:' . e((string) ($s['color'] ?? 'var(--ui-color-neutral)')) . '"></span>' . e((string) ($s['label'] ?? '')) . ' <b>0</b></li>';
                continue;
            }
            $frac = $value / $total;
            $len = $frac * $circ;
            $color = (string) ($s['color'] ?? 'var(--ui-color-primary)');
            $ring .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $color
                . '" stroke-width="' . $stroke . '" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2)
                . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')">'
                . '<title>' . e((string) ($s['label'] ?? '')) . ': ' . e(number_format($value, 0, '.', ' ')) . '</title></circle>';
            $offset += $len;
            $pct = round($frac * 100);
            $legend .= '<li><span class="chart-dot" style="background:' . e($color) . '"></span>'
                . e((string) ($s['label'] ?? '')) . ' <b>' . e(number_format($value, 0, '.', ' ')) . '</b> <em>' . $pct . '%</em></li>';
        }
    }
    $centerValue = number_format($total, 0, '.', ' ');

    return '<div class="chart-donut">'
        . '<svg class="chart-donut-svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" xmlns="http://www.w3.org/2000/svg">'
        . $ring
        . '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" class="chart-donut-value">' . e($centerValue) . '</text>'
        . '<text x="' . $cx . '" y="' . ($cy + 14) . '" text-anchor="middle" class="chart-donut-label">' . e($centerLabel) . '</text>'
        . '</svg>'
        . '<ul class="chart-legend">' . $legend . '</ul>'
        . '</div>';
}
