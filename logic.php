<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

function overtimeBuildSegments(DateTime $start, DateTime $end, bool $isDuty, array $config): array
{
    if ($isDuty) {
        return [[
            'type_id' => (int)$config['WORK_TYPE_DUTY_ID'],
            'type_name' => 'Дежурство',
            'start' => $start,
            'end' => $end,
            'hours' => overtimeGetHoursDiff($start, $end),
            'day_type' => 'duty',
        ]];
    }

    $segments = [];
    $cursorStartTs = strtotime($start->format('Y-m-d H:i:s'));
    $endTs = strtotime($end->format('Y-m-d H:i:s'));

    while ($cursorStartTs < $endTs) {
        $currentDate = date('Y-m-d', $cursorStartTs);
        $nextDayTs = strtotime($currentDate . ' 23:59:59') + 1;
        $segmentEndTs = min($nextDayTs, $endTs);

        $segmentStart = new DateTime(date('d.m.Y H:i:s', $cursorStartTs), 'd.m.Y H:i:s');
        $segmentEnd = new DateTime(date('d.m.Y H:i:s', $segmentEndTs), 'd.m.Y H:i:s');

        $isWorkday = overtimeIsWorkday1C($currentDate);
        $typeId = $isWorkday ? (int)$config['WORK_TYPE_OVERTIME_ID'] : (int)$config['WORK_TYPE_WEEKEND_ID'];
        $typeName = $isWorkday ? 'Сверхурочная работа' : 'Работа в выходной день';
        $dayType = $isWorkday ? 'workday' : 'weekend';

        if (!empty($segments)) {
            $lastIndex = array_key_last($segments);
            $last = $segments[$lastIndex];

            if (
                (int)$last['type_id'] === $typeId
                && $last['end']->format('Y-m-d H:i:s') === $segmentStart->format('Y-m-d H:i:s')
            ) {
                $segments[$lastIndex]['end'] = $segmentEnd;
                $segments[$lastIndex]['hours'] = overtimeGetHoursDiff($segments[$lastIndex]['start'], $segmentEnd);
                $cursorStartTs = $segmentEndTs;
                continue;
            }
        }

        $segments[] = [
            'type_id' => $typeId,
            'type_name' => $typeName,
            'start' => $segmentStart,
            'end' => $segmentEnd,
            'hours' => overtimeGetHoursDiff($segmentStart, $segmentEnd),
            'day_type' => $dayType,
        ];

        $cursorStartTs = $segmentEndTs;
    }

    return $segments;
}

function overtimeAnalyzeChecks(int $employeeId, array $segments, array $config): array
{
    $messages = [];

    $overtimeSegments = array_values(array_filter($segments, static function ($segment) use ($config) {
        return (int)$segment['type_id'] === (int)$config['WORK_TYPE_OVERTIME_ID'];
    }));

    if (empty($overtimeSegments)) {
        return [
            'messages' => [],
            'registry_total' => 0,
            'current_overtime_hours' => 0,
            'two_day_exceed' => [],
        ];
    }

    $currentOvertimeHours = 0.0;
    $newOvertimeHoursByDay = [];

    foreach ($overtimeSegments as $segment) {
        $currentOvertimeHours += (float)$segment['hours'];

        $byDay = overtimeSplitHoursByDay($segment['start'], $segment['end']);
        foreach ($byDay as $date => $hours) {
            if (!isset($newOvertimeHoursByDay[$date])) {
                $newOvertimeHoursByDay[$date] = 0.0;
            }
            $newOvertimeHoursByDay[$date] += $hours;
        }
    }

    $currentOvertimeHours = round($currentOvertimeHours, 2);
    $year = (int)$overtimeSegments[0]['start']->format('Y');
    $registryHours = overtimeGetOvertimeHoursInRegistry($employeeId, $year, $config);
    $withCurrent = round($registryHours + $currentOvertimeHours, 2);

    if ($registryHours > 120) {
        $messages[] = [
            'type' => 'warning',
            'text' => 'Количество сверхурочных часов в текущем году уже более 120 часов: ' . $registryHours . 'ч.',
        ];
    } elseif ($withCurrent > 120) {
        $messages[] = [
            'type' => 'warning',
            'text' => 'Количество сверхурочных часов в текущем году с учетом текущей заявки будет более 120 часов: ' . $withCurrent . 'ч.',
        ];
    } else {
        $messages[] = [
            'type' => 'success',
            'text' => 'Количество сверхурочных часов в текущем году не превышает 120 часов (' . $withCurrent . 'ч.)',
        ];
    }

    $dates = array_keys($newOvertimeHoursByDay);
    sort($dates);

    if (!empty($dates)) {
        $minDate = reset($dates);
        $maxDate = end($dates);

        $periodStart = new DateTime(date('d.m.Y H:i:s', strtotime($minDate . ' -1 day 00:00:00')), 'd.m.Y H:i:s');
        $periodEnd = new DateTime(date('d.m.Y H:i:s', strtotime($maxDate . ' +1 day 23:59:59')), 'd.m.Y H:i:s');

        $existingByDay = overtimeGetExistingOvertimeHoursByDay($employeeId, $periodStart, $periodEnd, $config);
        $combined = $existingByDay;

        foreach ($newOvertimeHoursByDay as $date => $hours) {
            if (!isset($combined[$date])) {
                $combined[$date] = 0.0;
            }
            $combined[$date] += $hours;
        }

        ksort($combined);
        $combinedDates = array_keys($combined);
        $twoDayExceed = [];

        for ($i = 0, $cnt = count($combinedDates) - 1; $i < $cnt; $i++) {
            $date1 = $combinedDates[$i];
            $date2 = $combinedDates[$i + 1];

            if ((strtotime($date2) - strtotime($date1)) !== 86400) {
                continue;
            }

            $pairHours = round($combined[$date1] + $combined[$date2], 2);
            if ($pairHours > 4) {
                $twoDayExceed[] = [
                    'date1' => $date1,
                    'date2' => $date2,
                    'hours' => $pairHours,
                    'exceed' => round($pairHours - 4, 2),
                ];
            }
        }

        if (empty($twoDayExceed)) {
            $messages[] = [
                'type' => 'success',
                'text' => 'Ограничение 4 часа за 2 дня подряд не превышено',
            ];
        } else {
            foreach ($twoDayExceed as $exceed) {
                $messages[] = [
                    'type' => 'warning',
                    'text' => 'Превышено ограничение 4 часа за 2 дня подряд (' . $exceed['date1'] . ' и ' . $exceed['date2'] . '): всего ' . $exceed['hours'] . ' ч., превышение ' . $exceed['exceed'] . ' ч.',
                ];
            }
        }
    }

    return [
        'messages' => $messages,
        'registry_total' => $registryHours,
        'current_overtime_hours' => $currentOvertimeHours,
        'two_day_exceed' => $twoDayExceed ?? [],
    ];
}

function overtimeNormalizeSegments(array $segments): array
{
    $result = [];

    foreach ($segments as $segment) {
        $result[] = [
            'type_id' => (int)$segment['type_id'],
            'type_name' => (string)$segment['type_name'],
            'start' => $segment['start']->format('Y-m-d H:i:s'),
            'end' => $segment['end']->format('Y-m-d H:i:s'),
            'hours' => (float)$segment['hours'],
            'day_type' => (string)$segment['day_type'],
        ];
    }

    return $result;
}

