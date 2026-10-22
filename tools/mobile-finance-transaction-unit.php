<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/finance-operations-lib.php';

// No database connection: verify that the ledger preserves its caller's transaction.
class FinanceUnitStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return app_finance_operational_schema_tables();
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return false; }
}
class FinanceUnitPDO extends PDO {
    public int $rollbacks = 0;
    public function __construct(public bool $active, public bool $failLock = false) {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active = true; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->active = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->failLock && str_contains($query, 'FOR UPDATE')) throw new RuntimeException('test_failure');
        return new FinanceUnitStatement();
    }
}
$actor = ['employee_role' => 'finance'];
$input = ['entry_id' => 123, 'reason' => 'Unit test', 'title' => 'Test', 'amount' => 1, 'transaction_date' => '2026-10-05'];
$outer = new FinanceUnitPDO(true);
$result = app_finance_save_entry($outer, $input, $actor);
if (($result['error'] ?? '') !== 'entry_not_found' || !$outer->active || $outer->rollbacks !== 0) throw new RuntimeException('caller_transaction_changed');
$own = new FinanceUnitPDO(false);
app_finance_save_entry($own, $input, $actor);
if ($own->active || $own->rollbacks !== 1) throw new RuntimeException('owned_transaction_not_rolled_back');
$failed = new FinanceUnitPDO(true, true);
$propagated = false;
try { app_finance_save_entry($failed, $input, $actor); }
catch (RuntimeException $e) { $propagated = $e->getMessage() === 'test_failure'; }
if (!$propagated || !$failed->active) throw new RuntimeException('caller_cannot_rollback_failure');
echo "Finance transaction ownership checks passed: 3\n";
