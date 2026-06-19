<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

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

function overtimeResolveEnumOrElementValue($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            $resolved = overtimeResolveEnumOrElementValue($item);
            if ($resolved !== '') {
                $items[] = $resolved;
            }
        }
        return implode(', ', $items);
    }

    static $enumCache = [];
    static $elementCache = [];

    $intValue = (int)$value;
    if ($intValue > 0) {
        if (!array_key_exists($intValue, $enumCache)) {
            $enum = CIBlockPropertyEnum::GetByID($intValue);
            $enumCache[$intValue] = $enum ? (string)$enum['VALUE'] : '';
        }
        if ($enumCache[$intValue] !== '') {
            return $enumCache[$intValue];
        }

        if (!array_key_exists($intValue, $elementCache)) {
            $res = CIBlockElement::GetList([], ['ID' => $intValue], false, false, ['ID', 'NAME']);
            $row = $res->Fetch();
            $elementCache[$intValue] = $row ? (string)$row['NAME'] : '';
        }
        if ($elementCache[$intValue] !== '') {
            return $elementCache[$intValue];
        }
    }

    return (string)$value;
}

function overtimeGetStatusClass(string $statusName): string
{
    $statusName = mb_strtolower(trim($statusName), 'UTF-8');
    if ($statusName === '') {
        return 'status-default';
    }

    if (mb_strpos($statusName, 'соглас') !== false || mb_strpos($statusName, 'утверж') !== false || mb_strpos($statusName, 'одобр') !== false) {
        return 'status-success';
    }
    if (mb_strpos($statusName, 'отказ') !== false || mb_strpos($statusName, 'отклон') !== false) {
        return 'status-danger';
    }
    if (mb_strpos($statusName, 'нов') !== false || mb_strpos($statusName, 'создан') !== false || mb_strpos($statusName, 'чернов') !== false) {
        return 'status-info';
    }
    if (mb_strpos($statusName, 'на соглас') !== false || mb_strpos($statusName, 'в работе') !== false || mb_strpos($statusName, 'рассмотр') !== false || mb_strpos($statusName, 'перераспредел') !== false) {
        return 'status-warning';
    }

    return 'status-default';
}


function overtimeGetStatusColorById(int $statusId): string
{
    static $cache = [];

    if ($statusId <= 0) {
        return '';
    }
    if (array_key_exists($statusId, $cache)) {
        return $cache[$statusId];
    }

    $res = CIBlockElement::GetList([], ['ID' => $statusId], false, false, ['ID', 'PROPERTY_COLOR']);
    $row = $res->Fetch();
    $color = trim((string)($row['PROPERTY_COLOR_VALUE'] ?? ''));
    if ($color !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $cache[$statusId] = strtoupper($color);
        return $cache[$statusId];
    }

    $cache[$statusId] = '';
    return '';
}

function overtimeGetStatusPillStyle(int $statusId): string
{
    $color = overtimeGetStatusColorById($statusId);
    return $color !== '' ? 'background:' . $color . ';' : '';
}

function overtimeGetRequestViewData(int $requestId, array $config): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $select = [
        'ID',
        'NAME',
        'CREATED_BY',
        'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_TYPE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_TIME'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_TIME'],
        'PROPERTY_' . $config['REQ_PROP_LINKED_REQUESTS'],
        'PROPERTY_' . $config['REQ_PROP_GROUP_LINK'],
        'PROPERTY_' . $config['REQ_PROP_JUSTIFICATION'],
        'PROPERTY_' . $config['REQ_PROP_STATUS'],
        'PROPERTY_' . $config['REQ_PROP_TOTAL_OT_HOURS'],
        'PROPERTY_' . $config['REQ_PROP_TOTAL_PREMIUM_HOURS'],
    ];
    $select = array_merge($select, overtimeBuildOptionalPropertySelect($config));

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
    $paymentTypeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_PAYMENT_TYPE']);
    $paymentTypeName = overtimeResolvePaymentTypeNameByItem($item, $config);
    $justification = trim((string)overtimeExtractPropertyValue($item, $config['REQ_PROP_JUSTIFICATION']));
    $statusId = (int)($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? 0);
    $statusName = overtimeResolveEnumOrElementValue($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? '');

    $employee = overtimeGetUserDataById($employeeId);
    $initiatorId = (int)($item['CREATED_BY'] ?? 0);
    $initiator = overtimeGetUserDataById($initiatorId);
    $workPeriod = overtimeBuildWorkPeriodTextByRequestItem($item, $config);

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

        $displayHours = overtimeResolveDisplayHoursByItem($item, $config);
        $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
    if ($calculationHtml === '') {
        $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
    }

    return [
        'id' => $requestId,
        'name' => (string)$item['NAME'],
        'initiator_name' => $initiator['name'] ?: 'Не указан',
        'employee_name' => $employee['name'] ?: 'Не указан',
        'work_type_id' => $workTypeId,
        'work_type_name' => overtimeGetElementNameById((int)$config['IBLOCK_WORK_TYPES'], $workTypeId),
        'work_start_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']),
        'work_end_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']),
        'is_duty' => $workTypeId === (int)$config['WORK_TYPE_DUTY_ID'],
        'work_period_text' => $workPeriod,
        'payment_type_id' => $paymentTypeId,
        'payment_type_name' => $paymentTypeName,
        'justification' => $justification,
        'calculation_html' => overtimeBuildCalculationHtmlByRequestItem($item, $config),
        'linked_request_ids' => array_values($linkedRequestIds),
        'group_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($item['PROPERTY_' . $config['REQ_PROP_GROUP_LINK'] . '_VALUE'] ?? []))))),
        'status_name' => $statusName,
        'status_id' => $statusId,
        'employee_id' => $employeeId,
        'total_ot_hours' => $displayHours['tk_hours'],
        'total_premium_hours' => $displayHours['premium_hours'],
    ];
}

function overtimeCollectAllLinkedRequestIds(int $requestId, array $config): array
{
    if ($requestId <= 0) {
        return [];
    }

    $iblockId = (int)($config['IBLOCK_REQUESTS'] ?? 0);
    $linkedPropCode = trim((string)($config['REQ_PROP_LINKED_REQUESTS'] ?? ''));
    if ($iblockId <= 0 || $linkedPropCode === '') {
        return [];
    }

    $visited = [$requestId => true];
    $result = [];
    $queue = [$requestId];

    while (!empty($queue)) {
        $batchIds = array_values(array_unique(array_map('intval', $queue)));
        $queue = [];

        $res = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'ID' => $batchIds,
            ],
            false,
            false,
            [
                'ID',
                'PROPERTY_' . $linkedPropCode,
            ]
        );

        while ($item = $res->Fetch()) {
            $currentId = (int)($item['ID'] ?? 0);
            if ($currentId <= 0) {
                continue;
            }

            $linkedValue = $item['PROPERTY_' . $linkedPropCode . '_VALUE'] ?? [];
            if (!is_array($linkedValue)) {
                $linkedValue = [$linkedValue];
            }

            foreach ($linkedValue as $value) {
                $linkedId = (int)$value;
                if ($linkedId <= 0 || $linkedId === $requestId) {
                    continue;
                }

                $result[$linkedId] = $linkedId;
                if (!isset($visited[$linkedId])) {
                    $visited[$linkedId] = true;
                    $queue[] = $linkedId;
                }
            }
        }
    }

    return array_values($result);
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
            'PROPERTY_' . $config['REQ_PROP_STATUS'],
            'PROPERTY_' . $config['REQ_PROP_TOTAL_OT_HOURS'],
            'PROPERTY_' . $config['REQ_PROP_TOTAL_PREMIUM_HOURS'],
            ...overtimeBuildOptionalPropertySelect($config),
        ]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
        $employee = overtimeGetUserDataById($employeeId);

        $displayHours = overtimeResolveDisplayHoursByItem($item, $config);
    $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
        if ($calculationHtml === '') {
            $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
        }

        $result[] = [
            'id' => (int)$item['ID'],
            'name' => (string)$item['NAME'],
            'employee_name' => $employee['name'] ?: 'Не указан',
            'payment_type_id' => (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_PAYMENT_TYPE']),
            'payment_type_name' => overtimeResolvePaymentTypeNameByItem($item, $config),
            'work_type_id' => (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE']),
            'work_type_name' => overtimeGetElementNameById((int)$config['IBLOCK_WORK_TYPES'], (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE'])),
            'work_start_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']),
            'work_end_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']),
            'work_period_text' => overtimeBuildWorkPeriodTextByRequestItem($item, $config),
            'total_ot_hours' => $displayHours['tk_hours'],
            'total_premium_hours' => $displayHours['premium_hours'],
            'calculation_html' => overtimeBuildCalculationHtmlByRequestItem($item, $config),
            'status_name' => overtimeResolveEnumOrElementValue($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? ''),
            'status_id' => (int)($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? 0),
        ];
    }

    return $result;
}

