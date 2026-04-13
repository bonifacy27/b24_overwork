<?php

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

function overtimeH($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function overtimeGetUserDataById(int $userId): array
{
    $result = [
        'id' => $userId,
        'name' => '',
        'position' => '',
        'display' => '',
    ];

    if ($userId <= 0) {
        return $result;
    }

    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        $name = trim($arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME']);
        $position = trim((string)$arUser['WORK_POSITION']);

        $result['name'] = $name;
        $result['position'] = $position;
        $result['display'] = trim($name . ($position !== '' ? ' — ' . $position : ''));
    }

    return $result;
}

function overtimeGetUserNameById(int $userId): string
{
    $data = overtimeGetUserDataById($userId);
    return $data['name'];
}



function overtimeGetDutyAllowedEmployeeIds(array $config): array
{
    static $cache = [];

    $cacheKey = md5(serialize([
        (int)($config['IBLOCK_DUTY_ACCESS'] ?? 0),
        (string)($config['DUTY_ACCESS_PROP_EMPLOYEE'] ?? ''),
    ]));

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $result = [];
    $iblockId = (int)($config['IBLOCK_DUTY_ACCESS'] ?? 0);
    $propCode = (string)($config['DUTY_ACCESS_PROP_EMPLOYEE'] ?? '');

    if ($iblockId <= 0 || $propCode === '') {
        $cache[$cacheKey] = [];
        return $cache[$cacheKey];
    }

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ],
        false,
        false,
        ['ID', 'PROPERTY_' . $propCode]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $propCode . '_VALUE'] ?? 0);
        if ($employeeId > 0) {
            $result[$employeeId] = $employeeId;
        }
    }

    $cache[$cacheKey] = array_values($result);
    return $cache[$cacheKey];
}

function overtimeCanCurrentUserUseDuty(int $userId, array $config): bool
{
    if ($userId <= 0) {
        return false;
    }

    $allowedUserIds = overtimeGetDutyAllowedEmployeeIds($config);
    return in_array($userId, $allowedUserIds, true);
}

function overtimeGetPastPeriodAllowedEmployeeIds(array $config): array
{
    static $cache = [];

    $cacheKey = md5(serialize([
        (int)($config['IBLOCK_PAST_PERIOD_ACCESS'] ?? 0),
        (string)($config['PAST_PERIOD_ACCESS_PROP_EMPLOYEE'] ?? ''),
    ]));

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $result = [];
    $iblockId = (int)($config['IBLOCK_PAST_PERIOD_ACCESS'] ?? 0);
    $propCode = (string)($config['PAST_PERIOD_ACCESS_PROP_EMPLOYEE'] ?? '');

    if ($iblockId <= 0 || $propCode === '') {
        $cache[$cacheKey] = [];
        return $cache[$cacheKey];
    }

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ],
        false,
        false,
        ['ID', 'PROPERTY_' . $propCode]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $propCode . '_VALUE'] ?? 0);
        if ($employeeId > 0) {
            $result[$employeeId] = $employeeId;
        }
    }

    $cache[$cacheKey] = array_values($result);
    return $cache[$cacheKey];
}

function overtimeCanCreatePastPeriodForEmployee(int $employeeId, array $config): bool
{
    if ($employeeId <= 0) {
        return false;
    }

    $allowedEmployeeIds = overtimeGetPastPeriodAllowedEmployeeIds($config);
    return in_array($employeeId, $allowedEmployeeIds, true);
}

