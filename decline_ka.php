<?php
/**
 * decline_ka.php
 *
 * Единый скрипт для активити БП «PHP-код» (Bitrix24).
 * Последовательно отклоняет все групповые и связанные заявки,
 * привязанные к текущей заявке.
 *
 * Каждый обработчик выполняется в отдельной области видимости: отсутствие
 * групповой или связанной заявки завершает только соответствующий этап,
 * не прерывая выполнение второго обработчика.
 */

// Сначала отклоняем все заявки из групп, указанных в GROUP_LINK.
(function (): void {
/**
 * group_auto_decline_ka_v1_0_0.php
 *
 * Скрипт для активити БП "PHP код" (Bitrix24).
 *
 * Версия: 1.2.0
 * Дата: 24.06.2026
 *
 * Назначение:
 * Автоматически отклоняет все заявки одной групповой заявки.
 *
 * Логика:
 * 1) Берет у текущей заявки значение свойства GROUP_LINK.
 * 2) Находит все заявки инфоблока 391, привязанные к этой же группе.
 * 3) Если в группе есть заявки с типом оплаты "Предоставление отгула", переводит их в статус "Отменена" и запускает БП 1292.
 * 4) Если по заявке есть активное задание БП, выполняет его с отрицательным результатом Decline.
 * 5) Заявки группы в статусе "Выполнена" без типа оплаты "Предоставление отгула" переводит в статус "Отменена" и пишет историю.
 * 6) От дублей защищается через:
 *    - DB-lock на группу;
 *    - служебное поле AUTO_DECLINE_MARKERS.
 *
 * Важно:
 * - Технический маркер НЕ пишется в пользовательскую историю ISTORIYA.
 * - Маркер хранится в AUTO_DECLINE_MARKERS.
 *
 * Изменения v1.1.0:
 * - добавлена проверка заявок группы по типу оплаты TIP_OPLATY;
 * - если TIP_OPLATY = 3537688 ("Предоставление отгула"), запускается БП шаблона 1292;
 * - запуск БП 1292 защищен от дублей через DB-lock на группу и marker в AUTO_DECLINE_MARKERS;
 * - marker запуска БП хранится только в AUTO_DECLINE_MARKERS и не пишется в ISTORIYA.
 *
 * Изменения v1.2.0:
 * - устранена коллизия повторного запуска БП 1292 при групповом автоотклонении;
 * - заявки с TIP_OPLATY = 3537688 сначала переводятся в статус "Отменена";
 * - только после фиксации статуса и служебного marker-а запускается БП 1292;
 * - обработка заявок с отгулом выполняется первым проходом по всей группе до завершения заданий БП;
 * - технический marker по-прежнему хранится только в AUTO_DECLINE_MARKERS и не пишется в ISTORIYA.
 */

$iblockId = 391;

$propertyCodeGroup = 'GROUP_LINK';
$propertyCodeStatus = 'STATUS';
$propertyCodeHistory = 'ISTORIYA';
$propertyCodeMarkers = 'AUTO_DECLINE_MARKERS';

// v1.1.0: тип оплаты заявки.
// PROPERTY_3087 / TIP_OPLATY — привязка к элементу справочника типов оплаты.
// 3537688 — "Предоставление отгула".
$propertyCodePaymentType = 'TIP_OPLATY';
$paymentTypeDayOffElementId = 3537688;

// v1.1.0: БП, который нужно запускать по заявке с типом оплаты "Предоставление отгула".
$dayOffWorkflowTemplateId = 1292;
$dayOffWorkflowParameters = [];

$statusDeclineElementId = 3511771; // ID элемента статуса "В работе КА".
$statusDeclineName = 'В работе КА'; // Фолбэк-проверка по названию статуса.

// v1.3.0: статус, в который переводим отменяемые заявки.
$statusCompletedElementId = 3511824; // ID элемента статуса "Выполнена".
$statusCompletedName = 'Выполнена';
$statusRejectedElementId = 3511775; // ID элемента статуса "Отменена".
$statusRejectedName = 'Отменена';

$executorUserId = 1; // Резервный пользователь для выполнения задания БП.
$debugEnabled = true; // После проверки на бою можно поставить false.

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();

$currentElementId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentElementId = (int)str_replace('element_', '', (string)$currentElementId);

if ($currentElementId <= 0) {
    $this->WriteToTrackingService('group_auto_decline_ka: Не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc')) {
    $this->WriteToTrackingService('group_auto_decline_ka: Не удалось подключить модули iblock/bizproc');
    return;
}

$currentUserId = 0;
if (class_exists('Bitrix\\Main\\Engine\\CurrentUser')) {
    $currentUserId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
}
if ($currentUserId <= 0 && isset($GLOBALS['USER']) && is_object($GLOBALS['USER'])) {
    $currentUserId = (int)$GLOBALS['USER']->GetID();
}
if ($currentUserId <= 0) {
    $currentUserId = 1;
}

$currentUserName = 'Пользователь #' . $currentUserId;
$userRs = CUser::GetByID($currentUserId);
if ($userData = $userRs->Fetch()) {
    $fio = trim((string)$userData['LAST_NAME'] . ' ' . (string)$userData['NAME'] . ' ' . (string)$userData['SECOND_NAME']);
    if ($fio !== '') {
        $currentUserName = $fio;
    }
}

$documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . $iblockId];

$debugLog = function (string $message) use ($debugEnabled): void {
    if ($debugEnabled) {
        $this->WriteToTrackingService('group_auto_decline_ka [debug]: ' . $message);
    }
};

$getElementTitle = static function (int $elementId) use ($iblockId): string {
    if ($elementId <= 0) {
        return '';
    }

    $rsElement = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $elementId],
        false,
        false,
        ['ID', 'NAME']
    );

    if ($element = $rsElement->Fetch()) {
        return trim((string)$element['NAME']);
    }

    return '';
};

$getPropertyValues = static function (int $elementId, string $propertyCode) use ($iblockId): array {
    $values = [];

    if ($elementId <= 0 || $propertyCode === '') {
        return $values;
    }

    $rsProps = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCode]);
    while ($prop = $rsProps->Fetch()) {
        $value = $prop['VALUE'] ?? '';
        if (is_array($value) && isset($value['TEXT'])) {
            $value = $value['TEXT'];
        }
        $value = trim((string)$value);
        if ($value !== '') {
            $values[] = $value;
        }
    }

    return array_values(array_unique($values));
};

$getLinkedGroupIds = static function (int $elementId) use ($getPropertyValues, $propertyCodeGroup): array {
    $groupIds = [];
    foreach ($getPropertyValues($elementId, $propertyCodeGroup) as $value) {
        $groupId = (int)$value;
        if ($groupId > 0) {
            $groupIds[$groupId] = true;
        }
    }

    return array_keys($groupIds);
};

$getElementStatus = static function (int $elementId) use ($iblockId, $propertyCodeStatus): array {
    $statusValue = '';
    $statusElementId = 0;

    $statusPropRes = CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => $propertyCodeStatus]);
    if ($statusProp = $statusPropRes->Fetch()) {
        $statusValue = trim((string)($statusProp['VALUE_ENUM'] ?: $statusProp['VALUE']));
        $statusElementId = (int)($statusProp['VALUE'] ?? 0);
    }

    return [$statusElementId, $statusValue];
};

$isExpectedStatus = static function (int $elementId) use ($getElementStatus, $statusDeclineElementId, $statusDeclineName): bool {
    [$statusElementId, $statusValue] = $getElementStatus($elementId);

    if ($statusDeclineElementId > 0 && $statusElementId > 0 && $statusElementId === $statusDeclineElementId) {
        return true;
    }

    return $statusValue !== '' && $statusValue === $statusDeclineName;
};

$getGroupRequestIds = static function (int $groupId) use ($iblockId, $propertyCodeGroup): array {
    $requestIds = [];

    if ($groupId <= 0) {
        return $requestIds;
    }

    $filter = [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        '=PROPERTY_' . $propertyCodeGroup => $groupId,
    ];

    $rsElements = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        ['ID', 'IBLOCK_ID', 'NAME']
    );

    while ($element = $rsElements->Fetch()) {
        $elementId = (int)$element['ID'];
        if ($elementId > 0) {
            $requestIds[$elementId] = true;
        }
    }

    return array_keys($requestIds);
};

$formatHistoryLine = static function (string $message): string {
    return date('d.m.Y H:i:s') . ' ' . $message;
};

$appendHistory = static function (int $elementId, string $message) use ($iblockId, $propertyCodeHistory, $formatHistoryLine): void {
    if ($elementId <= 0 || $message === '') {
        return;
    }

    $line = $formatHistoryLine($message);

    $existing = '';
    $propRes = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeHistory]);
    if ($prop = $propRes->Fetch()) {
        $value = $prop['VALUE'] ?? '';
        if (is_array($value) && isset($value['TEXT'])) {
            $value = $value['TEXT'];
        }
        $existing = trim((string)$value);
    }

    $newValue = $existing !== '' ? ($existing . "\n" . $line) : $line;
    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeHistory => $newValue]);
};

$getMarkers = static function (int $elementId) use ($getPropertyValues, $propertyCodeMarkers): array {
    return $getPropertyValues($elementId, $propertyCodeMarkers);
};

$hasMarker = static function (int $elementId, string $marker) use ($getMarkers): bool {
    if ($elementId <= 0 || $marker === '') {
        return false;
    }

    return in_array($marker, $getMarkers($elementId), true);
};

$appendMarker = static function (int $elementId, string $marker) use ($iblockId, $propertyCodeMarkers, $getMarkers): void {
    if ($elementId <= 0 || $marker === '') {
        return;
    }

    $markers = $getMarkers($elementId);
    if (!in_array($marker, $markers, true)) {
        $markers[] = $marker;
    }

    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeMarkers => $markers]);
};

$appendHistoryOnce = static function (int $elementId, string $marker, string $message) use ($iblockId, $propertyCodeHistory, $hasMarker, $appendMarker, $appendHistory): bool {
    if ($elementId <= 0 || $marker === '' || $message === '') {
        return false;
    }

    if ($hasMarker($elementId, $marker)) {
        return false;
    }

    $existing = '';
    $propRes = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeHistory]);
    if ($prop = $propRes->Fetch()) {
        $value = $prop['VALUE'] ?? '';
        if (is_array($value) && isset($value['TEXT'])) {
            $value = $value['TEXT'];
        }
        $existing = trim((string)$value);
    }

    if ($existing !== '' && strpos($existing, $message) !== false) {
        $appendMarker($elementId, $marker);
        return false;
    }

    // Marker ставим до записи истории: каскадные экземпляры, ожидающие DB-lock,
    // не должны успеть повторно добавить ту же строку в ISTORIYA.
    $appendMarker($elementId, $marker);
    $appendHistory($elementId, $message);

    return true;
};

$makeGroupMarker = static function (int $groupId, int $elementId): string {
    return 'AUTO_DECLINE_KA_GROUP_REQUEST:' . $groupId . ':' . $elementId;
};

