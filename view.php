<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (
    !Loader::includeModule('iblock')
    || !Loader::includeModule('main')
) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/inc/constants.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/logic.php';

$request = Context::getCurrent()->getRequest();
$requestId = (int)$request->getQuery('id');

function overtimeGetElementNameById(int $iblockId, int $elementId): string
{
    if ($iblockId <= 0 || $elementId <= 0) {
        return '';
    }

    static $cache = [];
    $cacheKey = $iblockId . ':' . $elementId;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $name = '';
    $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $elementId], false, false, ['ID', 'NAME']);
    if ($item = $res->Fetch()) {
        $name = (string)$item['NAME'];
    }

    $cache[$cacheKey] = $name;
    return $name;
}

function overtimeGetRequestViewData(int $requestId, array $config): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $select = [
        'ID',
        'NAME',
        'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_TYPE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_TIME'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_TIME'],
        'PROPERTY_' . $config['REQ_PROP_LINKED_REQUESTS'],
        'PROPERTY_' . $config['REQ_PROP_GROUP_LINK'],
    ];

    $res = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'],
            'ID' => $requestId,
        ],
        false,
        false,
        $select
    );

    $item = $res->Fetch();
    if (!$item) {
        return null;
    }

    $employeeId = (int)($item['PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
    $workTypeId = (int)($item['PROPERTY_' . $config['REQ_PROP_WORK_TYPE'] . '_VALUE'] ?? 0);

    $employee = overtimeGetUserDataById($employeeId);

    $linkedValue = $item['PROPERTY_' . $config['REQ_PROP_LINKED_REQUESTS'] . '_VALUE'] ?? [];
    if (!is_array($linkedValue)) {
        $linkedValue = [$linkedValue];
    }

    $linkedRequestIds = [];
    foreach ($linkedValue as $value) {
        $linkedId = (int)$value;
        if ($linkedId > 0 && $linkedId !== $requestId) {
            $linkedRequestIds[$linkedId] = $linkedId;
        }
    }

    $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
    if ($calculationHtml === '') {
        $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
    }

    return [
        'id' => $requestId,
        'name' => (string)$item['NAME'],
        'employee_name' => $employee['name'] ?: 'Не указан',
        'work_type_name' => overtimeGetElementNameById((int)$config['IBLOCK_WORK_TYPES'], $workTypeId),
        'calculation_html' => overtimeBuildCalculationHtmlByRequestItem($item, $config),
        'linked_request_ids' => array_values($linkedRequestIds),
        'group_id' => (int)($item['PROPERTY_' . $config['REQ_PROP_GROUP_LINK'] . '_VALUE'] ?? 0),
    ];
}

function overtimeGetLinkedRequestCalculations(array $requestIds, array $config): array
{
    $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
    if (empty($requestIds)) {
        return [];
    }

    $result = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'],
            'ID' => $requestIds,
        ],
        false,
        false,
        [
            'ID',
            'NAME',
            'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'],
            'PROPERTY_' . $config['REQ_PROP_WORK_TYPE'],
            'PROPERTY_' . $config['REQ_PROP_WORK_START_DATE'],
            'PROPERTY_' . $config['REQ_PROP_WORK_END_DATE'],
            'PROPERTY_' . $config['REQ_PROP_WORK_START_TIME'],
            'PROPERTY_' . $config['REQ_PROP_WORK_END_TIME'],
        ]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
        $employee = overtimeGetUserDataById($employeeId);

        $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
        if ($calculationHtml === '') {
            $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
        }

        $result[] = [
            'id' => (int)$item['ID'],
            'name' => (string)$item['NAME'],
            'employee_name' => $employee['name'] ?: 'Не указан',
            'calculation_html' => overtimeBuildCalculationHtmlByRequestItem($item, $config),
        ];
    }

    return $result;
}

