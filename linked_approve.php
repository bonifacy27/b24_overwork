<?php
/**
 * linked_approve: код для активити БП "PHP код" (Bitrix24).
 *
 * Логика:
 * 1) Находит связанные заявки по свойству SVYAZANNYE_ZAYAVKI.
 * 2) Проверяет, что связанная заявка в статусе "На согласовании C&B".
 * 3) Если есть текущее (Running/Waiting) задание БП по связанной заявке — выполняет его кнопкой "Согласовано".
 * 4) Пишет трекинг в связанную и текущую заявку.
 */

$iblockId = 391;
$propertyCodeLinked = 'SVYAZANNYE_ZAYAVKI';
$propertyCodeStatus = 'STATUS'; // При необходимости замените на фактический код свойства статуса.
$propertyCodeHistory = 'ISTORIYA'; // Поле истории заявки.
$statusApproveElementId = 3578386; // ID элемента статуса "В работе C&B" в справочнике статусов.
$statusApproveName = 'В работе C&B'; // Фолбэк-проверка по названию статуса.
$debugEnabled = true; // Временная подробная отладка в трекинге БП.
$version = '1.1.0'; // Исправление задвоения истории через идемпотентный ключ пары заявок и DB-lock.

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();

$currentElementId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentElementId = (int)str_replace('element_', '', (string)$currentElementId);

if ($currentElementId <= 0) {
    $this->WriteToTrackingService('linked_approve: Не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc')) {
    $this->WriteToTrackingService('linked_approve: Не удалось подключить модули iblock/bizproc');
    return;
}

$connection = null;
$sqlHelper = null;
if (class_exists('Bitrix\\Main\\Application')) {
    $connection = \Bitrix\Main\Application::getConnection();
    $sqlHelper = $connection->getSqlHelper();
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
$executorUserId = 1; // Выполняем задания БП от имени администратора.

$currentUserName = 'Пользователь #' . $currentUserId;
$userRs = CUser::GetByID($currentUserId);
if ($userData = $userRs->Fetch()) {
    $fio = trim((string)$userData['LAST_NAME'] . ' ' . (string)$userData['NAME'] . ' ' . (string)$userData['SECOND_NAME']);
    if ($fio !== '') {
        $currentUserName = $fio;
    }
}

$linkedElementIds = [];
$rsProps = CIBlockElement::GetProperty($iblockId, $currentElementId, [], ['CODE' => $propertyCodeLinked]);
while ($prop = $rsProps->Fetch()) {
    if (!empty($prop['VALUE'])) {
        $linkedElementIds[] = (int)$prop['VALUE'];
    }
}
$linkedElementIds = array_values(array_unique(array_filter($linkedElementIds)));

if (empty($linkedElementIds)) {
    $this->WriteToTrackingService('linked_approve: Связанные заявки не найдены');
    return;
}

$documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . $iblockId];
$debugLog = function (string $message) use ($debugEnabled): void {
    if ($debugEnabled) {
        $this->WriteToTrackingService('linked_approve [debug]: ' . $message);
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

$getHistoryValue = static function (int $elementId) use ($iblockId, $propertyCodeHistory): string {
    if ($elementId <= 0) {
        return '';
    }

    $existing = '';
    $propRes = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeHistory]);
    if ($prop = $propRes->Fetch()) {
        $existing = trim((string)($prop['VALUE'] ?? ''));
    }

    return $existing;
};

$appendHistory = static function (int $elementId, string $message) use ($iblockId, $propertyCodeHistory, $getHistoryValue): void {
    if ($elementId <= 0 || $message === '') {
        return;
    }

    $timestamp = date('d.m.Y H:i:s');
    $line = '[' . $timestamp . '] ' . $message;
    $existing = $getHistoryValue($elementId);

    $newValue = $existing !== '' ? ($existing . "\n" . $line) : $line;
    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeHistory => $newValue]);
};