function overtimeRestoreSegments(array $rawSegments): array
{
    $result = [];

    foreach ($rawSegments as $segment) {
        $result[] = [
            'type_id' => (int)$segment['type_id'],
            'type_name' => (string)$segment['type_name'],
            'start' => new DateTime(date('d.m.Y H:i:s', strtotime($segment['start'])), 'd.m.Y H:i:s'),
            'end' => new DateTime(date('d.m.Y H:i:s', strtotime($segment['end'])), 'd.m.Y H:i:s'),
            'hours' => (float)$segment['hours'],
            'day_type' => (string)$segment['day_type'],
        ];
    }

    return $result;
}

function overtimeGetRegistryHoursBeforeCurrentSegment(int $employeeId, DateTime $segmentStart, array $config): float
{
    $year = (int)$segmentStart->format('Y');
    return overtimeGetOvertimeHoursInRegistry($employeeId, $year, $config);
}

function overtimeGetExistingOvertimeHoursByDayForSegment(int $employeeId, DateTime $segmentStart, DateTime $segmentEnd, array $config): array
{
    $minDate = date('Y-m-d', strtotime($segmentStart->format('Y-m-d H:i:s') . ' -1 day'));
    $maxDate = date('Y-m-d', strtotime($segmentEnd->format('Y-m-d H:i:s') . ' +1 day'));

    $periodStart = new DateTime(date('d.m.Y H:i:s', strtotime($minDate . ' 00:00:00')), 'd.m.Y H:i:s');
    $periodEnd = new DateTime(date('d.m.Y H:i:s', strtotime($maxDate . ' 23:59:59')), 'd.m.Y H:i:s');

    return overtimeGetExistingOvertimeHoursByDay($employeeId, $periodStart, $periodEnd, $config);
}

function overtimeSplitSegmentToHourSlots(DateTime $start, DateTime $end): array
{
    $slots = [];
    $cursorTs = strtotime($start->format('Y-m-d H:i:s'));
    $endTs = strtotime($end->format('Y-m-d H:i:s'));

    while ($cursorTs < $endTs) {
        $slotEndTs = min($cursorTs + 3600, $endTs);

        $slotStart = new DateTime(date('d.m.Y H:i:s', $cursorTs), 'd.m.Y H:i:s');
        $slotEnd = new DateTime(date('d.m.Y H:i:s', $slotEndTs), 'd.m.Y H:i:s');

        $slots[] = [
            'start' => $slotStart,
            'end' => $slotEnd,
            'date' => $slotStart->format('Y-m-d'),
            'hours' => round(($slotEndTs - $cursorTs) / 3600, 2),
        ];

        $cursorTs = $slotEndTs;
    }

    return $slots;
}

function overtimeIsNightSlot(array $slot): bool
{
    $hour = (int)$slot['start']->format('H');
    return ($hour >= 22 || $hour < 6);
}

function overtimeFormatDebugInterval(DateTime $start, DateTime $end): string
{
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    $startText = $start->format('H:i');
    $endText = $end->format('H:i');

    if ($startDate !== $endDate && $endText === '00:00') {
        $endText = '24:00';
    }

    return $startText . '-' . $endText;
}

function overtimeExplainRejectedSlot(
    array $candidateSlot,
    array $acceptedSlots,
    array $existingByDay,
    float $registryHoursBefore,
    float $segmentTotalHours = 0.0
): array {
    $acceptedHours = 0.0;
    $acceptedByDay = [];

    foreach ($acceptedSlots as $slot) {
        $acceptedHours += (float)$slot['hours'];
        if (!isset($acceptedByDay[$slot['date']])) {
            $acceptedByDay[$slot['date']] = 0.0;
        }
        $acceptedByDay[$slot['date']] += (float)$slot['hours'];
    }

    $finalYearValue = round($registryHoursBefore + $segmentTotalHours, 2);

    if (($registryHoursBefore + $acceptedHours + (float)$candidateSlot['hours']) > 120) {
        return [
            'reason_code' => 'YEAR_LIMIT',
            'reason_text' => 'не включены в оплату по ТК РФ, так как при добавлении всей заявки годовое количество сверхурочных часов превысило бы 120 (' . $finalYearValue . ')',
        ];
    }

    $candidateDate = $candidateSlot['date'];
    if (!isset($acceptedByDay[$candidateDate])) {
        $acceptedByDay[$candidateDate] = 0.0;
    }
    $acceptedByDay[$candidateDate] += (float)$candidateSlot['hours'];

    $datesToCheck = [
        date('Y-m-d', strtotime($candidateDate . ' -1 day')),
        $candidateDate,
        date('Y-m-d', strtotime($candidateDate . ' +1 day')),
    ];

    foreach ($datesToCheck as $baseDate) {
        $nextDate = date('Y-m-d', strtotime($baseDate . ' +1 day'));

        $sum = (float)($existingByDay[$baseDate] ?? 0)
            + (float)($acceptedByDay[$baseDate] ?? 0)
            + (float)($existingByDay[$nextDate] ?? 0)
            + (float)($acceptedByDay[$nextDate] ?? 0);

        if ($sum > 4.0) {
            return [
                'reason_code' => 'TWO_DAY_LIMIT',
                'reason_text' => 'не включены в оплату по ТК РФ, так как при последовательном расчете был бы превышен лимит 4 часа за 2 дня подряд (пара дат ' . $baseDate . ' / ' . $nextDate . ', всего ' . round($sum, 2) . ' ч.)',
            ];
        }
    }

    return [
        'reason_code' => 'LIMIT',
        'reason_text' => 'не включены в оплату по ТК РФ из-за превышения ограничений',
    ];
}

function overtimeCanAllocateSlotToTk(array $candidateSlot, array $acceptedSlots, array $existingByDay, float $registryHoursBefore): bool
{
    $acceptedHours = 0.0;
    $acceptedByDay = [];

    foreach ($acceptedSlots as $slot) {
        $acceptedHours += (float)$slot['hours'];
        if (!isset($acceptedByDay[$slot['date']])) {
            $acceptedByDay[$slot['date']] = 0.0;
        }
        $acceptedByDay[$slot['date']] += (float)$slot['hours'];
    }

    if (($registryHoursBefore + $acceptedHours + (float)$candidateSlot['hours']) > 120) {
        return false;
    }

    $candidateDate = $candidateSlot['date'];
    if (!isset($acceptedByDay[$candidateDate])) {
        $acceptedByDay[$candidateDate] = 0.0;
    }
    $acceptedByDay[$candidateDate] += (float)$candidateSlot['hours'];

    $datesToCheck = [
        date('Y-m-d', strtotime($candidateDate . ' -1 day')),
        $candidateDate,
        date('Y-m-d', strtotime($candidateDate . ' +1 day')),
    ];

    foreach ($datesToCheck as $baseDate) {
        $nextDate = date('Y-m-d', strtotime($baseDate . ' +1 day'));

        $sum1 = (float)($existingByDay[$baseDate] ?? 0)
            + (float)($acceptedByDay[$baseDate] ?? 0)
            + (float)($existingByDay[$nextDate] ?? 0)
            + (float)($acceptedByDay[$nextDate] ?? 0);

        if ($sum1 > 4.0) {
            return false;
        }
    }

    return true;
}

