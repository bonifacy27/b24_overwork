<?php
/**
 * /forms/hr_administration/overtime/list.php
 * Версия: v1.3.0
 *
 * Изменения:
 * 1. Убран столбец "Связка"
 * 2. Для связанных заявок используется 2 горизонтальные строки таблицы
 * 3. ФИО и Обоснование объединяются через rowspan
 * 4. В столбце "Групповая заявка" выводится только признак группы
 * 5. По клику на группу применяется фильтр group_filter и выводятся только заявки этой группы
 */

use Bitrix\Main\Loader;

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("Заявки на сверхурочную работу / работу в выходной / дежурство");

if (!Loader::includeModule("iblock")) {
    ShowError("Не удалось подключить модуль iblock");
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
    exit;
}

/* ------------------------- настройки ------------------------- */

$GROUP_ID         = 87;
$IBLOCK_ID        = 391;
$IBLOCK_GROUPS_ID = 397;
$CREATE_URL       = "cr.php";
$LIST_PAGE_URL    = "/forms/hr_administration/overtime/list.php";

$PROP_MAP = [
    3080 => ['code' => 'TIP_RABOTY',                   'title' => 'Тип работы'],
    3113 => ['code' => 'DATA_NACHALA_RABOT',           'title' => 'Дата начала работ'],
    3115 => ['code' => 'VREMYA_NACHALA_RABOT',         'title' => 'Время начала работ'],
    3114 => ['code' => 'DATA_OKONCHANIYA_RABOT',       'title' => 'Дата окончания работ'],
    3116 => ['code' => 'VREMYA_OKONCHANIYA_RABOT',     'title' => 'Время окончания работ'],
    3081 => ['code' => 'STATUS',                       'title' => 'Статус'],
    3082 => ['code' => 'ISTORIYA',                     'title' => 'История'],
    3085 => ['code' => 'FIO_SOTRUDNIKA',               'title' => 'ФИО сотрудника'],
    3086 => ['code' => 'OBSHCHEE_KOLICHESTVO_CHASOV',  'title' => 'Общее количество часов'],
    3087 => ['code' => 'TIP_OPLATY',                   'title' => 'Тип оплаты'],
    3095 => ['code' => 'OBOSNOVANIE',                  'title' => 'Обоснование'],
    3107 => ['code' => 'SVYAZANNYE_ZAYAVKI',           'title' => 'Связанные заявки'],
    3112 => ['code' => 'GROUP_LINK',                   'title' => 'Связанная группа заявок'],
];

$GROUP_PROP_MAP = [
    3111 => ['code' => 'SVYAZANNYE_ZAYAVKI_GRUPPY', 'title' => 'Связанные заявки группы'],
];

/* ------------------------- helpers ------------------------- */

function h($s)
{
    return htmlspecialcharsbx((string)$s);
}

function userNameById($userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return '';
    }

    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        $name = trim($arUser["LAST_NAME"] . " " . $arUser["NAME"] . " " . $arUser["SECOND_NAME"]);
        return $name ?: $arUser["LOGIN"];
    }

    return '';
}

function propValueSafe(array $props, int $iblockId, int $elementId, int $propId, string $propCode)
{
    if (isset($props[$propCode])) {
        return $props[$propCode]["VALUE"];
    }

    if (isset($props[$propId])) {
        return $props[$propId]["VALUE"];
    }

    $res = CIBlockElement::GetProperty($iblockId, $elementId, [], ["ID" => $propId]);
    $vals = [];
    while ($ar = $res->Fetch()) {
        if ($ar["VALUE"] !== null && $ar["VALUE"] !== "") {
            $vals[] = $ar["VALUE"];
        }
    }

    if (!$vals) {
        return '';
    }

    return count($vals) > 1 ? $vals : $vals[0];
}

function resolveEnumOrElementValue($value)
{
    static $enumCache = [];
    static $elementCache = [];

    if ($value === null || $value === '') {
        return '';
    }

    if (is_array($value)) {
        $result = [];
        foreach ($value as $v) {
            $item = resolveEnumOrElementValue($v);
            if ($item !== '') {
                $result[] = $item;
            }
        }
        return implode(', ', $result);
    }

    $intValue = (int)$value;

    if ($intValue > 0) {
        if (!isset($enumCache[$intValue])) {
            $enum = CIBlockPropertyEnum::GetByID($intValue);
            $enumCache[$intValue] = $enum ? $enum['VALUE'] : null;
        }
        if (!empty($enumCache[$intValue])) {
            return $enumCache[$intValue];
        }

        if (!isset($elementCache[$intValue])) {
            $res = CIBlockElement::GetList([], ['ID' => $intValue], false, false, ['ID', 'NAME']);
            $row = $res->Fetch();
            $elementCache[$intValue] = $row ? $row['NAME'] : null;
        }
        if (!empty($elementCache[$intValue])) {
            return $elementCache[$intValue];
        }
    }

    return (string)$value;
}

function normalizeDateCustom($date)
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return '';
}

function formatDateRuCustom($date)
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    return $date;
}

function combineDateTimeCustom($date, $time)
{
    $date = trim((string)$date);
    $time = trim((string)$time);

    if ($date === '' && $time === '') {
        return '';
    }

    if ($date !== '' && $time !== '') {
        return $date . ' ' . $time;
    }

    return $date ?: $time;
}

function makeDateTimeTimestampCustom($date, $time)
{
    $dateNorm = normalizeDateCustom($date);
    $timeNorm = trim((string)$time);

    if ($dateNorm === '') {
        return 0;
    }

    if ($timeNorm === '') {
        $timeNorm = '00:00';
    }

    $ts = strtotime($dateNorm . ' ' . $timeNorm);
    return $ts ?: 0;
}

function buildElementUrlCustom($groupId, $iblockId, $elementId)
{
    return "/workgroups/group/" . (int)$groupId . "/lists/" . (int)$iblockId . "/element/0/" . (int)$elementId . "/?list_section_id=";
}

function buildRequestViewUrlCustom($elementId)
{
    return "view.php?id=" . (int)$elementId;
}

