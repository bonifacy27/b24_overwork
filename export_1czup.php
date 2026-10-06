<?php
/**
 * Код для активити бизнес-процесса «PHP действие».
 * Выгружает текущую, непосредственно связанные и все заявки той же группы
 * в тестовую таблицу 1С:ЗУП.
 * В таблицу попадают только заявки со статусом «Выполнена».
 */

$iblockId = 391;
$completedStatusId = 3511824;
$overtimeTypeId = 3537677;
$weekendTypeId = 3537684;
$paymentTypeDoubleId = 3537687;
$paymentTypeLaborCodeId = 3557341;
$targetConnectionName = 'gatedb_test';
$targetTable = 'dbo.StaffOvertime_1CZUP';
$debugEnabled = true;

$propertyEmployee = 'SOTRUDNIK';
$propertyStatus = 'STATUS';
$propertyWorkType = 'TIP_RABOTY';
$propertyStartDate = 'DATA_NACHALA_RABOT';
$propertyEndDate = 'DATA_OKONCHANIYA_RABOT';
$propertyStartTime = 'VREMYA_NACHALA_RABOT';
$propertyEndTime = 'VREMYA_OKONCHANIYA_RABOT';
$propertyTotalHours = 'OBSHCHEE_KOLICHESTVO_CHASOV';
$propertyHoursFallback = 'KOLICHESTVO_CHASOV';
$propertyWorkJustification = 'OBOSNOVANIE_DLYA_PRIKAZA';
$propertyPaymentType = 'TIP_OPLATY';
$propertyGroup = 'GROUP_LINK';
$propertyLinked = 'SVYAZANNYE_ZAYAVKI';
$employeeGuidField = 'XML_ID';

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();
$currentRequestId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentRequestId = (int)str_replace('element_', '', (string)$currentRequestId);

if ($currentRequestId <= 0) {
    $this->WriteToTrackingService('export_1czup: не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock')) {
    $this->WriteToTrackingService('export_1czup: не удалось подключить модуль iblock');
    return;
}

$debugLog = function (string $message) use ($debugEnabled): void {
    if ($debugEnabled) {
        $this->WriteToTrackingService('export_1czup [debug]: ' . $message);
    }
};

$debugLog('Запуск экспорта для текущей заявки #' . $currentRequestId);

$getPropertyValues = static function (int $requestId, string $propertyCode) use ($iblockId): array {
    $values = [];
    $result = CIBlockElement::GetProperty(
        $iblockId,
        $requestId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['CODE' => $propertyCode]
    );

    while ($property = $result->Fetch()) {
        $value = $property['VALUE'] ?? null;
        if (is_array($value) && array_key_exists('TEXT', $value)) {
            $value = $value['TEXT'];
        }
        if ($value !== null && trim((string)$value) !== '') {
            $values[] = trim((string)$value);
        }
    }

    return array_values(array_unique($values));
};

$getFirstPropertyValue = static function (int $requestId, string $propertyCode) use ($getPropertyValues): string {
    $values = $getPropertyValues($requestId, $propertyCode);
    return $values[0] ?? '';
};

// Обоснование берется только из головной (текущей) заявки и затем используется
// для каждой строки этого запуска — как для текущей, так и для связанных/групповых.
$workJustification = $getFirstPropertyValue($currentRequestId, $propertyWorkJustification);
if (mb_strlen($workJustification) > 200) {
    $debugLog('Обоснование для приказа сокращено до 200 символов');
    $workJustification = mb_substr($workJustification, 0, 200);
}
$debugLog('Обоснование для приказа получено из заявки #' . $currentRequestId);

// Текущая заявка выгружается всегда. Связанные и групповые заявки
// добавляются в тот же набор; array_unique ниже защищает от повторов ID.
$requestIds = [$currentRequestId];

foreach ($getPropertyValues($currentRequestId, $propertyLinked) as $linkedRequestId) {
    $linkedRequestId = (int)$linkedRequestId;
    if ($linkedRequestId > 0) {
        $requestIds[] = $linkedRequestId;
    }
}

$groupId = (int)$getFirstPropertyValue($currentRequestId, $propertyGroup);
if ($groupId > 0) {
    $result = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID' => $iblockId,
            '=PROPERTY_' . $propertyGroup => $groupId,
        ],
        false,
        false,
        ['ID']
    );
    while ($request = $result->Fetch()) {
        $requestIds[] = (int)$request['ID'];
    }
}

$requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
if (empty($requestIds)) {
    $this->WriteToTrackingService('export_1czup: заявки для выгрузки не найдены');
    return;
}
$debugLog('К выгрузке отобраны заявки: ' . implode(', ', $requestIds));

try {
    $targetConnection = \Bitrix\Main\Application::getConnection($targetConnectionName);
    $sqlHelper = $targetConnection->getSqlHelper();
    $debugLog('Подключение ' . $targetConnectionName . ' установлено');
} catch (\Throwable $exception) {
    $this->WriteToTrackingService('export_1czup: ошибка подключения к ' . $targetConnectionName . ': ' . $exception->getMessage());
    return;
}

$parseDateTime = static function (string $date, string $time): ?\DateTimeImmutable {
    $value = trim($date . ' ' . $time);
    foreach (['d.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
        $dateTime = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dateTime && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dateTime;
        }
    }

    return null;
};

$exportedCount = 0;
$skippedCount = 0;

