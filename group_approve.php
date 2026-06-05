<?php
/**
 * group_auto_approve_v1_0_0.php
 *
 * Скрипт для активити БП "PHP код" (Bitrix24).
 *
 * Версия: 1.0.0
 * Дата: 05.06.2026
 *
 * Назначение:
 * Автоматически согласовывает все заявки одной групповой заявки.
 *
 * Логика:
 * 1) Берет у текущей заявки значение свойства GROUP_LINK.
 * 2) Находит все заявки инфоблока 391, привязанные к этой же группе.
 * 3) Оставляет только заявки в статусе "В работе C&B".
 * 4) Если по заявке есть активное задание БП, выполняет его с положительным результатом Approve.
 * 5) Пишет запись в историю заявки.
 * 6) От дублей защищается через:
 *    - DB-lock на группу;
 *    - служебное поле AUTO_APPROVE_MARKERS.
 *
 * Важно:
 * - Технический маркер НЕ пишется в пользовательскую историю ISTORIYA.
 * - Маркер хранится в AUTO_APPROVE_MARKERS.
 */

$iblockId = 391;

$propertyCodeGroup = 'GROUP_LINK';
$propertyCodeStatus = 'STATUS';
$propertyCodeHistory = 'ISTORIYA';
$propertyCodeMarkers = 'AUTO_APPROVE_MARKERS';

$statusApproveElementId = 3578386; // ID элемента статуса "В работе C&B".
$statusApproveName = 'В работе C&B'; // Фолбэк-проверка по названию статуса.

$executorUserId = 1; // Резервный пользователь для выполнения задания БП.
$debugEnabled = true; // После проверки на бою можно поставить false.

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();

$currentElementId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentElementId = (int)str_replace('element_', '', (string)$currentElementId);

