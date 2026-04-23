<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (
    !Loader::includeModule('iblock')
    || !Loader::includeModule('main')
    || !Loader::includeModule('bizproc')
) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main/bizproc');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/logic.php';

function overtimeNormalizeDateForInput(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '';
}

function overtimeFormatDateRu(string $value): string
{
    $normalized = overtimeNormalizeDateForInput($value);
    if ($normalized === '') {
        return '';
    }

    return date('d.m.Y', strtotime($normalized));
}

function overtimeGetEnumValueById(int $enumId): string
{
    if ($enumId <= 0) {
        return '';
    }

    $enum = CIBlockPropertyEnum::GetByID($enumId);
    return $enum ? trim((string)$enum['VALUE']) : '';
}

function overtimeResolvePropertyDisplayValue(array $item, string $propertyCode): string
{
    $enumValue = trim((string)($item['PROPERTY_' . $propertyCode . '_ENUM'] ?? ''));
    if ($enumValue !== '') {
        return $enumValue;
    }

    $valueEnum = trim((string)($item['PROPERTY_' . $propertyCode . '_VALUE_ENUM'] ?? ''));
    if ($valueEnum !== '') {
        return $valueEnum;
    }

    $value = $item['PROPERTY_' . $propertyCode . '_VALUE'] ?? null;

    if (is_array($value)) {
        $parts = [];
        foreach ($value as $v) {
            $part = trim((string)$v);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if (!empty($parts)) {
            return implode(', ', $parts);
        }
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '') {
            if (is_numeric($value) && (int)$value > 0) {
                $enumValue = overtimeGetEnumValueById((int)$value);
                if ($enumValue !== '') {
                    return $enumValue;
                }

                $elementName = overtimeGetElementName((int)$value);
                if ($elementName !== '') {
                    return $elementName;
                }
            }

            return $value;
        }
    }

    if (is_numeric($value) && (int)$value > 0) {
        $enumValue = overtimeGetEnumValueById((int)$value);
        if ($enumValue !== '') {
            return $enumValue;
        }

        $elementName = overtimeGetElementName((int)$value);
        if ($elementName !== '') {
            return $elementName;
        }
    }

    return '';
}