/**
 * v1.1.0: marker запуска БП 1292 по заявке с типом оплаты "Предоставление отгула".
 * Marker не зависит от группы: БП должен быть запущен по конкретной заявке только один раз.
 */
$makeDayOffWorkflowMarker = static function (int $elementId) use ($dayOffWorkflowTemplateId, $paymentTypeDayOffElementId): string {
    return 'AUTO_DAYOFF_REJECTED_AND_WORKFLOW_STARTED:' . $dayOffWorkflowTemplateId . ':TIP_OPLATY:' . $paymentTypeDayOffElementId . ':REQUEST:' . $elementId;
};

/**
 * v1.1.0: получить ID элементов из свойства TIP_OPLATY.
 * Поддерживает одиночное и множественное свойство-привязку.
 */
$getPaymentTypeIds = static function (int $elementId) use ($iblockId, $propertyCodePaymentType): array {
    $ids = [];

    if ($elementId <= 0) {
        return [];
    }

    $res = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodePaymentType]);
    while ($prop = $res->Fetch()) {
        $value = (int)($prop['VALUE'] ?? 0);
        if ($value > 0) {
            $ids[$value] = true;
        }
    }

    return array_keys($ids);
};

/**
 * v1.2.0: установить статус заявки.
 * STATUS / PROPERTY_3081 — привязка к элементу справочника статусов.
 */
$setRequestStatus = static function (int $elementId, int $statusElementId) use ($iblockId, $propertyCodeStatus, $debugLog): bool {
    if ($elementId <= 0 || $statusElementId <= 0) {
        return false;
    }

    try {
        CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
            $propertyCodeStatus => $statusElementId,
        ]);
        $debugLog("v1.2.0: заявка #{$elementId} переведена в статус ID={$statusElementId}");
        return true;
    } catch (\Throwable $e) {
        $debugLog("v1.2.0: ошибка установки статуса заявки #{$elementId}: " . $e->getMessage());
        return false;
    }
};

/**
 * v1.1.0: если у заявки TIP_OPLATY = 3537688, переводит ее в "Отменена" и запускает БП 1292.
 * Защита от дублей: DB-lock на группу + marker в AUTO_DECLINE_MARKERS.
 * Технический marker и workflowId в пользовательскую историю ISTORIYA не пишутся.
 */
$startDayOffWorkflowIfNeeded = static function (int $requestId, int $currentElementId, int $groupId) use (
    $paymentTypeDayOffElementId,
    $dayOffWorkflowTemplateId,
    $dayOffWorkflowParameters,
    $statusRejectedElementId,
    $statusRejectedName,
    $getPaymentTypeIds,
    $getElementStatus,
    $setRequestStatus,
    $makeDayOffWorkflowMarker,
    $hasMarker,
    $appendMarker,
    $debugLog
): array {
    if ($requestId <= 0) {
        return [false, 'Некорректный ID заявки'];
    }

    $paymentTypeIds = $getPaymentTypeIds($requestId);
    $debugLog(
        "v1.2.0: TIP_OPLATY requestId={$requestId}: "
        . print_r($paymentTypeIds, true)
    );

    if (!in_array($paymentTypeDayOffElementId, $paymentTypeIds, true)) {
        return [false, 'Тип оплаты не "Предоставление отгула"'];
    }

    $workflowMarker = $makeDayOffWorkflowMarker($requestId);
    if ($hasMarker($requestId, $workflowMarker)) {
        return [false, "Заявка с отгулом уже обработана ранее, marker={$workflowMarker}"];
    }

    // v1.3.0: сначала переводим заявку с отгулом в статус "Отменена".
    [$currentStatusId, $currentStatusName] = $getElementStatus($requestId);
    if ($currentStatusId !== $statusRejectedElementId) {
        if (!$setRequestStatus($requestId, $statusRejectedElementId)) {
            return [false, "Не удалось перевести заявку в статус '{$statusRejectedName}'"];
        }
    } else {
        $debugLog("v1.2.0: заявка #{$requestId} уже в статусе '{$statusRejectedName}'");
    }

    // v1.2.0: marker ставим ДО запуска БП 1292.
    // Это защищает от повторных экземпляров группового скрипта, которые стартуют после автоотклонения других заявок группы.
    // Marker технический: в ISTORIYA он не пишется.
    $appendMarker($requestId, $workflowMarker);

    if (!class_exists('CBPDocument')) {
        return [false, 'Класс CBPDocument недоступен'];
    }

    if (class_exists('Bitrix\\Main\\Loader')) {
        if (!\Bitrix\Main\Loader::includeModule('bizproc')) {
            return [false, 'Не удалось подключить модуль bizproc'];
        }
        \Bitrix\Main\Loader::includeModule('lists');
    }

    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $requestId];
    $workflowErrors = [];

    try {
        $workflowId = CBPDocument::StartWorkflow(
            $dayOffWorkflowTemplateId,
            $documentId,
            $dayOffWorkflowParameters,
            $workflowErrors
        );

        if (!empty($workflowErrors)) {
            return [false, 'Ошибки запуска БП: ' . print_r($workflowErrors, true)];
        }

        if ((string)$workflowId === '') {
            return [false, 'CBPDocument::StartWorkflow вернул пустой workflowId'];
        }

        $debugLog(
            "v1.3.0: заявка #{$requestId} с отгулом переведена в статус '{$statusRejectedName}', "
            . "БП {$dayOffWorkflowTemplateId} запущен, workflowId={$workflowId}, "
            . "группа={$groupId}, инициатор={$currentElementId}"
        );

        return [true, (string)$workflowId];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
};

$finalizeDeclinedRequest = function (int $requestId, string $marker, string $historyMessage) use (
    $statusRejectedElementId,
    $statusRejectedName,
    $getElementStatus,
    $setRequestStatus,
    $hasMarker,
    $appendHistoryOnce,
    $currentUserId,
    $debugLog
): bool {
    if ($requestId <= 0 || $marker === '' || $historyMessage === '') {
        return false;
    }

    if ($hasMarker($requestId, $marker)) {
        $debugLog("Заявка #{$requestId} уже имеет marker {$marker}, финализация отклонения не повторяется");
        return false;
    }

    [$currentStatusId, $currentStatusName] = $getElementStatus($requestId);
    if ($currentStatusId !== $statusRejectedElementId) {
        if (!$setRequestStatus($requestId, $statusRejectedElementId)) {
            $debugLog("Не удалось перевести заявку #{$requestId} в статус '{$statusRejectedName}' перед записью истории");
            return false;
        }
    } else {
        $debugLog("Заявка #{$requestId} уже находится в статусе '{$statusRejectedName}'");
    }

    $historyWasAdded = $appendHistoryOnce($requestId, $marker, $historyMessage);
    if ($historyWasAdded && class_exists('CBPDocument')) {
        $requestDocumentId = ['lists', 'Bitrix\Lists\BizprocDocumentLists', $requestId];
        CBPDocument::AddDocumentToHistory($requestDocumentId, $historyMessage, $currentUserId);
    }

    return $historyWasAdded;
};

$getConnection = static function () {
    if (class_exists('Bitrix\\Main\\Application')) {
        return \Bitrix\Main\Application::getConnection();
    }

    return null;
};

$acquireLock = static function (string $lockKey, int $timeoutSeconds = 10) use ($getConnection, $debugLog): bool {
    $connection = $getConnection();
    if (!$connection || $lockKey === '') {
        $debugLog('DB-lock недоступен, продолжаем только с маркерами');
        return true;
    }

    try {
        $sqlHelper = $connection->getSqlHelper();
        $safeLockName = $sqlHelper->forSql('b24_' . md5($lockKey));
        $result = (int)$connection->queryScalar("SELECT GET_LOCK('{$safeLockName}', " . (int)$timeoutSeconds . ")");
        return $result === 1;
    } catch (\Throwable $e) {
        $debugLog('Ошибка получения DB-lock: ' . $e->getMessage());
        return true;
    }
};

$releaseLock = static function (string $lockKey) use ($getConnection, $debugLog): void {
    $connection = $getConnection();
    if (!$connection || $lockKey === '') {
        return;
    }

    try {
        $sqlHelper = $connection->getSqlHelper();
        $safeLockName = $sqlHelper->forSql('b24_' . md5($lockKey));
        $connection->queryExecute("SELECT RELEASE_LOCK('{$safeLockName}')");
    } catch (\Throwable $e) {
        $debugLog('Ошибка освобождения DB-lock: ' . $e->getMessage());
    }
};

$isTaskStillRunning = static function (int $taskId): bool {
    if ($taskId <= 0 || !class_exists('CBPTaskService')) {
        return false;
    }

    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    if (!is_object($res)) {
        return false;
    }

    $task = $res->Fetch();
    if (!$task) {
        return false;
    }

    return (int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running;
};

$resolveDeclineActionCode = static function (int $taskId): string {
    $controls = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        $controls = (array)CBPDocument::GetTaskControls($taskId);
    }
    if (empty($controls) && method_exists('CBPTaskService', 'GetTaskControls')) {
        $controls = (array)CBPTaskService::GetTaskControls($taskId);
    }

    foreach ($controls as $control) {
        $id = (string)($control['CONTROL_ID'] ?? $control['ID'] ?? '');
        $label = mb_strtolower(trim((string)($control['NAME'] ?? $control['TEXT'] ?? $control['LABEL'] ?? '')));
        $idNorm = mb_strtolower($id);

        if (
            strpos($label, 'отклон') !== false
            || strpos($label, 'отказ') !== false
            || strpos($idNorm, 'decline') !== false
            || strpos($idNorm, 'reject') !== false
            || $idNorm === 'no'
            || $idNorm === 'n'
        ) {
            return $id !== '' ? $id : 'Decline';
        }
    }

    return 'Decline';
};

$runAsUser = function (int $userId, callable $callback) use ($debugLog) {
    global $USER;

    $canAuth = is_object($USER) && method_exists($USER, 'Authorize') && method_exists($USER, 'GetID');
    $prevUserId = $canAuth ? (int)$USER->GetID() : 0;

    if ($canAuth && $userId > 0 && $prevUserId !== $userId) {
        $USER->Authorize($userId);
        $debugLog("runAsUser: переключили контекст пользователя с {$prevUserId} на {$userId}");
    }

    try {
        return $callback();
    } finally {
        if ($canAuth && $prevUserId > 0 && (int)$USER->GetID() !== $prevUserId) {
            $USER->Authorize($prevUserId);
            $debugLog("runAsUser: восстановили контекст пользователя {$prevUserId}");
        }
    }
};

$collectTaskDiagnostics = function (int $taskId, int $userId, string $stage = '') use ($debugLog): void {
    if (!class_exists('CBPTaskService')) {
        return;
    }

    $taskResDbg = CBPTaskService::GetList(
        ['ID' => 'DESC'],
        ['ID' => $taskId],
        false,
        false,
        ['ID', 'STATUS', 'USER_ID', 'USER_STATUS', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME']
    );
    if (is_object($taskResDbg)) {
        $taskDbg = $taskResDbg->Fetch();
        $debugLog("diag[{$stage}] task snapshot: " . print_r($taskDbg, true));
    }

    $controlsAttempts = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        try {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId)'] = CBPDocument::GetTaskControls($taskId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId) error'] = $e->getMessage();
        }
        try {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId,userId)'] = CBPDocument::GetTaskControls($taskId, $userId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId,userId) error'] = $e->getMessage();
        }
    }
    if (method_exists('CBPTaskService', 'GetTaskControls')) {
        try {
            $controlsAttempts['CBPTaskService::GetTaskControls(taskId)'] = CBPTaskService::GetTaskControls($taskId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPTaskService::GetTaskControls(taskId) error'] = $e->getMessage();
        }
    }

    $debugLog("diag[{$stage}] controls attempts: " . print_r($controlsAttempts, true));
};