function overtimeMergeSlotsToIntervals(array $slots): array
{
    if (empty($slots)) {
        return [];
    }

    $intervals = [];
    $current = [
        'start' => $slots[0]['start'],
        'end' => $slots[0]['end'],
        'hours' => (float)$slots[0]['hours'],
        'reason_code' => $slots[0]['reason_code'] ?? '',
        'reason_text' => $slots[0]['reason_text'] ?? '',
    ];

    for ($i = 1, $cnt = count($slots); $i < $cnt; $i++) {
        $slot = $slots[$i];
        $slotReasonCode = $slot['reason_code'] ?? '';
        $slotReasonText = $slot['reason_text'] ?? '';

        if (
            $current['end']->format('Y-m-d H:i:s') === $slot['start']->format('Y-m-d H:i:s')
            && $current['reason_code'] === $slotReasonCode
        ) {
            $current['end'] = $slot['end'];
            $current['hours'] += (float)$slot['hours'];
        } else {
            $intervals[] = $current;
            $current = [
                'start' => $slot['start'],
                'end' => $slot['end'],
                'hours' => (float)$slot['hours'],
                'reason_code' => $slotReasonCode,
                'reason_text' => $slotReasonText,
            ];
        }
    }

    $intervals[] = $current;
    return $intervals;
}