$makeAutoApprovePairMarker = static function (int $leftElementId, int $rightElementId): string {
    $ids = [$leftElementId, $rightElementId];
    sort($ids, SORT_NUMERIC);

    return '[AUTO_APPROVE_LINKED_REQUEST:' . $ids[0] . ':' . $ids[1] . ']';
};

$hasAutoApprovePairMarker = static function (int $elementId, string $marker) use ($getHistoryValue): bool {
    if ($elementId <= 0 || $marker === '') {
        return false;
    }

    return strpos($getHistoryValue($elementId), $marker) !== false;
};

$appendHistoryOnce = static function (int $elementId, string $message, string $marker) use ($iblockId, $propertyCodeHistory, $getHistoryValue): bool {
    if ($elementId <= 0 || $message === '' || $marker === '') {
        return false;
    }

    $existing = $getHistoryValue($elementId);
    if (strpos($existing, $marker) !== false) {
        return false;
    }

    $timestamp = date('d.m.Y H:i:s');
    $line = '[' . $timestamp . '] ' . $marker . ' ' . $message;
    $newValue = $existing !== '' ? ($existing . "\n" . $line) : $line;
    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeHistory => $newValue]);

    return true;
};

$acquirePairLock = static function (string $marker) use ($connection, $sqlHelper, $debugLog): ?string {
    if (!$connection || !$sqlHelper || $marker === '') {
        return null;
    }

    $lockName = 'linked_approve_' . md5($marker);
    $lockNameSql = $sqlHelper->forSql($lockName);

    try {
        $lockResult = (int)$connection->queryScalar("SELECT GET_LOCK('{$lockNameSql}', 10)");
        if ($lockResult === 1) {
            return $lockName;
        }
        $debugLog("Не удалось получить DB-lock {$lockName}, GET_LOCK result={$lockResult}");
    } catch (\Throwable $e) {
        $debugLog('Ошибка получения DB-lock: ' . $e->getMessage());
    }

    return null;
};

$releasePairLock = static function (?string $lockName) use ($connection, $sqlHelper, $debugLog): void {
    if (!$connection || !$sqlHelper || !$lockName) {
        return;
    }

    try {
        $lockNameSql = $sqlHelper->forSql($lockName);
        $connection->queryExecute("DO RELEASE_LOCK('{$lockNameSql}')");
    } catch (\Throwable $e) {
        $debugLog('Ошибка освобождения DB-lock: ' . $e->getMessage());
    }
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
            || strpos($idNorm, 'approve') !== false
            || $idNorm === 'yes'
            || $idNorm === 'y'
        ) {
            return $id !== '' ? $id : 'Approve';
        }
    }

    return 'Approve';
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

    $taskResDbg = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS', 'USER_ID', 'USER_STATUS', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME']);
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