$doDeclineTask = function (array $task, int $userId, string $comment = '', string $fallbackActivityName = '') use ($resolveDeclineActionCode, $isTaskStillRunning, $debugLog, $collectTaskDiagnostics, $runAsUser): array {
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [false, 'Некорректный taskId'];
    }

    $actionCode = $resolveDeclineActionCode($taskId);

    if ((string)($task['ACTIVITY_NAME'] ?? '') === '' && class_exists('CBPTaskService')) {
        $taskReloadRes = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['ID' => $taskId],
            false,
            false,
            ['ID', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME', 'PARAMETERS']
        );
        if (is_object($taskReloadRes)) {
            $taskReload = $taskReloadRes->Fetch();
            if (is_array($taskReload)) {
                $task = array_merge($task, $taskReload);
                $debugLog('Перезагрузили task с расширенными полями: ' . print_r($taskReload, true));
            }
        }
    }

    $debugLog("Начало doDeclineTask: taskId={$taskId}, userId={$userId}, actionCode={$actionCode}");
    $collectTaskDiagnostics($taskId, $userId, 'start');
    $debugLog('Task raw: ' . print_r($task, true));

    try {
        // v1.0.4: по HTML формы кнопка отказа называется nonapprove, а не Decline/Reject.
        // Поэтому первой попыткой отправляем payload, максимально похожий на реальную форму popup.php:
        // action=doTask, id, TASK_ID, workflow_id, nonapprove=Y.
        $workflowIdForForm = (string)($task['WORKFLOW_ID'] ?? '');
        $browserRejectRequest = [
            'action' => 'doTask',
            'id' => $taskId,
            'TASK_ID' => $taskId,
            'workflow_id' => $workflowIdForForm,
            'nonapprove' => 'Y',
            'comment' => $comment,
            'task_comment' => $comment,
            'COMMENT' => $comment,
            'USER_ID' => $userId,
            'REAL_USER_ID' => $userId,
        ];
        if (function_exists('bitrix_sessid')) {
            $browserRejectRequest['sessid'] = bitrix_sessid();
        }

        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $errors = [];
            $debugLog("v1.0.4: Вызов CBPDocument::PostTaskForm как HTML popup form для taskId={$taskId}, поля: " . print_r($browserRejectRequest, true));
            $postResult = $runAsUser($userId, static function () use ($taskId, $userId, $browserRejectRequest, &$errors) {
                return CBPDocument::PostTaskForm($taskId, $userId, $browserRejectRequest, $errors, '', $userId);
            });
            $debugLog("v1.0.4: browser-like PostTaskForm result для taskId={$taskId}: " . print_r($postResult, true));
            if (!empty($errors)) {
                $debugLog("v1.0.4: browser-like PostTaskForm errors для taskId={$taskId}: " . print_r($errors, true));
            }
            if (empty($errors) && !$isTaskStillRunning($taskId)) {
                $debugLog("v1.0.4: browser-like PostTaskForm успешно завершил taskId={$taskId}");
                return [true, ''];
            }
            $collectTaskDiagnostics($taskId, $userId, 'after_browser_like_posttaskform');
        }

        // v1.0.4: для задания "Утверждение документа" надежнее сначала завершать task
        // через статус пользователя "Нет/Отклонено". По логу у задания есть TaskButton2Message => Отклонить,
        // а GetTaskControls/PostTaskForm могут возвращать ActivityNotFound, если PHP-действие запущено уже
        // после прерывания текущего статуса БП.
        if (class_exists('CBPTaskService') && method_exists('CBPTaskService', 'CompleteTask')) {
            $declineStatuses = [];
            if (class_exists('CBPTaskUserStatus') && defined('CBPTaskUserStatus::No')) {
                $declineStatuses[] = CBPTaskUserStatus::No;
            }
            $declineStatuses[] = 2; // стандартное значение CBPTaskUserStatus::No в коробочном Битрикс.
            $declineStatuses = array_values(array_unique(array_map('intval', $declineStatuses)));

            foreach ($declineStatuses as $declineStatus) {
                $completePayloads = [
                    ['COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment, 'nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'TaskButton2' => 'Y', 'ACTION' => 'TaskButton2'],
                    ['COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ];

                foreach ($completePayloads as $payloadIdx => $completePayload) {
                    $completeAttempts = [
                        [$taskId, $userId, $declineStatus, $completePayload],
                        [$taskId, $userId, $declineStatus, [], $completePayload],
                        [$taskId, $userId, $declineStatus],
                    ];

                    foreach ($completeAttempts as $attemptIdx => $completeArgs) {
                        try {
                            $debugLog(
                                "Вызов CBPTaskService::CompleteTask для taskId={$taskId}, userId={$userId}, "
                                . "declineStatus={$declineStatus}, payloadIdx={$payloadIdx}, attempt={$attemptIdx}, args="
                                . print_r($completeArgs, true)
                            );
                            $completeResult = $runAsUser($userId, static function () use ($completeArgs) {
                                return call_user_func_array(['CBPTaskService', 'CompleteTask'], $completeArgs);
                            });
                            $debugLog("CompleteTask result для taskId={$taskId}: " . print_r($completeResult, true));

                            if (!$isTaskStillRunning($taskId)) {
                                $debugLog("CompleteTask успешно завершил taskId={$taskId} со статусом отказа {$declineStatus}");
                                return [true, ''];
                            }
                        } catch (\Throwable $e) {
                            $debugLog("CompleteTask exception taskId={$taskId}, attempt={$attemptIdx}: " . $e->getMessage());
                        }
                    }
                }
            }
            $debugLog("После всех попыток CompleteTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_completetask');
        }

        // v1.0.4: если CompleteTask недоступен/не сработал, пробуем DoTask раньше PostTaskForm
        // и первым вариантом передаем фактическую кнопку отказа TaskButton2.
        if (class_exists('CBPTaskService') && method_exists('CBPTaskService', 'DoTask')) {
            $doTaskPayloadsFirst = [
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'TaskButton2' => 'Y', 'ACTION' => 'TaskButton2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'taskbutton2' => 'Y', 'ACTION' => 'taskbutton2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'BUTTON2' => 'Y', 'ACTION' => 'BUTTON2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'DECLINE' => 'Y', 'REJECT' => 'Y', 'APPROVE' => 'N', 'approve' => 'N', 'status' => 'N', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
            ];
            foreach ($doTaskPayloadsFirst as $payloadIdx => $payload) {
                try {
                    $debugLog("Ранний вызов CBPTaskService::DoTask для taskId={$taskId}, payloadIdx={$payloadIdx}, payload=" . print_r($payload, true));
                    $doTaskResult = $runAsUser($userId, static function () use ($taskId, $userId, $payload) {
                        return CBPTaskService::DoTask($taskId, $userId, $payload);
                    });
                    $debugLog("Ранний DoTask result для taskId={$taskId}, payloadIdx={$payloadIdx}: " . print_r($doTaskResult, true));
                    if (!$isTaskStillRunning($taskId)) {
                        $debugLog("Ранний DoTask успешно завершил taskId={$taskId}, payloadIdx={$payloadIdx}");
                        return [true, ''];
                    }
                } catch (\Throwable $e) {
                    $debugLog("Ранний DoTask exception taskId={$taskId}, payloadIdx={$payloadIdx}: " . $e->getMessage());
                }
            }
            $debugLog("После ранних попыток DoTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_early_dotask');
        }

        $codesToTry = array_values(array_unique(array_filter([
            'nonapprove',
            'NONAPPROVE',
            $actionCode,
            'decline',
            'Decline',
            'reject',
            'Reject',
            'no',
            'NO',
            'N',
            'TaskButton2',
            'taskbutton2',
            'BUTTON2',
        ])));

        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $requests = [
                [
                    'nonapprove' => 'Y',
                    'action' => 'doTask',
                    'id' => $taskId,
                    'TASK_ID' => $taskId,
                    'workflow_id' => $workflowIdForForm,
                    'decline' => 'Y',
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'ACTION' => 'decline',
                    'status' => 'N',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $userId,
                    'REAL_USER_ID' => $userId,
                ],
            ];

            foreach ($codesToTry as $codeTry) {
                $requests[] = [
                    'nonapprove' => 'Y',
                    'action' => 'doTask',
                    'id' => $taskId,
                    'TASK_ID' => $taskId,
                    'workflow_id' => $workflowIdForForm,
                    'decline' => $codeTry,
                    $codeTry => 'Y',
                    'ACTION' => $codeTry,
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'APPROVE' => 'N',
                    'approve' => 'N',
                    'status' => 'N',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $userId,
                    'REAL_USER_ID' => $userId,
                ];
            }

            foreach ($requests as $idx => $requestFields) {
                $errors = [];
                $debugLog("Вызов CBPDocument::PostTaskForm для taskId={$taskId}, attempt={$idx}, поля: " . print_r($requestFields, true));

                $postResult = $runAsUser($userId, static function () use ($taskId, $userId, $requestFields, &$errors) {
                    return CBPDocument::PostTaskForm($taskId, $userId, $requestFields, $errors, '', $userId);
                });

                $debugLog("PostTaskForm result для taskId={$taskId}, attempt={$idx}: " . print_r($postResult, true));
                if (!empty($errors)) {
                    $debugLog("PostTaskForm errors для taskId={$taskId}, attempt={$idx}: " . print_r($errors, true));
                }

                if (empty($errors) && !$isTaskStillRunning($taskId)) {
                    $debugLog("PostTaskForm успешно завершил taskId={$taskId} на attempt={$idx}");
                    return [true, ''];
                }
            }

            $debugLog("После всех попыток PostTaskForm taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_posttaskform');
        }

        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activityCandidates = array_values(array_unique(array_filter([
            (string)($task['ACTIVITY_NAME'] ?? ''),
            (string)($task['ACTIVITY'] ?? ''),
            $fallbackActivityName,
        ])));

        if ($workflowId !== '' && !empty($activityCandidates) && class_exists('CBPRuntime') && method_exists('CBPRuntime', 'SendExternalEvent')) {
            $payload = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                'comment' => $comment,
                // В approve-activity отказ должен передаваться как отрицательный результат голосования.
                // Для согласования рабочий payload: APPROVE=1, approve=Y, status=Y.
                // Для отклонения зеркальный payload: APPROVE=0, approve=N, status=N.
                'APPROVE' => 0,
                'approve' => 'N',
                // Для стандартного UI Битрикс кнопка отклонения у этого задания отправляет именно nonapprove=Y.
                // В SendExternalEvent это критично: decline/reject/status=N сами по себе не завершают approvecopyactiveschedule.
                'nonapprove' => 'Y',
                'NONAPPROVE' => 'Y',
                'DECLINE' => 1,
                'REJECT' => 1,
                'decline' => 'Y',
                'reject' => 'Y',
                'status' => 'N',
            ];

            foreach ($activityCandidates as $activityCandidate) {
                $debugLog("Вызов CBPRuntime::SendExternalEvent workflowId={$workflowId}, activity={$activityCandidate}, payload=" . print_r($payload, true));

                $eventResult = $runAsUser($userId, static function () use ($workflowId, $activityCandidate, $payload) {
                    return CBPRuntime::SendExternalEvent($workflowId, $activityCandidate, $payload);
                });

                $debugLog("SendExternalEvent result taskId={$taskId}, activity={$activityCandidate}: " . print_r($eventResult, true));
                if (!$isTaskStillRunning($taskId)) {
                    $debugLog("SendExternalEvent успешно завершил taskId={$taskId}, activity={$activityCandidate}");
                    return [true, ''];
                }

                $debugLog("После SendExternalEvent taskId={$taskId} всё ещё running, activity={$activityCandidate}");
                $collectTaskDiagnostics($taskId, $userId, 'after_external_event_' . $activityCandidate);
            }
        } else {
            $debugLog("SendExternalEvent пропущен: workflowId='{$workflowId}', activityCandidates=" . print_r($activityCandidates, true));
        }

        if (method_exists('CBPTaskService', 'DoTask')) {
            foreach ($codesToTry as $codeTry) {
                $payload = [
                    'ACTION' => $codeTry,
                    $codeTry => 'Y',
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'APPROVE' => 'N',
                    'approve' => 'N',
                    'status' => 'N',
                    'COMMENT' => $comment,
                    'comment' => $comment,
                    'task_comment' => $comment,
                ];

                $debugLog("Вызов CBPTaskService::DoTask для taskId={$taskId}, codeTry={$codeTry}, payload: " . print_r($payload, true));

                $doTaskResult = $runAsUser($userId, static function () use ($taskId, $userId, $payload) {
                    return CBPTaskService::DoTask($taskId, $userId, $payload);
                });

                $debugLog("DoTask result для taskId={$taskId}, codeTry={$codeTry}: " . print_r($doTaskResult, true));
                if (!$isTaskStillRunning($taskId)) {
                    $debugLog("DoTask успешно завершил taskId={$taskId} с codeTry={$codeTry}");
                    return [true, ''];
                }
            }

            $debugLog("После всех попыток DoTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_dotask');
        }
    } catch (\Throwable $e) {
        $debugLog("Throwable в doDeclineTask для taskId={$taskId}: " . $e->getMessage());
        return [false, $e->getMessage()];
    }

    $controlsDbg = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        $controlsDbg = (array)CBPDocument::GetTaskControls($taskId);
    }
    if (empty($controlsDbg) && method_exists('CBPTaskService', 'GetTaskControls')) {
        $controlsDbg = (array)CBPTaskService::GetTaskControls($taskId);
    }

    $debugLog("Итог: taskId={$taskId} не завершился. Controls: " . print_r($controlsDbg, true));

    return [false, 'Задание не завершилось после попытки отклонения'];
};

$getRunningTasksForElement = function (int $elementId) use ($documentType, $debugLog): array {
    $tasks = [];

    if ($elementId <= 0) {
        return $tasks;
    }

    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $elementId];
    $states = CBPDocument::GetDocumentStates($documentType, $documentId);
    $debugLog("Найдены состояния БП для заявки {$elementId}: " . print_r($states, true));

    if (empty($states)) {
        return $tasks;
    }

    foreach ($states as $state) {
        $workflowId = (string)($state['ID'] ?? '');
        if ($workflowId === '') {
            continue;
        }

        $taskRes = CBPTaskService::GetList(
            ['ID' => 'ASC'],
            [
                'WORKFLOW_ID' => $workflowId,
                'STATUS' => CBPTaskStatus::Running,
            ],
            false,
            false,
            ['ID', 'NAME', 'WORKFLOW_ID', 'STATUS', 'USER_ID', 'USER_STATUS', 'ACTIVITY', 'ACTIVITY_NAME', 'PARAMETERS']
        );

        $foundTasksForWorkflow = 0;
        while ($task = $taskRes->Fetch()) {
            $foundTasksForWorkflow++;
            $taskId = (int)($task['ID'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $tasks[] = [
                'TASK' => $task,
                'STATE' => $state,
            ];
        }

        if ($foundTasksForWorkflow === 0) {
            $debugLog("По заявке {$elementId}, workflowId={$workflowId} не найдено задач по фильтру STATUS=Running");
        }
    }

    return $tasks;
};

$groupIds = $getLinkedGroupIds($currentElementId);
if (empty($groupIds)) {
    $this->WriteToTrackingService("group_auto_decline_ka: У текущей заявки #{$currentElementId} не заполнено поле {$propertyCodeGroup}");
    return;
}

foreach ($groupIds as $groupId) {
    $groupId = (int)$groupId;
    if ($groupId <= 0) {
        continue;
    }

    $groupName = $getElementTitle($groupId);
    $groupTitleForMessage = $groupName !== '' ? ($groupName . ' #' . $groupId) : ('#' . $groupId);

    $lockKey = 'AUTO_DECLINE_KA_GROUP:' . $groupId;
    if (!$acquireLock($lockKey, 10)) {
        $this->WriteToTrackingService("group_auto_decline_ka: Группа {$groupTitleForMessage} уже обрабатывается другим процессом, пропускаем");
        continue;
    }

    try {
        $requestIds = $getGroupRequestIds($groupId);
        if (empty($requestIds)) {
            $this->WriteToTrackingService("group_auto_decline_ka: По группе {$groupTitleForMessage} заявки не найдены");
            continue;
        }

        $this->WriteToTrackingService(
            "group_auto_decline_ka: Найдено заявок в группе {$groupTitleForMessage}: " . count($requestIds)
        );

        $currentRequestMarker = $makeGroupMarker($groupId, $currentElementId);
        if ($hasMarker($currentElementId, $currentRequestMarker)) {
            $debugLog(
                "Текущая заявка #{$currentElementId} уже обработана групповым автодействием "
                . "по marker={$currentRequestMarker}; каскадный запуск не размножаем"
            );
            continue;
        }

        // Первый проход — обрабатываем ВСЕ заявки с типом оплаты "Предоставление отгула":
        // переводим в "Отменена" и запускаем БП 1292 до завершения заданий БП по группе.
        foreach ($requestIds as $dayOffRequestId) {
            $dayOffRequestId = (int)$dayOffRequestId;
            if ($dayOffRequestId <= 0) {
                continue;
            }

            [$dayOffWorkflowStarted, $dayOffWorkflowInfo] = $startDayOffWorkflowIfNeeded($dayOffRequestId, $currentElementId, $groupId);
            if ($dayOffWorkflowStarted) {
                $this->WriteToTrackingService(
                    "group_auto_decline_ka: Заявка #{$dayOffRequestId} с типом оплаты «Предоставление отгула» переведена в статус «{$statusRejectedName}», запущен БП #{$dayOffWorkflowTemplateId}"
                );
            } else {
                $debugLog("v1.3.0: БП {$dayOffWorkflowTemplateId} по заявке #{$dayOffRequestId} не запускался: {$dayOffWorkflowInfo}");
            }
        }

        // Второй проход — автоотклонение активных заданий БП по заявкам группы.
        // Для заявок с активным заданием статус уже мог быть выставлен вручную для спец-сценариев,
        // но само задание БП обязательно пытаемся завершить отклонением.
        $requestIdsWithoutActiveTasks = [];

        foreach ($requestIds as $requestId) {
            $requestId = (int)$requestId;
            if ($requestId <= 0) {
                continue;
            }

            if ($requestId === $currentElementId) {
                $debugLog("Текущая заявка #{$requestId} уже завершена нажатием пользователя, повторно ее не автоотклоняем");
                continue;
            }

            [$statusElementId, $statusValue] = $getElementStatus($requestId);
            $debugLog("Заявка #{$requestId}: текущий статус '{$statusValue}', ID статуса '{$statusElementId}'. Проверяем активные задания БП/финализацию отклонения.");

            $marker = $makeGroupMarker($groupId, $requestId);
            if ($hasMarker($requestId, $marker)) {
                $debugLog("Заявка #{$requestId} уже имеет маркер {$marker}, повторная история не пишется");
                continue;
            }

            // Повторно собираем активные задачи уже внутри lock, чтобы не согласовать то, что успел завершить другой процесс.
            $taskItems = $getRunningTasksForElement($requestId);
            if (empty($taskItems)) {
                $requestIdsWithoutActiveTasks[] = $requestId;
                $debugLog("У заявки #{$requestId} нет активных заданий БП для автоотклонения; обработаем во втором проходе");
                continue;
            }

            $declinedAny = false;
            $lastError = '';

            foreach ($taskItems as $taskItem) {
                $task = (array)($taskItem['TASK'] ?? []);
                $state = (array)($taskItem['STATE'] ?? []);
                $taskId = (int)($task['ID'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }

                // Перед попыткой еще раз проверяем задачу, так как параллельные БП могли ее уже закрыть.
                if (!$isTaskStillRunning($taskId)) {
                    $debugLog("taskId={$taskId} по заявке #{$requestId} уже не Running, пропускаем");
                    continue;
                }

                $comment = 'Автоотклонено на этапе КА по групповой заявке ' . $groupTitleForMessage . '. Инициатор: заявка #' . $currentElementId;
                $taskAssignedUserId = (int)($task['USER_ID'] ?? 0);

                $executorCandidates = [];
                // Важно: автодействие должно выполняться от имени пользователя,
                // который нажал кнопку в головной заявке, а не от первого исполнителя найденного задания.
                if ($currentUserId > 0) {
                    $executorCandidates[] = $currentUserId;
                }
                if ($taskAssignedUserId > 0) {
                    $executorCandidates[] = $taskAssignedUserId;
                }
                $executorCandidates[] = $executorUserId;
                $executorCandidates = array_values(array_unique($executorCandidates));

                $ok = false;
                $err = 'Не удалось завершить задачу ни одним исполнителем.';

                foreach ($executorCandidates as $candidateUserId) {
                    $debugLog("Пробуем завершить taskId={$taskId} по заявке #{$requestId} от userId={$candidateUserId}");
                    [$ok, $err] = $doDeclineTask($task, (int)$candidateUserId, $comment, (string)($state['STATE_NAME'] ?? ''));
                    if ($ok) {
                        break;
                    }
                }

                if ($ok) {
                    $declinedAny = true;
                } else {
                    $lastError = $err;
                    $this->WriteToTrackingService(
                        "group_auto_decline_ka: Ошибка автоотклонения task {$taskId} по заявке #{$requestId}: {$err}"
                    );
                }
            }

            if ($declinedAny) {
                $requestDocumentId = ['lists', 'Bitrix\Lists\BizprocDocumentLists', $requestId];
                $historyMessage = "Заявка отклонена автоматически на этапе КА по групповой заявке {$groupTitleForMessage}. Отклонил: {$currentUserName}.";

                // Для заявок с активным заданием БП статус вручную не меняем: его должен выставить сам маршрут БП.
                // Здесь только фиксируем историю и marker, чтобы не было повторной обработки группы.
                $historyWasAdded = $appendHistoryOnce($requestId, $marker, $historyMessage);
                if ($historyWasAdded) {
                    CBPDocument::AddDocumentToHistory($requestDocumentId, $historyMessage, $currentUserId);
                    $this->WriteToTrackingService("group_auto_decline_ka: {$historyMessage}");
                } else {
                    $debugLog("История по заявке #{$requestId} уже была добавлена ранее, marker={$marker}");
                }
            } elseif ($lastError === '') {
                $debugLog("Заявка #{$requestId} не была отклонена: активные задания не завершены или уже закрыты");
            }
        }

        // Третий проход — заявки группы без активных заданий БП:
        // - "Предоставление отгула" уже переведены в "Отменена" и получили запуск БП 1292;
        // - остальные заявки отменяем только если они были в статусе "Выполнена".
        foreach ($requestIdsWithoutActiveTasks as $requestIdWithoutTask) {
            $requestIdWithoutTask = (int)$requestIdWithoutTask;
            if ($requestIdWithoutTask <= 0) {
                continue;
            }

            $marker = $makeGroupMarker($groupId, $requestIdWithoutTask);
            if ($hasMarker($requestIdWithoutTask, $marker)) {
                $debugLog("Заявка #{$requestIdWithoutTask} уже имеет маркер {$marker}, повторная финализация не выполняется");
                continue;
            }

            $isDayOffRequest = in_array($paymentTypeDayOffElementId, $getPaymentTypeIds($requestIdWithoutTask), true);
            [$requestStatusId, $requestStatusName] = $getElementStatus($requestIdWithoutTask);
            $isCompletedRequest = $requestStatusId === $statusCompletedElementId || $requestStatusName === $statusCompletedName;

            if (!$isDayOffRequest && !$isCompletedRequest) {
                $debugLog(
                    "Заявка #{$requestIdWithoutTask} без активных заданий БП не отменяется: "
                    . "тип оплаты не отгул, статус '{$requestStatusName}'/{$requestStatusId} не '{$statusCompletedName}'"
                );
                continue;
            }

            $historyMessage = "Заявка отменена автоматически на этапе КА по групповой заявке {$groupTitleForMessage}. Отклонил: {$currentUserName}.";
            if ($finalizeDeclinedRequest($requestIdWithoutTask, $marker, $historyMessage)) {
                $this->WriteToTrackingService("group_auto_decline_ka: {$historyMessage}");
            } else {
                $debugLog("Заявка #{$requestIdWithoutTask} без активных заданий БП не была финализирована или уже была обработана");
            }
        }
    } finally {
        $releaseLock($lockKey);
    }
}

})();

// Затем отклоняем все заявки, указанные в SVYAZANNYE_ZAYAVKI.
(function (): void {
/**
 * linked_decline_ka v1.0.0
 *
 * Код для активити БП "PHP код" (Bitrix24).
 *
 * Логика:
 * 1) Находит связанные заявки по свойству SVYAZANNYE_ZAYAVKI.
 * 2) Проверяет, что связанная заявка в статусе "В работе КА".
 * 3) Если есть текущее Running-задание БП по связанной заявке — выполняет его кнопкой "Отклонено".
 * 4) Пишет человекочитаемую историю в обе заявки.
 * 5) Для защиты от задвоения использует отдельное поле AUTO_DECLINE_MARKERS.
 *
 * Изменения v1.0.4:
 * - добавлен приоритетный способ отклонения через CBPTaskService::CompleteTask со статусом No;
 * - добавлен ранний DoTask с фактической кнопкой TaskButton2;
 * - PostTaskForm/SendExternalEvent оставлены как fallback для совместимости.
 *
 * v1.0.4:
 * - добавлена отправка отказа по фактическому HTML-полю задачи: nonapprove=Y;
 * - добавлены служебные поля формы task popup: action=doTask, id, TASK_ID, workflow_id;
 * - nonapprove=Y добавлен во все fallback-варианты PostTaskForm/DoTask.
 *
 * v1.0.4:
 * - для SendExternalEvent добавлены поля APPROVE=0 и approve=N;
 * - причина: стандартный activity approvecopyactiveschedule принимает отказ как отрицательный результат голосования, а не только как кнопку nonapprove.
 *
 * Изменения v1.1.1:
 * - служебный marker больше не выводится в ISTORIYA;
 * - marker хранится в отдельном множественном/строчном свойстве AUTO_DECLINE_MARKERS;
 * - текст истории приведен к формату:
 *   05.06.2026 10:47:03 Связанная заявка #3603166 отклонена автоматически на этапе КА. Отклонил: ФИО.
 * - добавлены appendHistoryLine() и addMarkerOnce();
 * - защита от повторного зеркального запуска сохраняется через marker пары заявок.
 *
 * Изменения v1.2.0:
 * - добавлена проверка связанной заявки по типу оплаты TIP_OPLATY;
 * - если TIP_OPLATY = 3537688 ("Предоставление отгула"), запускается БП шаблона 1292;
 * - добавлена защита от повторного запуска БП 1292 через marker в AUTO_DECLINE_MARKERS;
 * - запуск БП 1292 выполняется независимо от автоотклонения задания.
 *
 * Изменения v1.2.1:
 * - запуск БП 1292 дополнительно приведен к принципу group_auto_decline:
 *   DB-lock на пару заявок + marker в AUTO_DECLINE_MARKERS;
 * - marker запуска БП 1292 и workflowId не пишутся в пользовательскую историю ISTORIYA;
 * - в ISTORIYA остаются только человекочитаемые записи автоотклонения.
 */

$iblockId = 391;
$propertyCodeLinked = 'SVYAZANNYE_ZAYAVKI';
$propertyCodeStatus = 'STATUS';
$propertyCodeHistory = 'ISTORIYA';
$propertyCodeAutoDeclineMarkers = 'AUTO_DECLINE_MARKERS';

// v1.2.0: тип оплаты связанной заявки.
// PROPERTY_3087 / TIP_OPLATY — привязка к элементу справочника типов оплаты.
// 3537688 — "Предоставление отгула".
$propertyCodePaymentType = 'TIP_OPLATY';
$paymentTypeDayOffElementId = 3537688;

// v1.2.0: БП, который нужно запускать по связанной заявке с типом оплаты "Предоставление отгула".
$dayOffWorkflowTemplateId = 1292;
$dayOffWorkflowParameters = [];

$statusDeclineElementId = 3511771; // ID элемента статуса "В работе КА" в справочнике статусов.
$statusDeclineName = 'В работе КА'; // Фолбэк-проверка по названию статуса.

// Для связанной заявки с типом оплаты "Отгул" перед запуском БП переводим STATUS в "Отклонена".
$statusRejectedElementId = 3575323;
$statusRejectedName = 'Отклонена';

$debugEnabled = true; // При необходимости после проверки можно поставить false.
$executorUserId = 1; // Выполняем задания БП от имени администратора, если исполнитель задания не сработал.

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();

$currentElementId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentElementId = (int)str_replace('element_', '', (string)$currentElementId);

if ($currentElementId <= 0) {
    $this->WriteToTrackingService('linked_decline_ka: Не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc') || !CModule::IncludeModule('lists')) {
    $this->WriteToTrackingService('linked_decline_ka: Не удалось подключить модули iblock/bizproc/lists');
    return;
}

$currentUserId = 0;
if (class_exists('Bitrix\\Main\\Engine\\CurrentUser')) {
    $currentUserId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
}
if ($currentUserId <= 0 && isset($GLOBALS['USER']) && is_object($GLOBALS['USER'])) {
    $currentUserId = (int)$GLOBALS['USER']->GetID();
}
if ($currentUserId <= 0) {
    $currentUserId = 1;
}

$currentUserName = 'Пользователь #' . $currentUserId;
$userRs = CUser::GetByID($currentUserId);
if ($userData = $userRs->Fetch()) {
    $fio = trim((string)$userData['LAST_NAME'] . ' ' . (string)$userData['NAME'] . ' ' . (string)$userData['SECOND_NAME']);
    if ($fio !== '') {
        $currentUserName = $fio;
    }
}

$debugLog = function (string $message) use ($debugEnabled): void {
    if ($debugEnabled) {
        $this->WriteToTrackingService('linked_decline_ka [debug]: ' . $message);
    }
};

$linkedElementIds = [];
$rsProps = CIBlockElement::GetProperty($iblockId, $currentElementId, [], ['CODE' => $propertyCodeLinked]);
while ($prop = $rsProps->Fetch()) {
    if (!empty($prop['VALUE'])) {
        $linkedElementIds[] = (int)$prop['VALUE'];
    }
}
$linkedElementIds = array_values(array_unique(array_filter($linkedElementIds)));

if (empty($linkedElementIds)) {
    $this->WriteToTrackingService('linked_decline_ka: Связанные заявки не найдены');
    return;
}

$documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . $iblockId];

/**
 * Единый marker пары заявок. Порядок ID не важен.
 */
$buildPairMarker = static function (int $elementIdA, int $elementIdB): string {
    $ids = [$elementIdA, $elementIdB];
    sort($ids, SORT_NUMERIC);
    return 'AUTO_DECLINE_KA_LINKED_REQUEST:' . $ids[0] . ':' . $ids[1];
};

/**
 * MySQL named lock для защиты от одновременного запуска двух PHP-действий по паре заявок.
 */
$acquirePairLock = static function (string $marker) use ($debugLog): bool {
    if (!class_exists('Bitrix\\Main\\Application')) {
        return true;
    }

    try {
        $connection = \Bitrix\Main\Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $lockName = 'linked_decline_ka_' . md5($marker);
        $lockNameSql = $sqlHelper->forSql($lockName);
        $lockResult = (int)$connection->queryScalar("SELECT GET_LOCK('{$lockNameSql}', 10)");
        $debugLog("GET_LOCK {$lockName}: result={$lockResult}");

        return $lockResult === 1;
    } catch (\Throwable $e) {
        $debugLog('GET_LOCK недоступен: ' . $e->getMessage());
        // Не блокируем бизнес-логику, если конкретная БД/окружение не поддержало GET_LOCK.
        return true;
    }
};

$releasePairLock = static function (string $marker) use ($debugLog): void {
    if (!class_exists('Bitrix\\Main\\Application')) {
        return;
    }

    try {
        $connection = \Bitrix\Main\Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $lockName = 'linked_decline_ka_' . md5($marker);
        $lockNameSql = $sqlHelper->forSql($lockName);
        $releaseResult = (int)$connection->queryScalar("SELECT RELEASE_LOCK('{$lockNameSql}')");
        $debugLog("RELEASE_LOCK {$lockName}: result={$releaseResult}");
    } catch (\Throwable $e) {
        $debugLog('RELEASE_LOCK ошибка: ' . $e->getMessage());
    }
};

/**
 * Получить все значения свойства marker-ов.
 * Поддерживает и множественное, и одиночное строковое свойство.
 */
$getMarkers = static function (int $elementId) use ($iblockId, $propertyCodeAutoDeclineMarkers): array {
    $markers = [];

    if ($elementId <= 0) {
        return [];
    }

    $res = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeAutoDeclineMarkers]);
    while ($prop = $res->Fetch()) {
        $value = trim((string)($prop['VALUE'] ?? ''));
        if ($value !== '') {
            $markers[$value] = true;
        }
    }

    return array_keys($markers);
};