function overtimeResolvePaymentTypeNameFromRequest(array $item, array $config): string
{
    $propertyCodes = [
        (string)($config['REQ_PROP_PAYMENT_TYPE'] ?? ''),
        (string)($config['REQ_PROP_PAY_TYPE'] ?? ''),
        (string)($config['REQ_PROP_PAYMENT_KIND'] ?? ''),
    ];

    foreach ($propertyCodes as $propertyCode) {
        $propertyCode = trim($propertyCode);
        if ($propertyCode === '') {
            continue;
        }

        $resolved = overtimeResolvePropertyDisplayValue($item, $propertyCode);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    return 'Не указан';
}

function overtimeBuildRequestPeriodParts(array $item, array $config): array
{
    $dateStart = trim((string)($item['PROPERTY_' . $config['REQ_PROP_WORK_START_DATE'] . '_VALUE'] ?? ''));
    $timeStart = trim((string)($item['PROPERTY_' . $config['REQ_PROP_WORK_START_TIME'] . '_VALUE'] ?? ''));
    $dateEnd = trim((string)($item['PROPERTY_' . $config['REQ_PROP_WORK_END_DATE'] . '_VALUE'] ?? ''));
    $timeEnd = trim((string)($item['PROPERTY_' . $config['REQ_PROP_WORK_END_TIME'] . '_VALUE'] ?? ''));

    if ($dateStart !== '' && $dateEnd !== '') {
        return [
            'date_start_input' => overtimeNormalizeDateForInput($dateStart),
            'date_end_input' => overtimeNormalizeDateForInput($dateEnd),
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
        ];
    }

    $startRaw = trim((string)($item['PROPERTY_' . $config['REQ_PROP_START'] . '_VALUE'] ?? ''));
    $endRaw = trim((string)($item['PROPERTY_' . $config['REQ_PROP_END'] . '_VALUE'] ?? ''));
    $startTs = $startRaw !== '' ? strtotime($startRaw) : 0;
    $endTs = $endRaw !== '' ? strtotime($endRaw) : 0;

    return [
        'date_start_input' => $startTs > 0 ? date('Y-m-d', $startTs) : '',
        'date_end_input' => $endTs > 0 ? date('Y-m-d', $endTs) : '',
        'time_start' => $startTs > 0 ? date('H:i', $startTs) : '',
        'time_end' => $endTs > 0 ? date('H:i', $endTs) : '',
    ];
}

function overtimeCalculateEndDateByStart(string $sourceStartDate, string $sourceEndDate, string $newStartDate): string
{
    if ($newStartDate === '') {
        return '';
    }

    $newStart = DateTime::createFromFormat('Y-m-d', $newStartDate);
    if (!$newStart) {
        return '';
    }

    $sourceStart = DateTime::createFromFormat('Y-m-d', $sourceStartDate);
    $sourceEnd = DateTime::createFromFormat('Y-m-d', $sourceEndDate);
    if (!$sourceStart || !$sourceEnd) {
        return $newStart->format('Y-m-d');
    }

    $daysDiff = (int)$sourceStart->diff($sourceEnd)->format('%r%a');
    $newEnd = clone $newStart;
    if ($daysDiff !== 0) {
        $newEnd->modify(($daysDiff > 0 ? '+' : '') . $daysDiff . ' day');
    }

    return $newEnd->format('Y-m-d');
}

function overtimeGetDateDiffDays(string $sourceStartDate, string $sourceEndDate): int
{
    $sourceStart = DateTime::createFromFormat('Y-m-d', $sourceStartDate);
    $sourceEnd = DateTime::createFromFormat('Y-m-d', $sourceEndDate);
    if (!$sourceStart || !$sourceEnd) {
        return 0;
    }

    return (int)$sourceStart->diff($sourceEnd)->format('%r%a');
}

function overtimeGetElementName(int $elementId): string
{
    if ($elementId <= 0) {
        return '';
    }

    $res = CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'NAME']);
    $row = $res->Fetch();
    return $row ? trim((string)$row['NAME']) : '';
}

$request = Context::getCurrent()->getRequest();
$requestId = (int)$request->getQuery('id');