$doApproveTask = static function (array $task, int $userId, string $comment = '', string $fallbackActivityName = '') use ($resolveApproveActionCode, $isTaskStillRunning, $debugLog, $collectTaskDiagnostics, $runAsUser): array {
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [false, 'Некорректный taskId'];
    }

    $actionCode = $resolveApproveActionCode($taskId);
    if ((string)($task['ACTIVITY_NAME'] ?? '') === '' && class_exists('CBPTaskService')) {
        $taskReloadRes = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME', 'PARAMETERS']);
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
                ['approve' => 'Y', 'APPROVE' => 'Y', 'ACTION' => 'approve', 'status' => 'Y', 'comment' => $comment, 'task_comment' => $comment, 'USER_ID' => $userId, 'REAL_USER_ID' => $userId],
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

foreach ($linkedElementIds as $linkedElementId) {
    if ($linkedElementId <= 0 || $linkedElementId === $currentElementId) {
        continue;
    }

    $pairMarker = $makeAutoApprovePairMarker($currentElementId, $linkedElementId);
    $pairLockName = $acquirePairLock($pairMarker);
    if ($connection && $pairLockName === null) {
        $this->WriteToTrackingService("linked_approve: пара заявок {$currentElementId}/{$linkedElementId} уже обрабатывается другим экземпляром, пропускаем");
        continue;
    }

    try {
        if ($hasAutoApprovePairMarker($currentElementId, $pairMarker) || $hasAutoApprovePairMarker($linkedElementId, $pairMarker)) {
            $debugLog("Пара {$currentElementId}/{$linkedElementId} уже обработана ранее, marker={$pairMarker}");
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
    if ($statusApproveElementId > 0 && $statusElementId > 0 && $statusElementId === $statusApproveElementId) {
        $isExpectedStatus = true;
    }
    if (!$isExpectedStatus && $statusValue !== '' && $statusValue === $statusApproveName) {
        $isExpectedStatus = true;
    }

    if (!$isExpectedStatus) {
        $this->WriteToTrackingService(
            "linked_approve: Заявка {$linkedElementId} пропущена, статус '{$statusValue}', ID статуса '{$statusElementId}' "
            . "(ожидался '{$statusApproveName}', ID '{$statusApproveElementId}')"
        );
        continue;
    }

    $linkedDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $linkedElementId];
    $states = CBPDocument::GetDocumentStates($documentType, $linkedDocumentId);
    $debugLog("Найдены состояния БП для {$linkedElementId}: " . print_r($states, true));

    if (empty($states)) {
        $this->WriteToTrackingService("linked_approve: У связанной заявки {$linkedElementId} нет активных БП");
        continue;
    }

    $approvedAny = false;

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
            $debugLog("Найдена задача для linkedElementId={$linkedElementId}, workflowId={$workflowId}: " . print_r($task, true));

            $comment = 'Автосогласовано по согласованию связанной заявки #' . $currentElementId;
            $taskAssignedUserId = (int)($task['USER_ID'] ?? 0);
            $executorCandidates = [];
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
                [$ok, $err] = $doApproveTask($task, $candidateUserId, $comment, (string)($state['STATE_NAME'] ?? ''));
                if ($ok) {
                    $successUserId = $candidateUserId;
                    break;
                }
            }

            if ($ok) {
                $approvedAny = true;

                if ($hasAutoApprovePairMarker($currentElementId, $pairMarker) || $hasAutoApprovePairMarker($linkedElementId, $pairMarker)) {
                    $debugLog("После Approve история по паре уже содержит marker={$pairMarker}, повторную запись не делаем");
                    continue;
                }

                $msgLinked = "Заявка согласована автоматически по связанной заявке #{$currentElementId}. Инициатор согласования: {$currentUserName}.";
                CBPDocument::AddDocumentToHistory($linkedDocumentId, $pairMarker . ' ' . $msgLinked, $currentUserId);
                $appendHistoryOnce($linkedElementId, $msgLinked, $pairMarker);
                $this->WriteToTrackingService("linked_approve: {$msgLinked}");

                $mainDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $currentElementId];
                $msgMain = "Связанная заявка #{$linkedElementId} согласована автоматически, как связанная. Инициатор согласования: {$currentUserName}.";
                CBPDocument::AddDocumentToHistory($mainDocumentId, $pairMarker . ' ' . $msgMain, $currentUserId);
                $appendHistoryOnce($currentElementId, $msgMain, $pairMarker);
                $this->WriteToTrackingService("linked_approve: {$msgMain}");
            } else {
                $this->WriteToTrackingService(
                    "linked_approve: Ошибка автосогласования task {$taskId} по заявке {$linkedElementId}: {$err}"
                );
            }
        }
        if ($foundTasksForWorkflow === 0) {
            $debugLog("По workflowId={$workflowId} не найдено задач по фильтру STATUS=Running (без USER_STATUS)");
        }
    }

    if (!$approvedAny) {
        $this->WriteToTrackingService(
            "linked_approve: У связанной заявки {$linkedElementId} не найдено ожидающих заданий БП для автосогласования"
        );
    }
    } finally {
        $releasePairLock($pairLockName);
    }
}