function overtimeGetGroupRequestCalculations(array $groupIds, int $currentRequestId, array $config): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static function (int $groupId): bool {
        return $groupId > 0;
    })));
    if (empty($groupIds)) {
        return [];
    }

    $result = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'],
            'PROPERTY_' . $config['REQ_PROP_GROUP_LINK'] => $groupIds,
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
            'PROPERTY_' . $config['REQ_PROP_STATUS'],
            'PROPERTY_' . $config['REQ_PROP_TOTAL_OT_HOURS'],
            'PROPERTY_' . $config['REQ_PROP_TOTAL_PREMIUM_HOURS'],
            ...overtimeBuildOptionalPropertySelect($config),
        ]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
        $employee = overtimeGetUserDataById($employeeId);

        $displayHours = overtimeResolveDisplayHoursByItem($item, $config);
    $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
        if ($calculationHtml === '') {
            $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
        }

        $requestId = (int)$item['ID'];
        $result[$requestId] = [
            'id' => (int)$item['ID'],
            'name' => (string)$item['NAME'],
            'employee_name' => $employee['name'] ?: 'Не указан',
            'payment_type_id' => (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_PAYMENT_TYPE']),
            'payment_type_name' => overtimeResolvePaymentTypeNameByItem($item, $config),
            'work_type_id' => (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE']),
            'work_type_name' => overtimeGetElementNameById((int)$config['IBLOCK_WORK_TYPES'], (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE'])),
            'work_start_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']),
            'work_end_date' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']),
            'work_period_text' => overtimeBuildWorkPeriodTextByRequestItem($item, $config),
            'total_ot_hours' => $displayHours['tk_hours'],
            'total_premium_hours' => $displayHours['premium_hours'],
            'calculation_html' => $calculationHtml,
            'status_name' => overtimeResolveEnumOrElementValue($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? ''),
            'status_id' => (int)($item['PROPERTY_' . $config['REQ_PROP_STATUS'] . '_VALUE'] ?? 0),
        ];
    }

    return array_values($result);
}

function overtimeBuildOptionalPropertySelect(array $config): array
{
    $propertyCodes = [
        'REQ_PROP_PAYMENT_TYPE',
        'REQ_PROP_PAY_TYPE',
        'REQ_PROP_PAYMENT_KIND',
    ];

    $select = [];
    foreach ($propertyCodes as $configKey) {
        $code = trim((string)($config[$configKey] ?? ''));
        if ($code !== '') {
            $select[] = 'PROPERTY_' . $code;
        }
    }

    return array_values(array_unique($select));
}