function overtimeGetCreatorAccessMap(int $creatorId, array $config): array
{
    static $cache = [];

    $cacheKey = md5(serialize([$creatorId, (int)($config['STRUCTURE_IBLOCK_ID'] ?? 0)]));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $result = [
        'is_manager' => false,
        'allowed_employee_ids' => [$creatorId],
        'subordinate_employee_ids' => [],
        'managed_section_ids' => [],
    ];

    if ($creatorId <= 0) {
        $cache[$cacheKey] = $result;
        return $result;
    }

    $iblockId = (int)($config['STRUCTURE_IBLOCK_ID'] ?? 0);
    if ($iblockId <= 0) {
        $cache[$cacheKey] = $result;
        return $result;
    }

    $sections = [];
    $children = [];
    $rsSections = CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y'],
        false,
        ['ID', 'IBLOCK_SECTION_ID', 'UF_HEAD', 'UF_DEPUTY']
    );

    while ($section = $rsSections->GetNext()) {
        $sectionId = (int)$section['ID'];
        $parentId = (int)$section['IBLOCK_SECTION_ID'];
        $headId = (int)($section['UF_HEAD'] ?? 0);
        $deputyId = (int)($section['UF_DEPUTY'] ?? 0);

        $sections[$sectionId] = [
            'id' => $sectionId,
            'parent_id' => $parentId,
            'head_id' => $headId,
            'deputy_id' => $deputyId,
        ];

        if (!isset($children[$parentId])) {
            $children[$parentId] = [];
        }
        $children[$parentId][] = $sectionId;

        if ($creatorId === $headId || $creatorId === $deputyId) {
            $result['managed_section_ids'][$sectionId] = $sectionId;
        }
    }

    if (empty($result['managed_section_ids'])) {
        $cache[$cacheKey] = $result;
        return $result;
    }

    $allManagedSectionIds = [];
    $queue = array_values($result['managed_section_ids']);
    while (!empty($queue)) {
        $sectionId = (int)array_shift($queue);
        if ($sectionId <= 0 || isset($allManagedSectionIds[$sectionId])) {
            continue;
        }

        $allManagedSectionIds[$sectionId] = $sectionId;
        foreach (($children[$sectionId] ?? []) as $childId) {
            if (!isset($allManagedSectionIds[$childId])) {
                $queue[] = (int)$childId;
            }
        }
    }

    if (!empty($allManagedSectionIds)) {
        $userRes = CUser::GetList(
            $by = 'id',
            $order = 'asc',
            [
                'ACTIVE' => 'Y',
                'UF_DEPARTMENT' => array_values($allManagedSectionIds),
            ],
            ['FIELDS' => ['ID']]
        );

        while ($user = $userRes->Fetch()) {
            $employeeId = (int)$user['ID'];
            if ($employeeId > 0 && $employeeId !== $creatorId) {
                $result['subordinate_employee_ids'][$employeeId] = $employeeId;
            }
        }
    }

    $result['is_manager'] = !empty($result['subordinate_employee_ids']);
    $result['subordinate_employee_ids'] = array_values($result['subordinate_employee_ids']);
    $result['managed_section_ids'] = array_values($result['managed_section_ids']);
    $result['allowed_employee_ids'] = array_values(array_unique(array_merge([$creatorId], $result['subordinate_employee_ids'])));

    $cache[$cacheKey] = $result;
    return $cache[$cacheKey];
}

function overtimeCanCreatorCreateForEmployee(int $creatorId, int $employeeId, array $config): bool
{
    if ($creatorId <= 0 || $employeeId <= 0) {
        return false;
    }

    $accessMap = overtimeGetCreatorAccessMap($creatorId, $config);
    return in_array($employeeId, $accessMap['allowed_employee_ids'], true);
}

function overtimeGetHourOptions(): array
{
    $result = [];
    for ($hour = 0; $hour <= 23; $hour++) {
        $value = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
        $result[] = $value;
    }

    return $result;
}

function overtimeParseDateTimeFromHtml(string $date, string $time): ?DateTime
{
    $date = trim($date);
    $time = trim($time);

    if ($date === '' || $time === '') {
        return null;
    }

    if (!preg_match('/^\d{2}:00$/', $time)) {
        return null;
    }

    $timestamp = strtotime($date . ' ' . $time . ':00');
    if ($timestamp === false) {
        return null;
    }

    return new DateTime(date('d.m.Y H:i:s', $timestamp), 'd.m.Y H:i:s');
}

function overtimeFormatHuman(DateTime $dateTime): string
{
    return $dateTime->format('d.m.Y H:i');
}

function overtimeGetHoursDiff(DateTime $start, DateTime $end): float
{
    $startTs = strtotime($start->format('Y-m-d H:i:s'));
    $endTs = strtotime($end->format('Y-m-d H:i:s'));

    return round(($endTs - $startTs) / 3600, 2);
}

