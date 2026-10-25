<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/api/mobile/v1/lib/mobile-inventory-lib.php';
class MobileInventoryUnitStatement extends PDOStatement {
    public function __construct(private array $row) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
class MobileInventoryUnitPDO extends PDO {
    public function __construct(public bool $active, public array $row = []) {}
    public function inTransaction(): bool { return $this->active; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_starts_with($query, 'SELECT')) throw new LogicException('Unexpected write in validation test');
        return new MobileInventoryUnitStatement($this->row);
    }
}
$user = ['id' => 42, 'login' => 'tech42'];
$ticket = ['subscriber_code' => 'TEST', 'ticket_no' => 'TEST'];
$checks = 0;
$expect = static function (bool $value) use (&$checks): void { if (!$value) throw new RuntimeException('check_failed'); $checks++; };
$expect(app_mobile_inventory_owned(['t'=>'employee','i'=>'42'], $user, 't', 'i'));
$expect(app_mobile_inventory_owned(['t'=>'technician','i'=>'tech42'], $user, 't', 'i'));
$expect(!app_mobile_inventory_owned(['t'=>'employee','i'=>'43'], $user, 't', 'i'));
$expect(!app_mobile_inventory_owned(['t'=>'warehouse','i'=>'42'], $user, 't', 'i'));
$cases = [
    [new MobileInventoryUnitPDO(false), [], LogicException::class],
    [new MobileInventoryUnitPDO(true), ['materials_used'=>[['quantity'=>-1]]], InvalidArgumentException::class],
    [new MobileInventoryUnitPDO(true), ['materials_used'=>[['quantity'=>1]]], InvalidArgumentException::class],
    [new MobileInventoryUnitPDO(true, ['location_type'=>'employee','location_id'=>'43']), ['materials_used'=>[['quantity'=>1,'cable_drum_id'=>1]]], InvalidArgumentException::class],
    [new MobileInventoryUnitPDO(true, ['location_type'=>'employee','location_id'=>'42','remaining_length_meters'=>10,'reserved_length_meters'=>5]), ['materials_used'=>[['quantity'=>6,'cable_drum_id'=>1]]], InvalidArgumentException::class],
    [new MobileInventoryUnitPDO(true, ['current_location_type'=>'employee','current_location_id'=>'43']), ['equipment'=>['asset_id'=>1]], InvalidArgumentException::class],
];
foreach ($cases as [$db, $payload, $exceptionType]) {
    $rejected = false;
    try { app_mobile_inventory_consume($db, $user, $ticket, $payload); }
    catch (Throwable $e) { $rejected = $e instanceof $exceptionType; }
    $expect($rejected);
}
echo "Mobile inventory validation checks passed: $checks\n";