function overtimeGetSecondNextWorkdayDate(string $fromDate): ?string
{
    $count = 0;
    $cursor = strtotime($fromDate . ' +1 day');

    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d', $cursor);
        if (overtimeIsWorkday1C($date)) {
            $count++;
            if ($count >= 2) {
                return $date;
            }
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    return null;
}

function overtimeCheckLateSubmissionWarning(array $segments, array $config): array
{
    $today = date('Y-m-d');
    $secondNextWorkday = overtimeGetSecondNextWorkdayDate($today);

    if ($secondNextWorkday === null) {
        return [
            'required' => false,
            'text' => '',
        ];
    }

    foreach ($segments as $segment) {
        $startDate = $segment['start']->format('Y-m-d');

        if ($startDate <= $secondNextWorkday) {
            return [
                'required' => true,
                'text' => 'Заявка подается менее чем за 2 рабочих дня до начала работ и может быть не исполнена до указанного срока',
            ];
        }
    }

    return [
        'required' => false,
        'text' => '',
    ];
}

function overtimeBuildPaymentBreakdown(int $employeeId, array $segment, array $config): array
{
    if ((int)$segment['type_id'] === (int)$config['WORK_TYPE_WEEKEND_ID']) {
        $segmentHours = round((float)$segment['hours'], 2);
        $interval = overtimeFormatDebugInterval($segment['start'], $segment['end']);

        return [
            'registry_hours_before' => 0.0,
            'existing_by_day' => [],
            'rows' => [
                [
                    'title' => 'Общее количество часов',
                    'hours' => $segmentHours,
                    'interval' => $interval,
                    'basis' => 'расчет общего периода работы в выходной день по заявке',
                ],
            ],
            'summary' => [
                [
                    'title' => 'ИТОГО часы для оплаты единовременной премией',
                    'hours' => $segmentHours,
                    'interval' => $interval,
                    'basis' => 'все часы работы в выходной день оплачиваются единовременной премией',
                ],
            ],
            'hours_15' => 0.0,
            'hours_20' => 0.0,
            'night_hours_20' => 0.0,
            'tk_hours' => $segmentHours,
            'premium_hours' => $segmentHours,
        ];
    }

    if ((int)$segment['type_id'] !== (int)$config['WORK_TYPE_OVERTIME_ID']) {
        return [];
    }

    $slots = overtimeSplitSegmentToHourSlots($segment['start'], $segment['end']);
    $registryHoursBefore = overtimeGetRegistryHoursBeforeCurrentSegment($employeeId, $segment['start'], $config);
    $existingByDay = overtimeGetExistingOvertimeHoursByDayForSegment($employeeId, $segment['start'], $segment['end'], $config);
    $segmentTotalHours = (float)$segment['hours'];
    $finalYearValue = round($registryHoursBefore + $segmentTotalHours, 2);

    $acceptedSlots = [];
    $premiumSlots = [];

    foreach ($slots as $slot) {
        if (overtimeCanAllocateSlotToTk($slot, $acceptedSlots, $existingByDay, $registryHoursBefore)) {
            $slot['reason_code'] = '';
            $slot['reason_text'] = '';
            $acceptedSlots[] = $slot;
        } else {
            $reason = overtimeExplainRejectedSlot($slot, $acceptedSlots, $existingByDay, $registryHoursBefore, $segmentTotalHours);
            $slot['reason_code'] = $reason['reason_code'];
            $slot['reason_text'] = $reason['reason_text'];
            $premiumSlots[] = $slot;
        }
    }

    $firstTwoSlots = array_slice($acceptedSlots, 0, 2);
    $doubleSlots = array_slice($acceptedSlots, 2);

    foreach ($firstTwoSlots as &$slot) {
        $slot['reason_code'] = 'TK_15';
        $slot['reason_text'] = '1-й и 2-й часы сверхурочной работы, укладываются в ограничения ТК РФ';
    }
    unset($slot);

    foreach ($doubleSlots as &$slot) {
        $slot['reason_code'] = 'TK_20';
        $slot['reason_text'] = 'последующие часы сверхурочной работы, укладываются в ограничения ТК РФ';
    }
    unset($slot);

    $nightSlots = [];
    foreach ($acceptedSlots as $slot) {
        if (overtimeIsNightSlot($slot)) {
            $slot['reason_code'] = 'NIGHT_TK';
            $slot['reason_text'] = '';
            $nightSlots[] = $slot;
        }
    }

    $firstTwoIntervals = overtimeMergeSlotsToIntervals($firstTwoSlots);
    $doubleIntervals = overtimeMergeSlotsToIntervals($doubleSlots);
    $nightIntervals = overtimeMergeSlotsToIntervals($nightSlots);
    $premiumIntervals = overtimeMergeSlotsToIntervals($premiumSlots);

    $rows = [];
    $rows[] = [
        'title' => 'Общее кол-во сверхурочных часов',
        'hours' => round((float)$segment['hours'], 2),
        'interval' => overtimeFormatDebugInterval($segment['start'], $segment['end']),
        'basis' => 'расчет общего периода сверхурочной работы по заявке',
    ];

    foreach ($firstTwoIntervals as $interval) {
        $rows[] = [
            'title' => 'Часы для оплаты в 1,5 размере',
            'hours' => round((float)$interval['hours'], 2),
            'interval' => overtimeFormatDebugInterval($interval['start'], $interval['end']),
            'basis' => $interval['reason_text'],
        ];
    }

    foreach ($doubleIntervals as $interval) {
        $rows[] = [
            'title' => 'Часы для оплаты в 2 размере',
            'hours' => round((float)$interval['hours'], 2),
            'interval' => overtimeFormatDebugInterval($interval['start'], $interval['end']),
            'basis' => $interval['reason_text'],
        ];
    }

    foreach ($nightIntervals as $interval) {
        $intervalText = overtimeFormatDebugInterval($interval['start'], $interval['end']);
        $rows[] = [
            'title' => 'Включая ночные часы +20%',
            'hours' => round((float)$interval['hours'], 2),
            'interval' => $intervalText,
            'basis' => 'часы ' . $intervalText . ' являются ночными и одновременно входят в часть часов, оплачиваемых по ТК РФ',
        ];
    }

    foreach ($premiumIntervals as $interval) {
        $intervalText = overtimeFormatDebugInterval($interval['start'], $interval['end']);

        $basis = $interval['reason_text'];
        if (($interval['reason_code'] ?? '') === 'YEAR_LIMIT') {
            $basis = 'часы ' . $intervalText . ' не включены в оплату по ТК РФ, так как при добавлении всей заявки годовое количество сверхурочных часов превысило бы 120 (' . $finalYearValue . ')';
        } elseif (($interval['reason_code'] ?? '') === 'TWO_DAY_LIMIT') {
            $basis = 'часы ' . $intervalText . ' ' . $interval['reason_text'];
        } else {
            $basis = 'часы ' . $intervalText . ' ' . $interval['reason_text'];
        }

        $rows[] = [
            'title' => 'Часы для оплаты единовременной премией',
            'hours' => round((float)$interval['hours'], 2),
            'interval' => $intervalText,
            'basis' => $basis,
        ];
    }

    $tkHours = 0.0;
    foreach ($acceptedSlots as $slot) {
        $tkHours += (float)$slot['hours'];
    }

    $premiumHours = 0.0;
    foreach ($premiumSlots as $slot) {
        $premiumHours += (float)$slot['hours'];
    }

    $acceptedIntervals = overtimeMergeSlotsToIntervals($acceptedSlots);
    $premiumMergedIntervals = overtimeMergeSlotsToIntervals($premiumSlots);

    $summary = [
        [
            'title' => 'ИТОГО сверхурочных часов по ТК РФ',
            'hours' => round($tkHours, 2),
            'interval' => !empty($acceptedIntervals)
                ? overtimeFormatDebugInterval($acceptedIntervals[0]['start'], end($acceptedIntervals)['end'])
                : '',
            'basis' => 'сумма часов, которые последовательно укладываются в годовой лимит 120 часов и в ограничение 4 часа за 2 дня подряд',
        ],
        [
            'title' => 'ИТОГО часы для оплаты единовременной премией',
            'hours' => round($premiumHours, 2),
            'interval' => !empty($premiumMergedIntervals)
                ? overtimeFormatDebugInterval($premiumMergedIntervals[0]['start'], end($premiumMergedIntervals)['end'])
                : '',
            'basis' => 'сумма часов, которые не могут быть оплачены по ТК РФ из-за превышения ограничений',
        ],
    ];

    return [
        'registry_hours_before' => round($registryHoursBefore, 2),
        'existing_by_day' => $existingByDay,
        'rows' => $rows,
        'summary' => $summary,
        'hours_15' => round(array_reduce($firstTwoSlots, static function ($carry, $slot) {
            return $carry + (float)$slot['hours'];
        }, 0.0), 2),
        'hours_20' => round(array_reduce($doubleSlots, static function ($carry, $slot) {
            return $carry + (float)$slot['hours'];
        }, 0.0), 2),
        'night_hours_20' => round(array_reduce($nightSlots, static function ($carry, $slot) {
            return $carry + (float)$slot['hours'];
        }, 0.0), 2),
        'tk_hours' => round($tkHours, 2),
        'premium_hours' => round($premiumHours, 2),
    ];
}

function overtimeBuildCalculationHtmlReport(array $paymentBreakdown): string
{
    if (empty($paymentBreakdown['rows']) && empty($paymentBreakdown['summary'])) {
        return '';
    }

    $html = '<h3>Расчет часов для оплаты</h3>';
    $html .= '<table border="1" cellpadding="6" cellspacing="0">';
    $html .= '<thead><tr><th>Показатель</th><th>Часы</th><th>Интервал</th><th>Основание</th></tr></thead><tbody>';

    foreach (($paymentBreakdown['rows'] ?? []) as $row) {
        $html .= '<tr>';
        $html .= '<td>' . overtimeH((string)($row['title'] ?? '')) . '</td>';
        $html .= '<td>' . overtimeH((string)($row['hours'] ?? '')) . '</td>';
        $html .= '<td>' . overtimeH((string)($row['interval'] ?? '')) . '</td>';
        $html .= '<td>' . overtimeH((string)($row['basis'] ?? '')) . '</td>';
        $html .= '</tr>';
    }

    foreach (($paymentBreakdown['summary'] ?? []) as $row) {
        $html .= '<tr>';
        $html .= '<td><strong>' . overtimeH((string)($row['title'] ?? '')) . '</strong></td>';
        $html .= '<td><strong>' . overtimeH((string)($row['hours'] ?? '')) . '</strong></td>';
        $html .= '<td>' . overtimeH((string)($row['interval'] ?? '')) . '</td>';
        $html .= '<td>' . overtimeH((string)($row['basis'] ?? '')) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
}

function overtimeResolveSubtypeId(array $segment, float $totalTkHours, float $totalPremiumHours, array $config): int
{
    if ((int)$segment['type_id'] === (int)$config['WORK_TYPE_DUTY_ID']) {
        return (int)($config['SUBTYPE_DUTY_CB_ID'] ?? 0);
    }

    if ((int)$segment['type_id'] === (int)$config['WORK_TYPE_WEEKEND_ID']) {
        return (int)($config['SUBTYPE_WEEKEND_CB_ID'] ?? 0);
    }

    if ((int)$segment['type_id'] !== (int)$config['WORK_TYPE_OVERTIME_ID']) {
        return 0;
    }

    if ($totalTkHours <= 0 && $totalPremiumHours > 0) {
        return (int)($config['SUBTYPE_OVERTIME_CB_ID'] ?? 0);
    }

    if ($totalTkHours > 0 && $totalPremiumHours > 0) {
        return (int)($config['SUBTYPE_OVERTIME_CB_KA_ID'] ?? 0);
    }

    if ($totalTkHours > 0 && $totalPremiumHours <= 0) {
        return (int)($config['SUBTYPE_OVERTIME_KA_ID'] ?? 0);
    }

    return 0;
}

function overtimeIsDutyRequestedAndAllowed(bool $isDuty, array $config): bool
{
    return $isDuty && !empty($config['ALLOW_DUTY']);
}

function overtimeBuildSplitWarning(array $segments, array $config): array
{
    if (count($segments) < 2) {
        return [
            'required' => false,
            'text' => '',
        ];
    }

    $foundTypes = [];
    foreach ($segments as $segment) {
        $typeId = (int)($segment['type_id'] ?? 0);
        if (in_array($typeId, [(int)$config['WORK_TYPE_OVERTIME_ID'], (int)$config['WORK_TYPE_WEEKEND_ID']], true)) {
            $foundTypes[$typeId] = true;
        }
    }

    if (count($foundTypes) < 2) {
        return [
            'required' => false,
            'text' => '',
        ];
    }

    return [
        'required' => true,
        'text' => 'Т.к. период работ затрагивает будни и выходные дни будут созданы несколько заявок на сверхурочную работу и на работу в выходной день. Укажите тип оплаты в каждой из них',
    ];
}

function overtimeValidateCreatorEmployeeAccess(int $employeeId, array $config): array
{
    if (!empty($config['SKIP_CREATOR_ACCESS_CHECK'])) {
        if ($employeeId <= 0) {
            return [
                'allowed' => false,
                'error' => 'Не выбран сотрудник.',
            ];
        }

        return [
            'allowed' => true,
            'error' => '',
        ];
    }

    $creatorId = (int)($config['CURRENT_USER_ID'] ?? 0);
    if ($creatorId <= 0) {
        return [
            'allowed' => false,
            'error' => 'Не удалось определить текущего пользователя.',
        ];
    }

    if ($employeeId <= 0) {
        return [
            'allowed' => false,
            'error' => 'Не выбран сотрудник.',
        ];
    }

    if (!overtimeCanCreatorCreateForEmployee($creatorId, $employeeId, $config)) {
        return [
            'allowed' => false,
            'error' => 'Заявку можно создать только на подчиненных сотрудников и на самого себя.',
        ];
    }

    return [
        'allowed' => true,
        'error' => '',
    ];
}

function overtimeValidatePastDateRestriction(int $employeeId, DateTime $start, DateTime $end, array $config): array
{
    if (!empty($config['SKIP_PAST_DATE_RESTRICTION'])) {
        return ['allowed' => true, 'error' => ''];
    }

    $minAllowedDate = date('Y-m-d', strtotime('+1 day'));
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    if ($startDate >= $minAllowedDate && $endDate >= $minAllowedDate) {
        return ['allowed' => true, 'error' => ''];
    }

    if (overtimeCanCreatePastPeriodForEmployee($employeeId, $config)) {
        return ['allowed' => true, 'error' => ''];
    }

    return [
        'allowed' => false,
        'error' => 'Дата работ не может быть ранее завтрашнего дня. Выбор периода возможен только с завтрашней даты.',
    ];
}

function overtimeBuildSinglePreviewItem(int $employeeId, string $dateStart, string $timeStart, string $dateEnd, string $timeEnd, bool $isDuty, array $config): array
{
    $errors = [];
    $blockCreate = false;

    $accessValidation = overtimeValidateCreatorEmployeeAccess($employeeId, $config);
    if (!$accessValidation['allowed']) {
        $errors[] = $accessValidation['error'];
        $blockCreate = true;
    }

    $start = overtimeParseDateTimeFromHtml($dateStart, $timeStart);
    $end = overtimeParseDateTimeFromHtml($dateEnd, $timeEnd);

    if (!$start || !$end) {
        return [
            'success' => false,
            'errors' => [],
            'messages' => [],
            'all_check_messages' => [],
            'segments' => [],
            'segments_json' => '[]',
            'late_warning_required' => false,
            'late_warning_text' => '',
            'split_warning_required' => false,
            'split_warning_text' => '',
            'block_create' => $blockCreate,
        ];
    }

    if (strtotime($start->format('Y-m-d H:i:s')) >= strtotime($end->format('Y-m-d H:i:s'))) {
        $errors[] = 'Дата/время окончания должны быть больше даты/времени начала.';
    }

    $pastDateValidation = overtimeValidatePastDateRestriction($employeeId, $start, $end, $config);
    if (!$pastDateValidation['allowed']) {
        $errors[] = $pastDateValidation['error'];
        $blockCreate = true;
    }

    if (!empty($errors)) {
        return [
            'success' => false,
            'errors' => $errors,
            'messages' => [],
            'all_check_messages' => [],
            'segments' => [],
            'segments_json' => '[]',
            'late_warning_required' => false,
            'late_warning_text' => '',
            'split_warning_required' => false,
            'split_warning_text' => '',
            'block_create' => $blockCreate,
        ];
    }

    $isDuty = overtimeIsDutyRequestedAndAllowed($isDuty, $config);
    $segments = overtimeBuildSegments($start, $end, $isDuty, $config);
    $messages = [];
    $checkResult = [
        'messages' => [],
    ];

    if (!$isDuty) {
        $checkResult = overtimeAnalyzeChecks($employeeId, $segments, $config);
        if (!empty($config['DEBUG'])) {
            $messages = $checkResult['messages'];
        }
    }

    $lateWarning = overtimeCheckLateSubmissionWarning($segments, $config);
    $splitWarning = overtimeBuildSplitWarning($segments, $config);

    $preparedSegments = [];
    foreach ($segments as $segment) {
        $preparedSegments[] = [
            'type_id' => (int)$segment['type_id'],
            'type_name' => $segment['type_name'],
            'start' => overtimeFormatHuman($segment['start']),
            'end' => overtimeFormatHuman($segment['end']),
            'hours' => $segment['hours'],
            'payment_types' => overtimeGetPaymentTypesByWorkType((int)$segment['type_id'], $config),
            'debug_payment_breakdown' => !empty($config['DEBUG'])
                ? overtimeBuildPaymentBreakdown($employeeId, $segment, $config)
                : [],
        ];
    }

    return [
        'success' => true,
        'errors' => [],
        'messages' => $messages,
        'all_check_messages' => $checkResult['messages'],
        'segments' => $preparedSegments,
        'segments_json' => Json::encode(overtimeNormalizeSegments($segments)),
        'late_warning_required' => $lateWarning['required'],
        'late_warning_text' => $lateWarning['text'],
        'split_warning_required' => $splitWarning['required'],
        'split_warning_text' => $splitWarning['text'],
        'block_create' => false,
    ];
}

function overtimeBuildModePreview(string $mode, array $payload, array $config): array
{
    if ($mode === $config['MODE_SINGLE']) {
        $single = $payload['single'] ?? [];

        $item = overtimeBuildSinglePreviewItem(
            (int)($single['employee_id'] ?? 0),
            trim((string)($single['date_start'] ?? '')),
            trim((string)($single['time_start'] ?? '')),
            trim((string)($single['date_end'] ?? '')),
            trim((string)($single['time_end'] ?? '')),
            (($single['is_duty'] ?? 'N') === 'Y'),
            $config
        );

        return [
            'success' => true,
            'mode' => $mode,
            'single' => $item,
        ];
    }

    if ($mode === $config['MODE_MULTI_SAME']) {
        $common = $payload['common'] ?? [];
        $rows = is_array($payload['rows']) ? $payload['rows'] : [];
        $items = [];

        foreach ($rows as $index => $row) {
            $items[$index] = overtimeBuildSinglePreviewItem(
                (int)($row['employee_id'] ?? 0),
                trim((string)($common['date_start'] ?? '')),
                trim((string)($common['time_start'] ?? '')),
                trim((string)($common['date_end'] ?? '')),
                trim((string)($common['time_end'] ?? '')),
                (($common['is_duty'] ?? 'N') === 'Y'),
                $config
            );
        }

        return [
            'success' => true,
            'mode' => $mode,
            'rows' => $items,
        ];
    }

    if ($mode === $config['MODE_MULTI_DIFF']) {
        $common = $payload['common'] ?? [];
        $rows = is_array($payload['rows']) ? $payload['rows'] : [];
        $items = [];

        foreach ($rows as $index => $row) {
            $items[$index] = overtimeBuildSinglePreviewItem(
                (int)($row['employee_id'] ?? 0),
                trim((string)($row['date_start'] ?? '')),
                trim((string)($row['time_start'] ?? '')),
                trim((string)($row['date_end'] ?? '')),
                trim((string)($row['time_end'] ?? '')),
                (($common['is_duty'] ?? 'N') === 'Y'),
                $config
            );
        }

        return [
            'success' => true,
            'mode' => $mode,
            'rows' => $items,
        ];
    }

    return [
        'success' => false,
        'errors' => ['Неизвестный режим формы.'],
    ];
}

function overtimeBuildRequestName(int $employeeId, array $segment): string
{
    $employeeName = overtimeGetUserNameById($employeeId);

    return sprintf(
        '%s: %s (%s - %s)',
        $employeeName,
        $segment['type_name'],
        $segment['start']->format('d.m.Y H:i'),
        $segment['end']->format('d.m.Y H:i')
    );
}

function overtimeCreateRequestElement(array $fields, array $propertyValues): int
{
    $el = new CIBlockElement();
    $fields['PROPERTY_VALUES'] = $propertyValues;

    $id = (int)$el->Add($fields);
    if ($id <= 0) {
        throw new RuntimeException($el->LAST_ERROR ?: 'Не удалось создать элемент инфоблока');
    }

    return $id;
}

function overtimeStartRequestWorkflow(int $requestId, array $config, array $workflowParameters = []): ?string
{
    $workflowTemplateId = (int)($config['REQUEST_WORKFLOW_TEMPLATE_ID'] ?? 0);
    if ($requestId <= 0 || $workflowTemplateId <= 0) {
        return null;
    }

    if (!Loader::includeModule('bizproc')) {
        return 'Не удалось подключить модуль bizproc.';
    }

    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $requestId];
    $workflowErrors = [];
    $workflowId = CBPDocument::StartWorkflow(
        $workflowTemplateId,
        $documentId,
        $workflowParameters,
        $workflowErrors
    );

    if (!$workflowId) {
        return 'Бизнес-процесс не запустился: ' . implode('; ', $workflowErrors);
    }

    return null;
}

if (!function_exists('overtimeFlattenBizprocErrors')) {
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
}

function overtimeTerminateRequestWorkflows(int $requestId, string $reason = ''): ?string
{
    if ($requestId <= 0) {
        return 'Не указан ID заявки для прерывания бизнес-процесса.';
    }

    if (!Loader::includeModule('bizproc')) {
        return 'Не удалось подключить модуль bizproc.';
    }

    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $requestId];
    $states = CBPStateService::GetDocumentStates($documentId);
    if (empty($states) || !is_array($states)) {
        return null;
    }

    $errors = [];
    foreach ($states as $state) {
        $workflowId = (string)($state['ID'] ?? '');
        if ($workflowId === '') {
            continue;
        }

        $terminateErrors = [];
        $result = CBPDocument::TerminateWorkflow($workflowId, $documentId, $terminateErrors, $reason);
        if (!$result && !empty($terminateErrors)) {
            $errors[] = overtimeFlattenBizprocErrors($terminateErrors);
        }
    }

    if (!empty($errors)) {
        $errors = array_values(array_filter(array_unique(array_map('trim', $errors))));
        return empty($errors) ? null : implode(' ', $errors);
    }

    return null;
}

