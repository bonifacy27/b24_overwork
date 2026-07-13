<?php
/**
 * linked_decline v1.2.1
 *
 * Код для активити БП "PHP код" (Bitrix24).
 *
 * Логика:
 * 1) Находит связанные заявки по свойству SVYAZANNYE_ZAYAVKI.
 * 2) Проверяет, что связанная заявка в статусе "В работе C&B".
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
 * - текст истории приведен к формату без указания исполнителя.
 * - добавлен addMarkerOnce();
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
 * - marker запуска БП 1292, workflowId и человекочитаемые записи не пишутся в пользовательскую историю ISTORIYA.
 */

$iblockId = 391;
$propertyCodeLinked = 'SVYAZANNYE_ZAYAVKI';
$propertyCodeStatus = 'STATUS';
$propertyCodeAutoDeclineMarkers = 'AUTO_DECLINE_MARKERS';

// v1.2.0: тип оплаты связанной заявки.
// PROPERTY_3087 / TIP_OPLATY — привязка к элементу справочника типов оплаты.
// 3537688 — "Предоставление отгула".
$propertyCodePaymentType = 'TIP_OPLATY';
$paymentTypeDayOffElementId = 3537688;

// v1.2.0: БП, который нужно запускать по связанной заявке с типом оплаты "Предоставление отгула".
$dayOffWorkflowTemplateId = 1292;
$dayOffWorkflowParameters = [];

$statusDeclineElementId = 3578386; // ID элемента статуса "В работе C&B" в справочнике статусов.
$statusDeclineName = 'В работе C&B'; // Фолбэк-проверка по названию статуса.

$debugEnabled = true; // При необходимости после проверки можно поставить false.
$executorUserId = 1; // Выполняем задания БП от имени администратора, если исполнитель задания не сработал.

$rootActivity = $this->GetRootActivity();
$documentIdRaw = $rootActivity->GetDocumentId();

$currentElementId = is_array($documentIdRaw) ? end($documentIdRaw) : $documentIdRaw;
$currentElementId = (int)str_replace('element_', '', (string)$currentElementId);

if ($currentElementId <= 0) {
    $this->WriteToTrackingService('linked_decline: Не удалось определить ID текущей заявки');
    return;
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc') || !CModule::IncludeModule('lists')) {
    $this->WriteToTrackingService('linked_decline: Не удалось подключить модули iblock/bizproc/lists');
    return;
}

$currentRequestLink = 'view.php?id=' . $currentElementId;
$historyUserId = 0;

$debugLog = function (string $message) use ($debugEnabled): void {
    if ($debugEnabled) {
        $this->WriteToTrackingService('linked_decline [debug]: ' . $message);
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
    $this->WriteToTrackingService('linked_decline: Связанные заявки не найдены');
    return;
}

$documentType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . $iblockId];

/**
 * Единый marker пары заявок. Порядок ID не важен.
 */
