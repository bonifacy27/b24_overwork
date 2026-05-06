<?php
/**
 * Standalone runner for linked approvals.
 * Usage: /usr/bin/php linked_approve_cli.php --element=3586572 [--user=1]
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('BX_CRONTAB', true);
define('BX_CRONTAB_SUPPORT', true);
define('NO_AGENT_STATISTIC', 'Y');
define('DisableEventsCheck', true);

$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

$prolog = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (is_file($prolog)) {
    ob_start();
    require_once $prolog;
    $prologOutput = (string)ob_get_clean();
    if ($prologOutput !== '' && stripos($prologOutput, '<html') !== false) {
        fwrite(STDERR, "Warning: prolog produced HTML output (likely auth template), ignored for CLI run.\n");
    }
}

if (!class_exists('CModule')) {
    fwrite(STDERR, "Bitrix core is not loaded. Check DOCUMENT_ROOT/prolog path.\n");
    exit(1);
}

$options = getopt('', ['element:', 'user::', 'debug::']);
$elementId = (int)($options['element'] ?? 0);
$runUserId = (int)($options['user'] ?? 1);
$debug = (($options['debug'] ?? '1') !== '0');

if ($elementId <= 0) {
    fwrite(STDERR, "Usage: php linked_approve_cli.php --element=<ID> [--user=1] [--debug=1]\n");
    exit(2);
}

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('bizproc')) {
    fwrite(STDERR, "Failed to include iblock/bizproc modules\n");
    exit(3);
}

$log = static function (string $m) use ($debug): void {
    $line = '[' . date('Y-m-d H:i:s') . '] linked_approve_cli: ' . $m . PHP_EOL;
    echo $line;
    if ($debug) {
        $logFile = rtrim((string)getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'linked_approve_cli.log';
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
};

$runAsUser = static function (int $userId, callable $callback) use ($log) {
    global $USER;
    $canAuth = is_object($USER) && method_exists($USER, 'Authorize') && method_exists($USER, 'GetID');
    $prevUserId = $canAuth ? (int)$USER->GetID() : 0;

    if ($canAuth && $userId > 0 && $prevUserId !== $userId) {
        $USER->Authorize($userId);
        $log("runAsUser: {$prevUserId} -> {$userId}");
    }
    try {
        return $callback();
    } finally {
        if ($canAuth && $prevUserId > 0 && (int)$USER->GetID() !== $prevUserId) {
            $USER->Authorize($prevUserId);
            $log("runAsUser: restore {$prevUserId}");
        }
    }
};

$iblockId = 391;
$propLinked = 'SVYAZANNYE_ZAYAVKI';

$linked = [];
$rs = CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => $propLinked]);
while ($p = $rs->Fetch()) {
    if (!empty($p['VALUE'])) $linked[] = (int)$p['VALUE'];
}
$linked = array_values(array_unique(array_filter($linked)));
if (!$linked) {
    $log('No linked requests found');
    exit(0);
}

foreach ($linked as $linkedId) {
    $docType = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_' . $iblockId];
    $docId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $linkedId];
    $states = CBPDocument::GetDocumentStates($docType, $docId);
    if (!$states) {
        $log("No workflows for linked #{$linkedId}");
        continue;
    }

    foreach ($states as $state) {
        $wf = (string)($state['ID'] ?? '');
        if ($wf === '') continue;

        $tasks = CBPTaskService::GetList(['ID' => 'ASC'], ['WORKFLOW_ID' => $wf, 'STATUS' => CBPTaskStatus::Running], false, false, ['ID', 'USER_ID', 'WORKFLOW_ID', 'PARAMETERS', 'NAME', 'ACTIVITY', 'ACTIVITY_NAME']);
        while ($task = $tasks->Fetch()) {
            $taskId = (int)$task['ID'];
            $taskDocId = (int)($task['PARAMETERS']['DOCUMENT_ID'][2] ?? 0);
            if ($taskDocId > 0 && $taskDocId !== $linkedId) {
                $log("skip task={$taskId}: DOCUMENT_ID={$taskDocId}, expected linked={$linkedId}; raw=" . print_r($task, true));
                continue;
            }

            $taskUser = (int)($task['USER_ID'] ?? 0);
            $actUser = $taskUser > 0 ? $taskUser : $runUserId;
            $comment = 'Автосогласовано внешним скриптом по заявке #' . $elementId;

            $errors = [];
            $res = $runAsUser($actUser, static function () use ($taskId, $actUser, $comment, &$errors) {
                return CBPDocument::PostTaskForm($taskId, $actUser, [
                    'approve' => 'Y',
                    'ACTION' => 'approve',
                    'comment' => $comment,
                    'task_comment' => $comment,
                    'USER_ID' => $actUser,
                    'REAL_USER_ID' => $actUser,
                ], $errors, '', $actUser);
            });

            $log("linked={$linkedId}, task={$taskId}, user={$actUser}, result=" . print_r($res, true) . ', errors=' . print_r($errors, true));
        }
    }
}
