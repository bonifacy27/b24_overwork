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
    $paymentTypeName = overtimeResolvePaymentTypeNameByItem($item, $config);
    $justification = trim((string)overtimeExtractPropertyValue($item, $config['REQ_PROP_JUSTIFICATION']));

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

    $calculationHtml = overtimeBuildCalculationHtmlByRequestItem($item, $config);
    if ($calculationHtml === '') {
        $calculationHtml = (string)($item['PROPERTY_' . $config['REQ_PROP_CALCULATION_HTML'] . '_VALUE']['TEXT'] ?? '');
    }

    return [
        'id' => $requestId,
        'name' => (string)$item['NAME'],
        'initiator_name' => $initiator['name'] ?: 'Не указан',
        'employee_name' => $employee['name'] ?: 'Не указан',
        'work_type_name' => overtimeGetElementNameById((int)$config['IBLOCK_WORK_TYPES'], $workTypeId),
        'work_period_text' => $workPeriod,
        'payment_type_name' => $paymentTypeName,
        'justification' => $justification,
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
            ...overtimeBuildOptionalPropertySelect($config),
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
            'payment_type_name' => overtimeResolvePaymentTypeNameByItem($item, $config),
            'calculation_html' => overtimeBuildCalculationHtmlByRequestItem($item, $config),
        ];
    }

    return $result;
}