function buildBizprocTaskUrlCustom($taskId, $backUrl)
{
    return "/company/personal/bizproc/" . (int)$taskId . "/?back_url=" . rawurlencode((string)$backUrl);
}

function buildListUrlCustom($groupId, $iblockId)
{
    return "/workgroups/group/" . (int)$groupId . "/lists/" . (int)$iblockId . "/view/0/?list_section_id=";
}

function parseHoursNumericCustom($hoursRaw)
{
    $hoursRaw = trim((string)$hoursRaw);
    if ($hoursRaw === '') {
        return 0.0;
    }

    if (preg_match('/-?\d+(?:[.,]\d+)?/u', $hoursRaw, $m)) {
        return (float)str_replace(',', '.', $m[0]);
    }

    return 0.0;
}

function getHoursByPaymentTypeCustom($hoursRaw, $paymentType, $mode)
{
    $hours = parseHoursNumericCustom($hoursRaw);
    if ($hours <= 0) {
        return 0.0;
    }

    $paymentKey = mb_strtolower(trim((string)$paymentType), 'UTF-8');
    if ($mode === 'tk') {
        return (mb_strpos($paymentKey, 'тк') !== false) ? $hours : 0.0;
    }

    if ($mode === 'bonus') {
        return (mb_strpos($paymentKey, 'прем') !== false) ? $hours : 0.0;
    }

    return 0.0;
}