function overtimeGetRequestById(int $requestId, array $config): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $select = [
        'ID',
        'NAME',
        'CREATED_BY',
        'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'],
        'PROPERTY_' . $config['REQ_PROP_START'],
        'PROPERTY_' . $config['REQ_PROP_END'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_DATE'],
        'PROPERTY_' . $config['REQ_PROP_WORK_START_TIME'],
        'PROPERTY_' . $config['REQ_PROP_WORK_END_TIME'],
        'PROPERTY_' . $config['REQ_PROP_WORK_TYPE'],
        'PROPERTY_' . $config['REQ_PROP_PAYMENT_TYPE'],
        'PROPERTY_' . $config['REQ_PROP_JUSTIFICATION'],
        'PROPERTY_' . $config['REQ_PROP_JUST_FILE'],
        'PROPERTY_' . $config['REQ_PROP_STATUS'],
        'PROPERTY_' . $config['REQ_PROP_HISTORY'],
    ];

    $res = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'], 'ID' => $requestId],
        false,
        false,
        $select
    );

    $item = $res->Fetch();
    return $item ?: null;
}

function overtimeAppendRequestHistory(int $requestId, string $message, array $config): void
{
    $propCode = trim((string)($config['REQ_PROP_HISTORY'] ?? ''));
    if ($requestId <= 0 || $propCode === '' || trim($message) === '') {
        return;
    }

    $existing = [];
    $propRes = CIBlockElement::GetProperty(
        (int)$config['IBLOCK_REQUESTS'],
        $requestId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['CODE' => $propCode]
    );

    while ($prop = $propRes->Fetch()) {
        $value = trim((string)($prop['VALUE'] ?? ''));
        if ($value !== '') {
            $existing[] = $value;
        }
    }

    $existing[] = $message;

    $propertyMeta = CIBlockProperty::GetList(
        [],
        ['IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'], 'CODE' => $propCode]
    )->Fetch();

    $isMultiple = (($propertyMeta['MULTIPLE'] ?? 'N') === 'Y');
    $valueToSave = $isMultiple ? $existing : implode(PHP_EOL, $existing);

    CIBlockElement::SetPropertyValuesEx(
        $requestId,
        (int)$config['IBLOCK_REQUESTS'],
        [$propCode => $valueToSave]
    );
}

