<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/views/ai/module.php');
$shared = (string) file_get_contents($root . '/views/_module-group-nav.php');
$errors=[];
$check=static function(bool $ok,string $message)use(&$errors):void{if(!$ok)$errors[]=$message;};
$check(str_contains($source, "require __DIR__ . '/../_module-group-nav.php'"), 'ai-shared-nav-not-used');
foreach (['Gunluk AI aletleri', 'Admin texniki aletleri'] as $label) $check(str_contains($source,$label),'ai-group-missing:'.$label);
$check(str_contains($source, "'data-ai-nav'"), 'ai-nav-attribute-missing');
$check(str_contains($shared, "'data-ai-nav'"), 'ai-nav-attribute-not-allowed');
$check(str_contains($shared, 'data-module-section'), 'shared-nav-section-marker-missing');
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($errors===[]?0:1);