function extractRequestIdFromDocumentIdCustom($documentId, $iblockId)
{
    $documentId = trim((string)$documentId);
    if ($documentId === '') {
        return 0;
    }

    $patternByIblock = '/(?:^|_)' . preg_quote((string)(int)$iblockId, '/') . '_([0-9]+)$/';
    if ((int)$iblockId > 0 && preg_match($patternByIblock, $documentId, $m)) {
        return (int)$m[1];
    }

    if (preg_match('/([0-9]+)\D*$/', $documentId, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function loadCurrentUserBizprocTasksMapCustom(array $requestIds, $userId, $iblockId)
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    $userId = (int)$userId;
    $iblockId = (int)$iblockId;

    if (empty($requestIds) || $userId <= 0 || $iblockId <= 0) {
        return [];
    }

    if (!Loader::includeModule('bizproc') || !class_exists('CBPTaskService')) {
        return [];
    }

    $map = [];
    $res = CBPTaskService::GetList(
        ['ID' => 'DESC'],
        [
            'USER_ID'     => $userId,
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ],
        false,
        false,
        ['ID', 'DOCUMENT_ID']
    );

    while ($task = $res->GetNext()) {
        $requestId = extractRequestIdFromDocumentIdCustom($task['DOCUMENT_ID'] ?? '', $iblockId);
        if ($requestId <= 0 || !in_array($requestId, $requestIds, true) || isset($map[$requestId])) {
            continue;
        }

        $map[$requestId] = (int)$task['ID'];
    }

    return $map;
}

function loadCurrentExecutorsMapCustom(array $requestIds, $iblockId)
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    $iblockId = (int)$iblockId;

    if (empty($requestIds) || $iblockId <= 0) {
        return [];
    }

    if (!Loader::includeModule('bizproc') || !class_exists('CBPTaskService')) {
        return [];
    }

    $map = [];
    $res = CBPTaskService::GetList(
        ['ID' => 'DESC'],
        [
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ],
        false,
        false,
        ['ID', 'DOCUMENT_ID', 'USER_ID']
    );

    while ($task = $res->GetNext()) {
        $requestId = extractRequestIdFromDocumentIdCustom($task['DOCUMENT_ID'] ?? '', $iblockId);
        if ($requestId <= 0 || !in_array($requestId, $requestIds, true)) {
            continue;
        }

        $userId = (int)($task['USER_ID'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (!isset($map[$requestId])) {
            $map[$requestId] = [];
        }
        $map[$requestId][$userId] = userNameById($userId);
    }

    foreach ($map as $requestId => $executors) {
        $map[$requestId] = array_values(array_filter($executors, static function ($name) {
            return trim((string)$name) !== '';
        }));
    }

    return $map;
}

function renderHistoryHtmlCustom($historyRaw)
{
    $historyRaw = trim((string)$historyRaw);
    if ($historyRaw === '') {
        return '<div class="text-muted">История отсутствует</div>';
    }

    $lines = preg_split("/\r\n|\n|\r/u", $historyRaw);
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/u', $line, $m)) {
            $items[] = [
                'datetime' => $m[1],
                'text'     => $m[2],
            ];
        } else {
            $items[] = [
                'datetime' => '',
                'text'     => $line,
            ];
        }
    }

    if (empty($items)) {
        return '<div class="text-muted">История отсутствует</div>';
    }

    $html = '<ul class="list-unstyled mb-0">';
    foreach ($items as $item) {
        $html .= '<li style="margin-bottom:10px;">';
        if ($item['datetime'] !== '') {
            $html .= '<div><strong>' . h($item['datetime']) . '</strong></div>';
        }
        $html .= '<div>' . nl2br(h($item['text'])) . '</div>';
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function loadGroupsMapCustom($iblockGroupsId, $linkedPropId, $linkedPropCode)
{
    $map = [];

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockGroupsId,
            'ACTIVE'    => 'Y',
        ],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($ob = $res->GetNextElement()) {
        $f = $ob->GetFields();
        $p = $ob->GetProperties();

        $groupId = (int)$f['ID'];
        $linkedRaw = propValueSafe($p, $iblockGroupsId, $groupId, $linkedPropId, $linkedPropCode);

        $linked = [];
        if (is_array($linkedRaw)) {
            foreach ($linkedRaw as $v) {
                $v = (int)$v;
                if ($v > 0) {
                    $linked[] = $v;
                }
            }
        } else {
            $v = (int)$linkedRaw;
            if ($v > 0) {
                $linked[] = $v;
            }
        }

        $map[$groupId] = [
            'ID'         => $groupId,
            'NAME'       => $f['NAME'],
            'LINKED_IDS' => array_values(array_unique($linked)),
        ];
    }

    return $map;
}

function getStatusClassCustom($statusName)
{
    $statusName = mb_strtolower(trim((string)$statusName), 'UTF-8');

    if ($statusName === '') {
        return 'status-default';
    }

    if (
        mb_strpos($statusName, 'соглас') !== false ||
        mb_strpos($statusName, 'утверж') !== false ||
        mb_strpos($statusName, 'одобр') !== false
    ) {
        return 'status-success';
    }

    if (
        mb_strpos($statusName, 'отказ') !== false ||
        mb_strpos($statusName, 'отклон') !== false
    ) {
        return 'status-danger';
    }

    if (
        mb_strpos($statusName, 'нов') !== false ||
        mb_strpos($statusName, 'создан') !== false ||
        mb_strpos($statusName, 'чернов') !== false
    ) {
        return 'status-info';
    }

    if (
        mb_strpos($statusName, 'на соглас') !== false ||
        mb_strpos($statusName, 'в работе') !== false ||
        mb_strpos($statusName, 'рассмотр') !== false ||
        mb_strpos($statusName, 'перераспредел') !== false
    ) {
        return 'status-warning';
    }

    return 'status-default';
}

function normalizeTextKeyCustom($value)
{
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value);
    return $value;
}

function buildGroupFilterUrlCustom($groupFilterId)
{
    $params = $_GET;

    if ($groupFilterId > 0) {
        $params['group_filter'] = $groupFilterId;
    } else {
        unset($params['group_filter']);
    }

    return '?' . http_build_query($params);
}

/* ------------------------- фильтры ------------------------- */

$q            = trim((string)($_GET['q'] ?? ''));
$dateFrom     = trim((string)($_GET['date_from'] ?? ''));
$dateTo       = trim((string)($_GET['date_to'] ?? ''));
$groupFilter  = (int)($_GET['group_filter'] ?? 0);
$statusInput  = $_GET['status'] ?? [];

if (!is_array($statusInput)) {
    $statusInput = [$statusInput];
}
$statusInput = array_values(array_filter(array_map('intval', $statusInput)));

/* ------------------------- сортировка ------------------------- */

$allowedSort = [
    'id'     => 'ID',
    'fio'    => 'PROPERTY_3085',
    'start'  => 'PROPERTY_3113',
    'status' => 'PROPERTY_3081',
];

$sortKey = isset($_GET['sort'], $allowedSort[$_GET['sort']]) ? $_GET['sort'] : 'id';
$dir     = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
$order   = [$allowedSort[$sortKey] => $dir, 'ID' => 'DESC'];

/* ------------------------- выборка ------------------------- */

$filter = [
    'IBLOCK_ID'         => $IBLOCK_ID,
    'ACTIVE'            => 'Y',
    'CHECK_PERMISSIONS' => 'Y',
];

$arSelect = ['ID', 'NAME', 'CREATED_BY'];

$rsItems = CIBlockElement::GetList(
    $order,
    $filter,
    false,
    ['nPageSize' => 200],
    $arSelect
);

$groupMap = loadGroupsMapCustom(
    $IBLOCK_GROUPS_ID,
    3111,
    'SVYAZANNYE_ZAYAVKI_GRUPPY'
);

$rows = [];
$statusVariants = [];

while ($ob = $rsItems->GetNextElement()) {
    $f = $ob->GetFields();
    $p = $ob->GetProperties();

    $id = (int)$f['ID'];

    $v3080 = propValueSafe($p, $IBLOCK_ID, $id, 3080, $PROP_MAP[3080]['code']);
    $v3113 = propValueSafe($p, $IBLOCK_ID, $id, 3113, $PROP_MAP[3113]['code']);
    $v3115 = propValueSafe($p, $IBLOCK_ID, $id, 3115, $PROP_MAP[3115]['code']);
    $v3114 = propValueSafe($p, $IBLOCK_ID, $id, 3114, $PROP_MAP[3114]['code']);
    $v3116 = propValueSafe($p, $IBLOCK_ID, $id, 3116, $PROP_MAP[3116]['code']);
    $v3081 = propValueSafe($p, $IBLOCK_ID, $id, 3081, $PROP_MAP[3081]['code']);
    $v3082 = propValueSafe($p, $IBLOCK_ID, $id, 3082, $PROP_MAP[3082]['code']);
    $v3085 = propValueSafe($p, $IBLOCK_ID, $id, 3085, $PROP_MAP[3085]['code']);
    $v3086 = propValueSafe($p, $IBLOCK_ID, $id, 3086, $PROP_MAP[3086]['code']);
    $v3087 = propValueSafe($p, $IBLOCK_ID, $id, 3087, $PROP_MAP[3087]['code']);
    $v3095 = propValueSafe($p, $IBLOCK_ID, $id, 3095, $PROP_MAP[3095]['code']);
    $v3107 = propValueSafe($p, $IBLOCK_ID, $id, 3107, $PROP_MAP[3107]['code']);
    $v3112 = propValueSafe($p, $IBLOCK_ID, $id, 3112, $PROP_MAP[3112]['code']);

    $employeeName = '';
    if (is_numeric($v3085)) {
        $employeeName = userNameById($v3085);
    }
    if ($employeeName === '') {
        $employeeName = is_array($v3085) ? implode(', ', $v3085) : (string)$v3085;
    }

    $tipRaboty   = resolveEnumOrElementValue($v3080);
    $statusName  = resolveEnumOrElementValue($v3081);
    $paymentType = resolveEnumOrElementValue($v3087);
    $initiatorName = userNameById((int)($f['CREATED_BY'] ?? 0));

    $statusId = is_array($v3081) ? 0 : (int)$v3081;
    if ($statusId > 0 && $statusName !== '') {
        $statusVariants[$statusId] = $statusName;
    }

    $dateStartView = formatDateRuCustom($v3113);
    $dateEndView   = formatDateRuCustom($v3114);

    $startDateTime = combineDateTimeCustom($dateStartView, $v3115);
    $endDateTime   = combineDateTimeCustom($dateEndView, $v3116);
    $startTs       = makeDateTimeTimestampCustom($v3113, $v3115);

    $relatedIds = [];
    if (is_array($v3107)) {
        foreach ($v3107 as $relId) {
            $relId = (int)$relId;
            if ($relId > 0 && $relId !== $id) {
                $relatedIds[] = $relId;
            }
        }
    } else {
        $relId = (int)$v3107;
        if ($relId > 0 && $relId !== $id) {
            $relatedIds[] = $relId;
        }
    }
    $relatedIds = array_values(array_unique($relatedIds));

    $groupId = (int)$v3112;
    $groupInfo = null;
    $groupMembers = [];

    if ($groupId > 0 && isset($groupMap[$groupId])) {
        $groupInfo = $groupMap[$groupId];
        $groupMembers = $groupInfo['LINKED_IDS'];
    }

    $obosnovanie = is_array($v3095) ? implode(', ', $v3095) : (string)$v3095;

    $searchText = mb_strtolower(
        $employeeName . ' ' . $obosnovanie,
        'UTF-8'
    );

    if ($q !== '' && mb_stripos($searchText, mb_strtolower($q, 'UTF-8')) === false) {
        continue;
    }

    $rowDateNorm = normalizeDateCustom($v3113);

    if ($dateFrom !== '') {
        $dateFromNorm = normalizeDateCustom($dateFrom);
        if ($dateFromNorm !== '' && $rowDateNorm !== '' && $rowDateNorm < $dateFromNorm) {
            continue;
        }
    }

    if ($dateTo !== '') {
        $dateToNorm = normalizeDateCustom($dateTo);
        if ($dateToNorm !== '' && $rowDateNorm !== '' && $rowDateNorm > $dateToNorm) {
            continue;
        }
    }

    if (!empty($statusInput) && !in_array($statusId, $statusInput, true)) {
        continue;
    }

    if ($groupFilter > 0 && $groupId !== $groupFilter) {
        continue;
    }

    $rows[$id] = [
        'ID'              => $id,
        'FIO'             => $employeeName,
        'FIO_KEY'         => normalizeTextKeyCustom($employeeName),
        'TIP_RABOTY'      => $tipRaboty,
        'OBOSNOVANIE'     => $obosnovanie,
        'OBOSNOVANIE_KEY' => normalizeTextKeyCustom($obosnovanie),
        'START'           => $startDateTime,
        'END'             => $endDateTime,
        'START_TS'        => $startTs,
        'HOURS'           => is_array($v3086) ? implode(', ', $v3086) : (string)$v3086,
        'TIP_OPLATY'      => $paymentType,
        'HOURS_TK'        => getHoursByPaymentTypeCustom(is_array($v3086) ? implode(', ', $v3086) : (string)$v3086, $paymentType, 'tk'),
        'HOURS_BONUS'     => getHoursByPaymentTypeCustom(is_array($v3086) ? implode(', ', $v3086) : (string)$v3086, $paymentType, 'bonus'),
        'STATUS_ID'       => $statusId,
        'STATUS_NAME'     => $statusName,
        'STATUS_CLASS'    => getStatusClassCustom($statusName),
        'HISTORY_RAW'     => is_array($v3082) ? implode("\n", $v3082) : (string)$v3082,
        'INITIATOR'       => $initiatorName,
        'RELATED_IDS'     => $relatedIds,
        'GROUP_ID'        => $groupId,
        'GROUP_INFO'      => $groupInfo,
        'GROUP_MEMBERS'   => $groupMembers,
        'OPEN_URL'        => buildElementUrlCustom($GROUP_ID, $IBLOCK_ID, $id),
    ];
}

global $USER;
$taskMap = loadCurrentUserBizprocTasksMapCustom(
    array_keys($rows),
    is_object($USER) ? (int)$USER->GetID() : 0,
    $IBLOCK_ID
);

foreach ($rows as $id => $rowData) {
    $rows[$id]['TASK_ID'] = (int)($taskMap[$id] ?? 0);
    $rows[$id]['VIEW_URL'] = buildRequestViewUrlCustom($id);
}

$executorsMap = loadCurrentExecutorsMapCustom(array_keys($rows), $IBLOCK_ID);
foreach ($rows as $id => $rowData) {
    $rows[$id]['CURRENT_EXECUTORS'] = $executorsMap[$id] ?? [];
}

/* ------------------------- сортировка ------------------------- */

$rowsSorted = array_values($rows);

usort($rowsSorted, function ($a, $b) use ($sortKey, $dir) {
    $result = 0;

    switch ($sortKey) {
        case 'fio':
            $result = strcasecmp($a['FIO'], $b['FIO']);
            break;
        case 'start':
            $result = $a['START_TS'] <=> $b['START_TS'];
            break;
        case 'status':
            $result = strcasecmp($a['STATUS_NAME'], $b['STATUS_NAME']);
            break;
        case 'id':
        default:
            $result = $a['ID'] <=> $b['ID'];
            break;
    }

    if ($result === 0) {
        $result = $a['ID'] <=> $b['ID'];
    }

    return ($dir === 'ASC') ? $result : -$result;
});

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $fileName = 'overtime_registry_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        '№ заявки',
        'ФИО',
        'Периоды работы',
        'Тип работ',
        'Обоснование',
        'ИТОГО сверхурочных часов по ТК РФ',
        'ИТОГО часы для оплаты единовременной премией',
    ], ';');

    foreach ($rowsSorted as $row) {
        fputcsv($out, [
            (int)$row['ID'],
            (string)$row['FIO'],
            trim((string)$row['START'] . ' — ' . (string)$row['END']),
            (string)$row['TIP_RABOTY'],
            (string)$row['OBOSNOVANIE'],
            number_format((float)$row['HOURS_TK'], 2, ',', ''),
            number_format((float)$row['HOURS_BONUS'], 2, ',', ''),
        ], ';');
    }

    fclose($out);
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php");
    exit;
}