foreach ($requestIds as $requestId) {
    $element = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $requestId],
        false,
        false,
        ['ID']
    )->Fetch();

    if (!$element) {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' не найдена');
        $skippedCount++;
        continue;
    }

    $statusId = (int)$getFirstPropertyValue($requestId, $propertyStatus);
    if ($statusId !== $completedStatusId) {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — статус не «Выполнена»');
        $skippedCount++;
        continue;
    }

    $workTypeId = (int)$getFirstPropertyValue($requestId, $propertyWorkType);
    if ($workTypeId === $overtimeTypeId) {
        $requestType = 'OVERTIME';
    } elseif ($workTypeId === $weekendTypeId) {
        $requestType = 'WEEKEND';
    } else {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — неподдерживаемый тип');
        $skippedCount++;
        continue;
    }

    $employeeId = (int)$getFirstPropertyValue($requestId, $propertyEmployee);
    $employee = $employeeId > 0 ? CUser::GetByID($employeeId)->Fetch() : false;
    $employeeGuid = $employee ? trim((string)($employee[$employeeGuidField] ?? ''), "{} \t\n\r\0\x0B") : '';
    if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $employeeGuid)) {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — у сотрудника отсутствует корректный GUID в ' . $employeeGuidField);
        $skippedCount++;
        continue;
    }

    $workStartAt = $parseDateTime(
        $getFirstPropertyValue($requestId, $propertyStartDate),
        $getFirstPropertyValue($requestId, $propertyStartTime)
    );
    $workEndAt = $parseDateTime(
        $getFirstPropertyValue($requestId, $propertyEndDate),
        $getFirstPropertyValue($requestId, $propertyEndTime)
    );
    if (!$workStartAt || !$workEndAt || $workEndAt < $workStartAt) {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — некорректный период работ');
        $skippedCount++;
        continue;
    }

    $hoursRaw = str_replace(',', '.', $getFirstPropertyValue($requestId, $propertyTotalHours));
    if ($hoursRaw === '') {
        $hoursRaw = str_replace(',', '.', $getFirstPropertyValue($requestId, $propertyHoursFallback));
    }
    if (!is_numeric($hoursRaw) || (float)$hoursRaw < 0) {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — некорректное количество часов');
        $skippedCount++;
        continue;
    }

    $totalHours = number_format((float)$hoursRaw, 2, '.', '');

    $paymentTypeId = (int)$getFirstPropertyValue($requestId, $propertyPaymentType);
    if ($paymentTypeId === $paymentTypeDoubleId) {
        $paymentType = 'DOUBLE';
    } elseif ($paymentTypeId === $paymentTypeLaborCodeId) {
        $paymentType = 'LABOR_CODE';
    } else {
        $this->WriteToTrackingService('export_1czup: заявка #' . $requestId . ' пропущена — неподдерживаемый тип оплаты');
        $skippedCount++;
        continue;
    }

    $groupRequestId = (int)$getFirstPropertyValue($requestId, $propertyGroup);
    $groupRequestSql = $groupRequestId > 0 ? (string)$groupRequestId : 'NULL';
    $isLinkedRequest = empty($getPropertyValues($requestId, $propertyLinked)) ? 0 : 1;

    $safeRequestType = $sqlHelper->forSql($requestType);
    $safeEmployeeGuid = $sqlHelper->forSql($employeeGuid);
    $safeWorkStartAt = $sqlHelper->forSql($workStartAt->format('Y-m-d H:i:s'));
    $safeWorkEndAt = $sqlHelper->forSql($workEndAt->format('Y-m-d H:i:s'));
    $safeWorkJustification = $sqlHelper->forSql($workJustification);
    $safePaymentType = $sqlHelper->forSql($paymentType);

    // MERGE с HOLDLOCK делает повторные и параллельные запуски идемпотентными.
    // Существующая строка не обновляется, поэтому IsProcessed не сбрасывается в 0.
    $sql = "MERGE {$targetTable} WITH (HOLDLOCK) AS target
        USING (VALUES (
            {$requestId}, N'{$safeRequestType}', '{$safeEmployeeGuid}',
            '{$safeWorkStartAt}', '{$safeWorkEndAt}', {$totalHours},
            N'{$safeWorkJustification}', N'{$safePaymentType}',
            {$groupRequestSql}, {$isLinkedRequest}, 0
        )) AS source (
            RequestId, RequestType, Staff_ID, WorkStartAt, WorkEndAt,
            TotalHours, WorkJustification, PaymentType,
            GroupRequestId, IsLinkedRequest, IsProcessed
        )
        ON target.RequestId = source.RequestId
        WHEN NOT MATCHED THEN
            INSERT (
                RequestId, RequestType, Staff_ID, WorkStartAt, WorkEndAt,
                TotalHours, WorkJustification, PaymentType,
                GroupRequestId, IsLinkedRequest, IsProcessed
            )
            VALUES (
                source.RequestId, source.RequestType, source.Staff_ID,
                source.WorkStartAt, source.WorkEndAt, source.TotalHours,
                source.WorkJustification, source.PaymentType,
                source.GroupRequestId, source.IsLinkedRequest, source.IsProcessed
            )
        OUTPUT \$action AS MergeAction;";

    try {
        $recordset = $targetConnection->query($sql);
        $mergeResult = $recordset->fetch();
        $mergeAction = strtoupper((string)($mergeResult['MERGEACTION'] ?? $mergeResult['MergeAction'] ?? 'EXISTS'));
        $debugLog('Заявка #' . $requestId . ': результат SQL — ' . $mergeAction);
        $exportedCount++;
    } catch (\Throwable $exception) {
        $this->WriteToTrackingService('export_1czup: ошибка выгрузки заявки #' . $requestId . ': ' . $exception->getMessage());
        $skippedCount++;
    }
}

$this->WriteToTrackingService(
    'export_1czup: обработано заявок — ' . count($requestIds)
    . ', успешно выгружено/уже было выгружено — ' . $exportedCount
    . ', пропущено или с ошибкой — ' . $skippedCount
);
