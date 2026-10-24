<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root=dirname(__DIR__);$message=(string)file_get_contents($root.'/message-center.php');$noc=(string)file_get_contents($root.'/noc-devices.php');$shared=(string)file_get_contents($root.'/views/_module-group-nav.php');$errors=[];
$check=static function(bool $ok,string $m)use(&$errors):void{if(!$ok)$errors[]=$m;};
$check(str_contains($message,"require __DIR__ . '/views/_module-group-nav.php'"),'message-shared-nav-not-used');
$check(substr_count($message, 'css/operations-modules.css') === 1, 'message-shared-nav-stylesheet-invalid');
foreach(['Umumi baxis','Mesaj gonder','Mezmun','Tarixce ve audit','Provider test'] as $label)$check(str_contains($message,$label),'message-group-missing:'.$label);
$check(str_contains($noc,"require __DIR__ . '/views/_module-group-nav.php'"),'noc-shared-nav-not-used');
$check(substr_count($noc, 'css/operations-modules.css') === 1, 'noc-shared-nav-stylesheet-invalid');
foreach(['Monitor','Cihazlar','Backup','Sebeke tenzimlemeleri'] as $label)$check(str_contains($noc,$label),'noc-group-missing:'.$label);
foreach(['data-message-nav','data-noc-nav'] as $attr)$check(str_contains($shared,"'".$attr."'"),'shared-attribute-missing:'.$attr);
foreach (['Ã', 'Â', 'É', 'Å', 'Æ', 'Ä', 'ƒ', 'â'] as $marker) {
    $check(!str_contains($message, $marker), 'message-mojibake-remains:' . $marker);
}
echo json_encode(['status'=>$errors===[]?'passed':'failed','errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($errors===[]?0:1);