function overtimeExtractPropertyValue(array $item, string $propertyCode)
{
    $value = $item['PROPERTY_' . $propertyCode . '_VALUE'] ?? null;

    if (is_array($value)) {
        if (array_key_exists('VALUE', $value)) {
            return overtimeExtractScalarValue($value['VALUE']);
        }
        return overtimeExtractScalarValue($value);
    }

    return $value;
}

function overtimeExtractScalarValue($value)
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $itemValue) {
        $scalar = overtimeExtractScalarValue($itemValue);
        if ($scalar !== null && $scalar !== '') {
            return $scalar;
        }
    }

    return null;
}

function overtimeBuildCalculationHtmlByRequestItem(array $item, array $config): string
{
    $employeeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_EMPLOYEE']);
    $workTypeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE']);

    if ($employeeId <= 0 || $workTypeId <= 0) {
        return '';
    }

    if ($workTypeId !== (int)$config['WORK_TYPE_OVERTIME_ID']) {
        return '';
    }

    $startDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']);
    $startTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_TIME']);
    $endDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']);
    $endTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_TIME']);

    $start = overtimeBuildDateTimeFromDateAndTime($startDate, $startTime);
    $end = overtimeBuildDateTimeFromDateAndTime($endDate, $endTime);

    // Fallback на старые поля, если дата/время работ в заявке не заполнены.
    if ($start === null || $end === null) {
        $start = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_START']));
        $end = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_END']));
    }

    if ($start === null || $end === null) {
        return '';
    }

    if (strtotime($start->format('Y-m-d H:i:s')) >= strtotime($end->format('Y-m-d H:i:s'))) {
        return '';
    }

    $segment = [
        'type_id' => $workTypeId,
        'type_name' => 'Сверхурочная работа',
        'start' => $start,
        'end' => $end,
        'hours' => overtimeGetHoursDiff($start, $end),
        'day_type' => 'workday',
    ];

    $paymentBreakdown = overtimeBuildPaymentBreakdown($employeeId, $segment, $config);
    return overtimeBuildCalculationHtmlReport($paymentBreakdown);
}

function overtimeNormalizeTimeValue($value): string
{
    $time = trim((string)$value);
    if ($time === '') {
        return '';
    }

    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }

    $ts = strtotime($time);
    if ($ts === false) {
        return '';
    }

    return date('H:i:s', $ts);
}

function overtimeBuildDateTimeFromDateAndTime($dateValue, $timeValue): ?\Bitrix\Main\Type\DateTime
{
    $dateRaw = trim((string)$dateValue);
    $timeRaw = overtimeNormalizeTimeValue($timeValue);

    if ($dateRaw === '' || $timeRaw === '') {
        return null;
    }

    $date = overtimeParseRequestDateValue($dateRaw);
    if ($date === null) {
        return null;
    }

    return overtimeParseRequestDateTimeValue($date . ' ' . $timeRaw);
}

function overtimeParseRequestDateValue(string $rawDate): ?string
{
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return null;
    }

    $formats = [
        'd.m.Y',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $dt = \DateTime::createFromFormat($format, $rawDate);
        if ($dt instanceof \DateTime) {
            return $dt->format('d.m.Y');
        }
    }

    $ts = strtotime($rawDate);
    if ($ts === false) {
        return null;
    }

    return date('d.m.Y', $ts);
}

