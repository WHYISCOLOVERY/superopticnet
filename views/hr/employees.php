<?php
declare(strict_types=1);

$pageData = (array) ($hrContext['data']['page'] ?? []);
$employeeRows = (array) ($pageData['rows'] ?? []);
$employeeOptions = (array) ($hrContext['data']['employees'] ?? []);
$branches = (array) ($hrContext['data']['branches'] ?? []);
$positions = (array) ($hrContext['data']['positions'] ?? []);
$query = (array) ($hrContext['query'] ?? []);
$canWrite = !empty($hrContext['data']['can_write']);
$canManagePositions = !empty($hrContext['data']['can_manage_positions']);
$serviceAreas = app_service_areas();
$positionIdsByTitle = [];
foreach ($positions as $position) {
    $title = trim((string) ($position['title'] ?? ''));
    if ($title !== '') $positionIdsByTitle[mb_strtolower($title)] = (int) ($position['id'] ?? 0);
}
?>
<div data-hr-employees>
    <div class="portal-card-head">
        <div>
            <strong>Əməkdaş siyahısı və maaş bazası</strong>
            <small>HR vəzifəni seçir; portal rolunu və giriş icazəsini yalnız admin verir.</small>
        </div>
        <div class="portal-badge-list">
            <?php if ($canManagePositions): ?>
                <a class="portal-ghost-btn portal-inline-link" href="admin-positions.php">
                    <i class="fa-solid fa-user-shield"></i> Vəzifələr və icazələr
                </a>
            <?php endif; ?>
            <?php if ($canWrite): ?>
                <button class="portal-ghost-btn" type="button" data-hr-dialog-open="compensation-create">Maaş profili əlavə et</button>
                <button class="portal-primary-btn" type="button" data-hr-dialog-open="employee-create">Yeni əməkdaş</button>
            <?php endif; ?>
        </div>
    </div>

    <form class="hr-filter" method="get" action="operations-center.php" data-hr-filter>
        <input type="hidden" name="section" value="hr">
        <input type="hidden" name="hr_tab" value="employees">
        <div class="portal-form-row">
            <label for="hrEmployeeSearch">Axtarış</label>
            <input id="hrEmployeeSearch" type="search" name="search" value="<?= e((string) ($query['search'] ?? '')); ?>" placeholder="Ad, login və ya vəzifə">
        </div>
        <span></span>
        <span></span>
        <button class="portal-primary-btn" type="submit">Axtar</button>
    </form>

    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>Əməkdaş</th>
                    <th>Vəzifə</th>
                    <th>Sahə</th>
                    <th>Status</th>
                    <th>Baza</th>
                    <th>Gross</th>
                    <th>Net</th>
                    <th>Tam xərc</th>
                    <th>Əməliyyat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employeeRows as $row):
                    $employeePayload = [
                        'id' => (int) ($row['id'] ?? 0),
                        'full_name' => $row['full_name'] ?? '',
                        'login' => $row['login'] ?? '',
                        'position_id' => (int) ($row['position_id'] ?? 0)
                            ?: (int) ($positionIdsByTitle[mb_strtolower(trim((string) ($row['job_title'] ?? '')))] ?? 0),
                        'hire_date' => $row['hire_date'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'email' => $row['email'] ?? '',
                        'area' => $row['area'] ?? '',
                        'responsible_areas' => app_db_account_areas((int) ($row['id'] ?? 0)),
                        'branch_id' => $row['branch_id'] ?? '',
                        'address' => $row['address'] ?? '',
                    ];
                ?>
                    <tr data-hr-row>
                        <td><strong><?= e((string) ($row['full_name'] ?? '-')); ?></strong><br><small><?= e((string) ($row['login'] ?? '-')); ?></small></td>
                        <td><?= e((string) ($row['job_title'] ?? '-')); ?><br><small><?= e((string) (($row['employee_role'] ?? '') ?: 'rol gözləyir')); ?></small></td>
                        <td><?= e((string) ($row['area'] ?? '-')); ?></td>
                        <td><?= (int) ($row['is_active'] ?? 0) === 1 ? 'Aktiv' : 'Passiv'; ?></td>
                        <td><?= e((string) ($row['salary_mode'] ?? '-')); ?></td>
                        <td><?= e(billing_money($row['gross_salary'] ?? 0)); ?></td>
                        <td><?= e(billing_money($row['net_salary'] ?? 0)); ?></td>
                        <td><?= e(billing_money($row['employer_total_cost'] ?? 0)); ?></td>
                        <td>
                            <?php if ($canWrite): ?>
                                <button class="portal-ghost-btn" type="button" data-hr-employee-edit data-hr-dialog-open="employee-edit" data-employee='<?= e(json_encode($employeePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); ?>'>Düzəlt</button>
                            <?php else: ?>
                                <small>-</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($employeeRows === []): ?>
                    <tr><td colspan="9" class="hr-empty">Əməkdaş tapılmadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php require __DIR__ . '/_pagination.php'; ?>

    <?php if ($canWrite): ?>
        <div class="hr-dialog" data-hr-dialog="employee-create" hidden>
            <div class="hr-dialog-backdrop" data-hr-dialog-close></div>
            <section class="hr-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="employee-create-title">
                <div class="hr-dialog-head">
                    <div>
                        <h3 id="employee-create-title">Yeni əməkdaş</h3>
                        <small>Hesab passiv və icazəsiz yaranır; portal rolunu admin verir.</small>
                    </div>
                    <button class="hr-dialog-close" type="button" data-hr-dialog-close aria-label="Bağla">X</button>
                </div>
                <div class="hr-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_hr_employee">
                        <input type="hidden" name="hr_tab" value="employees">
                        <div class="portal-form-row portal-grid-slim">
                            <input name="full_name" placeholder="Ad soyad" required>
                            <input name="login" placeholder="Login" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="position_id" required>
                                <option value="">Vəzifə seç</option>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= e((string) ($position['id'] ?? '')); ?>"><?= e((string) ($position['title'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="hire_date" value="<?= e(date('Y-m-d')); ?>" required>
                        </div>
                        <?php if ($positions === []): ?>
                            <div class="portal-alert portal-alert-warning">
                                Aktiv vəzifə yoxdur. Admin əvvəlcə “Vəzifələr və icazələr” bölməsində vəzifə yaratmalıdır.
                            </div>
                        <?php endif; ?>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="phone" placeholder="Telefon">
                            <input type="email" name="email" placeholder="E-mail">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="responsible_areas[]" required multiple size="5" aria-label="Məsul ərazilər">
                                <?php foreach ($serviceAreas as $area): ?>
                                    <option value="<?= e($area); ?>"><?= e($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="branch_id">
                                <option value="">Filial seç</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <input name="address" placeholder="Ünvan">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="salary_mode">
                                <option value="gross">Gross</option>
                                <option value="net">Net</option>
                            </select>
                            <input type="date" name="effective_from" value="<?= e(date('Y-m-d')); ?>">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" min="0" step="0.01" name="gross_salary" placeholder="Gross maaş">
                            <input type="number" min="0" step="0.01" name="net_salary" placeholder="Net maaş">
                        </div>
                        <button class="portal-primary-btn" type="submit"<?= $positions === [] ? ' disabled' : ''; ?>>Əməkdaşı yarat</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="hr-dialog" data-hr-dialog="employee-edit" hidden>
            <div class="hr-dialog-backdrop" data-hr-dialog-close></div>
            <section class="hr-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="employee-edit-title">
                <div class="hr-dialog-head">
                    <div>
                        <h3 id="employee-edit-title">Əməkdaşı düzəlt</h3>
                        <small>Vəzifə və şəxsi məlumatlar yenilənir; portal rolu dəyişmir.</small>
                    </div>
                    <button class="hr-dialog-close" type="button" data-hr-dialog-close aria-label="Bağla">X</button>
                </div>
                <div class="hr-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_hr_employee">
                        <input type="hidden" name="hr_tab" value="employees">
                        <input type="hidden" name="employee_account_id">
                        <div class="portal-form-row portal-grid-slim">
                            <input name="full_name" placeholder="Ad soyad" required>
                            <input name="login" placeholder="Login" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="position_id" required>
                                <option value="">Vəzifə seç</option>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= e((string) ($position['id'] ?? '')); ?>"><?= e((string) ($position['title'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="date" name="hire_date" required>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input name="phone" placeholder="Telefon">
                            <input type="email" name="email" placeholder="E-mail">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <select name="responsible_areas[]" required multiple size="5" aria-label="Məsul ərazilər">
                                <?php foreach ($serviceAreas as $area): ?>
                                    <option value="<?= e($area); ?>"><?= e($area); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="branch_id">
                                <option value="">Filial seç</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= e((string) ($branch['id'] ?? '')); ?>"><?= e((string) ($branch['name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="portal-form-row">
                            <input name="address" placeholder="Ünvan">
                        </div>
                        <button class="portal-primary-btn" type="submit">Yenilə</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="hr-dialog" data-hr-dialog="compensation-create" hidden>
            <div class="hr-dialog-backdrop" data-hr-dialog-close></div>
            <section class="hr-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="compensation-create-title">
                <div class="hr-dialog-head">
                    <div>
                        <h3 id="compensation-create-title">Əməkdaş maaş profili</h3>
                        <small>Net və ya gross bazanı, qüvvəyə minmə tarixini qeyd edin.</small>
                    </div>
                    <button class="hr-dialog-close" type="button" data-hr-dialog-close aria-label="Bağla">X</button>
                </div>
                <div class="hr-dialog-body">
                    <form class="portal-stacked-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(app_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_hr_employee_compensation">
                        <input type="hidden" name="hr_tab" value="employees">
                        <div class="portal-form-row portal-grid-slim">
                            <select name="comp_employee_account_id" required>
                                <option value="">İşçi seç</option>
                                <?php foreach ($employeeOptions as $employee): ?>
                                    <option value="<?= e((string) ($employee['id'] ?? '')); ?>"><?= e((string) ($employee['full_name'] ?? '-')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="salary_mode">
                                <option value="gross">Gross yazılıb</option>
                                <option value="net">Net yazılıb</option>
                            </select>
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="number" step="0.01" min="0.01" name="comp_gross_salary" placeholder="Gross maaş">
                            <input type="number" step="0.01" min="0.01" name="comp_net_salary" placeholder="Net maaş">
                        </div>
                        <div class="portal-form-row portal-grid-slim">
                            <input type="date" name="comp_effective_from" value="<?= e(date('Y-m-d')); ?>">
                            <input type="text" name="comp_note" placeholder="Əmr və qeyd">
                        </div>
                        <button class="portal-primary-btn" type="submit">Maaş profilini saxla</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