function overtimeBuildGroupRequestName(array $employeeIds): string
{
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
    $count = count($employeeIds);

    return sprintf(
        'Групповая заявка №%s (%d сотрудн.)',
        date('dmY_His'),
        $count
    );
}

function overtimeCreateGroupRequestElement(array $employeeIds, int $createdBy, array $config): int
{
    $fields = [
        'IBLOCK_ID' => $config['IBLOCK_GROUP_REQUESTS'],
        'IBLOCK_SECTION_ID' => false,
        'NAME' => overtimeBuildGroupRequestName($employeeIds),
        'ACTIVE' => 'Y',
        'CREATED_BY' => $createdBy,
        'MODIFIED_BY' => $createdBy,
    ];

    return overtimeCreateRequestElement($fields, []);
}

function overtimeUpdateGroupRequestLinks(int $groupId, array $requestIds, array $config): void
{
    if ($groupId <= 0 || empty($requestIds)) {
        return;
    }

    CIBlockElement::SetPropertyValuesEx(
        $groupId,
        $config['IBLOCK_GROUP_REQUESTS'],
        [$config['GROUP_PROP_LINKED_REQUESTS'] => array_values(array_unique(array_map('intval', $requestIds)))]
    );
}

function overtimeUpdateLinkedRequests(array $createdIds, array $config): void
{
    if (count($createdIds) < 2) {
        return;
    }

    foreach ($createdIds as $id) {
        $linkedIds = array_values(array_diff($createdIds, [$id]));
        CIBlockElement::SetPropertyValuesEx(
            $id,
            $config['IBLOCK_REQUESTS'],
            [$config['REQ_PROP_LINKED_REQUESTS'] => $linkedIds]
        );
    }
}

function overtimeSaveUploadedFileToProperty(array $fileArray): ?array
{
    if (empty($fileArray['name']) || (int)$fileArray['error'] !== 0) {
        return null;
    }

    $prepared = CFile::MakeFileArray($fileArray['tmp_name']);
    if (!$prepared) {
        return null;
    }

    $prepared['name'] = $fileArray['name'];
    $prepared['type'] = $fileArray['type'];
    $prepared['MODULE_ID'] = 'iblock';

    return $prepared;
}