function overtimeParseRequestDateTimeValue($value): ?\Bitrix\Main\Type\DateTime
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $formats = [
        'd.m.Y H:i:s',
        'd.m.Y H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
    ];

    foreach ($formats as $format) {
        try {
            $dt = new \Bitrix\Main\Type\DateTime($raw, $format);
            if ($dt instanceof \Bitrix\Main\Type\DateTime) {
                return $dt;
            }
        } catch (\Throwable $e) {
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return new \Bitrix\Main\Type\DateTime(date('d.m.Y H:i:s', $ts), 'd.m.Y H:i:s');
}

$viewData = overtimeGetRequestViewData($requestId, $overtimeConfig);
$linkedCalculations = $viewData ? overtimeGetLinkedRequestCalculations($viewData['linked_request_ids'], $overtimeConfig) : [];

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Просмотр заявки');
?>
<style>
    .overtime-view-wrap {max-width: 1280px; margin: 0 auto;}
    .overtime-view-box {background:#fff; border:1px solid #dfe3e8; border-radius:8px; padding:20px; margin-bottom:20px;}
    .overtime-view-meta {display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px; margin-bottom:20px;}
    .overtime-view-meta-item {padding:12px; border:1px solid #e4e8ee; border-radius:6px; background:#f8fafc;}
    .overtime-view-meta-label {font-size:12px; color:#6b7280; margin-bottom:4px;}
    .overtime-view-meta-value {font-size:15px; font-weight:600;}
    .overtime-view-title {font-size:18px; margin-bottom:12px;}
    .overtime-view-subtitle {font-size:16px; margin:16px 0 10px;}
    .overtime-view-calc {border:1px solid #e4e8ee; border-radius:6px; padding:12px; background:#fff; overflow:auto;}
    .overtime-view-actions {display:flex; gap:10px; margin-top:20px;}
    .overtime-btn {display:inline-block; padding:10px 14px; border:1px solid #cfd7df; border-radius:6px; background:#fff; text-decoration:none; color:#1f2937;}
    .overtime-btn-primary {background:#1f6feb; border-color:#1f6feb; color:#fff;}
</style>

<div class="overtime-view-wrap">
    <div class="overtime-view-box">
        <div class="overtime-view-meta-label" style="margin-bottom:10px;">
            Версия скрипта: <?= defined('OVERTIME_REQUEST_VERSION') ? overtimeH((string)OVERTIME_REQUEST_VERSION) : 'n/a' ?>
        </div>
        <?php if (!$viewData): ?>
            <div class="ui-alert ui-alert-danger">
                <span class="ui-alert-message">Заявка с ID <?= (int)$requestId ?> не найдена.</span>
            </div>
        <?php else: ?>
            <div class="overtime-view-title">Заявка #<?= (int)$viewData['id'] ?></div>

            <div class="overtime-view-meta">
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Название заявки</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['name']) ?></div>
                </div>
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Тип заявки</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['work_type_name'] ?: 'Не указан') ?></div>
                </div>
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Сотрудник</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['employee_name']) ?></div>
                </div>
            </div>

            <div class="overtime-view-subtitle">Расчетная часть</div>
            <div class="overtime-view-calc">
                <?= $viewData['calculation_html'] !== '' ? $viewData['calculation_html'] : '<i>Расчет отсутствует</i>' ?>
            </div>

            <?php if (!empty($linkedCalculations)): ?>
                <div class="overtime-view-subtitle">Расчетная часть связанных заявок</div>
                <?php foreach ($linkedCalculations as $linked): ?>
                    <div class="overtime-view-subtitle" style="font-size:14px; margin-top:12px;">Заявка #<?= (int)$linked['id'] ?> — <?= overtimeH($linked['employee_name']) ?></div>
                    <div class="overtime-view-calc">
                        <?= $linked['calculation_html'] !== '' ? $linked['calculation_html'] : '<i>Расчет отсутствует</i>' ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($viewData['group_id'] > 0): ?>
                <div class="overtime-view-subtitle">Групповая заявка</div>
                <a
                    class="overtime-btn"
                    href="https://ourtricolortv.nsc.ru/forms/hr_administration/overtime/list.php?group_filter=<?= (int)$viewData['group_id'] ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Открыть группу заявок #<?= (int)$viewData['group_id'] ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <div class="overtime-view-actions">
            <a class="overtime-btn overtime-btn-primary" href="/forms/hr_administration/overtime/list.php">Вернуться к заявкам</a>
        </div>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
