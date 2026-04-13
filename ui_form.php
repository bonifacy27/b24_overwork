<?php
CJSCore::Init(['ui.entity-selector']);
$hourOptions = overtimeGetHourOptions();
$dutyAllowed = !empty($overtimeConfig['ALLOW_DUTY']);
$creatorAccess = $overtimeConfig['CREATOR_ACCESS_MAP'] ?? ['is_manager' => false];
$creatorCanCreate = !empty($creatorAccess['is_manager']);

$initialPaymentState = [
    'single' => $formData['single']['payment_type'] ?? [],
    'rows_same' => [],
    'rows_diff' => [],
];

foreach ($formData['rows_same'] as $index => $row) {
    $initialPaymentState['rows_same'][(string)$index] = $row['payment_type'] ?? [];
}

foreach ($formData['rows_diff'] as $index => $row) {
    $initialPaymentState['rows_diff'][(string)$index] = $row['payment_type'] ?? [];
}
?>
<style>
    .overtime-wrap {max-width: 1280px; margin: 0 auto;}
    .overtime-box {background:#fff; border:1px solid #dfe3e8; border-radius:8px; padding:20px; margin-bottom:20px;}
    .overtime-version {color:#7a7a7a; margin-bottom:14px;}
    .overtime-grid-4 {display:grid; grid-template-columns:repeat(4, minmax(180px, 1fr)); gap:12px;}
    .overtime-field {margin-bottom:14px;}
    .overtime-field label {display:block; margin-bottom:6px; font-weight:600;}
    .overtime-field input[type="text"],
    .overtime-field input[type="date"],
    .overtime-field textarea,
    .overtime-field select {width:100%; box-sizing:border-box; padding:9px 10px;}
    .overtime-alert {padding:12px 14px; border-radius:6px; margin-bottom:10px;}
    .overtime-alert-success {background:#f0fff4; border:1px solid #b7ebc6;}
    .overtime-alert-warning {background:#fff8e6; border:1px solid #f3d48b;}
    .overtime-alert-error {background:#fff1f0; border:1px solid #ffb3b3;}
    .overtime-table {width:100%; border-collapse:collapse; margin-top:10px;}
    .overtime-table th, .overtime-table td {border:1px solid #dfe3e8; padding:8px; vertical-align:top; text-align:left;}
    .overtime-user-box {border:1px solid #cfd7df; min-height:39px; border-radius:4px; padding:6px 10px; background:#fff; cursor:pointer;}
    .overtime-hidden {display:none;}
    .overtime-mode-tabs {display:flex; gap:10px; margin-bottom:18px;}
    .overtime-mode-tab {padding:10px 14px; border:1px solid #cfd7df; border-radius:6px; cursor:pointer; background:#fff;}
    .overtime-mode-tab.active {background:#e6f4ff; border-color:#7bb4ff;}
    .overtime-row-card {border:1px solid #d7e3f4; border-radius:10px; padding:16px; margin-bottom:14px; background:#f7fbff;}
    .overtime-row-header {display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;}
    .overtime-row-actions {display:flex; gap:10px;}
    .overtime-preview-box {margin-top:12px; padding:12px; background:#fafbfc; border:1px solid #e8eaed; border-radius:6px;}
    .overtime-subtitle {font-size:16px; font-weight:600; margin:10px 0 12px;}
    .overtime-user-info {margin-top:6px; font-size:13px; color:#4f5b66;}
    .overtime-modal-overlay {position: fixed; inset: 0; background: rgba(0,0,0,0.35); display: none; align-items: center; justify-content: center; z-index: 2000;}
    .overtime-modal {width: min(1100px, calc(100vw - 40px)); max-height: calc(100vh - 40px); overflow: auto; background: #fff; border-radius: 10px; box-shadow: 0 15px 40px rgba(0,0,0,0.2); padding: 20px;}
    .overtime-modal-header {display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;}
    .overtime-modal-actions {display:flex; justify-content:flex-end; gap:10px; margin-top:16px;}
    .overtime-compact-table th, .overtime-compact-table td {font-size:13px; padding:6px 8px;}
</style>

<div class="overtime-wrap">
    <div class="overtime-box">
        <div class="overtime-version">Версия скрипта: <?= overtimeH(OVERTIME_REQUEST_VERSION) ?></div>

        <?php if (!empty($createResult) && empty($createResult['success'])): ?>
            <?php foreach ($createResult['errors'] as $error): ?>
                <div class="overtime-alert overtime-alert-error"><?= overtimeH($error) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$creatorCanCreate): ?>
            <div class="overtime-alert overtime-alert-error">Создание заявок доступно только руководителям и назначенным заместителям руководителя.</div>
        <?php endif; ?>

        <form method="post" id="overtime-form" enctype="multipart/form-data">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="mode" id="mode" value="<?= overtimeH($formData['mode']) ?>">
            <input type="hidden" name="confirm_create" id="confirm_create" value="N">

            <div class="overtime-mode-tabs">
                <div class="overtime-mode-tab <?= $formData['mode'] === 'single' ? 'active' : '' ?>" data-mode="single">Один сотрудник</div>
                <div class="overtime-mode-tab <?= $formData['mode'] === 'multi_same' ? 'active' : '' ?>" data-mode="multi_same">Несколько сотрудников, один период</div>
                <div class="overtime-mode-tab <?= $formData['mode'] === 'multi_diff' ? 'active' : '' ?>" data-mode="multi_diff">Несколько сотрудников, разные периоды</div>
            </div>

            <div id="mode-single" class="<?= $formData['mode'] === 'single' ? '' : 'overtime-hidden' ?>">
                <div class="overtime-field">
                    <label>Сотрудник</label>
                    <div class="overtime-user-box overtime-selector-single" data-input="single_employee_id" data-title="single_employee_title" data-position="single_employee_position">
                        <?= $formData['single']['employee_name'] !== '' ? overtimeH($formData['single']['employee_name']) : 'Выберите сотрудника' ?>
                    </div>
                    <input type="hidden" name="single[employee_id]" id="single_employee_id" value="<?= (int)$formData['single']['employee_id'] ?>">
                    <input type="hidden" id="single_employee_title" value="<?= overtimeH($formData['single']['employee_name']) ?>">
                    <input type="hidden" id="single_employee_position" value="<?= overtimeH($formData['single']['employee_position']) ?>">
                    <div class="overtime-user-info" id="single_employee_info">
                        <?= overtimeH(trim($formData['single']['employee_name'] . ($formData['single']['employee_position'] !== '' ? ' — ' . $formData['single']['employee_position'] : ''))) ?>
                    </div>
                </div>

                <div class="overtime-field">
                    <?php if ($dutyAllowed): ?>
                        <label><input type="checkbox" name="single[is_duty]" id="single_is_duty" value="Y" <?= $formData['single']['is_duty'] === 'Y' ? 'checked' : '' ?>> Дежурство</label>
                    <?php endif; ?>
                </div>

                <div class="overtime-subtitle">Выберите периоды работы</div>
                <div class="overtime-grid-4">
                    <div class="overtime-field">
                        <label>Дата начала</label>
                        <input type="date" name="single[date_start]" id="single_date_start" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($formData['single']['date_start']) ?>">
                    </div>

                    <div class="overtime-field">
                        <label>Время начала</label>
                        <select name="single[time_start]" id="single_time_start">
                            <option value="">Выберите время</option>
                            <?php foreach ($hourOptions as $hour): ?>
                                <option value="<?= overtimeH($hour) ?>" <?= $formData['single']['time_start'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="overtime-field">
                        <label>Дата окончания</label>
                        <input type="date" name="single[date_end]" id="single_date_end" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($formData['single']['date_end']) ?>">
                    </div>

                    <div class="overtime-field">
                        <label>Время окончания</label>
                        <select name="single[time_end]" id="single_time_end">
                            <option value="">Выберите время</option>
                            <?php foreach ($hourOptions as $hour): ?>
                                <option value="<?= overtimeH($hour) ?>" <?= $formData['single']['time_end'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="overtime-field" id="single_justification_wrap">
                    <label>Обоснование</label>
                    <textarea name="single[justification]" id="single_justification" rows="3"><?= overtimeH($formData['single']['justification']) ?></textarea>
                </div>

                <div class="overtime-field" id="single_justification_file_wrap">
                    <label>Обоснование (файл)</label>
                    <input type="file" name="single_justification_file" id="single_justification_file">
                </div>

                <div class="overtime-alert overtime-alert-error overtime-hidden" id="single_late_warning_box">
                    <span id="single_late_warning_text"></span>
                </div>

                <div class="overtime-field overtime-hidden" id="single_late_ack_wrap">
                    <label style="font-weight:400;">
                        <input type="checkbox" name="single[late_ack]" id="single_late_ack" value="Y" <?= $formData['single']['late_ack'] === 'Y' ? 'checked' : '' ?>>
                        Я ознакомлен с этим условием
                    </label>
                </div>

                <div class="overtime-preview-box">
                    <h3>Предпросмотр создаваемых заявок</h3>
                    <div id="single_preview"></div>
                </div>
            </div>

            <div id="mode-multi-same" class="<?= $formData['mode'] === 'multi_same' ? '' : 'overtime-hidden' ?>">
                <div class="overtime-field">
                    <?php if ($dutyAllowed): ?>
                        <label><input type="checkbox" name="common[is_duty]" id="same_is_duty" value="Y" <?= $formData['common']['is_duty'] === 'Y' ? 'checked' : '' ?>> Дежурство</label>
                    <?php endif; ?>
                </div>

                <div class="overtime-subtitle">Выберите периоды работы</div>
                <div class="overtime-grid-4">
                    <div class="overtime-field">
                        <label>Дата начала</label>
                        <input type="date" name="common[date_start]" id="same_date_start" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($formData['common']['date_start']) ?>">
                    </div>

                    <div class="overtime-field">
                        <label>Время начала</label>
                        <select name="common[time_start]" id="same_time_start">
                            <option value="">Выберите время</option>
                            <?php foreach ($hourOptions as $hour): ?>
                                <option value="<?= overtimeH($hour) ?>" <?= $formData['common']['time_start'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="overtime-field">
                        <label>Дата окончания</label>
                        <input type="date" name="common[date_end]" id="same_date_end" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($formData['common']['date_end']) ?>">
                    </div>

                    <div class="overtime-field">
                        <label>Время окончания</label>
                        <select name="common[time_end]" id="same_time_end">
                            <option value="">Выберите время</option>
                            <?php foreach ($hourOptions as $hour): ?>
                                <option value="<?= overtimeH($hour) ?>" <?= $formData['common']['time_end'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="overtime-field" id="common_justification_wrap_same">
                    <label>Обоснование</label>
                    <textarea name="common[justification]" id="common_justification_same" rows="3"><?= overtimeH($formData['common']['justification']) ?></textarea>
                </div>

                <div class="overtime-field" id="common_justification_file_wrap_same">
                    <label>Обоснование (файл)</label>
                    <input type="file" name="common_justification_file" id="common_justification_file_same">
                </div>

                <div class="overtime-alert overtime-alert-error overtime-hidden" id="common_late_warning_box_same">
                    <span id="common_late_warning_text_same"></span>
                </div>

                <div class="overtime-field overtime-hidden" id="common_late_ack_wrap_same">
                    <label style="font-weight:400;">
                        <input type="checkbox" name="common[late_ack]" id="common_late_ack_same" value="Y" <?= $formData['common']['late_ack'] === 'Y' ? 'checked' : '' ?>>
                        Я ознакомлен с этим условием
                    </label>
                </div>

                <div id="rows_same_container">
                    <?php foreach ($formData['rows_same'] as $index => $row): ?>
                        <div class="overtime-row-card same-row" data-index="<?= (int)$index ?>">
                            <div class="overtime-row-header">
                                <strong>Сотрудник #<?= (int)($index + 1) ?></strong>
                                <div class="overtime-row-actions">
                                    <button type="button" class="ui-btn ui-btn-light-border remove-same-row">Удалить</button>
                                </div>
                            </div>

                            <div class="overtime-field">
                                <label>Сотрудник</label>
                                <div class="overtime-user-box overtime-selector-row" data-input="rows_same_<?= (int)$index ?>_employee_id" data-title="rows_same_<?= (int)$index ?>_employee_title" data-position="rows_same_<?= (int)$index ?>_employee_position">
                                    <?= $row['employee_name'] !== '' ? overtimeH($row['employee_name']) : 'Выберите сотрудника' ?>
                                </div>
                                <input type="hidden" name="rows_same[<?= (int)$index ?>][employee_id]" id="rows_same_<?= (int)$index ?>_employee_id" value="<?= (int)$row['employee_id'] ?>">
                                <input type="hidden" id="rows_same_<?= (int)$index ?>_employee_title" value="<?= overtimeH($row['employee_name']) ?>">
                                <input type="hidden" id="rows_same_<?= (int)$index ?>_employee_position" value="<?= overtimeH($row['employee_position']) ?>">
                                <div class="overtime-user-info" id="rows_same_<?= (int)$index ?>_employee_info">
                                    <?= overtimeH(trim($row['employee_name'] . ($row['employee_position'] !== '' ? ' — ' . $row['employee_position'] : ''))) ?>
                                </div>
                            </div>

                            <div class="overtime-preview-box row-preview" id="same_preview_<?= (int)$index ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="ui-btn ui-btn-light-border" id="add_same_row">Добавить сотрудника</button>
            </div>

            <div id="mode-multi-diff" class="<?= $formData['mode'] === 'multi_diff' ? '' : 'overtime-hidden' ?>">
                <div class="overtime-field">
                    <?php if ($dutyAllowed): ?>
                        <label><input type="checkbox" name="common[is_duty]" id="diff_is_duty" value="Y" <?= $formData['common']['is_duty'] === 'Y' ? 'checked' : '' ?>> Дежурство</label>
                    <?php endif; ?>
                </div>

                <div class="overtime-field" id="common_justification_wrap_diff">
                    <label>Обоснование</label>
                    <textarea name="common[justification]" id="common_justification_diff" rows="3"><?= overtimeH($formData['common']['justification']) ?></textarea>
                </div>

                <div class="overtime-field" id="common_justification_file_wrap_diff">
                    <label>Обоснование (файл)</label>
                    <input type="file" name="common_justification_file" id="common_justification_file_diff">
                </div>

                <div class="overtime-alert overtime-alert-error overtime-hidden" id="common_late_warning_box_diff">
                    <span id="common_late_warning_text_diff"></span>
                </div>

                <div class="overtime-field overtime-hidden" id="common_late_ack_wrap_diff">
                    <label style="font-weight:400;">
                        <input type="checkbox" name="common[late_ack]" id="common_late_ack_diff" value="Y" <?= $formData['common']['late_ack'] === 'Y' ? 'checked' : '' ?>>
                        Я ознакомлен с этим условием
                    </label>
                </div>

                <div id="rows_diff_container">
                    <?php foreach ($formData['rows_diff'] as $index => $row): ?>
                        <div class="overtime-row-card diff-row" data-index="<?= (int)$index ?>">
                            <div class="overtime-row-header">
                                <strong>Строка #<?= (int)($index + 1) ?></strong>
                                <div class="overtime-row-actions">
                                    <button type="button" class="ui-btn ui-btn-light-border remove-diff-row">Удалить</button>
                                </div>
                            </div>

                            <div class="overtime-field">
                                <label>Сотрудник</label>
                                <div class="overtime-user-box overtime-selector-row" data-input="rows_diff_<?= (int)$index ?>_employee_id" data-title="rows_diff_<?= (int)$index ?>_employee_title" data-position="rows_diff_<?= (int)$index ?>_employee_position">
                                    <?= $row['employee_name'] !== '' ? overtimeH($row['employee_name']) : 'Выберите сотрудника' ?>
                                </div>
                                <input type="hidden" name="rows_diff[<?= (int)$index ?>][employee_id]" id="rows_diff_<?= (int)$index ?>_employee_id" value="<?= (int)$row['employee_id'] ?>">
                                <input type="hidden" id="rows_diff_<?= (int)$index ?>_employee_title" value="<?= overtimeH($row['employee_name']) ?>">
                                <input type="hidden" id="rows_diff_<?= (int)$index ?>_employee_position" value="<?= overtimeH($row['employee_position']) ?>">
                                <div class="overtime-user-info" id="rows_diff_<?= (int)$index ?>_employee_info">
                                    <?= overtimeH(trim($row['employee_name'] . ($row['employee_position'] !== '' ? ' — ' . $row['employee_position'] : ''))) ?>
                                </div>
                            </div>

                            <div class="overtime-subtitle">Выберите периоды работы</div>
                            <div class="overtime-grid-4">
                                <div class="overtime-field">
                                    <label>Дата начала</label>
                                    <input type="date" name="rows_diff[<?= (int)$index ?>][date_start]" class="diff-date-start" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($row['date_start']) ?>">
                                </div>

                                <div class="overtime-field">
                                    <label>Время начала</label>
                                    <select name="rows_diff[<?= (int)$index ?>][time_start]" class="diff-time-start">
                                        <option value="">Выберите время</option>
                                        <?php foreach ($hourOptions as $hour): ?>
                                            <option value="<?= overtimeH($hour) ?>" <?= $row['time_start'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="overtime-field">
                                    <label>Дата окончания</label>
                                    <input type="date" name="rows_diff[<?= (int)$index ?>][date_end]" class="diff-date-end" min="<?= date('Y-m-d') ?>" value="<?= overtimeH($row['date_end']) ?>">
                                </div>

                                <div class="overtime-field">
                                    <label>Время окончания</label>
                                    <select name="rows_diff[<?= (int)$index ?>][time_end]" class="diff-time-end">
                                        <option value="">Выберите время</option>
                                        <?php foreach ($hourOptions as $hour): ?>
                                            <option value="<?= overtimeH($hour) ?>" <?= $row['time_end'] === $hour ? 'selected' : '' ?>><?= overtimeH($hour) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="overtime-preview-box row-preview" id="diff_preview_<?= (int)$index ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="ui-btn ui-btn-light-border" id="add_diff_row">Добавить строку</button>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="ui-btn ui-btn-success" id="create-btn" <?= !$creatorCanCreate ? 'disabled' : '' ?>>Создать заявки</button>
            </div>
        </form>
    </div>
</div>

<div class="overtime-modal-overlay" id="overtime-confirm-overlay">
    <div class="overtime-modal">
        <div class="overtime-modal-header">
            <h3 style="margin:0;">Подтверждение создания заявок</h3>
            <button type="button" class="ui-btn ui-btn-light-border" id="overtime-confirm-close">Закрыть</button>
        </div>

        <div id="overtime-confirm-content"></div>

        <div class="overtime-modal-actions">
            <button type="button" class="ui-btn ui-btn-light-border" id="overtime-confirm-cancel">Отмена</button>
            <button type="button" class="ui-btn ui-btn-success" id="overtime-confirm-submit">Создать заявки</button>
        </div>
    </div>
</div>

<script>
window.overtimeInitialPaymentState = <?= \Bitrix\Main\Web\Json::encode($initialPaymentState) ?>;
</script>