function overtimeIsWorkday1C(string $dateCalc): bool
{
    $dateCalc = date('Y-m-d', strtotime($dateCalc));

    $connection = Application::getConnection('gatedb');

    $sql = "SELECT CONVERT(TEXT, Calend_TimeType) AS CALEND_TIME_TYPE
            FROM GateDB.dbo.ProdCalendar_1CZUP
            WHERE Calend_Date = '" . $dateCalc . "'
              AND Source ='Srvr=srv-off-1c01;Ref=1c_Pay83_NSC;'";

    $recordset = $connection->query($sql);
    $row = $recordset->fetch();

    $type = isset($row['CALEND_TIME_TYPE']) ? trim((string)$row['CALEND_TIME_TYPE']) : '';

    return in_array($type, ['Рабочий', 'Предпраздничный'], true);
}

function overtimeGetPaymentTypesByWorkType(int $workTypeId, array $config): array
{
    $result = [];

    $res = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        [
            'IBLOCK_ID' => $config['IBLOCK_PAYMENT_TYPES'],
            'ACTIVE' => 'Y',
            'PROPERTY_' . $config['PAYMENT_PROP_WORK_TYPES'] => $workTypeId,
        ],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($item = $res->Fetch()) {
        $result[] = [
            'ID' => (int)$item['ID'],
            'NAME' => $item['NAME'],
        ];
    }

    return $result;
}

function overtimeGetOvertimeHoursInRegistry(int $employeeId, int $year, array $config): float
{
    $sum = 0.0;

    $res = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => $config['IBLOCK_OVERTIME_REGISTRY'],
            'ACTIVE' => 'Y',
            'PROPERTY_' . $config['REG_PROP_EMPLOYEE'] => $employeeId,
            'PROPERTY_' . $config['REG_PROP_YEAR'] => $year,
        ],
        false,
        false,
        ['ID', 'PROPERTY_' . $config['REG_PROP_HOURS']]
    );

    while ($item = $res->Fetch()) {
        $value = $item['PROPERTY_' . $config['REG_PROP_HOURS'] . '_VALUE'];
        $sum += (float)str_replace(',', '.', (string)$value);
    }

    return round($sum, 2);
}

function overtimeSplitHoursByDay(DateTime $start, DateTime $end): array
{
    $result = [];

    $cursorStart = strtotime($start->format('Y-m-d H:i:s'));
    $endTs = strtotime($end->format('Y-m-d H:i:s'));

    while ($cursorStart < $endTs) {
        $currentDate = date('Y-m-d', $cursorStart);
        $nextDateTs = strtotime($currentDate . ' 23:59:59') + 1;
        $segmentEnd = min($nextDateTs, $endTs);

        $hours = round(($segmentEnd - $cursorStart) / 3600, 2);

        if (!isset($result[$currentDate])) {
            $result[$currentDate] = 0.0;
        }

        $result[$currentDate] += $hours;
        $cursorStart = $segmentEnd;
    }

    return $result;
}

function overtimeGetExistingOvertimeHoursByDay(int $employeeId, DateTime $periodStart, DateTime $periodEnd, array $config): array
{
    $result = [];

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $config['IBLOCK_REQUESTS'],
            'ACTIVE' => 'Y',
            'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] => $employeeId,
            'PROPERTY_' . $config['REQ_PROP_WORK_TYPE'] => $config['WORK_TYPE_OVERTIME_ID'],
            '!=PROPERTY_' . $config['REQ_PROP_STATUS'] => $config['STATUS_CANCELLED_ID'],
        ],
        false,
        false,
        [
            'ID',
            'PROPERTY_' . $config['REQ_PROP_START'],
            'PROPERTY_' . $config['REQ_PROP_END']
        ]
    );

    while ($item = $res->Fetch()) {
        $startValue = $item['PROPERTY_' . $config['REQ_PROP_START'] . '_VALUE'];
        $endValue   = $item['PROPERTY_' . $config['REQ_PROP_END'] . '_VALUE'];

        if (!$startValue || !$endValue) {
            continue;
        }

        try {
            $start = new DateTime($startValue);
            $end = new DateTime($endValue);
        } catch (Throwable $e) {
            continue;
        }

        $startTs = strtotime($start->format('Y-m-d H:i:s'));
        $endTs   = strtotime($end->format('Y-m-d H:i:s'));
        $periodStartTs = strtotime($periodStart->format('Y-m-d H:i:s'));
        $periodEndTs   = strtotime($periodEnd->format('Y-m-d H:i:s'));

        if ($endTs <= $periodStartTs || $startTs >= $periodEndTs) {
            continue;
        }

        $byDay = overtimeSplitHoursByDay($start, $end);
        foreach ($byDay as $date => $hours) {
            if (!isset($result[$date])) {
                $result[$date] = 0.0;
            }
            $result[$date] += $hours;
        }
    }

    foreach ($result as $date => $hours) {
        $result[$date] = round($hours, 2);
    }

    return $result;
}