function overtimeGetGroupRequestCalculations(int $groupId, int $currentRequestId, array $config): array
{
    if ($groupId <= 0) {
        return [];
    }

    $result = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'],
            'PROPERTY_' . $config['REQ_PROP_GROUP_LINK'] => $groupId,
            '!ID' => $currentRequestId,
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
            ...overtimeBuildOptionalPropertySelect($config),
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
            'payment_type_name' => overtimeResolvePaymentTypeNameByItem($item, $config),
            'calculation_html' => $calculationHtml,
        ];
    }

    return $result;
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

    $select = ['ID', 'NAME', 'DOCUMENT_ID', 'WORKFLOW_ID', 'ACTIVITY_NAME', 'USER_ID', 'USERS', 'PARAMETERS'];

    $checkTask = static function (array $task) use ($userId, $requestId, $iblockId): bool {
        if (!overtimeBizprocTaskIsForUser($task, $userId)) {
            return false;
        }

        $taskRequestId = overtimeExtractRequestIdFromDocumentId((string)($task['DOCUMENT_ID'] ?? ''), $iblockId);
        if ($taskRequestId !== $requestId) {
            return false;
        }

        [$approveCaption, $rejectCaption] = overtimeGetTaskCaptions($task, 'Согласовать', 'Отклонить');
        $approveCaption = mb_strtolower(trim($approveCaption), 'UTF-8');
        $rejectCaption = mb_strtolower(trim($rejectCaption), 'UTF-8');

        return $approveCaption === 'согласовать' && $rejectCaption === 'отклонить';
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

function overtimeCompleteBizprocTask(array $task, int $userId, string $action = 'approve', string $comment = ''): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0 || $userId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректные входные данные для завершения задачи БП.'];
    }

    $errors = [];
    $aliases = [
        'yes' => 'approve', 'ok' => 'approve',
        'no' => 'nonapprove', 'cancel' => 'nonapprove',
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

    $baseFields = [
        'USER_ID' => $userId,
        'REAL_USER_ID' => $userId,
        'COMMENT' => $comment,
        'task_comment' => $comment,
    ];
    $requests = [];
    if ($code === 'approve') {
        $requests[] = $baseFields + ['approve' => 'Y', 'ACTION' => 'approve'];
    } elseif ($code === 'nonapprove') {
        $requests[] = $baseFields + ['nonapprove' => 'Y', 'ACTION' => 'nonapprove'];
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
            if ($code === 'approve') {
                $payload['APPROVE'] = true;
            } else {
                $payload['APPROVE'] = false;
                if ($code === 'refine') {
                    $payload['REFINE'] = 'Y';
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
            CBPTaskService::DoTask($taskId, $userId, ['ACTION' => $rawCode, $rawCode => 'Y', 'COMMENT' => $comment, 'task_comment' => $comment]);
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

    $pattern = '/https?:\/\/[^\s<>"\']+/iu';
    if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        return nl2br(overtimeH($text));
    }

    $result = '';
    $cursor = 0;
    foreach ($matches[0] as $matchData) {
        [$rawUrl, $offset] = $matchData;
        $offset = (int)$offset;

        if ($offset > $cursor) {
            $result .= overtimeH(substr($text, $cursor, $offset - $cursor));
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
            $result .= overtimeH($suffix);
        }

        $cursor = $offset + strlen($rawUrl);
    }

    $tail = substr($text, $cursor);
    if ($tail !== '') {
        $result .= overtimeH($tail);
    }

    return nl2br($result);
}

$viewData = overtimeGetRequestViewData($requestId, $overtimeConfig);
$linkedCalculations = $viewData ? overtimeGetLinkedRequestCalculations($viewData['linked_request_ids'], $overtimeConfig) : [];
$groupCalculations = $viewData ? overtimeGetGroupRequestCalculations((int)$viewData['group_id'], (int)$viewData['id'], $overtimeConfig) : [];
$currentUserId = (int)($GLOBALS['USER']->GetID() ?? 0);
$approvalTask = null;
$approvalButtons = [];
$bpActionError = '';
$bpCommentLabel = 'Комментарий';
$bpDescriptionForForm = '';

if ($viewData && $currentUserId > 0) {
    $approvalTask = overtimeFindCurrentUserApprovalTask($viewData['id'], $currentUserId, (int)$overtimeConfig['IBLOCK_REQUESTS']);
    if ($approvalTask) {
        $taskParams = overtimeExtractTaskParameters($approvalTask['PARAMETERS'] ?? []);
        $bpCommentLabel = trim((string)($taskParams['CommentLabelMessage'] ?? '')) ?: 'Комментарий';
        $bpDescriptionForForm = trim(str_replace('Текст задания для формы', '', (string)($taskParams['DescriptionForForm'] ?? '')));
        $approvalButtons = overtimeGetTaskActionButtons($approvalTask);
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
        $completionResult = overtimeCompleteBizprocTask($approvalTask, $currentUserId, $completionAction, $bpComment);

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
        $bpCommentLabel = trim((string)($taskParams['CommentLabelMessage'] ?? '')) ?: 'Комментарий';
        $bpDescriptionForForm = trim(str_replace('Текст задания для формы', '', (string)($taskParams['DescriptionForForm'] ?? '')));
        $approvalButtons = overtimeGetTaskActionButtons($approvalTask);
    }
}

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
    .overtime-view-meta-note {font-size:12px; color:#6b7280; margin-top:4px;}
    .overtime-view-title {font-size:18px; margin-bottom:12px;}
    .overtime-view-subtitle {font-size:16px; margin:16px 0 10px;}
    .overtime-view-calc {border:1px solid #e4e8ee; border-radius:6px; padding:12px; background:#fff; overflow:auto;}
    .overtime-view-main-calc {border-color:#c9defa; background:#f7fbff;}
    .overtime-view-linked-wrap {margin-top:18px; border-top:1px dashed #d8dee8; padding-top:14px;}
    .overtime-view-linked-details {border:1px solid #e5e9f0; border-radius:6px; background:#fbfcfe; margin-bottom:10px;}
    .overtime-view-linked-summary {cursor:pointer; padding:8px 12px; font-size:13px; color:#4b5563; user-select:none;}
    .overtime-view-linked-body {padding:0 12px 12px;}
    .overtime-view-linked-item-title {font-size:13px; margin:8px 0 6px; color:#374151;}
    .overtime-view-linked-calc {font-size:13px;}
    .overtime-view-highlight-row {font-weight:700; font-size:14px;}
    .overtime-view-highlight-cell {background:#fff4cc !important;}
    .overtime-view-marker {display:inline-block; margin-left:6px; padding:1px 6px; border-radius:10px; background:#d1242f; color:#fff; font-size:11px; font-weight:700; letter-spacing:.2px;}
    .overtime-view-justification {padding:12px; border:1px solid #e4e8ee; border-radius:6px; background:#f8fafc; white-space:pre-wrap; line-height:1.45;}
    .overtime-view-justification-details {border:1px solid #e5e9f0; border-radius:6px; background:#fbfcfe; margin-bottom:8px;}
    .overtime-view-justification-summary {cursor:pointer; padding:8px 12px; font-size:14px; color:#374151; user-select:none; font-weight:600;}
    .overtime-view-justification-body {padding:0 12px 12px;}
    .overtime-view-actions {display:flex; gap:10px; margin-top:20px;}
    .overtime-view-approval {border:1px solid #e9b99a; border-radius:10px; padding:16px; background:#ffd9bd; margin-top:20px; box-shadow:0 2px 12px rgba(180,110,62,.12);}
    .overtime-view-approval-title {font-size:16px; margin-bottom:10px; font-weight:600;}
    .overtime-view-approval-actions {display:flex; gap:10px; flex-wrap:wrap;}
    .overtime-btn.overtime-btn-success {background:#2ea043 !important; border-color:#2ea043 !important; color:#fff !important;}
    .overtime-btn.overtime-btn-danger {background:#d1242f !important; border-color:#d1242f !important; color:#fff !important;}
    .overtime-btn.overtime-btn-warning {background:#f28c28 !important; border-color:#f28c28 !important; color:#fff !important;}
    .overtime-view-approval-comment {margin-bottom:10px;}
    .overtime-view-approval-description {padding:12px; border:1px solid #e9b99a; border-radius:6px; background:#ffd9bd; white-space:pre-wrap; line-height:1.45;}
    .overtime-view-approval-comment textarea {width:30%; min-height:74px; resize:vertical; border:1px solid #cfd7df; border-radius:6px; padding:8px; font-size:14px;}
    .overtime-view-approval-comment-note {font-size:12px; color:#7c2d12; margin-top:4px;}
    .overtime-btn {display:inline-block; padding:10px 14px; border:1px solid #cfd7df; border-radius:6px; background:#fff; text-decoration:none; color:#1f2937; cursor:pointer;}
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
                    <div class="overtime-view-meta-label">Сотрудник</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['employee_name']) ?></div>
                </div>
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Тип заявки</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['work_type_name'] ?: 'Не указан') ?></div>
                    <?php if ($viewData['work_period_text'] !== ''): ?>
                        <div class="overtime-view-meta-note"><?= overtimeH($viewData['work_period_text']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Тип оплаты</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['payment_type_name']) ?></div>
                </div>
                <div class="overtime-view-meta-item">
                    <div class="overtime-view-meta-label">Инициатор</div>
                    <div class="overtime-view-meta-value"><?= overtimeH($viewData['initiator_name']) ?></div>
                </div>
            </div>

            <details class="overtime-view-justification-details">
                <summary class="overtime-view-justification-summary">Обоснование</summary>
                <div class="overtime-view-justification-body">
                    <div class="overtime-view-justification">
                        <?= $viewData['justification'] !== '' ? nl2br(overtimeH($viewData['justification'])) : '<i>Не заполнено</i>' ?>
                    </div>
                </div>
            </details>

            <div class="overtime-view-subtitle">Расчетная часть</div>
            <div class="overtime-view-calc overtime-view-main-calc">
                <?= $viewData['calculation_html'] !== '' ? overtimeHighlightCalculationRows($viewData['calculation_html']) : '<i>Расчет отсутствует</i>' ?>
            </div>

            <?php if (!empty($linkedCalculations)): ?>
                <div class="overtime-view-linked-wrap">
                    <details class="overtime-view-linked-details">
                        <summary class="overtime-view-linked-summary">
                            Расчет по связанным заявкам (<?= count($linkedCalculations) ?>)
                        </summary>
                        <div class="overtime-view-linked-body">
                            <?php foreach ($linkedCalculations as $linked): ?>
                                <div class="overtime-view-linked-item-title">
                                    Заявка #<?= (int)$linked['id'] ?> — <?= overtimeH($linked['name']) ?>
                                </div>
                                <div class="overtime-view-meta-label" style="margin-bottom:6px;">
                                    Сотрудник: <?= overtimeH($linked['employee_name']) ?> · Тип оплаты: <?= overtimeH($linked['payment_type_name']) ?>
                                </div>
                                <div class="overtime-view-calc overtime-view-linked-calc">
                                    <?= $linked['calculation_html'] !== '' ? overtimeHighlightCalculationRows($linked['calculation_html']) : '<i>Расчет отсутствует</i>' ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

            <?php if ($viewData['group_id'] > 0): ?>
                <div class="overtime-view-subtitle">Групповая заявка</div>
                <?php if (!empty($groupCalculations)): ?>
                    <div class="overtime-view-linked-wrap">
                        <?php foreach ($groupCalculations as $groupRequest): ?>
                            <details class="overtime-view-linked-details">
                                <summary class="overtime-view-linked-summary">
                                    Заявка #<?= (int)$groupRequest['id'] ?> — <?= overtimeH($groupRequest['name']) ?>
                                </summary>
                                <div class="overtime-view-linked-body">
                                    <div class="overtime-view-meta-label" style="margin-bottom:6px;">
                                        Сотрудник: <?= overtimeH($groupRequest['employee_name']) ?> · Тип оплаты: <?= overtimeH($groupRequest['payment_type_name']) ?>
                                    </div>
                                    <div class="overtime-view-calc overtime-view-linked-calc">
                                        <?= $groupRequest['calculation_html'] !== '' ? overtimeHighlightCalculationRows($groupRequest['calculation_html']) : '<i>Расчет отсутствует</i>' ?>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="overtime-view-meta-label">Других заявок в группе нет.</div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($approvalTask): ?>
            <div class="overtime-view-approval">
                <div class="overtime-view-approval-title">Согласование заявки</div>
                <?php if ($bpActionError !== ''): ?>
                    <div class="ui-alert ui-alert-danger" style="margin-bottom:10px;">
                        <span class="ui-alert-message"><?= overtimeH($bpActionError) ?></span>
                    </div>
                <?php endif; ?>
                <form method="post" style="margin:0;">
                    <?= bitrix_sessid_post() ?>
                    <?php if ($bpDescriptionForForm !== ''): ?>
                        <div class="overtime-view-approval-comment">
                            <div class="overtime-view-approval-description"><?= overtimeRenderTextWithLinks($bpDescriptionForForm) ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="overtime-view-approval-comment">
                        <div class="overtime-view-meta-label" style="margin-bottom:6px;"><?= overtimeH($bpCommentLabel) ?></div>
                        <textarea name="bp_comment" id="bp-comment-field"></textarea>
                        <div class="overtime-view-approval-comment-note">Комментарий обязателен при отклонении заявки.</div>
                    </div>
                    <div class="overtime-view-approval-actions">
                        <input type="hidden" name="bp_action" value="">
                        <?php foreach ($approvalButtons as $button): ?>
                            <?php
                            $buttonClass = 'overtime-btn';
                            if (($button['kind'] ?? '') === 'approve') {
                                $buttonClass .= ' overtime-btn-success';
                            } elseif (($button['kind'] ?? '') === 'refine') {
                                $buttonClass .= ' overtime-btn-warning';
                            } elseif (($button['kind'] ?? '') === 'reject') {
                                $buttonClass .= ' overtime-btn-danger';
                            }
                            ?>
                            <button
                                type="submit"
                                class="<?= overtimeH($buttonClass) ?>"
                                onclick="this.form.bp_action.value='<?= overtimeH((string)$button['code']) ?>'; return true;"
                            ><?= overtimeH((string)$button['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="overtime-view-actions">
            <a class="overtime-btn overtime-btn-primary" href="/forms/hr_administration/overtime/list.php">Вернуться к заявкам</a>
        </div>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