function overtimeNeedTextJustificationFromSegments(array $segments): bool
{
    foreach ($segments as $segment) {
        if (in_array($segment['type_name'], ['Сверхурочная работа', 'Работа в выходной день'], true)) {
            return true;
        }
    }
    return false;
}

function overtimeNeedFileJustificationFromSegments(array $segments): bool
{
    foreach ($segments as $segment) {
        if ($segment['type_name'] === 'Дежурство') {
            return true;
        }
    }
    return false;
}

function overtimeValidatePaymentTypes(array $segments, array $paymentTypes, int $employeeId): array
{
    $errors = [];

    foreach ($segments as $index => $segment) {
        $paymentTypeId = isset($paymentTypes[$index]) ? (int)$paymentTypes[$index] : 0;
        if ($paymentTypeId <= 0) {
            $errors[] = 'Не выбран тип оплаты для заявки №' . ($index + 1) . ' сотрудника ' . overtimeGetUserNameById($employeeId) . '.';
        }
    }

    return $errors;
}

function overtimeCreateEmployeeRequestPack(
    int $employeeId,
    array $segmentsRaw,
    array $paymentTypes,
    string $justification,
    ?array $justificationFile,
    bool $lateAck,
    int $createdBy,
    array $config,
    int $groupId = 0,
    array $workflowParameters = []
): array {
    $segments = overtimeRestoreSegments($segmentsRaw);
    $errors = [];
    $createdIds = [];

    $accessValidation = overtimeValidateCreatorEmployeeAccess($employeeId, $config);
    if (!$accessValidation['allowed']) {
        $errors[] = $accessValidation['error'];
    }

    foreach ($segments as $segment) {
        $pastDateValidation = overtimeValidatePastDateRestriction($employeeId, $segment['start'], $segment['end'], $config);
        if (!$pastDateValidation['allowed']) {
            $errors[] = $pastDateValidation['error'] . ' Сотрудник: ' . overtimeGetUserNameById($employeeId) . '.';
            break;
        }
    }

    $errors = array_merge($errors, overtimeValidatePaymentTypes($segments, $paymentTypes, $employeeId));

    if (overtimeNeedTextJustificationFromSegments($segments) && trim($justification) === '') {
        $errors[] = 'Не заполнено обязательное поле "Обоснование" для сотрудника ' . overtimeGetUserNameById($employeeId) . '.';
    }

    if (overtimeNeedFileJustificationFromSegments($segments) && empty($justificationFile)) {
        $errors[] = 'Не приложен обязательный файл "Обоснование (файл)" для сотрудника ' . overtimeGetUserNameById($employeeId) . '.';
    }

    $lateWarning = overtimeCheckLateSubmissionWarning($segments, $config);
    $splitWarning = overtimeBuildSplitWarning($segments, $config);
    if ($lateWarning['required'] && !$lateAck) {
        $errors[] = 'Не установлен обязательный флажок ознакомления с условием о подаче заявки менее чем за 2 рабочих дня.';
    }

    if (!empty($errors)) {
        return [
            'success' => false,
            'errors' => $errors,
            'created_ids' => [],
        ];
    }

    foreach ($segments as $index => $segment) {
        $paymentTypeId = (int)$paymentTypes[$index];

        $fields = [
            'IBLOCK_ID' => $config['IBLOCK_REQUESTS'],
            'IBLOCK_SECTION_ID' => false,
            'NAME' => overtimeBuildRequestName($employeeId, $segment),
            'ACTIVE' => 'Y',
            'CREATED_BY' => $createdBy,
            'MODIFIED_BY' => $createdBy,
        ];

        $paymentBreakdown = overtimeBuildPaymentBreakdown($employeeId, $segment, $config);
        $supportsCalculatedHours = in_array((int)$segment['type_id'], [
            (int)$config['WORK_TYPE_OVERTIME_ID'],
            (int)$config['WORK_TYPE_WEEKEND_ID'],
        ], true);
        $totalTkHours = $supportsCalculatedHours
            ? (float)($paymentBreakdown['tk_hours'] ?? 0)
            : 0;
        $totalPremiumHours = $supportsCalculatedHours
            ? (float)($paymentBreakdown['premium_hours'] ?? 0)
            : 0;
        $overtimeTotalHours = $supportsCalculatedHours
            ? (float)$segment['hours']
            : 0;
        $hours15 = (int)$segment['type_id'] === (int)$config['WORK_TYPE_OVERTIME_ID']
            ? (float)($paymentBreakdown['hours_15'] ?? 0)
            : 0;
        $hours20 = (int)$segment['type_id'] === (int)$config['WORK_TYPE_OVERTIME_ID']
            ? (float)($paymentBreakdown['hours_20'] ?? 0)
            : 0;
        $nightHours20 = (int)$segment['type_id'] === (int)$config['WORK_TYPE_OVERTIME_ID']
            ? (float)($paymentBreakdown['night_hours_20'] ?? 0)
            : 0;
        $subtypeId = overtimeResolveSubtypeId($segment, $totalTkHours, $totalPremiumHours, $config);
        $calculationHtml = (int)$segment['type_id'] === (int)$config['WORK_TYPE_OVERTIME_ID']
            ? overtimeBuildCalculationHtmlReport($paymentBreakdown)
            : '';

        $propertyValues = [
            $config['REQ_PROP_EMPLOYEE'] => $employeeId,
            $config['REQ_PROP_START'] => $segment['start']->format('d.m.Y H:i:s'),
            $config['REQ_PROP_END'] => $segment['end']->format('d.m.Y H:i:s'),
            $config['REQ_PROP_WORK_TYPE'] => (int)$segment['type_id'],
            $config['REQ_PROP_PAYMENT_TYPE'] => $paymentTypeId,
            $config['REQ_PROP_HOURS'] => $segment['hours'],
            $config['REQ_PROP_WORK_START_DATE'] => $segment['start']->format('d.m.Y'),
            $config['REQ_PROP_WORK_END_DATE'] => $segment['end']->format('d.m.Y'),
            $config['REQ_PROP_WORK_START_TIME'] => $segment['start']->format('H:i'),
            $config['REQ_PROP_WORK_END_TIME'] => $segment['end']->format('H:i'),
            $config['REQ_PROP_TOTAL_HOURS'] => (string)$segment['hours'],
            $config['REQ_PROP_OVERTIME_TOTAL_HOURS'] => $overtimeTotalHours,
            $config['REQ_PROP_HOURS_15'] => $hours15,
            $config['REQ_PROP_HOURS_20'] => $hours20,
            $config['REQ_PROP_NIGHT_HOURS_20'] => $nightHours20,
            $config['REQ_PROP_TOTAL_OT_HOURS'] => $totalTkHours,
            $config['REQ_PROP_TOTAL_PREMIUM_HOURS'] => $totalPremiumHours,
        ];

        if ($subtypeId > 0 && !empty($config['REQ_PROP_SUBTYPE'])) {
            $propertyValues[$config['REQ_PROP_SUBTYPE']] = $subtypeId;
        }

        if ($calculationHtml !== '' && !empty($config['REQ_PROP_CALCULATION_HTML'])) {
            $propertyValues[$config['REQ_PROP_CALCULATION_HTML']] = [
                'VALUE' => [
                    'TYPE' => 'HTML',
                    'TEXT' => $calculationHtml,
                ],
            ];
        }

        if ($groupId > 0 && !empty($config['REQ_PROP_GROUP_LINK'])) {
            $propertyValues[$config['REQ_PROP_GROUP_LINK']] = $groupId;
        }

        if ($justification !== '' && $config['REQ_PROP_JUSTIFICATION'] !== '') {
            $propertyValues[$config['REQ_PROP_JUSTIFICATION']] = $justification;
        }

        if (!empty($justificationFile) && $config['REQ_PROP_JUST_FILE'] !== '') {
            $propertyValues[$config['REQ_PROP_JUST_FILE']] = $justificationFile;
        }

        $createdId = overtimeCreateRequestElement($fields, $propertyValues);
        $workflowError = overtimeStartRequestWorkflow($createdId, $config, $workflowParameters);
        if ($workflowError !== null) {
            $errors[] = 'Ошибка для заявки "' . $fields['NAME'] . '": ' . $workflowError;
            continue;
        }

        $createdIds[] = $createdId;
    }

    if (!empty($errors)) {
        return [
            'success' => false,
            'errors' => $errors,
            'created_ids' => $createdIds,
        ];
    }

    overtimeUpdateLinkedRequests($createdIds, $config);

    return [
        'success' => true,
        'errors' => [],
        'created_ids' => $createdIds,
    ];
}