$hasMarker = static function (int $elementId, string $marker) use ($getMarkers): bool {
    if ($elementId <= 0 || $marker === '') {
        return false;
    }

    return in_array($marker, $getMarkers($elementId), true);
};

/**
 * Добавить marker в AUTO_DECLINE_MARKERS один раз.
 * Важно: если поле множественное, SetPropertyValuesEx принимает массив значений.
 * Если поле одиночное, будет сохранен массив как набор значений не всегда корректно — для надежности лучше сделать поле множественным.
 */
$addMarkerOnce = static function (int $elementId, string $marker) use ($iblockId, $propertyCodeAutoDeclineMarkers, $getMarkers): void {
    if ($elementId <= 0 || $marker === '') {
        return;
    }

    $markers = $getMarkers($elementId);
    if (in_array($marker, $markers, true)) {
        return;
    }

    $markers[] = $marker;
    $markers = array_values(array_unique(array_filter(array_map('trim', $markers))));

    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
        $propertyCodeAutoDeclineMarkers => $markers,
    ]);
};


/**
 * v1.2.0: Marker запуска БП 1292 по заявке с типом оплаты "Предоставление отгула".
 * Marker не зависит от пары заявок: БП должен быть запущен по конкретной связанной заявке только один раз.
 */
$buildDayOffWorkflowMarker = static function (int $elementId) use ($dayOffWorkflowTemplateId, $paymentTypeDayOffElementId): string {
    return 'AUTO_START_WORKFLOW:' . $dayOffWorkflowTemplateId . ':TIP_OPLATY:' . $paymentTypeDayOffElementId . ':REQUEST:' . $elementId;
};