if ($currentElementId <= 0) {
    $this->WriteToTrackingService('group_auto_approve: Не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc')) {
    $this->WriteToTrackingService('group_auto_approve: Не удалось подключить модули iblock/bizproc');
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
        $this->WriteToTrackingService('group_auto_approve [debug]: ' . $message);
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

$isExpectedStatus = static function (int $elementId) use ($getElementStatus, $statusApproveElementId, $statusApproveName): bool {
    [$statusElementId, $statusValue] = $getElementStatus($elementId);

    if ($statusApproveElementId > 0 && $statusElementId > 0 && $statusElementId === $statusApproveElementId) {
        return true;
    }

    return $statusValue !== '' && $statusValue === $statusApproveName;
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

$appendHistoryOnce = static function (int $elementId, string $marker, string $message) use ($hasMarker, $appendMarker, $appendHistory): bool {
    if ($elementId <= 0 || $marker === '' || $message === '') {
        return false;
    }

    if ($hasMarker($elementId, $marker)) {
        return false;
    }

    $appendHistory($elementId, $message);
    $appendMarker($elementId, $marker);

    return true;
};

$makeGroupMarker = static function (int $groupId, int $elementId): string {
    return 'AUTO_APPROVE_GROUP_REQUEST:' . $groupId . ':' . $elementId;
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

$resolveApproveActionCode = static function (int $taskId): string {
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
            strpos($label, 'соглас') !== false
            || strpos($label, 'утверж') !== false
            || strpos($idNorm, 'approve') !== false
            || $idNorm === 'yes'
            || $idNorm === 'y'
        ) {
            return $id !== '' ? $id : 'Approve';
        }
    }

    return 'Approve';
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

$doApproveTask = function (array $task, int $userId, string $comment = '', string $fallbackActivityName = '') use ($resolveApproveActionCode, $isTaskStillRunning, $debugLog, $collectTaskDiagnostics, $runAsUser): array {
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [false, 'Некорректный taskId'];
    }

    $actionCode = $resolveApproveActionCode($taskId);

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

    $debugLog("Начало doApproveTask: taskId={$taskId}, userId={$userId}, actionCode={$actionCode}");
    $collectTaskDiagnostics($taskId, $userId, 'start');
    $debugLog('Task raw: ' . print_r($task, true));

    try {
        $codesToTry = array_values(array_unique(array_filter([
            $actionCode,
            'approve',
            'Approve',
            'yes',
            'YES',
            'Y',
            'TaskButton1',
            'taskbutton1',
            'BUTTON1',
            'complete',
            'Complete',
        ])));

        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $requests = [
                [
                    'approve' => 'Y',
                    'APPROVE' => 'Y',
                    'ACTION' => 'approve',
                    'status' => 'Y',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $userId,
                    'REAL_USER_ID' => $userId,
                ],
            ];

            foreach ($codesToTry as $codeTry) {
                $requests[] = [
                    'approve' => $codeTry,
                    $codeTry => 'Y',
                    'ACTION' => $codeTry,
                    'APPROVE' => 'Y',
                    'status' => 'Y',
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
                'APPROVE' => 1,
                'approve' => 'Y',
                'status' => 'Y',
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
                    'APPROVE' => 'Y',
                    'status' => 'Y',
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
        $debugLog("Throwable в doApproveTask для taskId={$taskId}: " . $e->getMessage());
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

    return [false, 'Задание не завершилось после попытки согласования'];
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
    $this->WriteToTrackingService("group_auto_approve: У текущей заявки #{$currentElementId} не заполнено поле {$propertyCodeGroup}");
    return;
}

foreach ($groupIds as $groupId) {
    $groupId = (int)$groupId;
    if ($groupId <= 0) {
        continue;
    }

    $groupName = $getElementTitle($groupId);
    $groupTitleForMessage = $groupName !== '' ? ($groupName . ' #' . $groupId) : ('#' . $groupId);

    $lockKey = 'AUTO_APPROVE_GROUP:' . $groupId;
    if (!$acquireLock($lockKey, 10)) {
        $this->WriteToTrackingService("group_auto_approve: Группа {$groupTitleForMessage} уже обрабатывается другим процессом, пропускаем");
        continue;
    }

    try {
        $requestIds = $getGroupRequestIds($groupId);
        if (empty($requestIds)) {
            $this->WriteToTrackingService("group_auto_approve: По группе {$groupTitleForMessage} заявки не найдены");
            continue;
        }

        $this->WriteToTrackingService(
            "group_auto_approve: Найдено заявок в группе {$groupTitleForMessage}: " . count($requestIds)
        );

        foreach ($requestIds as $requestId) {
            $requestId = (int)$requestId;
            if ($requestId <= 0) {
                continue;
            }

            [$statusElementId, $statusValue] = $getElementStatus($requestId);
            if (!$isExpectedStatus($requestId)) {
                $debugLog(
                    "Заявка #{$requestId} пропущена, статус '{$statusValue}', ID статуса '{$statusElementId}' "
                    . "(ожидался '{$statusApproveName}', ID '{$statusApproveElementId}')"
                );
                continue;
            }

            $marker = $makeGroupMarker($groupId, $requestId);
            if ($hasMarker($requestId, $marker)) {
                $debugLog("Заявка #{$requestId} уже имеет маркер {$marker}, повторная история не пишется");
                continue;
            }

            // Повторно собираем активные задачи уже внутри lock, чтобы не согласовать то, что успел завершить другой процесс.
            $taskItems = $getRunningTasksForElement($requestId);
            if (empty($taskItems)) {
                $debugLog("У заявки #{$requestId} нет активных заданий БП для автосогласования");
                continue;
            }

            $approvedAny = false;
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

                $comment = 'Автосогласовано по групповой заявке ' . $groupTitleForMessage . '. Инициатор: заявка #' . $currentElementId;
                $taskAssignedUserId = (int)($task['USER_ID'] ?? 0);

                $executorCandidates = [];
                if ($taskAssignedUserId > 0) {
                    $executorCandidates[] = $taskAssignedUserId;
                }
                $executorCandidates[] = $executorUserId;
                $executorCandidates = array_values(array_unique($executorCandidates));

                $ok = false;
                $err = 'Не удалось завершить задачу ни одним исполнителем.';

                foreach ($executorCandidates as $candidateUserId) {
                    $debugLog("Пробуем завершить taskId={$taskId} по заявке #{$requestId} от userId={$candidateUserId}");
                    [$ok, $err] = $doApproveTask($task, (int)$candidateUserId, $comment, (string)($state['STATE_NAME'] ?? ''));
                    if ($ok) {
                        break;
                    }
                }

                if ($ok) {
                    $approvedAny = true;
                } else {
                    $lastError = $err;
                    $this->WriteToTrackingService(
                        "group_auto_approve: Ошибка автосогласования task {$taskId} по заявке #{$requestId}: {$err}"
                    );
                }
            }

            if ($approvedAny) {
                $requestDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $requestId];
                $historyMessage = "Заявка согласована автоматически по групповой заявке {$groupTitleForMessage}. Согласовал: {$currentUserName}.";

                // История и marker пишутся один раз на заявку по группе.
                $historyWasAdded = $appendHistoryOnce($requestId, $marker, $historyMessage);
                if ($historyWasAdded) {
                    CBPDocument::AddDocumentToHistory($requestDocumentId, $historyMessage, $currentUserId);
                    $this->WriteToTrackingService("group_auto_approve: {$historyMessage}");
                } else {
                    $debugLog("История по заявке #{$requestId} уже была добавлена ранее, marker={$marker}");
                }
            } elseif ($lastError === '') {
                $debugLog("Заявка #{$requestId} не была согласована: активные задания не завершены или уже закрыты");
            }
        }
    } finally {
        $releaseLock($lockKey);
    }
}