function overtimeResolvePaymentTypeNameByItem(array $item, array $config): string
{
    $paymentPropertyCode = '';
    foreach (['REQ_PROP_PAYMENT_TYPE', 'REQ_PROP_PAY_TYPE', 'REQ_PROP_PAYMENT_KIND'] as $configKey) {
        $candidate = trim((string)($config[$configKey] ?? ''));
        if ($candidate !== '') {
            $paymentPropertyCode = $candidate;
            break;
        }
    }

    if ($paymentPropertyCode === '') {
        return 'Не указан';
    }

    $rawValue = overtimeExtractPropertyValue($item, $paymentPropertyCode);
    if ($rawValue === null || $rawValue === '') {
        return 'Не указан';
    }

    if (!is_numeric($rawValue)) {
        return trim((string)$rawValue) ?: 'Не указан';
    }

    $paymentTypeId = (int)$rawValue;
    if ($paymentTypeId <= 0) {
        return 'Не указан';
    }

    $paymentTypeIblockId = (int)($config['IBLOCK_PAYMENT_TYPES'] ?? $config['IBLOCK_PAY_TYPES'] ?? 0);
    if ($paymentTypeIblockId <= 0) {
        return (string)$paymentTypeId;
    }

    $name = overtimeGetElementNameById($paymentTypeIblockId, $paymentTypeId);
    return $name !== '' ? $name : (string)$paymentTypeId;
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

function overtimeBuildWorkPeriodTextByRequestItem(array $item, array $config): string
{
    $startDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']);
    $startTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_TIME']);
    $endDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']);
    $endTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_TIME']);

    $start = overtimeBuildDateTimeFromDateAndTime($startDate, $startTime);
    $end = overtimeBuildDateTimeFromDateAndTime($endDate, $endTime);

    if ($start === null || $end === null) {
        $start = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_START']));
        $end = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_END']));
    }

    if ($start === null || $end === null) {
        return '';
    }

    return sprintf('(%s - %s)', $start->format('d.m.Y H:i'), $end->format('d.m.Y H:i'));
}


function overtimeResolveDisplayHoursByItem(array $item, array $config): array
{
    $paymentBreakdown = overtimeBuildPaymentBreakdownByRequestItem($item, $config);
    if (!empty($paymentBreakdown)) {
        return [
            'tk_hours' => (string)round((float)($paymentBreakdown['tk_hours'] ?? 0), 2),
            'premium_hours' => (string)round((float)($paymentBreakdown['premium_hours'] ?? 0), 2),
        ];
    }

    return [
        'tk_hours' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_TOTAL_OT_HOURS']),
        'premium_hours' => (string)overtimeExtractPropertyValue($item, $config['REQ_PROP_TOTAL_PREMIUM_HOURS']),
    ];
}

function overtimeBuildPaymentBreakdownByRequestItem(array $item, array $config): array
{
    $employeeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_EMPLOYEE']);
    $workTypeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE']);

    if ($employeeId <= 0 || $workTypeId <= 0) {
        return [];
    }

    $startDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_DATE']);
    $startTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_START_TIME']);
    $endDate = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_DATE']);
    $endTime = overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_END_TIME']);

    $start = overtimeBuildDateTimeFromDateAndTime($startDate, $startTime);
    $end = overtimeBuildDateTimeFromDateAndTime($endDate, $endTime);

    if ($start === null || $end === null) {
        $start = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_START']));
        $end = overtimeParseRequestDateTimeValue(overtimeExtractPropertyValue($item, $config['REQ_PROP_END']));
    }

    if ($start === null || $end === null) {
        return [];
    }

    if (strtotime($start->format('Y-m-d H:i:s')) >= strtotime($end->format('Y-m-d H:i:s'))) {
        return [];
    }

    $segment = [
        'type_id' => $workTypeId,
        'start' => $start,
        'end' => $end,
        'hours' => overtimeGetHoursDiff($start, $end),
    ];

    return overtimeBuildPaymentBreakdown($employeeId, $segment, $config);
}

function overtimeBuildCalculationHtmlByRequestItem(array $item, array $config): string
{
    $employeeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_EMPLOYEE']);
    $workTypeId = (int)overtimeExtractPropertyValue($item, $config['REQ_PROP_WORK_TYPE']);

    if ($employeeId <= 0 || $workTypeId <= 0) {
        return '';
    }

    $isOvertime = $workTypeId === (int)$config['WORK_TYPE_OVERTIME_ID'];
    $isWeekend = isset($config['WORK_TYPE_WEEKEND_ID']) && $workTypeId === (int)$config['WORK_TYPE_WEEKEND_ID'];

    if (!$isOvertime && !$isWeekend) {
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
        'type_name' => $isWeekend ? 'Работа в выходной день' : 'Сверхурочная работа',
        'start' => $start,
        'end' => $end,
        'hours' => overtimeGetHoursDiff($start, $end),
        'day_type' => $isWeekend ? 'weekend' : 'workday',
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

function overtimeHighlightCalculationRows(string $html): string
{
    if (trim($html) === '') {
        return $html;
    }

    $targets = [
        'ИТОГО сверхурочных часов по ТК РФ' => 'КА',
        'ИТОГО часов работы в выходной день по ТК РФ' => 'КА',
        'ИТОГО часы для оплаты единовременной премией' => 'C&B',
    ];

    return (string)preg_replace_callback('/<tr\b[^>]*>.*?<\/tr>/isu', static function (array $matches) use ($targets) {
        $rowHtml = $matches[0];
        $rowText = trim(html_entity_decode(strip_tags($rowHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $isTargetRow = false;
        $marker = '';
        $targetText = '';
        foreach ($targets as $target => $targetMarker) {
            if (mb_stripos($rowText, $target) !== false) {
                $isTargetRow = true;
                $marker = $targetMarker;
                $targetText = $target;
                break;
            }
        }

        if (!$isTargetRow) {
            return $rowHtml;
        }

        $hoursText = str_replace($targetText, '', $rowText);
        preg_match_all('/-?\d+(?:[.,]\d+)?/u', $hoursText, $hoursMatches);
        $hasNonZeroHours = false;
        foreach (($hoursMatches[0] ?? []) as $rawValue) {
            $hours = (float)str_replace(',', '.', $rawValue);
            if ($hours != 0.0) {
                $hasNonZeroHours = true;
                break;
            }
        }

        if (!$hasNonZeroHours) {
            return $rowHtml;
        }

        if ($marker !== '' && $targetText !== '') {
            $replacement = $targetText . ' <span class="overtime-view-marker">' . htmlspecialcharsbx($marker) . '</span>';
            $rowHtml = str_replace($targetText, $replacement, $rowHtml);
        }

        $rowHtml = preg_replace_callback('/<(td|th)\b([^>]*)>/iu', static function (array $cellMatches) {
            $tag = $cellMatches[1];
            $attrs = $cellMatches[2];

            if (preg_match('/\bclass\s*=\s*"([^"]*)"/iu', $attrs, $classMatch)) {
                $updatedClasses = trim($classMatch[1] . ' overtime-view-highlight-cell');
                $attrs = preg_replace('/\bclass\s*=\s*"[^"]*"/iu', 'class="' . $updatedClasses . '"', $attrs, 1) ?? $attrs;
            } else {
                $attrs = trim($attrs . ' class="overtime-view-highlight-cell"');
            }

            return '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . '>';
        }, $rowHtml) ?? $rowHtml;

        if (preg_match('/\bclass\s*=\s*"([^"]*)"/iu', $rowHtml, $classMatch)) {
            $classes = trim($classMatch[1]);
            $newClassAttr = 'class="' . trim($classes . ' overtime-view-highlight-row') . '"';
            return preg_replace('/\bclass\s*=\s*"[^"]*"/iu', $newClassAttr, $rowHtml, 1) ?? $rowHtml;
        }

        return preg_replace('/^<tr\b/iu', '<tr class="overtime-view-highlight-row"', $rowHtml, 1) ?? $rowHtml;
    }, $html);
}

function overtimeExtractRequestIdFromDocumentId(string $documentId, int $iblockId): int
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return 0;
    }

    $patternByIblock = '/(?:^|_)' . preg_quote((string)$iblockId, '/') . '_([0-9]+)$/';
    if ($iblockId > 0 && preg_match($patternByIblock, $documentId, $matches)) {
        return (int)$matches[1];
    }

    if (preg_match('/([0-9]+)\D*$/', $documentId, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function overtimeGetDocumentIdCandidates(int $iblockId, int $requestId): array
{
    if ($iblockId <= 0 || $requestId <= 0) {
        return [];
    }

    return [
        ['lists', 'BizprocDocument', 'lists_' . $iblockId . '_' . $requestId],
        ['iblock', 'CIBlockDocument', 'iblock_' . $iblockId . '_' . $requestId],
        ['lists', 'Bitrix\Lists\BizprocDocumentLists', (string)$requestId],
    ];
}

function overtimeFindCurrentUserApprovalTask(int $requestId, int $userId, int $iblockId): ?array
{
    if ($requestId <= 0 || $userId <= 0 || $iblockId <= 0) {
        return null;
    }

    if (!Loader::includeModule('bizproc') || !class_exists('CBPTaskService')) {
        return null;
    }

    $select = ['ID', 'NAME', 'DOCUMENT_ID', 'WORKFLOW_ID', 'ACTIVITY_NAME', 'ACTIVITY', 'ACTIVITY_ID', 'USER_ID', 'USERS', 'PARAMETERS'];

    $checkTask = static function (array $task) use ($userId, $requestId, $iblockId): bool {
        if (!overtimeBizprocTaskIsForUser($task, $userId)) {
            return false;
        }

        $taskRequestId = overtimeExtractRequestIdFromDocumentId((string)($task['DOCUMENT_ID'] ?? ''), $iblockId);
        if ($taskRequestId !== $requestId) {
            return false;
        }
        return true;
    };

    foreach (overtimeGetDocumentIdCandidates($iblockId, $requestId) as $docIdCandidate) {
        $res = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            [
                'DOCUMENT_ID' => $docIdCandidate,
                'USER_STATUS' => CBPTaskUserStatus::Waiting,
            ],
            false,
            false,
            $select
        );

        while ($task = $res->GetNext()) {
            if ($checkTask($task)) {
                return $task;
            }
        }
    }

    // Fallback для нестандартных DOCUMENT_ID: сохраняем совместимость.
    $res = CBPTaskService::GetList(
        ['ID' => 'DESC'],
        [
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ],
        false,
        false,
        $select
    );

    while ($task = $res->GetNext()) {
        if ($checkTask($task)) {
            return $task;
        }
    }

    return null;
}

function overtimeBizprocTaskIsForUser(array $task, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $rawUserId = (string)($task['USER_ID'] ?? '');
    $scalarUserId = (int)preg_replace('/\D+/u', '', $rawUserId);
    if ($scalarUserId > 0) {
        return $scalarUserId === $userId;
    }

    $users = $task['USERS'] ?? null;
    if (is_string($users) && $users !== '') {
        $parts = preg_split('/[,\s;|]+/u', $users) ?: [];
        foreach ($parts as $part) {
            $normalized = (int)preg_replace('/\D+/u', '', (string)$part);
            if ($normalized === $userId) {
                return true;
            }
        }
    }

    if (is_array($users)) {
        foreach ($users as $value) {
            $normalized = (int)preg_replace('/\D+/u', '', (string)$value);
            if ($normalized === $userId) {
                return true;
            }
        }
    }

    return false;
}

function overtimeExtractTaskParameters($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $unserialized = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($unserialized)) {
        return $unserialized;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function overtimeDeepFindFirstString(array $haystack, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($haystack[$key]) && is_string($haystack[$key]) && trim($haystack[$key]) !== '') {
            return trim($haystack[$key]);
        }
    }

    foreach ($haystack as $value) {
        if (is_array($value)) {
            $found = overtimeDeepFindFirstString($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function overtimeGetTaskCaptions(array $task, string $defaultApprove = 'Согласовать', string $defaultReject = 'Отклонить'): array
{
    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? null);

    $approve = overtimeDeepFindFirstString($params, [
        'TaskButton1Message', 'APPROVE_BUTTON', 'ApproveButton', 'APPROVEBUTTON', 'YES_BUTTON', 'ApproveText', 'APPROVE_TEXT',
    ]);
    $reject = overtimeDeepFindFirstString($params, [
        'TaskButton2Message', 'NONAPPROVE_BUTTON', 'NonApproveButton', 'REJECT_BUTTON', 'RejectButton', 'DECLINE_BUTTON', 'DeclineButton', 'NO_BUTTON', 'REJECT_TEXT', 'NONAPPROVE_TEXT',
    ]);

    return [
        $approve !== null && $approve !== '' ? $approve : $defaultApprove,
        $reject !== null && $reject !== '' ? $reject : $defaultReject,
    ];
}

function overtimeFindTaskActionCodeByButtonCaption(int $taskId, string $caption): string
{
    if ($taskId <= 0 || $caption === '') {
        return '';
    }

    $controls = [];
    try {
        if (method_exists('CBPDocument', 'GetTaskControls')) {
            $controls = (array)CBPDocument::GetTaskControls($taskId);
        }
    } catch (\Throwable $e) {
    }

    if (empty($controls)) {
        try {
            if (method_exists('CBPTaskService', 'GetTaskControls')) {
                $controls = (array)CBPTaskService::GetTaskControls($taskId);
            }
        } catch (\Throwable $e) {
        }
    }

    $target = mb_strtolower(trim($caption), 'UTF-8');
    foreach ($controls as $controlCode => $controlData) {
        $label = '';
        if (is_array($controlData)) {
            $label = (string)($controlData['TEXT'] ?? $controlData['LABEL'] ?? $controlData['NAME'] ?? '');
        } elseif (is_string($controlData)) {
            $label = $controlData;
        }

        $label = mb_strtolower(trim($label), 'UTF-8');
        if ($label === '' || $label !== $target) {
            continue;
        }

        if (is_string($controlCode) && $controlCode !== '') {
            return $controlCode;
        }

        if (is_array($controlData)) {
            $code = trim((string)($controlData['NAME'] ?? $controlData['ID'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }
    }

    return '';
}

function overtimeGetTaskControlsByTaskId(int $taskId): array
{
    if ($taskId <= 0) {
        return [];
    }

    $controls = [];
    try {
        if (method_exists('CBPDocument', 'GetTaskControls')) {
            $controls = (array)CBPDocument::GetTaskControls($taskId);
        }
    } catch (\Throwable $e) {
    }

    if (!empty($controls)) {
        return $controls;
    }

    try {
        if (method_exists('CBPTaskService', 'GetTaskControls')) {
            $controls = (array)CBPTaskService::GetTaskControls($taskId);
        }
    } catch (\Throwable $e) {
    }

    return is_array($controls) ? $controls : [];
}

function overtimeNormalizeTaskButtonLabel(string $label): string
{
    return mb_strtolower(trim($label), 'UTF-8');
}

function overtimeDetectTaskButtonKind(string $code, string $label): string
{
    $haystack = overtimeNormalizeTaskButtonLabel($code . ' ' . $label);
    if (preg_match('/\b(approve|agree|accept|ok|yes|y|соглас)/u', $haystack)) {
        return 'approve';
    }

    if (preg_match('/\b(refine|доработ)/u', $haystack)) {
        return 'refine';
    }

    if (preg_match('/\b(nonapprove|reject|decline|deny|refuse|cancel|no|n|refine|доработ|отклон)/u', $haystack)) {
        return 'reject';
    }

    return 'default';
}

function overtimeGetTaskActionButtons(array $task): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [];
    }

    $activityName = overtimeNormalizeTaskButtonLabel((string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''));
    $hideRefineForActivity = $activityName === 'approvecopyactiveschedule';

    $controls = overtimeGetTaskControlsByTaskId($taskId);
    $buttons = [];
    $knownCodes = [];
    foreach ($controls as $controlCode => $controlData) {
        $code = is_string($controlCode) ? trim($controlCode) : '';
        $label = '';

        if (is_array($controlData)) {
            $label = trim((string)($controlData['TEXT'] ?? $controlData['LABEL'] ?? $controlData['NAME'] ?? ''));
            if ($code === '') {
                $code = trim((string)($controlData['NAME'] ?? $controlData['ID'] ?? ''));
            }
        } elseif (is_string($controlData)) {
            $label = trim($controlData);
        }

        if ($code === '' || $label === '') {
            continue;
        }

        $kind = overtimeDetectTaskButtonKind($code, $label);
        if ($hideRefineForActivity && strpos(overtimeNormalizeTaskButtonLabel($code . ' ' . $label), 'доработ') !== false) {
            continue;
        }

        $buttons[] = [
            'code' => $code,
            'label' => $label,
            'kind' => $kind,
        ];
        $knownCodes[overtimeNormalizeTaskButtonLabel($code)] = true;
    }

    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? []);
    $approveText = trim((string)($params['TaskButton1Message'] ?? ''));
    $rejectText = trim((string)($params['TaskButton2Message'] ?? ''));
    $refineText = trim((string)($params['TaskButton3Message'] ?? ''));
    $refineAllowed = !isset($params['RefineAllowed']) || (string)$params['RefineAllowed'] !== 'N';

    if (!isset($knownCodes['approve'])) {
        $buttons[] = [
            'code' => 'approve',
            'label' => $approveText !== '' ? $approveText : 'Согласовать',
            'kind' => 'approve',
        ];
        $knownCodes['approve'] = true;
    }

    if (!isset($knownCodes['nonapprove'])) {
        $buttons[] = [
            'code' => 'nonapprove',
            'label' => $rejectText !== '' ? $rejectText : 'Отклонить',
            'kind' => 'reject',
        ];
        $knownCodes['nonapprove'] = true;
    }

    if (!$hideRefineForActivity && $refineAllowed && !isset($knownCodes['refine'])) {
        $buttons[] = [
            'code' => 'refine',
            'label' => $refineText !== '' ? $refineText : 'Доработка',
            'kind' => 'refine',
        ];
    }

    if (!empty($buttons)) {
        return $buttons;
    }

    [$approveCaption, $rejectCaption] = overtimeGetTaskCaptions($task, 'Согласовать', 'Отклонить');
    return [
        ['code' => 'approve', 'label' => $approveCaption, 'kind' => 'approve'],
        ['code' => 'nonapprove', 'label' => $rejectCaption, 'kind' => 'reject'],
    ];
}



function overtimeFindTaskParamValue(array $params, array $keys)
{
    $normalizedKeys = array_map(static fn($key) => mb_strtolower((string)$key, 'UTF-8'), $keys);
    foreach ($params as $key => $value) {
        if (in_array(mb_strtolower((string)$key, 'UTF-8'), $normalizedKeys, true)) {
            return $value;
        }
    }
    foreach ($params as $value) {
        if (is_array($value)) {
            $found = overtimeFindTaskParamValue($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

function overtimeParamString(array $params, array $keys): string
{
    $value = overtimeFindTaskParamValue($params, $keys);
    return is_scalar($value) ? trim((string)$value) : '';
}

function overtimeLooksLikeRequestedInformationField(array $field): bool
{
    $keys = array_change_key_case($field, CASE_LOWER);
    return isset($keys['name']) && (isset($keys['type']) || isset($keys['title']));
}

function overtimeIsRequestInformationOptionalTask(array $task): bool
{
    $activityName = overtimeNormalizeTaskButtonLabel((string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? $task['ACTIVITY_ID'] ?? ''));
    if (strpos($activityName, 'requestinformationoptionalactivity') !== false) {
        return true;
    }

    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? []);
    if (overtimeParamString($params, ['TaskButtonCancelMessage', 'task_button_cancel_message']) !== '') {
        return true;
    }

    return !empty(overtimeGetRequestedInformationFields($task));
}

function overtimeGetRequestedInformationFields(array $task): array
{
    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? []);
    $fields = overtimeFindTaskParamValue($params, [
        'RequestedInformation', 'requested_information', 'REQUESTED_INFORMATION',
        'Fields', 'fields', 'Parameters', 'parameters',
    ]);

    if (!is_array($fields)) {
        $fields = [];
    }

    $result = [];
    foreach ($fields as $field) {
        if (!is_array($field) || !overtimeLooksLikeRequestedInformationField($field)) {
            continue;
        }
        $keys = array_change_key_case($field, CASE_LOWER);
        $name = trim((string)($keys['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $normalized = $field;
        $normalized['Name'] = $name;
        $normalized['Title'] = trim((string)($keys['title'] ?? $name));
        $normalized['Type'] = trim((string)($keys['type'] ?? 'string')) ?: 'string';
        $normalized['Description'] = trim((string)($keys['description'] ?? ''));
        $normalized['Required'] = strtoupper((string)($keys['required'] ?? 'N')) === 'Y' ? 'Y' : 'N';
        $normalized['Multiple'] = strtoupper((string)($keys['multiple'] ?? 'N')) === 'Y' ? 'Y' : 'N';
        $normalized['Options'] = $field['Options'] ?? $field['options'] ?? $field['OptionsText'] ?? $field['optionstext'] ?? [];
        $normalized['Default'] = $field['Default'] ?? $field['default'] ?? '';
        $result[] = $normalized;
    }

    return $result;
}

function overtimeGetTaskActionButtonsUniversal(array $task): array
{
    if (!overtimeIsRequestInformationOptionalTask($task)) {
        return overtimeGetTaskActionButtons($task);
    }

    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? []);
    $approveText = overtimeParamString($params, ['TaskButtonMessage', 'task_button_message', 'TaskButton1Message']);
    $cancelText = overtimeParamString($params, ['TaskButtonCancelMessage', 'task_button_cancel_message']);

    return [
        ['code' => 'approve', 'label' => $approveText !== '' ? $approveText : 'Согласовать', 'kind' => 'approve'],
        ['code' => 'cancel', 'label' => $cancelText !== '' ? $cancelText : 'Отклонить', 'kind' => 'reject'],
    ];
}

function overtimeGetPostedBizprocFields(array $fields, \Bitrix\Main\HttpRequest $request): array
{
    $posted = (array)$request->getPost('bp_fields');
    $result = [];
    foreach ($fields as $field) {
        $name = (string)$field['Name'];
        $value = $posted[$name] ?? null;
        if (($field['Multiple'] ?? 'N') === 'Y') {
            if (is_string($value)) {
                $value = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value) ?: []), static fn($v) => $v !== ''));
            } elseif (!is_array($value)) {
                $value = $value === null ? [] : [$value];
            }
        }
        $result[$name] = $value;
    }
    return $result;
}

function overtimeValidateRequestedInformationFields(array $fields, array $values): ?string
{
    foreach ($fields as $field) {
        if (($field['Required'] ?? 'N') !== 'Y') {
            continue;
        }
        $name = (string)$field['Name'];
        $value = $values[$name] ?? null;
        $empty = is_array($value) ? count(array_filter($value, static fn($v) => trim((string)$v) !== '')) === 0 : trim((string)$value) === '';
        if ($empty) {
            return 'Поле "' . (string)$field['Title'] . '" обязательно для заполнения.';
        }
    }
    return null;
}

function overtimeRenderRequestedInformationField(array $field, $value = null): string
{
    $name = (string)$field['Name'];
    $htmlName = 'bp_fields[' . $name . ']';
    $type = mb_strtolower((string)$field['Type'], 'UTF-8');
    $multiple = ($field['Multiple'] ?? 'N') === 'Y';
    $valueString = is_array($value) ? implode("\n", array_map('strval', $value)) : (string)($value ?? ($field['Default'] ?? ''));
    $label = overtimeH((string)$field['Title']) . (($field['Required'] ?? 'N') === 'Y' ? ' <span class="overtime-view-field-required">*</span>' : '');
    $description = trim((string)($field['Description'] ?? ''));
    $out = '<div class="overtime-view-field"><label for="bp-field-' . overtimeH($name) . '">' . $label . '</label>';
    if ($description !== '') {
        $out .= '<div class="overtime-view-field-description">' . nl2br(overtimeH($description)) . '</div>';
    }

    if ($multiple) {
        $out .= '<textarea id="bp-field-' . overtimeH($name) . '" name="' . overtimeH($htmlName) . '" placeholder="Каждое значение с новой строки">' . overtimeH($valueString) . '</textarea>';
    } elseif (in_array($type, ['text', 'textarea'], true)) {
        $out .= '<textarea id="bp-field-' . overtimeH($name) . '" name="' . overtimeH($htmlName) . '">' . overtimeH($valueString) . '</textarea>';
    } elseif (in_array($type, ['select', 'list'], true) && !empty($field['Options']) && is_array($field['Options'])) {
        $out .= '<select id="bp-field-' . overtimeH($name) . '" name="' . overtimeH($htmlName) . '"><option value=""></option>';
        foreach ($field['Options'] as $optionValue => $optionLabel) {
            $selected = (string)$optionValue === $valueString ? ' selected' : '';
            $out .= '<option value="' . overtimeH((string)$optionValue) . '"' . $selected . '>' . overtimeH((string)$optionLabel) . '</option>';
        }
        $out .= '</select>';
    } elseif (in_array($type, ['bool', 'boolean'], true)) {
        $checked = in_array(mb_strtolower($valueString, 'UTF-8'), ['y', 'yes', '1', 'true', 'да'], true) ? ' checked' : '';
        $out .= '<input type="hidden" name="' . overtimeH($htmlName) . '" value="N"><label style="font-weight:400"><input type="checkbox" id="bp-field-' . overtimeH($name) . '" name="' . overtimeH($htmlName) . '" value="Y"' . $checked . '> Да</label>';
    } else {
        $inputType = in_array($type, ['int', 'integer', 'double', 'float'], true) ? 'number' : (in_array($type, ['date'], true) ? 'date' : (in_array($type, ['datetime', 'datetime-local'], true) ? 'datetime-local' : 'text'));
        $out .= '<input type="' . $inputType . '" id="bp-field-' . overtimeH($name) . '" name="' . overtimeH($htmlName) . '" value="' . overtimeH($valueString) . '">';
    }

    return $out . '</div>';
}


function overtimeGetTaskDescriptionForForm(array $task, array $params): string
{
    $description = overtimeParamString($params, [
        'DescriptionForForm', 'description_for_form', 'TaskDescription', 'task_description',
        'RequestedDescription', 'requested_description', 'Description', 'description',
    ]);
    return trim(str_replace('Текст задания для формы', '', $description));
}



function overtimeEnsureRequestInformationOptionalActivityLoaded(): bool
{
    if (class_exists('CBPRequestInformationOptionalActivity')) {
        return true;
    }
    if (!Loader::includeModule('bizproc') || !class_exists('CBPRuntime')) {
        return false;
    }

    try {
        $file = __DIR__ . '/requestinformationoptionalactivity/requestinformationoptionalactivity.php';
        if (is_file($file)) {
            require_once $file;
        }
    } catch (\Throwable $e) {
        return false;
    }

    return class_exists('CBPRequestInformationOptionalActivity');
}

function overtimeGetNativeTaskFormHtml(array $task, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    try {
        $userName = '';
        if (isset($GLOBALS['USER']) && is_object($GLOBALS['USER']) && method_exists($GLOBALS['USER'], 'GetFormattedName')) {
            $userName = (string)$GLOBALS['USER']->GetFormattedName(false);
        }

        if (overtimeIsRequestInformationOptionalTask($task) && overtimeEnsureRequestInformationOptionalActivityLoaded()) {
            [$form] = CBPRequestInformationOptionalActivity::ShowTaskForm($task, $userId, $userName, null);
            return trim((string)$form);
        }

        if (class_exists('CBPActivity') && method_exists('CBPActivity', 'ShowTaskForm')) {
            $result = CBPActivity::ShowTaskForm($task, $userId, $userName, null);
            if (is_array($result)) {
                return trim((string)($result[0] ?? ''));
            }
        }
    } catch (\Throwable $e) {
        return '';
    }

    return '';
}

function overtimeGetTaskPostFields(\Bitrix\Main\HttpRequest $request): array
{
    $result = [];
    foreach ((array)$request->getPostList()->toArray() as $key => $value) {
        $key = (string)$key;
        if (in_array($key, ['sessid', 'bp_action', 'bp_comment', 'bp_fields'], true)) {
            continue;
        }
        $result[$key] = $value;
    }
    return $result;
}

function overtimeTaskIsRunning(int $taskId): bool
{
    if ($taskId <= 0 || !class_exists('CBPTaskService')) {
        return false;
    }

    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    if (!is_object($res)) {
        return false;
    }

    $task = $res->GetNext();
    if (!$task) {
        return false;
    }

    return (int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running;
}

function overtimeFlattenBizprocErrors(array $errors): string
{
    $messages = [];
    foreach ($errors as $error) {
        if (is_string($error)) {
            $message = trim($error);
            if ($message !== '') {
                $messages[] = $message;
            }
            continue;
        }

        if (is_array($error)) {
            $message = trim((string)($error['message'] ?? $error['MESSAGE'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
    }

    $messages = array_values(array_unique($messages));
    return implode(' ', $messages);
}

function overtimeCompleteBizprocTask(array $task, int $userId, string $action = 'approve', string $comment = '', array $fields = []): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0 || $userId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректные входные данные для завершения задачи БП.'];
    }

    $errors = [];
    $aliases = [
        'yes' => 'approve', 'ok' => 'approve',
        'no' => 'nonapprove',
        'reject' => 'nonapprove', 'decline' => 'nonapprove',
    ];
    $rawCode = trim($action);
    if ($rawCode === '') {
        $rawCode = 'approve';
    }
    $code = strtolower($rawCode);
    if (isset($aliases[$code])) {
        $code = $aliases[$code];
        $rawCode = $code;
    }

    $validationError = overtimeValidateCommentByTaskParameters($task, $code, $comment);
    if ($validationError !== null) {
        return ['OK' => false, 'ERROR' => $validationError];
    }

    $rawTaskFields = [];
    $responseFields = [];
    foreach ($fields as $fieldName => $fieldValue) {
        $fieldName = (string)$fieldName;
        if ($fieldName === 'task_comment' || strpos($fieldName, 'bprioact_') === 0) {
            $rawTaskFields[$fieldName] = $fieldValue;
        } else {
            $responseFields[$fieldName] = $fieldValue;
        }
    }

    $baseFields = $rawTaskFields + [
        'USER_ID' => $userId,
        'REAL_USER_ID' => $userId,
        'COMMENT' => $comment,
        'task_comment' => $comment,
    ];
    if (!empty($responseFields)) {
        $baseFields['fields'] = $responseFields;
    }
    $requests = [];
    if ($code === 'approve') {
        $requests[] = $baseFields + ['approve' => 'Y', 'ACTION' => 'approve'];
    } elseif ($code === 'nonapprove') {
        $requests[] = $baseFields + ['nonapprove' => 'Y', 'ACTION' => 'nonapprove'];
    } elseif ($code === 'cancel') {
        $requests[] = $baseFields + ['cancel' => 'Y', 'ACTION' => 'cancel', 'INLINE_USER_STATUS' => CBPTaskUserStatus::Cancel];
    } elseif ($code === 'refine') {
        $requests[] = $baseFields + ['refine' => 'Y', 'REFINE' => 'Y', 'nonapprove' => 'Y', 'ACTION' => 'refine'];
        $requests[] = $baseFields + ['refine' => 'Y', 'REFINE' => 'Y', 'nonapprove' => 'Y', 'ACTION' => 'nonapprove'];
    } else {
        $requests[] = $baseFields + [$rawCode => 'Y', 'ACTION' => $rawCode];
    }

    foreach ($requests as $requestFields) {
        try {
            if (method_exists('CBPDocument', 'PostTaskForm')) {
                $tmpErr = [];
                CBPDocument::PostTaskForm($taskId, $userId, $requestFields, $tmpErr, '', $userId);
                if (!empty($tmpErr)) {
                    $errors = array_merge($errors, $tmpErr);
                }
                if (!overtimeTaskIsRunning($taskId)) {
                    return ['OK' => true, 'ERROR' => ''];
                }
            }
        } catch (\Throwable $e) {
            $errors[] = ['message' => $e->getMessage()];
        }
    }

    try {
        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activity = (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? '');
        if ($workflowId !== '' && $activity !== '' && class_exists('CBPRuntime') && method_exists('CBPRuntime', 'SendExternalEvent')) {
            $payload = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
            ];
            if (!empty($responseFields)) {
                $payload['RESPONCE'] = $responseFields;
            }
            if ($code === 'approve') {
                $payload['APPROVE'] = true;
            } else {
                $payload['APPROVE'] = false;
                if ($code === 'refine') {
                    $payload['REFINE'] = 'Y';
                }
                if ($code === 'cancel') {
                    $payload['CANCEL'] = true;
                }
            }

            CBPRuntime::SendExternalEvent($workflowId, $activity, $payload);
            if (!overtimeTaskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    try {
        if (method_exists('CBPTaskService', 'DoTask')) {
            $doTaskFields = ['ACTION' => $rawCode, $rawCode => 'Y', 'COMMENT' => $comment, 'task_comment' => $comment];
            foreach ($rawTaskFields as $fieldName => $fieldValue) {
                $doTaskFields[$fieldName] = $fieldValue;
            }
            if (!empty($responseFields)) {
                $doTaskFields['fields'] = $responseFields;
            }
            CBPTaskService::DoTask($taskId, $userId, $doTaskFields);
            if (!overtimeTaskIsRunning($taskId)) {
                return ['OK' => true, 'ERROR' => ''];
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    $flatError = overtimeFlattenBizprocErrors($errors);
    if ($flatError === '') {
        $flatError = 'Задание осталось активным после всех попыток завершения.';
    }

    return ['OK' => false, 'ERROR' => $flatError];
}

function overtimeValidateCommentByTaskParameters(array $task, string $action, string $comment): ?string
{
    $params = overtimeExtractTaskParameters($task['PARAMETERS'] ?? []);
    $showComment = (string)($params['ShowComment'] ?? 'N');
    $commentRequired = (string)($params['CommentRequired'] ?? 'N');

    if ($showComment !== 'Y') {
        return null;
    }

    $commentEmpty = trim($comment) === '';
    if (!$commentEmpty) {
        return null;
    }

    $isApprove = (bool)preg_match('/\b(approve|agree|accept|ok|yes|y|соглас)/u', overtimeNormalizeTaskButtonLabel($action));
    $isNonApprove = (bool)preg_match('/\b(nonapprove|reject|decline|deny|refuse|cancel|no|n|refine|доработ|отклон)/u', overtimeNormalizeTaskButtonLabel($action));
    $mustFill = $commentRequired === 'Y'
        || ($commentRequired === 'YA' && $isApprove)
        || ($commentRequired === 'YR' && $isNonApprove);

    if (!$mustFill) {
        return null;
    }

    $label = trim((string)($params['CommentLabelMessage'] ?? ''));
    if ($label === '') {
        $label = 'Комментарий';
    }

    return 'Поле "' . $label . '" обязательно для выбранного действия.';
}

function overtimeRenderTextWithLinks(string $text): string
{
    $text = str_replace('Текст задания для формы', '', $text);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $formatPlainText = static function (string $value): string {
        $escaped = overtimeH($value);
        return str_ireplace(['[b]', '[/b]'], ['<strong>', '</strong>'], $escaped);
    };

    $pattern = '/https?:\/\/[^\s<>"\']+/iu';
    if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        return nl2br($formatPlainText($text));
    }

    $result = '';
    $cursor = 0;
    foreach ($matches[0] as $matchData) {
        [$rawUrl, $offset] = $matchData;
        $offset = (int)$offset;

        if ($offset > $cursor) {
            $result .= $formatPlainText(substr($text, $cursor, $offset - $cursor));
        }

        $url = $rawUrl;
        $suffix = '';
        while ($url !== '' && preg_match('/[.,;:!?)]+$/u', $url)) {
            $suffix = substr($url, -1) . $suffix;
            $url = substr($url, 0, -1);
        }

        if ($url !== '') {
            $safeUrl = overtimeH($url);
            $result .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
        }
        if ($suffix !== '') {
            $result .= $formatPlainText($suffix);
        }

        $cursor = $offset + strlen($rawUrl);
    }

    $tail = substr($text, $cursor);
    if ($tail !== '') {
        $result .= $formatPlainText($tail);
    }

    return nl2br($result);
}


function overtimeFormatRequestDateOnly($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/(\d{2}\.\d{2}\.\d{4})/', $value, $matches)) {
        return $matches[1];
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
        return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
    }

    return $value;
}


function overtimeBuildRequestDatePeriodText(array $row): string
{
    $start = overtimeFormatRequestDateOnly($row['work_start_date'] ?? '');
    $end = overtimeFormatRequestDateOnly($row['work_end_date'] ?? '');

    if ($start === '' && $end === '') {
        return '';
    }

    if ($start === $end || $end === '') {
        return $start;
    }

    if ($start === '') {
        return $end;
    }

    return $start . ' - ' . $end;
}

$viewData = overtimeGetRequestViewData($requestId, $overtimeConfig);
$allLinkedRequestIds = $viewData ? overtimeCollectAllLinkedRequestIds((int)$viewData['id'], $overtimeConfig) : [];
$linkedCalculations = $viewData ? overtimeGetLinkedRequestCalculations($allLinkedRequestIds, $overtimeConfig) : [];
$groupCalculations = $viewData ? overtimeGetGroupRequestCalculations((array)($viewData['group_ids'] ?? []), (int)$viewData['id'], $overtimeConfig) : [];
$currentUserId = (int)($GLOBALS['USER']->GetID() ?? 0);
$approvalTask = null;
$approvalButtons = [];
$requestedInformationFields = [];
$postedRequestedInformationValues = [];
$bpNativeTaskFormHtml = '';
$bpActionError = '';
$bpCommentLabel = 'Комментарий';
$bpDescriptionForForm = '';
$bpTaskTitle = 'Согласование заявки';

if ($viewData && $currentUserId > 0) {
    $approvalTask = overtimeFindCurrentUserApprovalTask($viewData['id'], $currentUserId, (int)$overtimeConfig['IBLOCK_REQUESTS']);
    if ($approvalTask) {
        $taskParams = overtimeExtractTaskParameters($approvalTask['PARAMETERS'] ?? []);
        $bpCommentLabel = overtimeParamString($taskParams, ['CommentLabelMessage', 'comment_label_message']) ?: 'Комментарий';
        $bpDescriptionForForm = overtimeGetTaskDescriptionForForm($approvalTask, $taskParams);
        $bpTaskTitle = trim((string)($approvalTask['NAME'] ?? '')) ?: $bpDescriptionForForm ?: 'Согласование заявки';
        $approvalButtons = overtimeGetTaskActionButtonsUniversal($approvalTask);
        $requestedInformationFields = overtimeGetRequestedInformationFields($approvalTask);
        $bpNativeTaskFormHtml = overtimeIsRequestInformationOptionalTask($approvalTask) ? overtimeGetNativeTaskFormHtml($approvalTask, $currentUserId) : '';
    }
}

if (
    $approvalTask
    && $request->isPost()
    && check_bitrix_sessid()
) {
    $postAction = trim((string)$request->getPost('bp_action'));
    $allowedActions = array_column($approvalButtons, 'code');
    if ($postAction !== '' && in_array($postAction, $allowedActions, true)) {
        $bpComment = trim((string)$request->getPost('bp_comment'));
        $completionAction = $postAction;
        $postedRequestedInformationValues = overtimeGetPostedBizprocFields($requestedInformationFields, $request);
        $nativePostFields = overtimeGetTaskPostFields($request);
        $postedTaskValues = $nativePostFields + $postedRequestedInformationValues;
        $fieldsError = ($completionAction === 'cancel') ? null : overtimeValidateRequestedInformationFields($requestedInformationFields, $postedRequestedInformationValues);
        $completionResult = $fieldsError === null
            ? overtimeCompleteBizprocTask($approvalTask, $currentUserId, $completionAction, $bpComment, $postedTaskValues)
            : ['OK' => false, 'ERROR' => $fieldsError];

        if (!empty($completionResult['OK'])) {
            LocalRedirect(Application::getInstance()->getContext()->getRequest()->getRequestUri());
        } else {
            $bpActionError = (string)($completionResult['ERROR'] ?? 'Не удалось выполнить задание бизнес-процесса.');
        }
    }
}

if ($viewData && $currentUserId > 0) {
    $approvalTask = overtimeFindCurrentUserApprovalTask($viewData['id'], $currentUserId, (int)$overtimeConfig['IBLOCK_REQUESTS']);
    if ($approvalTask) {
        $taskParams = overtimeExtractTaskParameters($approvalTask['PARAMETERS'] ?? []);
        $bpCommentLabel = overtimeParamString($taskParams, ['CommentLabelMessage', 'comment_label_message']) ?: 'Комментарий';
        $bpDescriptionForForm = overtimeGetTaskDescriptionForForm($approvalTask, $taskParams);
        $bpTaskTitle = trim((string)($approvalTask['NAME'] ?? '')) ?: $bpDescriptionForForm ?: 'Согласование заявки';
        $approvalButtons = overtimeGetTaskActionButtonsUniversal($approvalTask);
        $requestedInformationFields = overtimeGetRequestedInformationFields($approvalTask);
        $bpNativeTaskFormHtml = overtimeIsRequestInformationOptionalTask($approvalTask) ? overtimeGetNativeTaskFormHtml($approvalTask, $currentUserId) : '';
        if (!$request->isPost() || $bpActionError === '') {
            $postedRequestedInformationValues = [];
        }
    }
}


require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Универсальный просмотр и выполнение задания');
$viewMode = trim((string)$request->getQuery('mode')) === 'extended' ? 'extended' : 'simple';
?>
<style>.overtime-view-field{margin:12px 0}.overtime-view-field label{display:block;font-weight:600;margin-bottom:5px}.overtime-view-field-description{font-size:12px;color:#586069;margin-bottom:5px}.overtime-view-field input[type=text],.overtime-view-field input[type=number],.overtime-view-field input[type=date],.overtime-view-field input[type=datetime-local],.overtime-view-field textarea,.overtime-view-field select{width:30%;min-width:280px;border:1px solid #cfd7df;border-radius:6px;padding:8px;font-size:14px}.overtime-view-field textarea{min-height:74px;resize:vertical}.overtime-view-field-required{color:#d1242f}.overtime-view-wrap{max-width:1280px;margin:0 auto}.overtime-view-box{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px;margin-bottom:20px}.overtime-view-modes{display:flex;gap:10px;margin-bottom:16px}.overtime-view-mode-link{display:inline-block;padding:8px 12px;border:1px solid #cfd7df;border-radius:6px;text-decoration:none;color:#1f2937;background:#fff}.overtime-view-mode-link.active{background:#1f6feb;border-color:#1f6feb;color:#fff}.overtime-simple-table{width:100%;border-collapse:collapse}.overtime-simple-table th,.overtime-simple-table td{border:1px solid #e4e8ee;padding:8px 10px}.overtime-simple-table th{background:#f8fafc}.overtime-view-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-bottom:14px}.overtime-view-meta-item{padding:10px;border:1px solid #e4e8ee;border-radius:6px;background:#f8fafc}.overtime-view-calc{border:1px solid #e4e8ee;border-radius:6px;padding:12px;background:#fff;overflow:auto;margin-bottom:12px}.overtime-view-linked{margin-top:12px}.overtime-view-separator{margin:20px 0 12px;border:0;border-top:1px solid #dfe3e8}.overtime-view-linked details{margin-bottom:10px;border:1px solid #e4e8ee;border-radius:6px;padding:8px;background:#fbfcfe}.status-pill{display:inline-block;padding:4px 10px;border-radius:999px;color:#fff;font-size:12px;font-weight:600}.status-success{background:#28a745}.status-danger{background:#dc3545}.status-warning{background:#f0ad4e}.status-info{background:#17a2b8}.status-default{background:#6c757d}.overtime-view-marker{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:10px;background:#d1242f;color:#fff;font-size:11px;font-weight:700;letter-spacing:.2px}.overtime-view-approval{border:1px solid #b5e7f5;border-radius:10px;padding:16px;background:#E8F9FE;margin-top:20px;box-shadow:0 2px 12px rgba(99,154,176,.12)}.overtime-view-approval-title{font-size:16px;margin-bottom:10px;font-weight:600}.overtime-view-approval-description{padding:12px;border:1px solid #b5e7f5;border-radius:6px;background:#E8F9FE;white-space:normal;line-height:1.45}.overtime-view-approval-comment textarea{width:30%;min-height:74px;resize:vertical;border:1px solid #cfd7df;border-radius:6px;padding:8px;font-size:14px}.overtime-view-approval-actions{display:flex;gap:10px;flex-wrap:wrap}.overtime-btn{display:inline-block;padding:10px 14px;border:1px solid #cfd7df;border-radius:6px;background:#fff;text-decoration:none;color:#1f2937;cursor:pointer}.overtime-btn.overtime-btn-success{background:#2ea043 !important;border-color:#2ea043 !important;color:#fff !important}.overtime-btn.overtime-btn-danger{background:#d1242f !important;border-color:#d1242f !important;color:#fff !important}.overtime-btn.overtime-btn-warning{background:#f28c28 !important;border-color:#f28c28 !important;color:#fff !important}</style>
<div class='overtime-view-wrap'><div class='overtime-view-box'>
<div class='overtime-view-modes'><a class='overtime-view-mode-link <?= $viewMode === "simple" ? "active" : "" ?>' href='?id=<?= (int)$requestId ?>&mode=simple'>Краткое описание</a><a class='overtime-view-mode-link <?= $viewMode === "extended" ? "active" : "" ?>' href='?id=<?= (int)$requestId ?>&mode=extended'>Детальное описание</a></div>
<?php if ($viewData): ?>
<?php if ($viewMode === 'simple'): ?>
<?php
$employeeId = (int)($viewData['employee_id'] ?? 0);
if ($employeeId <= 0) {
    $employeeRes = CIBlockElement::GetList([], ['IBLOCK_ID' => (int)$overtimeConfig['IBLOCK_REQUESTS'], 'ID' => (int)$viewData['id']], false, false, ['ID', 'PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE']]);
    if ($employeeItem = $employeeRes->Fetch()) {
        $employeeId = (int)($employeeItem['PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
    }
}
$employeeData = $employeeId > 0 ? (CUser::GetByID($employeeId)->Fetch() ?: []) : [];
$position = trim((string)($employeeData['WORK_POSITION'] ?? ''));
$department = trim((string)($employeeData['WORK_DEPARTMENT'] ?? ''));
?>
<div><b>ФИО сотрудника:</b> <?= overtimeH($viewData['employee_name']) ?></div>
<div><b>Должность:</b> <?= overtimeH($position !== '' ? $position : 'Не указана') ?></div>
<div><b>Подразделение:</b> <?= overtimeH($department !== '' ? $department : 'Не указано') ?></div>
<div style="margin-bottom:10px;"><b>ФИО инициатора заявки:</b> <?= overtimeH($viewData['initiator_name']) ?></div>
<div style="margin-bottom:10px;"><b>Обоснование:</b> <?= nl2br(overtimeH((string)$viewData['justification'])) ?></div>
<?php $isDutyView = !empty($viewData['is_duty']); ?>
<?php if ($isDutyView): ?>
<table class='overtime-simple-table'><tr><th>ID</th><th>Статус</th><th>Тип заявки</th><th>Дата начала</th><th>Дата окончания</th></tr>
<?php $rows = array_merge([['id'=>$viewData['id'],'work_type_name'=>$viewData['work_type_name'],'work_start_date'=>$viewData['work_start_date'] ?? '','work_end_date'=>$viewData['work_end_date'] ?? '','status_name'=>$viewData['status_name'] ?? '','status_id'=>$viewData['status_id'] ?? 0]], $linkedCalculations); foreach ($rows as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)($row['status_name'] ?? ''))) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($row['status_id'] ?? 0))) ?>'><?= overtimeH((string)($row['status_name'] ?? '')) ?></span></td><td><?= overtimeH((string)($row['work_type_name'] ?? '')) ?></td><td><?= overtimeH(overtimeFormatRequestDateOnly($row['work_start_date'] ?? '')) ?></td><td><?= overtimeH(overtimeFormatRequestDateOnly($row['work_end_date'] ?? '')) ?></td></tr><?php endforeach; ?></table>
<?php else: ?>
<table class='overtime-simple-table'><tr><th>ID</th><th>Статус</th><th>Тип заявки</th><th>Начало</th><th>Окончание</th><th>Часы премией <span class='overtime-view-marker'>C&B</span></th><th>Часы бухгалтерией <span class='overtime-view-marker'>КА</span></th><th>Тип оплаты</th></tr>
<?php $rows = array_merge([['id'=>$viewData['id'],'work_type_name'=>$viewData['work_type_name'],'work_period_text'=>$viewData['work_period_text'],'payment_type_name'=>$viewData['payment_type_name'],'total_premium_hours'=>$viewData['total_premium_hours'] ?? '0','total_ot_hours'=>$viewData['total_ot_hours'] ?? '0','status_name'=>$viewData['status_name'] ?? '','status_id'=>$viewData['status_id'] ?? 0]], $linkedCalculations); foreach ($rows as $row): preg_match('/(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}).*(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2})/u', (string)($row['work_period_text'] ?? ''), $m); ?><tr><td><?= (int)$row['id'] ?></td><td><span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)($row['status_name'] ?? ''))) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($row['status_id'] ?? 0))) ?>'><?= overtimeH((string)($row['status_name'] ?? '')) ?></span></td><td><?= overtimeH((string)($row['work_type_name'] ?? '')) ?></td><td><?= overtimeH((string)($m[1] ?? '')) ?></td><td><?= overtimeH((string)($m[2] ?? '')) ?></td><td><?= overtimeH((string)($row['total_premium_hours'] ?? '0')) ?></td><td><?= overtimeH((string)($row['total_ot_hours'] ?? '0')) ?></td><td><?= overtimeH((string)($row['payment_type_name'] ?? '')) ?></td></tr><?php endforeach; ?></table>
<?php endif; ?>
<?php else: ?>
<div class='overtime-view-meta'>
<div class='overtime-view-meta-item'><b>Статус заявки:</b> <span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)$viewData['status_name'])) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($viewData['status_id'] ?? 0))) ?>'><?= overtimeH((string)$viewData['status_name']) ?></span></div>
<div class='overtime-view-meta-item'><b>Сотрудник:</b> <?= overtimeH($viewData['employee_name']) ?></div>
<div class='overtime-view-meta-item'><b>Тип заявки и период работ:</b> <?= overtimeH((string)$viewData['work_type_name']) ?> <?= overtimeH(!empty($viewData['is_duty']) ? overtimeBuildRequestDatePeriodText($viewData) : (string)$viewData['work_period_text']) ?></div>
<?php if (empty($viewData['is_duty'])): ?><div class='overtime-view-meta-item'><b>Тип оплаты:</b> <?= overtimeH((string)$viewData['payment_type_name']) ?></div><?php endif; ?>
<div class='overtime-view-meta-item'><b>Инициатор:</b> <?= overtimeH($viewData['initiator_name']) ?></div>
<div class='overtime-view-meta-item'><b>Обоснование:</b> <?= nl2br(overtimeH((string)$viewData['justification'])) ?></div>
</div>
<div class='overtime-view-calc'><?= overtimeHighlightCalculationRows((string)$viewData['calculation_html']) ?></div>
<?php foreach ($linkedCalculations as $linked): ?>
<details>
<summary>#<?= (int)$linked['id'] ?> — <?= overtimeH((string)$linked['employee_name']) ?></summary>
<div class='overtime-view-meta'>
<div class='overtime-view-meta-item'><b>Статус заявки:</b> <span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)$linked['status_name'])) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($linked['status_id'] ?? 0))) ?>'><?= overtimeH((string)$linked['status_name']) ?></span></div>
<div class='overtime-view-meta-item'><b>Сотрудник:</b> <?= overtimeH((string)$linked['employee_name']) ?></div>
<div class='overtime-view-meta-item'><b>Тип заявки и период работ:</b> <?= overtimeH((string)($linked['work_type_name'] ?? '')) ?> <?= overtimeH(!empty($viewData['is_duty']) ? overtimeBuildRequestDatePeriodText($linked) : (string)($linked['work_period_text'] ?? '')) ?></div>
<?php if (empty($viewData['is_duty'])): ?><div class='overtime-view-meta-item'><b>Тип оплаты:</b> <?= overtimeH((string)($linked['payment_type_name'] ?? '')) ?></div><?php endif; ?>
</div>
<div class='overtime-view-calc'><?= overtimeHighlightCalculationRows((string)$linked['calculation_html']) ?></div>
</details>
<?php endforeach; ?>
<?php endif; ?>
<?php else: ?><div>Заявка с ID <?= (int)$requestId ?> не найдена.</div><?php endif; ?>
<?php if ($approvalTask): ?><div class='overtime-view-approval'><div class='overtime-view-approval-title'><?= overtimeH($bpTaskTitle) ?></div><?php if ($bpActionError !== ''): ?><div class='ui-alert ui-alert-danger' style='margin-bottom:10px;'><span class='ui-alert-message'><?= overtimeH($bpActionError) ?></span></div><?php endif; ?><form method='post' style='margin:0;'><?= bitrix_sessid_post() ?><?php if ($bpNativeTaskFormHtml !== ''): ?><div class='overtime-view-approval-native'><?= $bpNativeTaskFormHtml ?></div><?php else: ?><?php if ($bpDescriptionForForm !== ''): ?><div class='overtime-view-approval-comment'><div class='overtime-view-approval-description'><?= overtimeRenderTextWithLinks($bpDescriptionForForm) ?></div></div><?php endif; ?><?php foreach ($requestedInformationFields as $requestedField): ?><?= overtimeRenderRequestedInformationField($requestedField, $postedRequestedInformationValues[$requestedField['Name']] ?? null) ?><?php endforeach; ?><div class='overtime-view-approval-comment'><div style='margin-bottom:6px;'><?= overtimeH($bpCommentLabel) ?></div><textarea name='bp_comment' id='bp-comment-field'></textarea></div><?php endif; ?><div class='overtime-view-approval-actions'><input type='hidden' name='bp_action' value=''><?php foreach ($approvalButtons as $button): ?><?php $buttonClass = 'overtime-btn'; if (($button['kind'] ?? '') === 'approve') {$buttonClass .= ' overtime-btn-success';} elseif (($button['kind'] ?? '') === 'refine') {$buttonClass .= ' overtime-btn-warning';} elseif (($button['kind'] ?? '') === 'reject') {$buttonClass .= ' overtime-btn-danger';} ?><button type='submit' class='<?= overtimeH($buttonClass) ?>' onclick='this.form.bp_action.value="<?= overtimeH((string)$button['code']) ?>";return true;'><?= overtimeH((string)$button['label']) ?></button><?php endforeach; ?></div></form></div><?php endif; ?>
<?php if ($viewData && !empty($groupCalculations)): ?>
<div class='overtime-view-linked'>
<?php if ($viewMode === 'simple'): ?><hr class='overtime-view-separator'><?php endif; ?>
<h3>Групповая заявка</h3>
<?php if ($viewMode === 'simple'): ?>
<?php if (!empty($viewData['is_duty'])): ?>
<table class='overtime-simple-table'><tr><th>ID</th><th>Статус</th><th>ФИО сотрудника</th><th>Тип заявки</th><th>Дата начала</th><th>Дата окончания</th></tr>
<?php foreach ($groupCalculations as $group): ?><tr><td><a href='view.php?id=<?= (int)$group['id'] ?>'><?= (int)$group['id'] ?></a></td><td><span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)($group['status_name'] ?? ''))) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($group['status_id'] ?? 0))) ?>'><?= overtimeH((string)($group['status_name'] ?? '')) ?></span></td><td><?= overtimeH((string)($group['employee_name'] ?? '')) ?></td><td><?= overtimeH((string)($group['work_type_name'] ?? '')) ?></td><td><?= overtimeH(overtimeFormatRequestDateOnly($group['work_start_date'] ?? '')) ?></td><td><?= overtimeH(overtimeFormatRequestDateOnly($group['work_end_date'] ?? '')) ?></td></tr><?php endforeach; ?>
</table>
<?php else: ?>
<table class='overtime-simple-table'><tr><th>ID</th><th>Статус</th><th>ФИО сотрудника</th><th>Тип заявки</th><th>Начало</th><th>Окончание</th><th>Часы премией <span class='overtime-view-marker'>C&B</span></th><th>Часы бухгалтерией <span class='overtime-view-marker'>КА</span></th><th>Тип оплаты</th></tr>
<?php foreach ($groupCalculations as $group): preg_match('/(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}).*(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2})/u', (string)($group['work_period_text'] ?? ''), $m); ?><tr><td><a href='view.php?id=<?= (int)$group['id'] ?>'><?= (int)$group['id'] ?></a></td><td><span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)($group['status_name'] ?? ''))) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($group['status_id'] ?? 0))) ?>'><?= overtimeH((string)($group['status_name'] ?? '')) ?></span></td><td><?= overtimeH((string)($group['employee_name'] ?? '')) ?></td><td><?= overtimeH((string)($group['work_type_name'] ?? '')) ?></td><td><?= overtimeH((string)($m[1] ?? '')) ?></td><td><?= overtimeH((string)($m[2] ?? '')) ?></td><td><?= overtimeH((string)($group['total_premium_hours'] ?? '0')) ?></td><td><?= overtimeH((string)($group['total_ot_hours'] ?? '0')) ?></td><td><?= overtimeH((string)($group['payment_type_name'] ?? '')) ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groupCalculations as $group): ?><details><summary><a href='view.php?id=<?= (int)$group['id'] ?>'>Заявка #<?= (int)$group['id'] ?></a> — <?= overtimeH((string)$group['employee_name']) ?>: <?= overtimeH((string)($group['work_type_name'] ?? '')) ?> <?= overtimeH(!empty($viewData['is_duty']) ? overtimeBuildRequestDatePeriodText($group) : (string)($group['work_period_text'] ?? '')) ?> <span class='status-pill <?= overtimeH(overtimeGetStatusClass((string)($group['status_name'] ?? ''))) ?>' style='<?= overtimeH(overtimeGetStatusPillStyle((int)($group['status_id'] ?? 0))) ?>'><?= overtimeH((string)($group['status_name'] ?? '')) ?></span></summary><?php if (empty($viewData['is_duty'])): ?><div class='overtime-view-calc'><?= overtimeHighlightCalculationRows((string)$group['calculation_html']) ?></div><?php endif; ?></details><?php endforeach; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<div style='margin-top:16px;'>
<a href='list.php' class='overtime-btn'>Вернуться к списку</a>
</div>
</div></div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