$buildPairMarker = static function (int $elementIdA, int $elementIdB): string {
    $ids = [$elementIdA, $elementIdB];
    sort($ids, SORT_NUMERIC);
    return 'AUTO_DECLINE_LINKED_REQUEST:' . $ids[0] . ':' . $ids[1];
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
        $lockName = 'linked_decline_' . md5($marker);
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
        $lockName = 'linked_decline_' . md5($marker);
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

/**
 * v1.2.0: Запустить БП 1292 по связанной заявке, если у нее TIP_OPLATY = 3537688.
 * Запуск делается один раз за счет marker-а в AUTO_DECLINE_MARKERS.
 */
$startDayOffWorkflowIfNeeded = static function (int $linkedElementId, int $currentElementId) use (
    $iblockId,
    $paymentTypeDayOffElementId,
    $dayOffWorkflowTemplateId,
    $dayOffWorkflowParameters,
    $getPaymentTypeIds,
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

        // Marker ставим только после успешного старта.
        $addMarkerOnce($linkedElementId, $workflowMarker);

        $debugLog(
            "v1.2.0: БП {$dayOffWorkflowTemplateId} запущен по заявке {$linkedElementId}, "
            . "workflowId={$workflowId}, основание — связанная заявка {$currentElementId}"
        );

        return [true, (string)$workflowId];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
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
        $this->WriteToTrackingService("linked_decline: пара {$currentElementId}/{$linkedElementId} уже обрабатывается другим процессом");
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
                "linked_decline: По связанной заявке #{$linkedElementId} с типом оплаты «Предоставление отгула» запущен БП #{$dayOffWorkflowTemplateId}"
            );
        } else {
            $debugLog("v1.2.1: БП {$dayOffWorkflowTemplateId} по связанной заявке {$linkedElementId} не запускался: {$dayOffWorkflowInfo}");
        }

        // Повторная проверка под lock: если любая из заявок уже содержит marker пары, повторно ничего не делаем.
        if ($hasMarker($currentElementId, $pairMarker) || $hasMarker($linkedElementId, $pairMarker)) {
            $this->WriteToTrackingService("linked_decline: пара {$currentElementId}/{$linkedElementId} уже была обработана ранее, marker={$pairMarker}");
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
                "linked_decline: Заявка {$linkedElementId} пропущена, статус '{$statusValue}', ID статуса '{$statusElementId}' "
                . "(ожидался '{$statusDeclineName}', ID '{$statusDeclineElementId}')"
            );
            continue;
        }

        $linkedDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $linkedElementId];
        $states = CBPDocument::GetDocumentStates($documentType, $linkedDocumentId);
        $debugLog("Найдены состояния БП для {$linkedElementId}: " . print_r($states, true));

        if (empty($states)) {
            $this->WriteToTrackingService("linked_decline: У связанной заявки {$linkedElementId} нет активных БП");
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
                    $this->WriteToTrackingService("linked_decline: marker появился во время обработки, повтор пропущен: {$pairMarker}");
                    break;
                }

                $foundTasksForWorkflow++;
                $taskId = (int)($task['ID'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }

                $debugLog("Найдена задача для linkedElementId={$linkedElementId}, workflowId={$workflowId}: " . print_r($task, true));

                $comment = 'Автоотклонено по связанной заявке #' . $currentElementId . ' (' . $currentRequestLink . ')';
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
                    [$ok, $err] = $doDeclineTask($task, $candidateUserId, $comment, (string)($state['STATE_NAME'] ?? ''));
                    if ($ok) {
                        $successUserId = $candidateUserId;
                        break;
                    }
                }

                if ($ok) {
                    // После успешного завершения еще раз проверяем marker перед записью истории.
                    if ($hasMarker($currentElementId, $pairMarker) || $hasMarker($linkedElementId, $pairMarker)) {
                        $this->WriteToTrackingService("linked_decline: task {$taskId} завершен, но история уже была записана ранее, marker={$pairMarker}");
                        $declinedAny = true;
                        break;
                    }

                    $declinedAny = true;

                    // Сначала фиксируем marker в обеих заявках, затем пишем историю.
                    // Даже если зеркальный БП стартует сразу после Decline, он увидит marker и не продублирует запись.
                    $addMarkerOnce($currentElementId, $pairMarker);
                    $addMarkerOnce($linkedElementId, $pairMarker);

                    $msgLinked = "Заявка отклонена автоматически по связанной заявке #{$currentElementId} ({$currentRequestLink}).";
                    CBPDocument::AddDocumentToHistory($linkedDocumentId, $msgLinked, $historyUserId);
                    $this->WriteToTrackingService("linked_decline: {$msgLinked}");

                    $mainDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $currentElementId];
                    $msgMain = "Связанная заявка #{$linkedElementId} отклонена автоматически.";
                    CBPDocument::AddDocumentToHistory($mainDocumentId, $msgMain, $historyUserId);
                    $this->WriteToTrackingService("linked_decline: {$msgMain}");

                    break;
                }

                $this->WriteToTrackingService(
                    "linked_decline: Ошибка автоотклонения task {$taskId} по заявке {$linkedElementId}: {$err}"
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
                "linked_decline: У связанной заявки {$linkedElementId} не найдено ожидающих заданий БП для автоотклонения"
            );
        }
    } finally {
        $releasePairLock($pairMarker);
    }
}