/* ------------------------- группировка пар ------------------------- */

$displayRows = [];
$usedIds = [];

foreach ($rowsSorted as $row) {
    $id = (int)$row['ID'];

    if (isset($usedIds[$id])) {
        continue;
    }

    $paired = false;

    if (count($row['RELATED_IDS']) === 1) {
        $relatedId = (int)$row['RELATED_IDS'][0];

        if ($relatedId > 0 && isset($rows[$relatedId]) && !isset($usedIds[$relatedId])) {
            $other = $rows[$relatedId];

            $isMutual = in_array($id, $other['RELATED_IDS'], true);
            $sameFio = ($row['FIO_KEY'] !== '' && $row['FIO_KEY'] === $other['FIO_KEY']);
            $sameObosnovanie = ($row['OBOSNOVANIE_KEY'] === $other['OBOSNOVANIE_KEY']);

            if ($isMutual && $sameFio && $sameObosnovanie) {
                $pairItems = [$row, $other];

                usort($pairItems, function ($a, $b) {
                    return $a['START_TS'] <=> $b['START_TS'];
                });

                $displayRows[] = [
                    'MODE'       => 'PAIR',
                    'COMMON_FIO' => $row['FIO'],
                    'COMMON_OBOS' => $row['OBOSNOVANIE'],
                    'ITEMS'      => $pairItems,
                ];

                $usedIds[$id] = true;
                $usedIds[$relatedId] = true;
                $paired = true;
            }
        }
    }

    if (!$paired) {
        $displayRows[] = [
            'MODE'        => 'SINGLE',
            'COMMON_FIO'  => $row['FIO'],
            'COMMON_OBOS' => $row['OBOSNOVANIE'],
            'ITEMS'       => [$row],
        ];
        $usedIds[$id] = true;
    }
}

