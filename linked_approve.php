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
$statusApproveElementId = 3578386; // ID элемента статуса "На согласовании C&B" в справочнике статусов.
$statusApproveName = 'На согласовании C&B'; // Фолбэк-проверка по названию статуса.

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

$appendHistory = static function (int $elementId, string $message) use ($iblockId, $propertyCodeHistory): void {
    if ($elementId <= 0 || $message === '') {
        return;
    }

    $timestamp = date('d.m.Y H:i:s');
    $line = '[' . $timestamp . '] ' . $message;

    $existing = [];
    $propRes = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['CODE' => $propertyCodeHistory]);
    while ($prop = $propRes->Fetch()) {
        $value = trim((string)($prop['VALUE'] ?? ''));
        if ($value !== '') {
            $existing[] = $value;
        }
    }

    $existing[] = $line;
    CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propertyCodeHistory => $existing]);
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

$doApproveTask = static function (array $task, int $userId, string $comment = '') use ($resolveApproveActionCode, $isTaskStillRunning): array {
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) {
        return [false, 'Некорректный taskId'];
    }

    $actionCode = $resolveApproveActionCode($taskId);

    try {
        if (method_exists('CBPDocument', 'PostTaskForm')) {
            $errors = [];
            $requestFields = [
                'approve' => $actionCode,
                $actionCode => 'Y',
                'comment' => $comment,
                'task_comment' => $comment,
            ];
            CBPDocument::PostTaskForm($taskId, $userId, $requestFields, $errors, '', $userId);
            if (empty($errors) && !$isTaskStillRunning($taskId)) {
                return [true, ''];
            }
        }

        if (method_exists('CBPTaskService', 'DoTask')) {
            CBPTaskService::DoTask($taskId, $userId, [
                'ACTION' => $actionCode,
                $actionCode => 'Y',
                'COMMENT' => $comment,
                'task_comment' => $comment,
            ]);
            if (!$isTaskStillRunning($taskId)) {
                return [true, ''];
            }
        }
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }

    return [false, 'Задание не завершилось после попытки согласования'];
};

foreach ($linkedElementIds as $linkedElementId) {
    if ($linkedElementId <= 0 || $linkedElementId === $currentElementId) {
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
                'USER_STATUS' => CBPTaskUserStatus::Waiting,
                'STATUS' => CBPTaskStatus::Running,
            ],
            false,
            false,
            ['ID', 'NAME', 'WORKFLOW_ID', 'STATUS', 'USER_ID', 'USER_STATUS']
        );

        while ($task = $taskRes->Fetch()) {
            $taskId = (int)($task['ID'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $comment = 'Автосогласовано по согласованию связанной заявки #' . $currentElementId;
            [$ok, $err] = $doApproveTask($task, $executorUserId, $comment);

            if ($ok) {
                $approvedAny = true;

                $msgLinked = "Заявка автосогласована по согласованию связанной заявки #{$currentElementId}. Инициатор согласования: {$currentUserName}. Выполнено от имени администратора (ID 1).";
                CBPDocument::AddDocumentToHistory($linkedDocumentId, $msgLinked, $currentUserId);
                $appendHistory($linkedElementId, $msgLinked);
                $this->WriteToTrackingService("linked_approve: {$msgLinked}");

                $mainDocumentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $currentElementId];
                $msgMain = "Связанная заявка #{$linkedElementId} автосогласована по согласованию этой заявки. Инициатор согласования: {$currentUserName}. Выполнено от имени администратора (ID 1).";
                CBPDocument::AddDocumentToHistory($mainDocumentId, $msgMain, $currentUserId);
                $appendHistory($currentElementId, $msgMain);
                $this->WriteToTrackingService("linked_approve: {$msgMain}");
            } else {
                $this->WriteToTrackingService(
                    "linked_approve: Ошибка автосогласования task {$taskId} по заявке {$linkedElementId}: {$err}"
                );
            }
        }
    }

    if (!$approvedAny) {
        $this->WriteToTrackingService(
            "linked_approve: У связанной заявки {$linkedElementId} не найдено ожидающих заданий БП для автосогласования"
        );
    }
}