/**
 * v1.2.0: Получить ID элементов из свойства TIP_OPLATY.
 * Поддерживает одиночное и множественное свойство-привязку.
 */
$getPaymentTypeIds = static function (int $elementId) use ($iblockId, $propertyCodePaymentType): array {
    $ids = [];

    if ($elementId <= 0) {
        return [];
    }

    $res = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodePaymentType]);
    while ($prop = $res->Fetch()) {
        $value = (int)($prop['VALUE'] ?? 0);
        if ($value > 0) {
            $ids[$value] = true;
        }
    }

    return array_keys($ids);
};

$setRequestStatus = static function (int $elementId, int $statusElementId) use ($iblockId, $propertyCodeStatus, $debugLog): bool {
    if ($elementId <= 0 || $statusElementId <= 0) {
        return false;
    }

    try {
        CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
            $propertyCodeStatus => $statusElementId,
        ]);
        $debugLog("Заявка #{$elementId} переведена в статус ID={$statusElementId}");
        return true;
    } catch (\Throwable $e) {
        $debugLog("Ошибка установки статуса заявки #{$elementId}: " . $e->getMessage());
        return false;
    }
};

/**
 * v1.2.0: Запустить БП 1292 по связанной заявке, если у нее TIP_OPLATY = 3537688.
 * Запуск делается один раз за счет marker-а в AUTO_DECLINE_MARKERS.
 */