global $USER;
$currentUserId = (is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 0;
$adminName = trim(overtimeGetUserNameById($currentUserId));
if ($adminName === '') {
    $adminName = 'Пользователь #' . $currentUserId;
}

$overtimeConfig['CURRENT_USER_ID'] = $currentUserId;
$overtimeConfig['ALLOW_DUTY'] = overtimeCanCurrentUserUseDuty($currentUserId, $overtimeConfig);
$overtimeConfig['CREATOR_ACCESS_MAP'] = overtimeGetCreatorAccessMap($currentUserId, $overtimeConfig);
$overtimeConfig['SKIP_CREATOR_ACCESS_CHECK'] = true;
$overtimeConfig['SKIP_PAST_DATE_RESTRICTION'] = true;

$errors = [];
$successMessage = '';
$previewRows = [];

$sourceRequest = overtimeGetRequestById($requestId, $overtimeConfig);
if (!$sourceRequest) {
    $errors[] = 'Заявка не найдена.';
}

$workTypeId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_WORK_TYPE'] . '_VALUE'] ?? 0);
$isDuty = $workTypeId === (int)$overtimeConfig['WORK_TYPE_DUTY_ID'];

$employeeId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
$paymentTypeId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_PAYMENT_TYPE'] . '_VALUE'] ?? 0);

$initiatorId = (int)($sourceRequest['CREATED_BY'] ?? 0);
$initiatorName = overtimeGetUserNameById($initiatorId);
$employeeName = overtimeGetUserNameById($employeeId);
$workTypeName = overtimeGetElementName($workTypeId);
$paymentTypeName = overtimeResolvePaymentTypeNameFromRequest((array)$sourceRequest, $overtimeConfig);
$justificationText = trim((string)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_JUSTIFICATION'] . '_VALUE'] ?? ''));

$periodParts = overtimeBuildRequestPeriodParts((array)$sourceRequest, $overtimeConfig);
$sourceDateStartInput = (string)$periodParts['date_start_input'];
$sourceDateEndInput = (string)$periodParts['date_end_input'];
$sourceTimeStart = (string)$periodParts['time_start'];
$sourceTimeEnd = (string)$periodParts['time_end'];
$sourceDateDiffDays = overtimeGetDateDiffDays($sourceDateStartInput, $sourceDateEndInput);

$editDateStart = $sourceDateStartInput;
$editDateEnd = overtimeCalculateEndDateByStart($sourceDateStartInput, $sourceDateEndInput, $editDateStart);

if ($request->isPost() && $request->getPost('action') === 'edit_ka' && check_bitrix_sessid()) {
    $editDateStart = overtimeNormalizeDateForInput((string)$request->getPost('date_start'));
    $editDateEnd = overtimeCalculateEndDateByStart($sourceDateStartInput, $sourceDateEndInput, $editDateStart);

    if ($editDateStart === '' || $editDateEnd === '') {
        $errors[] = 'Необходимо выбрать дату начала работ.';
    }

    $preview = [];
    if (empty($errors)) {
        try {
            $preview = overtimeBuildSinglePreviewItem(
                $employeeId,
                $editDateStart,
                $sourceTimeStart,
                $editDateEnd,
                $sourceTimeEnd,
                $isDuty,
                $overtimeConfig
            );
        } catch (Throwable $e) {
            $preview = ['errors' => ['Ошибка предпросмотра: ' . $e->getMessage()], 'segments_json' => '[]'];
        }

        if (!empty($preview['errors'])) {
            $errors = array_merge($errors, (array)$preview['errors']);
        }
    }

    $segmentsRaw = [];
    if (empty($errors)) {
        $segmentsRaw = \Bitrix\Main\Web\Json::decode($preview['segments_json'] ?: '[]');
        if (empty($segmentsRaw)) {
            $errors[] = 'Не удалось сформировать сегменты новой заявки.';
        }
    }

    if (empty($segmentsRaw)) {
        $previewRows = [];
    } else {
        foreach ($segmentsRaw as $segment) {
            $previewRows[] = [
                'type_name' => (string)($segment['type_name'] ?? ''),
                'start' => date('d.m.Y H:i', strtotime((string)$segment['start'])),
                'end' => date('d.m.Y H:i', strtotime((string)$segment['end'])),
                'hours' => (float)($segment['hours'] ?? 0),
            ];
        }
    }

    if (empty($errors) && ($request->getPost('confirm_transfer') === 'Y')) {
        $paymentTypes = [];
        foreach ($segmentsRaw as $index => $_segment) {
            $paymentTypes[$index] = $paymentTypeId;
        }

        $justification = trim((string)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_JUSTIFICATION'] . '_VALUE'] ?? ''));
        $justificationFileId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_JUST_FILE'] . '_VALUE'] ?? 0);
        $justificationFile = $justificationFileId > 0 ? CFile::MakeFileArray($justificationFileId) : null;

        $createResult = overtimeCreateEmployeeRequestPack(
            $employeeId,
            $segmentsRaw,
            $paymentTypes,
            $justification,
            $justificationFile,
            true,
            $currentUserId,
            $overtimeConfig,
            0,
            ['par_Start' => 'edit_ka']
        );

        if (empty($createResult['success'])) {
            $errors = array_merge($errors, (array)($createResult['errors'] ?? ['Не удалось создать новую заявку.']));
        } else {
            $newRequestId = (int)($createResult['created_ids'][0] ?? 0);
            if ($newRequestId <= 0) {
                $errors[] = 'Новая заявка создана некорректно: не получен ID.';
            }

            if (empty($errors)) {
                CIBlockElement::SetPropertyValuesEx(
                    $requestId,
                    (int)$overtimeConfig['IBLOCK_REQUESTS'],
                    [
                        $overtimeConfig['REQ_PROP_STATUS'] => (int)$overtimeConfig['STATUS_TRANSFERRED_ID'],
                    ]
                );

                $stopWorkflowError = overtimeTerminateRequestWorkflows(
                    $requestId,
                    'Перенос заявки кадровым администратором'
                );
                if ($stopWorkflowError !== null) {
                    $errors[] = 'Не удалось прервать бизнес-процесс старой заявки: ' . $stopWorkflowError;
                }

                $now = date('d.m.Y H:i:s');
                overtimeAppendRequestHistory(
                    $requestId,
                    $now . ' Перенесена в заявку #' . $newRequestId . ' (' . $adminName . ')',
                    $overtimeConfig
                );
                overtimeAppendRequestHistory(
                    $newRequestId,
                    $now . ' Создана перенесом после редактирования заявки #' . $requestId . ' (' . $adminName . ')',
                    $overtimeConfig
                );

                if (empty($errors)) {
                    LocalRedirect(
                        'list.php?transfer_done=Y&old_id=' . (int)$requestId . '&new_id=' . (int)$newRequestId
                    );
                }
            }
        }
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Редактирование заявки кадровым администратором');
?>
<div style="max-width: 900px;">
    <h2>Редактирование заявки #<?= (int)$requestId ?></h2>

    <?php if (!empty($errors)): ?>
        <div style="padding:12px; border:1px solid #c44; background:#fff1f1; margin-bottom:16px;">
            <?php foreach ($errors as $error): ?>
                <div style="margin:4px 0;"><?= htmlspecialcharsbx((string)$error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div style="padding:12px; border:1px solid #2f8a3b; background:#f0fff2; margin-bottom:16px;">
            <?= htmlspecialcharsbx($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($sourceRequest): ?>
        <div style="padding:14px; border:1px solid #d5d5d5; margin-bottom:16px; background:#fafafa;">
            <h3 style="margin-top:0;">Текущие данные заявки</h3>
            <div><b>Инициатор:</b> <?= htmlspecialcharsbx($initiatorName ?: 'Не указан') ?></div>
            <div><b>Сотрудник:</b> <?= htmlspecialcharsbx($employeeName ?: 'Не указан') ?></div>
            <div><b>Тип работы:</b> <?= htmlspecialcharsbx($workTypeName ?: 'Не указан') ?></div>
            <div><b>Тип оплаты:</b> <?= htmlspecialcharsbx($paymentTypeName ?: 'Не указан') ?></div>
            <div><b>Обоснование:</b> <?= nl2br(htmlspecialcharsbx($justificationText !== '' ? $justificationText : 'Не указано')) ?></div>
            <div><b>Дата/время начала работ:</b> <?= htmlspecialcharsbx(overtimeFormatDateRu($sourceDateStartInput) . ' ' . $sourceTimeStart) ?></div>
            <div><b>Дата/время окончания работ:</b> <?= htmlspecialcharsbx(overtimeFormatDateRu($sourceDateEndInput) . ' ' . $sourceTimeEnd) ?></div>
        </div>

        <form method="post" action="" style="padding:14px; border:1px solid #d5d5d5; margin-bottom:16px;">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="edit_ka">

            <h3 style="margin-top:0;">Перенос дат работ (редактируется только дата начала)</h3>
            <p style="margin-top:0;color:#666;">Дата окончания рассчитывается автоматически по длительности исходной заявки. Время начала/окончания сохраняется из исходной заявки.</p>

            <table class="adm-detail-content-table edit-table" style="width:100%; max-width:700px;">
                <tr>
                    <td style="width:260px;">Новая дата начала работ</td>
                    <td>
                        <input type="date" id="date_start" name="date_start" value="<?= htmlspecialcharsbx($editDateStart) ?>" data-date-diff-days="<?= (int)$sourceDateDiffDays ?>">
                        <span style="margin-left:8px;color:#666;">время: <?= htmlspecialcharsbx($sourceTimeStart) ?></span>
                    </td>
                </tr>
                <tr>
                    <td>Новая дата окончания работ (авторасчет)</td>
                    <td>
                        <input type="date" id="date_end_display" value="<?= htmlspecialcharsbx($editDateEnd) ?>" disabled>
                        <input type="hidden" id="date_end" name="date_end" value="<?= htmlspecialcharsbx($editDateEnd) ?>">
                        <span style="margin-left:8px;color:#666;">время: <?= htmlspecialcharsbx($sourceTimeEnd) ?></span>
                    </td>
                </tr>
            </table>

            <div style="margin-top:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button type="submit" class="ui-btn">Показать изменения</button>
                <button type="submit" class="ui-btn ui-btn-success" name="confirm_transfer" value="Y">Подтвердить перенос</button>
            </div>
        </form>

        <div style="padding:14px; border:1px solid #d5d5d5; background:#fff;">
            <h3 style="margin-top:0;">Что изменится в новой заявке</h3>
            <div style="margin-bottom:8px;">
                <b>Было:</b>
                <?= htmlspecialcharsbx(overtimeFormatDateRu($sourceDateStartInput) . ' ' . $sourceTimeStart) ?> —
                <?= htmlspecialcharsbx(overtimeFormatDateRu($sourceDateEndInput) . ' ' . $sourceTimeEnd) ?>
            </div>
            <div style="margin-bottom:12px;">
                <b>Станет:</b>
                <?= htmlspecialcharsbx(overtimeFormatDateRu($editDateStart) . ' ' . $sourceTimeStart) ?> —
                <?= htmlspecialcharsbx(overtimeFormatDateRu($editDateEnd) . ' ' . $sourceTimeEnd) ?>
                <?php if ($editDateStart !== $sourceDateStartInput || $editDateEnd !== $sourceDateEndInput): ?>
                    <span style="margin-left:8px; color:#2f8a3b; font-weight:600;">(даты изменены)</span>
                <?php else: ?>
                    <span style="margin-left:8px; color:#777;">(без изменений)</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($previewRows)): ?>
                <div style="font-weight:600; margin-bottom:8px;">Новые сегменты/заявки после перерасчета:</div>
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                    <tr>
                        <th style="border:1px solid #ddd; padding:6px; text-align:left;">Тип</th>
                        <th style="border:1px solid #ddd; padding:6px; text-align:left;">Начало</th>
                        <th style="border:1px solid #ddd; padding:6px; text-align:left;">Окончание</th>
                        <th style="border:1px solid #ddd; padding:6px; text-align:left;">Часы</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td style="border:1px solid #ddd; padding:6px;"><?= htmlspecialcharsbx($row['type_name']) ?></td>
                            <td style="border:1px solid #ddd; padding:6px;"><?= htmlspecialcharsbx($row['start']) ?></td>
                            <td style="border:1px solid #ddd; padding:6px;"><?= htmlspecialcharsbx($row['end']) ?></td>
                            <td style="border:1px solid #ddd; padding:6px;"><?= htmlspecialcharsbx((string)$row['hours']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="color:#666;">Нажмите «Показать изменения», чтобы увидеть структуру новой заявки после перерасчёта.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    (function() {
        var dateStart = document.getElementById('date_start');
        var dateEndDisplay = document.getElementById('date_end_display');
        var dateEndHidden = document.getElementById('date_end');
        if (!dateStart || !dateEndDisplay || !dateEndHidden) {
            return;
        }

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function formatIsoDate(date) {
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
        }

        function recalculateEndDate() {
            var startValue = dateStart.value;
            if (!startValue) {
                dateEndDisplay.value = '';
                dateEndHidden.value = '';
                return;
            }

            var diffDays = parseInt(dateStart.getAttribute('data-date-diff-days') || '0', 10);
            var startDate = new Date(startValue + 'T00:00:00');
            if (isNaN(startDate.getTime())) {
                dateEndDisplay.value = '';
                dateEndHidden.value = '';
                return;
            }

            startDate.setDate(startDate.getDate() + diffDays);
            var endValue = formatIsoDate(startDate);
            dateEndDisplay.value = endValue;
            dateEndHidden.value = endValue;
        }

        dateStart.addEventListener('change', recalculateEndDate);
        recalculateEndDate();
    })();
</script>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