function overtimeCreateByMode(string $mode, array $post, array $files, int $createdBy, array $config): array
{
    $creatorAccess = overtimeGetCreatorAccessMap($createdBy, $config);
    if (empty($creatorAccess['is_manager'])) {
        return [
            'success' => false,
            'errors' => ['Создание заявок доступно только руководителям и назначенным заместителям руководителя.'],
            'created_ids' => [],
        ];
    }

    if ($mode === $config['MODE_SINGLE']) {
        $single = $post['single'] ?? [];
        $employeeId = (int)($single['employee_id'] ?? 0);
        $justification = trim((string)($single['justification'] ?? ''));
        $justFile = overtimeSaveUploadedFileToProperty($files['single_justification_file'] ?? []);
        $lateAck = (($single['late_ack'] ?? 'N') === 'Y');

        $preview = overtimeBuildSinglePreviewItem(
            $employeeId,
            trim((string)($single['date_start'] ?? '')),
            trim((string)($single['time_start'] ?? '')),
            trim((string)($single['date_end'] ?? '')),
            trim((string)($single['time_end'] ?? '')),
            (($single['is_duty'] ?? 'N') === 'Y'),
            $config
        );

        if (empty($preview['segments_json']) || $preview['segments_json'] === '[]') {
            return ['success' => false, 'errors' => ['Нет данных по сегментам заявок.'], 'created_ids' => []];
        }

        $segmentsRaw = Json::decode($preview['segments_json']);
        $paymentTypes = $single['payment_type'] ?? [];

        return overtimeCreateEmployeeRequestPack(
            $employeeId,
            $segmentsRaw,
            $paymentTypes,
            $justification,
            $justFile,
            $lateAck,
            $createdBy,
            $config,
            0
        );
    }

    if ($mode === $config['MODE_MULTI_SAME']) {
        $common = $post['common'] ?? [];
        $rows = is_array($post['rows_same'] ?? null) ? $post['rows_same'] : [];
        $allCreated = [];
        $errors = [];
        $employeeIds = [];
        $justification = trim((string)($common['justification'] ?? ''));
        $justFile = overtimeSaveUploadedFileToProperty($files['common_justification_file'] ?? []);
        $lateAck = (($common['late_ack'] ?? 'N') === 'Y');

        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $employeeIds[] = $employeeId;
            }
        }

        $groupId = !empty($employeeIds) ? overtimeCreateGroupRequestElement($employeeIds, $createdBy, $config) : 0;

        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $preview = overtimeBuildSinglePreviewItem(
                $employeeId,
                trim((string)($common['date_start'] ?? '')),
                trim((string)($common['time_start'] ?? '')),
                trim((string)($common['date_end'] ?? '')),
                trim((string)($common['time_end'] ?? '')),
                (($common['is_duty'] ?? 'N') === 'Y'),
                $config
            );

            $segmentsRaw = Json::decode($preview['segments_json'] ?: '[]');
            $paymentTypes = $row['payment_type'] ?? [];

            $packResult = overtimeCreateEmployeeRequestPack(
                $employeeId,
                $segmentsRaw,
                $paymentTypes,
                $justification,
                $justFile,
                $lateAck,
                $createdBy,
                $config,
                $groupId
            );

            if (!$packResult['success']) {
                $errors = array_merge($errors, $packResult['errors']);
            } else {
                $allCreated = array_merge($allCreated, $packResult['created_ids']);
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'created_ids' => $allCreated, 'group_id' => $groupId];
        }

        overtimeUpdateGroupRequestLinks($groupId, $allCreated, $config);

        return ['success' => true, 'errors' => [], 'created_ids' => $allCreated, 'group_id' => $groupId];
    }

    if ($mode === $config['MODE_MULTI_DIFF']) {
        $common = $post['common'] ?? [];
        $rows = is_array($post['rows_diff'] ?? null) ? $post['rows_diff'] : [];
        $allCreated = [];
        $errors = [];
        $employeeIds = [];
        $justification = trim((string)($common['justification'] ?? ''));
        $justFile = overtimeSaveUploadedFileToProperty($files['common_justification_file'] ?? []);
        $lateAck = (($common['late_ack'] ?? 'N') === 'Y');

        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $employeeIds[] = $employeeId;
            }
        }

        $groupId = !empty($employeeIds) ? overtimeCreateGroupRequestElement($employeeIds, $createdBy, $config) : 0;

        foreach ($rows as $row) {
            $employeeId = (int)($row['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $preview = overtimeBuildSinglePreviewItem(
                $employeeId,
                trim((string)($row['date_start'] ?? '')),
                trim((string)($row['time_start'] ?? '')),
                trim((string)($row['date_end'] ?? '')),
                trim((string)($row['time_end'] ?? '')),
                (($common['is_duty'] ?? 'N') === 'Y'),
                $config
            );

            $segmentsRaw = Json::decode($preview['segments_json'] ?: '[]');
            $paymentTypes = $row['payment_type'] ?? [];

            $packResult = overtimeCreateEmployeeRequestPack(
                $employeeId,
                $segmentsRaw,
                $paymentTypes,
                $justification,
                $justFile,
                $lateAck,
                $createdBy,
                $config,
                $groupId
            );

            if (!$packResult['success']) {
                $errors = array_merge($errors, $packResult['errors']);
            } else {
                $allCreated = array_merge($allCreated, $packResult['created_ids']);
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'created_ids' => $allCreated, 'group_id' => $groupId];
        }

        overtimeUpdateGroupRequestLinks($groupId, $allCreated, $config);

        return ['success' => true, 'errors' => [], 'created_ids' => $allCreated, 'group_id' => $groupId];
    }

    return ['success' => false, 'errors' => ['Неизвестный режим создания.'], 'created_ids' => []];
}