$startDayOffWorkflowIfNeeded = static function (int $linkedElementId, int $currentElementId) use (
    $iblockId,
    $paymentTypeDayOffElementId,
    $dayOffWorkflowTemplateId,
    $dayOffWorkflowParameters,
    $statusRejectedElementId,
    $statusRejectedName,
    $getPaymentTypeIds,
    $setRequestStatus,
    $buildDayOffWorkflowMarker,
    $hasMarker,
    $addMarkerOnce,
    $debugLog
): array {
    if ($linkedElementId <= 0) {
        return [false, 'Некорректный ID связанной заявки'];
    }

    $paymentTypeIds = $getPaymentTypeIds($linkedElementId);
    $debugLog(
        "v1.2.0: TIP_OPLATY linkedElementId={$linkedElementId}: "
        . print_r($paymentTypeIds, true)
    );

    if (!in_array($paymentTypeDayOffElementId, $paymentTypeIds, true)) {
        return [false, 'Тип оплаты не "Предоставление отгула"'];
    }

    $workflowMarker = $buildDayOffWorkflowMarker($linkedElementId);
    if ($hasMarker($linkedElementId, $workflowMarker)) {
        return [false, "БП {$dayOffWorkflowTemplateId} уже запускался ранее, marker={$workflowMarker}"];
    }

    if (!$setRequestStatus($linkedElementId, $statusRejectedElementId)) {
        return [false, "Не удалось перевести связанную заявку в статус '{$statusRejectedName}'"];
    }

    // Marker ставим до запуска БП: повторные экземпляры не должны стартовать workflow второй раз.
    $addMarkerOnce($linkedElementId, $workflowMarker);

    if (!class_exists('CBPDocument')) {
        return [false, 'Класс CBPDocument недоступен'];
    }

    if (class_exists('Bitrix\\Main\\Loader')) {
        if (!\Bitrix\Main\Loader::includeModule('bizproc')) {
            return [false, 'Не удалось подключить модуль bizproc'];
        }
        \Bitrix\Main\Loader::includeModule('lists');
    }

    $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $linkedElementId];
    $workflowErrors = [];

    try {
        $workflowId = CBPDocument::StartWorkflow(
            $dayOffWorkflowTemplateId,
            $documentId,
            $dayOffWorkflowParameters,
            $workflowErrors
        );

        if (!empty($workflowErrors)) {
            return [false, 'Ошибки запуска БП: ' . print_r($workflowErrors, true)];
        }

        if ((string)$workflowId === '') {
            return [false, 'CBPDocument::StartWorkflow вернул пустой workflowId'];
        }

        $debugLog(
            "v1.2.0: связанная заявка {$linkedElementId} переведена в статус '{$statusRejectedName}', "
            . "БП {$dayOffWorkflowTemplateId} запущен, workflowId={$workflowId}, "
            . "основание — связанная заявка {$currentElementId}"
        );

        return [true, (string)$workflowId];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
};


/**
 * Запись в пользовательскую историю без служебных marker-ов.
 * Формат: 05.06.2026 10:47:03 Текст сообщения
 */
$appendHistoryLine = static function (int $elementId, string $message) use ($iblockId, $propertyCodeHistory): void {
    if ($elementId <= 0 || trim($message) === '') {
        return;
    }

    $line = date('d.m.Y H:i:s') . ' ' . trim($message);

    $existing = '';
    $propRes = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeHistory]);
    if ($prop = $propRes->Fetch()) {
        $existing = trim((string)($prop['VALUE'] ?? ''));
    }

    $newValue = $existing !== '' ? ($existing . "\n" . $line) : $line;
    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeHistory => $newValue]);
};

$isTaskStillRunning = static function (int $taskId): bool {
    if ($taskId <= 0 || !class_exists('CBPTaskService')) {
        return false;
    }

    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    if (!is_object($res)) {
        return false;
    }

    $task = $res->Fetch();
    if (!$task) {
        return false;
    }

    return (int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running;
};

$resolveDeclineActionCode = static function (int $taskId): string {
    $controls = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        $controls = (array)CBPDocument::GetTaskControls($taskId);
    }
    if (empty($controls) && method_exists('CBPTaskService', 'GetTaskControls')) {
        $controls = (array)CBPTaskService::GetTaskControls($taskId);
    }

    foreach ($controls as $control) {
        $id = (string)($control['CONTROL_ID'] ?? $control['ID'] ?? '');
        $label = mb_strtolower(trim((string)($control['NAME'] ?? $control['TEXT'] ?? $control['LABEL'] ?? '')));
        $idNorm = mb_strtolower($id);

        if (
            strpos($label, 'отклон') !== false
            || strpos($label, 'отказ') !== false
            || strpos($idNorm, 'decline') !== false
            || strpos($idNorm, 'reject') !== false
            || $idNorm === 'no'
            || $idNorm === 'n'
        ) {
            return $id !== '' ? $id : 'Decline';
        }
    }

    return 'Decline';
};

$runAsUser = static function (int $userId, callable $callback) use ($debugLog) {
    global $USER;
    $canAuth = is_object($USER) && method_exists($USER, 'Authorize') && method_exists($USER, 'GetID');
    $prevUserId = $canAuth ? (int)$USER->GetID() : 0;

    if ($canAuth && $userId > 0 && $prevUserId !== $userId) {
        $USER->Authorize($userId);
        $debugLog("runAsUser: переключили контекст пользователя с {$prevUserId} на {$userId}");
    }

    try {
        return $callback();
    } finally {
        if ($canAuth && $prevUserId > 0 && (int)$USER->GetID() !== $prevUserId) {
            $USER->Authorize($prevUserId);
            $debugLog("runAsUser: восстановили контекст пользователя {$prevUserId}");
        }
    }
};

$collectTaskDiagnostics = static function (int $taskId, int $userId, string $stage = '') use ($debugLog): void {
    if (!class_exists('CBPTaskService')) {
        return;
    }

    $taskResDbg = CBPTaskService::GetList(
        ['ID' => 'DESC'],
        ['ID' => $taskId],
        false,
        false,
        ['ID', 'STATUS', 'USER_ID', 'USER_STATUS', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME']
    );
    if (is_object($taskResDbg)) {
        $taskDbg = $taskResDbg->Fetch();
        $debugLog("diag[{$stage}] task snapshot: " . print_r($taskDbg, true));
    }

    $controlsAttempts = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        try {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId)'] = CBPDocument::GetTaskControls($taskId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId) error'] = $e->getMessage();
        }
        try {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId,userId)'] = CBPDocument::GetTaskControls($taskId, $userId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPDocument::GetTaskControls(taskId,userId) error'] = $e->getMessage();
        }
    }
    if (method_exists('CBPTaskService', 'GetTaskControls')) {
        try {
            $controlsAttempts['CBPTaskService::GetTaskControls(taskId)'] = CBPTaskService::GetTaskControls($taskId);
        } catch (\Throwable $e) {
            $controlsAttempts['CBPTaskService::GetTaskControls(taskId) error'] = $e->getMessage();
        }
    }

    $debugLog("diag[{$stage}] controls attempts: " . print_r($controlsAttempts, true));
};