?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<style>
    .page-wrap { padding: 16px 24px; }
    .table thead th { white-space: nowrap; vertical-align: middle; }
    .main-table td { vertical-align: top; }
    .sort-link { color: #fff; text-decoration: none; }
    .sort-link:hover { color: #fff; text-decoration: underline; }
    .sort-caret { margin-left: 4px; font-weight: 700; }
    .nowrap { white-space: nowrap; }

    .number-link {
        font-weight: 700;
        color: #0d6efd;
        text-decoration: none;
        border: 0;
        background: transparent;
        padding: 0;
    }
    .number-link:hover {
        text-decoration: underline;
    }

    .status-pill {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        color: #fff;
        font-size: 12px;
        line-height: 1.2;
        white-space: nowrap;
        font-weight: 600;
    }
    .status-success { background: #28a745; }
    .status-danger  { background: #dc3545; }
    .status-warning { background: #f0ad4e; }
    .status-info    { background: #17a2b8; }
    .status-default { background: #6c757d; }

    .history-btn {
        border: 0;
        background: #6c757d;
        color: #fff;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        line-height: 22px;
        padding: 0;
        font-size: 12px;
        font-weight: bold;
        margin-left: 6px;
        cursor: pointer;
    }
    .history-btn:hover {
        background: #5a6268;
    }

    .pair-top-row td,
    .pair-bottom-row td {
        background: var(--pair-row-bg, #f7fbff);
    }

    .pair-top-row td {
        border-top: 2px solid var(--pair-border-color, #c9def5) !important;
    }

    .pair-bottom-row td {
        border-bottom: 2px solid var(--pair-border-color, #c9def5) !important;
    }

    .pair-top-row td:first-child,
    .pair-bottom-row td:first-child,
    .shared-cell {
        border-left: 4px solid var(--pair-accent-color, #7fb3e6) !important;
    }

    .pair-divider-cell {
        border-top: 1px dashed var(--pair-border-color, #c9def5) !important;
    }

    .shared-cell {
        background: var(--pair-shared-bg, #edf5ff) !important;
        font-weight: 500;
    }

    .pair-variant-0 {
        --pair-row-bg: #f7fbff;
        --pair-shared-bg: #edf5ff;
        --pair-border-color: #c9def5;
        --pair-accent-color: #7fb3e6;
    }

    .pair-variant-1 {
        --pair-row-bg: #f9f8ff;
        --pair-shared-bg: #f1efff;
        --pair-border-color: #d8d0fb;
        --pair-accent-color: #9d8df1;
    }

    .pair-variant-2 {
        --pair-row-bg: #f7fcf8;
        --pair-shared-bg: #ebf7ee;
        --pair-border-color: #c9e8d0;
        --pair-accent-color: #72b487;
    }

    .group-pill {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        background: #eef7f0;
        border: 1px solid #cfe3d4;
        color: #2f6b3f;
        text-decoration: none;
        font-size: 12px;
        line-height: 1.2;
        font-weight: 600;
        white-space: nowrap;
    }
    .group-pill:hover {
        text-decoration: none;
        background: #e3f2e7;
        color: #245533;
    }

    .clear-group-filter {
        display: inline-block;
        margin-left: 8px;
        font-size: 12px;
    }

    .active-group-filter-box {
        margin-bottom: 12px;
        padding: 10px 12px;
        border: 1px solid #d8e6d8;
        background: #f5fbf5;
        border-radius: 8px;
    }

    .history-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .history-modal {
        background: #fff;
        border-radius: 10px;
        max-width: 900px;
        width: 92%;
        max-height: 82vh;
        box-shadow: 0 10px 30px rgba(0,0,0,.25);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .history-modal-header {
        padding: 12px 16px;
        border-bottom: 1px solid #e5e5e5;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .history-modal-title {
        font-size: 16px;
        font-weight: 600;
    }

    .status-open-btn {
        border: 0;
        background: transparent;
        padding: 0;
    }

    .history-modal-body {
        padding: 16px;
        overflow-y: auto;
    }

    .history-modal-close {
        border: 0;
        background: transparent;
        font-size: 24px;
        line-height: 1;
        cursor: pointer;
    }
    .filter-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding: 12px 14px;
    }

    .filter-item {
        flex: 0 0 auto;
        min-width: 0;
    }

    .filter-item.search-item {
        width: 320px;
    }

    .filter-item.date-item {
        width: 155px;
    }

    .filter-item.status-item {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 280px;
        flex: 1 1 auto;
    }

    .filter-item .form-control {
        min-width: 0;
    }

    .filter-label-compact {
        font-size: 12px;
        color: #6c757d;
        white-space: nowrap;
        margin-bottom: 0;
    }

    .status-inline-group {
        display: flex;
        align-items: center;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 2px;
        white-space: nowrap;
    }

    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border: 1px solid #d7dee6;
        border-radius: 999px;
        background: #fff;
        font-size: 13px;
        line-height: 1;
        margin-bottom: 0;
        cursor: pointer;
    }

    .status-chip input {
        margin: 0;
    }

    .filter-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
        flex: 0 0 auto;
    }

    .actions-cell {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }
</style>

<div class="container-fluid page-wrap">
    <h2 class="mb-3">Заявки на сверхурочную работу / работу в выходной / дежурство</h2>

    <div class="d-flex flex-wrap align-items-center mb-3">
        <a href="<?= h($CREATE_URL) ?>" class="btn btn-success mr-3 mb-2">Создать заявку</a>
    </div>

    <?php if ($groupFilter > 0): ?>
        <div class="active-group-filter-box">
            <strong>Применен фильтр по группе:</strong>
            <?= isset($groupMap[$groupFilter]) ? h($groupMap[$groupFilter]['NAME']) . ' (#' . (int)$groupFilter . ')' : '#' . (int)$groupFilter ?>
            <a class="clear-group-filter" href="<?= h(buildGroupFilterUrlCustom(0)) ?>">Сбросить фильтр группы</a>
        </div>
    <?php endif; ?>

    <?php asort($statusVariants, SORT_NATURAL | SORT_FLAG_CASE); ?>

    <form method="get" class="card mb-3">
        <input type="hidden" name="group_filter" value="<?= $groupFilter > 0 ? (int)$groupFilter : '' ?>">
        <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
        <input type="hidden" name="dir" value="<?= h($dir) ?>">

        <div class="filter-toolbar">
            <div class="filter-item search-item">
                <input
                    type="text"
                    name="q"
                    value="<?= h($q) ?>"
                    class="form-control form-control-sm"
                    placeholder="Поиск по ФИО / обоснованию"
                    aria-label="Поиск по ФИО или обоснованию"
                >
            </div>

            <div class="filter-item date-item">
                <input
                    type="date"
                    name="date_from"
                    value="<?= h(normalizeDateCustom($dateFrom)) ?>"
                    class="form-control form-control-sm"
                    aria-label="Дата с"
                >
            </div>

            <div class="filter-item date-item">
                <input
                    type="date"
                    name="date_to"
                    value="<?= h(normalizeDateCustom($dateTo)) ?>"
                    class="form-control form-control-sm"
                    aria-label="Дата по"
                >
            </div>

            <div class="filter-item status-item">
                <span class="filter-label-compact">Статусы:</span>
                <div class="status-inline-group">
                    <?php foreach ($statusVariants as $statusId => $statusName): ?>
                        <label class="status-chip">
                            <input
                                type="checkbox"
                                name="status[]"
                                value="<?= (int)$statusId ?>"
                                <?= in_array((int)$statusId, $statusInput, true) ? 'checked' : '' ?>
                            >
                            <span><?= h($statusName) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                <a href="<?= h('?' . http_build_query(array_filter([
                    'q'            => $q,
                    'date_from'    => $dateFrom,
                    'date_to'      => $dateTo,
                    'status'       => !empty($statusInput) ? $statusInput : null,
                    'group_filter' => $groupFilter > 0 ? $groupFilter : null,
                    'sort'         => $sortKey,
                    'dir'          => $dir,
                    'export'       => 'excel',
                ], static function ($v) {
                    return $v !== null && $v !== '';
                }))) ?>" class="btn btn-outline-success btn-sm">Excel</a>
                <a href="<?= h($APPLICATION->GetCurPage()) ?>" class="btn btn-secondary btn-sm">Сбросить</a>
            </div>
        </div>
    </form>

    <?php if (empty($displayRows)): ?>
        <div class="alert alert-info">Заявки не найдены.</div>
    <?php else: ?>
        <?php
        $dirOpposite = ($dir === 'ASC') ? 'DESC' : 'ASC';

        $makeSortLink = function ($key, $title) use ($sortKey, $dir, $dirOpposite, $q, $dateFrom, $dateTo, $statusInput, $groupFilter) {
            $isActive = ($sortKey === $key);

            $params = [
                'q'           => $q,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'sort'        => $key,
                'dir'         => $isActive ? $dirOpposite : 'ASC',
                'group_filter'=> $groupFilter > 0 ? $groupFilter : '',
            ];

            if (!empty($statusInput)) {
                $params['status'] = $statusInput;
            }

            $url = '?' . http_build_query($params);
            $caret = '';

            if ($isActive) {
                $caret = ($dir === 'ASC') ? '▲' : '▼';
            }

            return '<a href="' . h($url) . '" class="sort-link">' . h($title) . ($caret ? '<span class="sort-caret">' . $caret . '</span>' : '') . '</a>';
        };
        ?>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover main-table">
                <thead class="thead-dark">
                    <tr>
                        <th><?= $makeSortLink('id', '№ заявки') ?></th>
                        <th><?= $makeSortLink('fio', 'ФИО сотрудника') ?></th>
                        <th>Тип заявки</th>
                        <th><?= $makeSortLink('start', 'Начало работ') ?></th>
                        <th>Окончание работ</th>
                        <th>Часы</th>
                        <th>Тип оплаты</th>
                        <th><?= $makeSortLink('status', 'Статус + история') ?></th>
                        <th>Групповая заявка</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $pairVariantIndex = 0; ?>
                    <?php foreach ($displayRows as $displayRow): ?>
                        <?php if ($displayRow['MODE'] === 'PAIR'): ?>
                            <?php
                            $a = $displayRow['ITEMS'][0];
                            $b = $displayRow['ITEMS'][1];
                            $pairVariantClass = 'pair-variant-' . ($pairVariantIndex % 3);
                            $pairVariantIndex++;
                            ?>
                            <tr class="pair-top-row <?= h($pairVariantClass) ?>">
                                <td class="nowrap">
                                    <button
                                        type="button"
                                        class="number-link js-request-btn"
                                        data-request-id="request-<?= (int)$a['ID'] ?>"
                                    >
                                        #<?= (int)$a['ID'] ?>
                                    </button>
                                    <div id="request-<?= (int)$a['ID'] ?>" class="d-none">
                                        <ul class="list-unstyled mb-0">
                                            <li><strong>ФИО сотрудника:</strong> <?= h($a['FIO']) ?></li>
                                            <li><strong>Тип работ:</strong> <?= h($a['TIP_RABOTY']) ?></li>
                                            <li><strong>Периоды работы:</strong> <?= h($a['START']) ?> — <?= h($a['END']) ?></li>
                                            <li><strong>Обоснование:</strong><br><?= nl2br(h($a['OBOSNOVANIE'])) ?></li>
                                            <li><strong>Инициатор заявки:</strong> <?= h($a['INITIATOR'] !== '' ? $a['INITIATOR'] : '—') ?></li>
                                        </ul>
                                    </div>
                                </td>

                                <td rowspan="2" class="shared-cell">
                                    <?= h($displayRow['COMMON_FIO']) ?>
                                </td>

                                <td><?= h($a['TIP_RABOTY']) ?></td>
                                <td class="nowrap"><?= h($a['START']) ?></td>
                                <td class="nowrap"><?= h($a['END']) ?></td>
                                <td class="nowrap"><?= h($a['HOURS']) ?></td>
                                <td><?= h($a['TIP_OPLATY']) ?></td>

                                <td>
                                    <?php if ($a['STATUS_NAME'] !== ''): ?>
                                        <button type="button" class="status-open-btn js-executors-btn" data-executors-id="executors-<?= (int)$a['ID'] ?>">
                                            <span class="status-pill <?= h($a['STATUS_CLASS']) ?>"><?= h($a['STATUS_NAME']) ?></span>
                                        </button>
                                        <div id="executors-<?= (int)$a['ID'] ?>" class="d-none">
                                            <?php if (!empty($a['CURRENT_EXECUTORS'])): ?>
                                                <ul class="mb-0">
                                                    <?php foreach ($a['CURRENT_EXECUTORS'] as $executorName): ?>
                                                        <li><?= h($executorName) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <div class="text-muted">Текущие исполнители не найдены.</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>

                                    <?php if (trim($a['HISTORY_RAW']) !== ''): ?>
                                        <button
                                            type="button"
                                            class="history-btn js-history-btn"
                                            title="Показать историю заявки"
                                            data-history-id="history-<?= (int)$a['ID'] ?>"
                                        >i</button>
                                        <div id="history-<?= (int)$a['ID'] ?>" class="d-none">
                                            <?= renderHistoryHtmlCustom($a['HISTORY_RAW']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ((int)$a['GROUP_ID'] > 0): ?>
                                        <a class="group-pill" href="<?= h(buildGroupFilterUrlCustom((int)$a['GROUP_ID'])) ?>">
                                            Группа #<?= (int)$a['GROUP_ID'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="nowrap">
                                    <div class="actions-cell">
                                        <a href="<?= h($a['VIEW_URL']) ?>" target="_blank" rel="noopener">Открыть</a>
                                        <?php if ((int)$a['TASK_ID'] > 0): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= h(buildBizprocTaskUrlCustom((int)$a['TASK_ID'], $LIST_PAGE_URL)) ?>" target="_blank" rel="noopener">Задание БП</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr class="pair-bottom-row <?= h($pairVariantClass) ?>">
                                <td class="nowrap">
                                    <button
                                        type="button"
                                        class="number-link js-request-btn"
                                        data-request-id="request-<?= (int)$b['ID'] ?>"
                                    >
                                        #<?= (int)$b['ID'] ?>
                                    </button>
                                    <div id="request-<?= (int)$b['ID'] ?>" class="d-none">
                                        <ul class="list-unstyled mb-0">
                                            <li><strong>ФИО сотрудника:</strong> <?= h($b['FIO']) ?></li>
                                            <li><strong>Тип работ:</strong> <?= h($b['TIP_RABOTY']) ?></li>
                                            <li><strong>Периоды работы:</strong> <?= h($b['START']) ?> — <?= h($b['END']) ?></li>
                                            <li><strong>Обоснование:</strong><br><?= nl2br(h($b['OBOSNOVANIE'])) ?></li>
                                            <li><strong>Инициатор заявки:</strong> <?= h($b['INITIATOR'] !== '' ? $b['INITIATOR'] : '—') ?></li>
                                        </ul>
                                    </div>
                                </td>

                                <td class="pair-divider-cell"><?= h($b['TIP_RABOTY']) ?></td>
                                <td class="nowrap pair-divider-cell"><?= h($b['START']) ?></td>
                                <td class="nowrap pair-divider-cell"><?= h($b['END']) ?></td>
                                <td class="nowrap pair-divider-cell"><?= h($b['HOURS']) ?></td>
                                <td class="pair-divider-cell"><?= h($b['TIP_OPLATY']) ?></td>

                                <td class="pair-divider-cell">
                                    <?php if ($b['STATUS_NAME'] !== ''): ?>
                                        <button type="button" class="status-open-btn js-executors-btn" data-executors-id="executors-<?= (int)$b['ID'] ?>">
                                            <span class="status-pill <?= h($b['STATUS_CLASS']) ?>"><?= h($b['STATUS_NAME']) ?></span>
                                        </button>
                                        <div id="executors-<?= (int)$b['ID'] ?>" class="d-none">
                                            <?php if (!empty($b['CURRENT_EXECUTORS'])): ?>
                                                <ul class="mb-0">
                                                    <?php foreach ($b['CURRENT_EXECUTORS'] as $executorName): ?>
                                                        <li><?= h($executorName) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <div class="text-muted">Текущие исполнители не найдены.</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>

                                    <?php if (trim($b['HISTORY_RAW']) !== ''): ?>
                                        <button
                                            type="button"
                                            class="history-btn js-history-btn"
                                            title="Показать историю заявки"
                                            data-history-id="history-<?= (int)$b['ID'] ?>"
                                        >i</button>
                                        <div id="history-<?= (int)$b['ID'] ?>" class="d-none">
                                            <?= renderHistoryHtmlCustom($b['HISTORY_RAW']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="pair-divider-cell">
                                    <?php if ((int)$b['GROUP_ID'] > 0): ?>
                                        <a class="group-pill" href="<?= h(buildGroupFilterUrlCustom((int)$b['GROUP_ID'])) ?>">
                                            Группа #<?= (int)$b['GROUP_ID'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="nowrap pair-divider-cell">
                                    <div class="actions-cell">
                                        <a href="<?= h($b['VIEW_URL']) ?>" target="_blank" rel="noopener">Открыть</a>
                                        <?php if ((int)$b['TASK_ID'] > 0): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= h(buildBizprocTaskUrlCustom((int)$b['TASK_ID'], $LIST_PAGE_URL)) ?>" target="_blank" rel="noopener">Задание БП</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>
                            <?php $row = $displayRow['ITEMS'][0]; ?>
                            <tr>
                                <td class="nowrap">
                                    <button
                                        type="button"
                                        class="number-link js-request-btn"
                                        data-request-id="request-<?= (int)$row['ID'] ?>"
                                    >
                                        #<?= (int)$row['ID'] ?>
                                    </button>
                                    <div id="request-<?= (int)$row['ID'] ?>" class="d-none">
                                        <ul class="list-unstyled mb-0">
                                            <li><strong>ФИО сотрудника:</strong> <?= h($row['FIO']) ?></li>
                                            <li><strong>Тип работ:</strong> <?= h($row['TIP_RABOTY']) ?></li>
                                            <li><strong>Периоды работы:</strong> <?= h($row['START']) ?> — <?= h($row['END']) ?></li>
                                            <li><strong>Обоснование:</strong><br><?= nl2br(h($row['OBOSNOVANIE'])) ?></li>
                                            <li><strong>Инициатор заявки:</strong> <?= h($row['INITIATOR'] !== '' ? $row['INITIATOR'] : '—') ?></li>
                                        </ul>
                                    </div>
                                </td>
                                <td><?= h($row['FIO']) ?></td>
                                <td><?= h($row['TIP_RABOTY']) ?></td>
                                <td class="nowrap"><?= h($row['START']) ?></td>
                                <td class="nowrap"><?= h($row['END']) ?></td>
                                <td class="nowrap"><?= h($row['HOURS']) ?></td>
                                <td><?= h($row['TIP_OPLATY']) ?></td>

                                <td>
                                    <?php if ($row['STATUS_NAME'] !== ''): ?>
                                        <button type="button" class="status-open-btn js-executors-btn" data-executors-id="executors-<?= (int)$row['ID'] ?>">
                                            <span class="status-pill <?= h($row['STATUS_CLASS']) ?>"><?= h($row['STATUS_NAME']) ?></span>
                                        </button>
                                        <div id="executors-<?= (int)$row['ID'] ?>" class="d-none">
                                            <?php if (!empty($row['CURRENT_EXECUTORS'])): ?>
                                                <ul class="mb-0">
                                                    <?php foreach ($row['CURRENT_EXECUTORS'] as $executorName): ?>
                                                        <li><?= h($executorName) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <div class="text-muted">Текущие исполнители не найдены.</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>

                                    <?php if (trim($row['HISTORY_RAW']) !== ''): ?>
                                        <button
                                            type="button"
                                            class="history-btn js-history-btn"
                                            title="Показать историю заявки"
                                            data-history-id="history-<?= (int)$row['ID'] ?>"
                                        >i</button>
                                        <div id="history-<?= (int)$row['ID'] ?>" class="d-none">
                                            <?= renderHistoryHtmlCustom($row['HISTORY_RAW']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ((int)$row['GROUP_ID'] > 0): ?>
                                        <a class="group-pill" href="<?= h(buildGroupFilterUrlCustom((int)$row['GROUP_ID'])) ?>">
                                            Группа #<?= (int)$row['GROUP_ID'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="nowrap">
                                    <div class="actions-cell">
                                        <a href="<?= h($row['VIEW_URL']) ?>" target="_blank" rel="noopener">Открыть</a>
                                        <?php if ((int)$row['TASK_ID'] > 0): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="<?= h(buildBizprocTaskUrlCustom((int)$row['TASK_ID'], $LIST_PAGE_URL)) ?>" target="_blank" rel="noopener">Задание БП</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="history-modal-backdrop" class="history-modal-backdrop">
    <div class="history-modal">
        <div class="history-modal-header">
            <div class="history-modal-title" id="history-modal-title">Информация</div>
            <button type="button" class="history-modal-close js-history-close">&times;</button>
        </div>
        <div class="history-modal-body" id="history-modal-body"></div>
    </div>
</div>

<script>
(function() {
    var backdrop = document.getElementById('history-modal-backdrop');
    var bodyEl = document.getElementById('history-modal-body');
    var titleEl = document.getElementById('history-modal-title');

    if (!backdrop || !bodyEl || !titleEl) {
        return;
    }

    function openModal(title, html) {
        titleEl.textContent = title;
        bodyEl.innerHTML = html;
        backdrop.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeHistory() {
        backdrop.style.display = 'none';
        bodyEl.innerHTML = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('.js-history-btn') : null;
        if (btn) {
            var id = btn.getAttribute('data-history-id');
            var container = document.getElementById(id);
            if (container) {
                openModal('История заявки', container.innerHTML);
            }
            return;
        }

        var requestBtn = e.target.closest ? e.target.closest('.js-request-btn') : null;
        if (requestBtn) {
            var requestId = requestBtn.getAttribute('data-request-id');
            var requestContainer = document.getElementById(requestId);
            if (requestContainer) {
                openModal('Сведения о заявке', requestContainer.innerHTML);
            }
            return;
        }

        var executorsBtn = e.target.closest ? e.target.closest('.js-executors-btn') : null;
        if (executorsBtn) {
            var executorsId = executorsBtn.getAttribute('data-executors-id');
            var executorsContainer = document.getElementById(executorsId);
            if (executorsContainer) {
                openModal('Текущие исполнители', executorsContainer.innerHTML);
            }
            return;
        }

        if (e.target === backdrop || (e.target.closest && e.target.closest('.js-history-close'))) {
            closeHistory();
        }
    });
})();
</script>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