function overtimeBuildDefaultFormData(int $currentUserId): array
{
    return [
        'mode' => 'single',
        'single' => [
            'employee_id' => 0,
            'employee_name' => '',
            'employee_position' => '',
            'date_start' => '',
            'time_start' => '',
            'date_end' => '',
            'time_end' => '',
            'is_duty' => 'N',
            'justification' => '',
            'late_ack' => 'N',
            'payment_type' => [],
        ],
        'common' => [
            'date_start' => '',
            'time_start' => '',
            'date_end' => '',
            'time_end' => '',
            'is_duty' => 'N',
            'justification' => '',
            'late_ack' => 'N',
        ],
        'rows_same' => [
            [
                'employee_id' => 0,
                'employee_name' => '',
                'employee_position' => '',
                'payment_type' => [],
            ],
        ],
        'rows_diff' => [
            [
                'employee_id' => 0,
                'employee_name' => '',
                'employee_position' => '',
                'date_start' => '',
                'time_start' => '',
                'date_end' => '',
                'time_end' => '',
                'payment_type' => [],
            ],
        ],
    ];
}

function overtimeMergePostedFormData(array $formData, array $post): array
{
    if (!empty($post['mode'])) {
        $formData['mode'] = trim((string)$post['mode']);
    }

    if (!empty($post['single']) && is_array($post['single'])) {
        $formData['single'] = array_merge($formData['single'], $post['single']);
        $formData['single']['employee_id'] = (int)$formData['single']['employee_id'];

        $userData = overtimeGetUserDataById((int)$formData['single']['employee_id']);
        $formData['single']['employee_name'] = $userData['name'];
        $formData['single']['employee_position'] = $userData['position'];
        $formData['single']['payment_type'] = is_array($post['single']['payment_type'] ?? null) ? $post['single']['payment_type'] : [];
        $formData['single']['late_ack'] = (($post['single']['late_ack'] ?? 'N') === 'Y') ? 'Y' : 'N';
    }

    if (!empty($post['common']) && is_array($post['common'])) {
        $formData['common'] = array_merge($formData['common'], $post['common']);
        $formData['common']['late_ack'] = (($post['common']['late_ack'] ?? 'N') === 'Y') ? 'Y' : 'N';
    }

    if (!empty($post['rows_same']) && is_array($post['rows_same'])) {
        $formData['rows_same'] = [];
        foreach ($post['rows_same'] as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            $userData = overtimeGetUserDataById($employeeId);

            $formData['rows_same'][] = [
                'employee_id' => $employeeId,
                'employee_name' => $userData['name'],
                'employee_position' => $userData['position'],
                'payment_type' => is_array($row['payment_type'] ?? null) ? $row['payment_type'] : [],
            ];
        }
    }

    if (!empty($post['rows_diff']) && is_array($post['rows_diff'])) {
        $formData['rows_diff'] = [];
        foreach ($post['rows_diff'] as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            $userData = overtimeGetUserDataById($employeeId);

            $formData['rows_diff'][] = [
                'employee_id' => $employeeId,
                'employee_name' => $userData['name'],
                'employee_position' => $userData['position'],
                'date_start' => trim((string)($row['date_start'] ?? '')),
                'time_start' => trim((string)($row['time_start'] ?? '')),
                'date_end' => trim((string)($row['date_end'] ?? '')),
                'time_end' => trim((string)($row['time_end'] ?? '')),
                'payment_type' => is_array($row['payment_type'] ?? null) ? $row['payment_type'] : [],
            ];
        }
    }

    return $formData;
}
