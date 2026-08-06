<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$outputDirectory = $root . '/tmp';
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Cannot create tmp directory\n");
    exit(1);
}

$css = (string) file_get_contents($root . '/css/design-system.css');
$js = (string) file_get_contents($root . '/script/design-system.js');

$html = <<<HTML
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stage 2 design system fixture</title>
<style>{$css}</style>
</head>
<body data-design-system="v2">
<main style="max-width:720px;margin:0 auto;padding:24px;display:grid;gap:16px">
    <h1>Stage 2 komponent fixture</h1>
    <section>
        <button class="ui-button ui-button-primary" type="button" id="fixture-toast">Toast goster</button>
        <button class="ui-button ui-button-secondary" type="button" data-ui-open="fixture-modal">Modal ac</button>
    </section>
    <form id="fixture-form" data-ui-confirm="Fixture form gonderilsin?" data-ui-confirm-title="Fixture tesdiqi" action="#" method="get">
        <label>Ad <input name="fixture_name" value=""></label>
        <button class="ui-button ui-button-danger" type="submit">Gonder</button>
    </form>
    <div class="ui-overlay" data-ui-modal="fixture-modal" id="fixture-modal" hidden>
        <section class="ui-dialog" role="dialog" aria-modal="true" aria-labelledby="fixture-modal-title">
            <header class="ui-dialog-header"><h2 id="fixture-modal-title">Fixture modal</h2></header>
            <div class="ui-dialog-body"><p>Focus trap testi ucun modal.</p><input placeholder="Fokus testi"></div>
            <footer class="ui-dialog-footer"><button class="ui-button ui-button-secondary" type="button" data-ui-close>Bagla</button></footer>
        </section>
    </div>
</main>
<script>{$js}</script>
<script>
document.getElementById('fixture-toast').addEventListener('click', () => {
    window.SuperOpticUI.toast({ tone: 'success', title: 'Fixture', message: 'Toast isleyir.' });
});
</script>
</body>
</html>
HTML;

$target = $outputDirectory . '/stage2-design-system-fixture.html';
file_put_contents($target, $html);
echo 'fixture=' . $target . PHP_EOL;