$doDeclineTask = static function (array $task, int $userId, string $comment = '', string $fallbackActivityName = '') use ($resolveDeclineActionCode, $isTaskStillRunning, $debugLog, $collectTaskDiagnostics, $runAsUser): array {
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [false, 'Некорректный taskId'];
    }

    $actionCode = $resolveDeclineActionCode($taskId);
    if ((string)($task['ACTIVITY_NAME'] ?? '') === '' && class_exists('CBPTaskService')) {
        $taskReloadRes = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            ['ID' => $taskId],
            false,
            false,
            ['ID', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME', 'PARAMETERS']
        );
        if (is_object($taskReloadRes)) {
            $taskReload = $taskReloadRes->Fetch();
            if (is_array($taskReload)) {
                $task = array_merge($task, $taskReload);
                $debugLog('Перезагрузили task с расширенными полями: ' . print_r($taskReload, true));
            }
        }
    }

    $debugLog("Начало doDeclineTask: taskId={$taskId}, userId={$userId}, actionCode={$actionCode}");
    $collectTaskDiagnostics($taskId, $userId, 'start');
    $debugLog('Task raw: ' . print_r($task, true));

    try {
        // v1.0.4: по HTML формы кнопка отказа называется nonapprove, а не Decline/Reject.
        // Поэтому первой попыткой отправляем payload, максимально похожий на реальную форму popup.php:
        // action=doTask, id, TASK_ID, workflow_id, nonapprove=Y.
        $workflowIdForForm = (string)($task['WORKFLOW_ID'] ?? '');
        $browserRejectRequest = [
            'action' => 'doTask',
            'id' => $taskId,
            'TASK_ID' => $taskId,
            'workflow_id' => $workflowIdForForm,
            'nonapprove' => 'Y',
            'comment' => $comment,
            'task_comment' => $comment,
            'COMMENT' => $comment,
            'USER_ID' => $userId,
            'REAL_USER_ID' => $userId,
        ];
        if (function_exists('bitrix_sessid')) {
            $browserRejectRequest['sessid'] = bitrix_sessid();
        }

        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $errors = [];
            $debugLog("v1.0.4: Вызов CBPDocument::PostTaskForm как HTML popup form для taskId={$taskId}, поля: " . print_r($browserRejectRequest, true));
            $postResult = $runAsUser($userId, static function () use ($taskId, $userId, $browserRejectRequest, &$errors) {
                return CBPDocument::PostTaskForm($taskId, $userId, $browserRejectRequest, $errors, '', $userId);
            });
            $debugLog("v1.0.4: browser-like PostTaskForm result для taskId={$taskId}: " . print_r($postResult, true));
            if (!empty($errors)) {
                $debugLog("v1.0.4: browser-like PostTaskForm errors для taskId={$taskId}: " . print_r($errors, true));
            }
            if (empty($errors) && !$isTaskStillRunning($taskId)) {
                $debugLog("v1.0.4: browser-like PostTaskForm успешно завершил taskId={$taskId}");
                return [true, ''];
            }
            $collectTaskDiagnostics($taskId, $userId, 'after_browser_like_posttaskform');
        }

        // v1.0.4: для задания "Утверждение документа" надежнее сначала завершать task
        // через статус пользователя "Нет/Отклонено". По логу у задания есть TaskButton2Message => Отклонить,
        // а GetTaskControls/PostTaskForm могут возвращать ActivityNotFound, если PHP-действие запущено уже
        // после прерывания текущего статуса БП.
        if (class_exists('CBPTaskService') && method_exists('CBPTaskService', 'CompleteTask')) {
            $declineStatuses = [];
            if (class_exists('CBPTaskUserStatus') && defined('CBPTaskUserStatus::No')) {
                $declineStatuses[] = CBPTaskUserStatus::No;
            }
            $declineStatuses[] = 2; // стандартное значение CBPTaskUserStatus::No в коробочном Битрикс.
            $declineStatuses = array_values(array_unique(array_map('intval', $declineStatuses)));

            foreach ($declineStatuses as $declineStatus) {
                $completePayloads = [
                    ['COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment, 'nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'TaskButton2' => 'Y', 'ACTION' => 'TaskButton2'],
                    ['COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ];

                foreach ($completePayloads as $payloadIdx => $completePayload) {
                    $completeAttempts = [
                        [$taskId, $userId, $declineStatus, $completePayload],
                        [$taskId, $userId, $declineStatus, [], $completePayload],
                        [$taskId, $userId, $declineStatus],
                    ];

                    foreach ($completeAttempts as $attemptIdx => $completeArgs) {
                        try {
                            $debugLog(
                                "Вызов CBPTaskService::CompleteTask для taskId={$taskId}, userId={$userId}, "
                                . "declineStatus={$declineStatus}, payloadIdx={$payloadIdx}, attempt={$attemptIdx}, args="
                                . print_r($completeArgs, true)
                            );
                            $completeResult = $runAsUser($userId, static function () use ($completeArgs) {
                                return call_user_func_array(['CBPTaskService', 'CompleteTask'], $completeArgs);
                            });
                            $debugLog("CompleteTask result для taskId={$taskId}: " . print_r($completeResult, true));

                            if (!$isTaskStillRunning($taskId)) {
                                $debugLog("CompleteTask успешно завершил taskId={$taskId} со статусом отказа {$declineStatus}");
                                return [true, ''];
                            }
                        } catch (\Throwable $e) {
                            $debugLog("CompleteTask exception taskId={$taskId}, attempt={$attemptIdx}: " . $e->getMessage());
                        }
                    }
                }
            }
            $debugLog("После всех попыток CompleteTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_completetask');
        }

        // v1.0.4: если CompleteTask недоступен/не сработал, пробуем DoTask раньше PostTaskForm
        // и первым вариантом передаем фактическую кнопку отказа TaskButton2.
        if (class_exists('CBPTaskService') && method_exists('CBPTaskService', 'DoTask')) {
            $doTaskPayloadsFirst = [
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'TaskButton2' => 'Y', 'ACTION' => 'TaskButton2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'taskbutton2' => 'Y', 'ACTION' => 'taskbutton2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'BUTTON2' => 'Y', 'ACTION' => 'BUTTON2', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
                ['nonapprove' => 'Y', 'action' => 'doTask', 'id' => $taskId, 'TASK_ID' => $taskId, 'workflow_id' => $workflowIdForForm, 'DECLINE' => 'Y', 'REJECT' => 'Y', 'APPROVE' => 'N', 'approve' => 'N', 'status' => 'N', 'COMMENT' => $comment, 'comment' => $comment, 'task_comment' => $comment],
            ];
            foreach ($doTaskPayloadsFirst as $payloadIdx => $payload) {
                try {
                    $debugLog("Ранний вызов CBPTaskService::DoTask для taskId={$taskId}, payloadIdx={$payloadIdx}, payload=" . print_r($payload, true));
                    $doTaskResult = $runAsUser($userId, static function () use ($taskId, $userId, $payload) {
                        return CBPTaskService::DoTask($taskId, $userId, $payload);
                    });
                    $debugLog("Ранний DoTask result для taskId={$taskId}, payloadIdx={$payloadIdx}: " . print_r($doTaskResult, true));
                    if (!$isTaskStillRunning($taskId)) {
                        $debugLog("Ранний DoTask успешно завершил taskId={$taskId}, payloadIdx={$payloadIdx}");
                        return [true, ''];
                    }
                } catch (\Throwable $e) {
                    $debugLog("Ранний DoTask exception taskId={$taskId}, payloadIdx={$payloadIdx}: " . $e->getMessage());
                }
            }
            $debugLog("После ранних попыток DoTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_early_dotask');
        }

        $codesToTry = array_values(array_unique(array_filter([
            'nonapprove',
            'NONAPPROVE',
            $actionCode,
            'decline',
            'Decline',
            'reject',
            'Reject',
            'no',
            'NO',
            'N',
            'TaskButton2',
            'taskbutton2',
            'BUTTON2',
        ])));

        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $requests = [
                [
                    'nonapprove' => 'Y',
                    'action' => 'doTask',
                    'id' => $taskId,
                    'TASK_ID' => $taskId,
                    'workflow_id' => $workflowIdForForm,
                    'decline' => 'Y',
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'ACTION' => 'decline',
                    'status' => 'N',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $userId,
                    'REAL_USER_ID' => $userId,
                ],
            ];
            foreach ($codesToTry as $codeTry) {
                $requests[] = [
                    'nonapprove' => 'Y',
                    'action' => 'doTask',
                    'id' => $taskId,
                    'TASK_ID' => $taskId,
                    'workflow_id' => $workflowIdForForm,
                    'decline' => $codeTry,
                    $codeTry => 'Y',
                    'ACTION' => $codeTry,
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'APPROVE' => 'N',
                    'approve' => 'N',
                    'status' => 'N',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $userId,
                    'REAL_USER_ID' => $userId,
                ];
            }

            foreach ($requests as $idx => $requestFields) {
                $errors = [];
                $debugLog("Вызов CBPDocument::PostTaskForm для taskId={$taskId}, attempt={$idx}, поля: " . print_r($requestFields, true));
                $postResult = $runAsUser($userId, static function () use ($taskId, $userId, $requestFields, &$errors) {
                    return CBPDocument::PostTaskForm($taskId, $userId, $requestFields, $errors, '', $userId);
                });
                $debugLog("PostTaskForm result для taskId={$taskId}, attempt={$idx}: " . print_r($postResult, true));
                if (!empty($errors)) {
                    $debugLog("PostTaskForm errors для taskId={$taskId}, attempt={$idx}: " . print_r($errors, true));
                }
                if (empty($errors) && !$isTaskStillRunning($taskId)) {
                    $debugLog("PostTaskForm успешно завершил taskId={$taskId} на attempt={$idx}");
                    return [true, ''];
                }
            }
            $debugLog("После всех попыток PostTaskForm taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_posttaskform');
        }

        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activityCandidates = array_values(array_unique(array_filter([
            (string)($task['ACTIVITY_NAME'] ?? ''),
            (string)($task['ACTIVITY'] ?? ''),
            $fallbackActivityName,
        ])));
        if ($workflowId !== '' && !empty($activityCandidates) && class_exists('CBPRuntime') && method_exists('CBPRuntime', 'SendExternalEvent')) {
            $payload = [
                'USER_ID' => $userId,
                'REAL_USER_ID' => $userId,
                'COMMENT' => $comment,
                'comment' => $comment,
                // В approve-activity отказ должен передаваться как отрицательный результат голосования.
                // Для согласования рабочий payload: APPROVE=1, approve=Y, status=Y.
                // Для отклонения зеркальный payload: APPROVE=0, approve=N, status=N.
                'APPROVE' => 0,
                'approve' => 'N',
                // Для стандартного UI Битрикс кнопка отклонения у этого задания отправляет именно nonapprove=Y.
                // В SendExternalEvent это критично: decline/reject/status=N сами по себе не завершают approvecopyactiveschedule.
                'nonapprove' => 'Y',
                'NONAPPROVE' => 'Y',
                'DECLINE' => 1,
                'REJECT' => 1,
                'decline' => 'Y',
                'reject' => 'Y',
                'status' => 'N',
            ];
            foreach ($activityCandidates as $activityCandidate) {
                $debugLog("Вызов CBPRuntime::SendExternalEvent workflowId={$workflowId}, activity={$activityCandidate}, payload=" . print_r($payload, true));
                $eventResult = $runAsUser($userId, static function () use ($workflowId, $activityCandidate, $payload) {
                    return CBPRuntime::SendExternalEvent($workflowId, $activityCandidate, $payload);
                });
                $debugLog("SendExternalEvent result taskId={$taskId}, activity={$activityCandidate}: " . print_r($eventResult, true));
                if (!$isTaskStillRunning($taskId)) {
                    $debugLog("SendExternalEvent успешно завершил taskId={$taskId}, activity={$activityCandidate}");
                    return [true, ''];
                }
                $debugLog("После SendExternalEvent taskId={$taskId} всё ещё running, activity={$activityCandidate}");
                $collectTaskDiagnostics($taskId, $userId, 'after_external_event_' . $activityCandidate);
            }
        } else {
            $debugLog("SendExternalEvent пропущен: workflowId='{$workflowId}', activityCandidates=" . print_r($activityCandidates, true));
        }

        if (method_exists('CBPTaskService', 'DoTask')) {
            foreach ($codesToTry as $codeTry) {
                $payload = [
                    'ACTION' => $codeTry,
                    $codeTry => 'Y',
                    'DECLINE' => 'Y',
                    'REJECT' => 'Y',
                    'APPROVE' => 'N',
                    'approve' => 'N',
                    'status' => 'N',
                    'COMMENT' => $comment,
                    'comment' => $comment,
                    'task_comment' => $comment,
                ];
                $debugLog("Вызов CBPTaskService::DoTask для taskId={$taskId}, codeTry={$codeTry}, payload: " . print_r($payload, true));
                $doTaskResult = $runAsUser($userId, static function () use ($taskId, $userId, $payload) {
                    return CBPTaskService::DoTask($taskId, $userId, $payload);
                });
                $debugLog("DoTask result для taskId={$taskId}, codeTry={$codeTry}: " . print_r($doTaskResult, true));
                if (!$isTaskStillRunning($taskId)) {
                    $debugLog("DoTask успешно завершил taskId={$taskId} с codeTry={$codeTry}");
                    return [true, ''];
                }
            }
            $debugLog("После всех попыток DoTask taskId={$taskId} всё ещё running");
            $collectTaskDiagnostics($taskId, $userId, 'after_dotask');
        }
    } catch (\Throwable $e) {
        $debugLog("Throwable в doDeclineTask для taskId={$taskId}: " . $e->getMessage());
        return [false, $e->getMessage()];
    }

    $controlsDbg = [];
    if (method_exists('CBPDocument', 'GetTaskControls')) {
        $controlsDbg = (array)CBPDocument::GetTaskControls($taskId);
    }
    if (empty($controlsDbg) && method_exists('CBPTaskService', 'GetTaskControls')) {
        $controlsDbg = (array)CBPTaskService::GetTaskControls($taskId);
    }
    $debugLog("Итог: taskId={$taskId} не завершился. Controls: " . print_r($controlsDbg, true));

    return [false, 'Задание не завершилось после попытки отклонения'];
};

foreach ($linkedElementIds as $linkedElementId) {
    if ($linkedElementId <= 0 || $linkedElementId === $currentElementId) {
        continue;
    }

    $pairMarker = $buildPairMarker($currentElementId, $linkedElementId);

    if (!$acquirePairLock($pairMarker)) {
        $this->WriteToTrackingService("linked_decline_ka: пара {$currentElementId}/{$linkedElementId} уже обрабатывается другим процессом");
        continue;
    }

    try {
        // v1.2.0: сначала проверяем специальный сценарий "Предоставление отгула".
        // Он не зависит от автоотклонения задания: если связанная заявка подходит по TIP_OPLATY,
        // по ней должен стартовать отдельный БП 1292.
        [$dayOffWorkflowStarted, $dayOffWorkflowInfo] = $startDayOffWorkflowIfNeeded($linkedElementId, $currentElementId);
        if ($dayOffWorkflowStarted) {
            // v1.2.1: marker запуска БП 1292 хранится только в AUTO_DECLINE_MARKERS.
            // WorkflowId не пишем в ISTORIYA, чтобы не засорять пользовательскую историю техническими данными.
            $this->WriteToTrackingService(
                "linked_decline_ka: По связанной заявке #{$linkedElementId} с типом оплаты «Предоставление отгула» запущен БП #{$dayOffWorkflowTemplateId}"
            );
        } else {
            $debugLog("v1.2.1: БП {$dayOffWorkflowTemplateId} по связанной заявке {$linkedElementId} не запускался: {$dayOffWorkflowInfo}");
        }

        // Повторная проверка под lock: если любая из заявок уже содержит marker пары, повторно ничего не делаем.
        if ($hasMarker($currentElementId, $pairMarker) || $hasMarker($linkedElementId, $pairMarker)) {
            $this->WriteToTrackingService("linked_decline_ka: пара {$currentElementId}/{$linkedElementId} уже была обработана ранее, marker={$pairMarker}");
            continue;
        }

        $statusValue = '';
        $statusElementId = 0;
        $statusPropRes = CIBlockElement::GetProperty($iblockId, $linkedElementId, [], ['CODE' => $propertyCodeStatus]);
        if ($statusProp = $statusPropRes->Fetch()) {
            $statusValue = trim((string)($statusProp['VALUE_ENUM'] ?: $statusProp['VALUE']));
            $statusElementId = (int)($statusProp['VALUE'] ?? 0);
        }

        $isExpectedStatus = false;
        if ($statusDeclineElementId > 0 && $statusElementId > 0 && $statusElementId === $statusDeclineElementId) {
            $isExpectedStatus = true;
        }
        if (!$isExpectedStatus && $statusValue !== '' && $statusValue === $statusDeclineName) {
            $isExpectedStatus = true;
        }

        if (!$isExpectedStatus) {
            $this->WriteToTrackingService(
                "linked_decline_ka: Заявка {$linkedElementId} пропущена, статус '{$statusValue}', ID статуса '{$statusElementId}' "
                . "(ожидался '{$statusDeclineName}', ID '{$statusDeclineElementId}')"
            );
            continue;
        }

        $linkedDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $linkedElementId];
        $states = CBPDocument::GetDocumentStates($documentType, $linkedDocumentId);
        $debugLog("Найдены состояния БП для {$linkedElementId}: " . print_r($states, true));

        if (empty($states)) {
            $this->WriteToTrackingService("linked_decline_ka: У связанной заявки {$linkedElementId} нет активных БП");
            continue;
        }

        $declinedAny = false;

        foreach ($states as $state) {
            $workflowId = (string)($state['ID'] ?? '');
            if ($workflowId === '') {
                continue;
            }

            $taskRes = CBPTaskService::GetList(
                ['ID' => 'ASC'],
                [
                    'WORKFLOW_ID' => $workflowId,
                    'STATUS' => CBPTaskStatus::Running,
                ],
                false,
                false,
                ['ID', 'NAME', 'WORKFLOW_ID', 'STATUS', 'USER_ID', 'USER_STATUS', 'ACTIVITY', 'ACTIVITY_NAME', 'PARAMETERS']
            );

            $foundTasksForWorkflow = 0;
            while ($task = $taskRes->Fetch()) {
                // Еще одна защита внутри цикла: если marker появился после завершения другого task, не пишем повторно.
                if ($hasMarker($currentElementId, $pairMarker) || $hasMarker($linkedElementId, $pairMarker)) {
                    $this->WriteToTrackingService("linked_decline_ka: marker появился во время обработки, повтор пропущен: {$pairMarker}");
                    break;
                }

                $foundTasksForWorkflow++;
                $taskId = (int)($task['ID'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }

                $debugLog("Найдена задача для linkedElementId={$linkedElementId}, workflowId={$workflowId}: " . print_r($task, true));

                $comment = 'Автоотклонено на этапе КА по связанной заявке #' . $currentElementId;
                $taskAssignedUserId = (int)($task['USER_ID'] ?? 0);
                $executorCandidates = [];
                // Важно: связанную заявку сначала завершаем от имени пользователя,
                // который нажал кнопку в основной заявке, а не от первого исполнителя найденного задания.
                if ($currentUserId > 0) {
                    $executorCandidates[] = $currentUserId;
                }
                if ($taskAssignedUserId > 0) {
                    $executorCandidates[] = $taskAssignedUserId;
                }
                $executorCandidates[] = $executorUserId;
                $executorCandidates = array_values(array_unique($executorCandidates));

                $ok = false;
                $err = 'Не удалось завершить задачу ни одним исполнителем.';
                $successUserId = 0;
                foreach ($executorCandidates as $candidateUserId) {
                    $debugLog("Пробуем завершить taskId={$taskId} от userId={$candidateUserId}");
                    [$ok, $err] = $doDeclineTask($task, $candidateUserId, $comment, (string)($state['STATE_NAME'] ?? ''));
                    if ($ok) {
                        $successUserId = $candidateUserId;
                        break;
                    }
                }

                if ($ok) {
                    // После успешного завершения еще раз проверяем marker перед записью истории.
                    if ($hasMarker($currentElementId, $pairMarker) || $hasMarker($linkedElementId, $pairMarker)) {
                        $this->WriteToTrackingService("linked_decline_ka: task {$taskId} завершен, но история уже была записана ранее, marker={$pairMarker}");
                        $declinedAny = true;
                        break;
                    }

                    $declinedAny = true;

                    // Сначала фиксируем marker в обеих заявках, затем пишем историю.
                    // Даже если зеркальный БП стартует сразу после Decline, он увидит marker и не продублирует запись.
                    $addMarkerOnce($currentElementId, $pairMarker);
                    $addMarkerOnce($linkedElementId, $pairMarker);

                    $msgLinked = "Заявка отклонена автоматически на этапе КА по связанной заявке #{$currentElementId}. Отклонил: {$currentUserName}.";
                    CBPDocument::AddDocumentToHistory($linkedDocumentId, $msgLinked, $currentUserId);
                    $appendHistoryLine($linkedElementId, $msgLinked);
                    $this->WriteToTrackingService("linked_decline_ka: {$msgLinked}");

                    $mainDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $currentElementId];
                    $msgMain = "Связанная заявка #{$linkedElementId} отклонена автоматически на этапе КА. Отклонил: {$currentUserName}.";
                    CBPDocument::AddDocumentToHistory($mainDocumentId, $msgMain, $currentUserId);
                    $appendHistoryLine($currentElementId, $msgMain);
                    $this->WriteToTrackingService("linked_decline_ka: {$msgMain}");

                    break;
                }

                $this->WriteToTrackingService(
                    "linked_decline_ka: Ошибка автоотклонения task {$taskId} по заявке {$linkedElementId}: {$err}"
                );
            }

            if ($foundTasksForWorkflow === 0) {
                $debugLog("По workflowId={$workflowId} не найдено задач по фильтру STATUS=Running (без USER_STATUS)");
            }

            if ($declinedAny) {
                break;
            }
        }

        if (!$declinedAny) {
            $this->WriteToTrackingService(
                "linked_decline_ka: У связанной заявки {$linkedElementId} не найдено ожидающих заданий БП для автоотклонения"
            );
        }
    } finally {
        $releasePairLock($pairMarker);
    }
}

})();
