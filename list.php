<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (
    !Loader::includeModule('iblock')
    || !Loader::includeModule('main')
    || !Loader::includeModule('bizproc')
) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main/bizproc');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/data.php';

/**
 * Возвращает карту [requestId => taskId] для текущих задач БП пользователя.
 */
function overtimeGetCurrentUserTaskMapByRequestId(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $connection = Application::getConnection();
    $sql = "
        SELECT
            t.ID AS TASK_ID,
            s.DOCUMENT_ID
        FROM b_bp_task_user tu
        INNER JOIN b_bp_task t ON t.ID = tu.TASK_ID
        INNER JOIN b_bp_workflow_state s ON s.ID = t.WORKFLOW_ID
        WHERE tu.USER_ID = " . (int)$userId . "
          AND tu.STATUS = 0
          AND t.STATUS = 0
        ORDER BY t.ID DESC
    ";

    $taskMap = [];
    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $documentId = (string)($row['DOCUMENT_ID'] ?? '');
        if (!preg_match('/_(\d+)$/', $documentId, $matches)) {
            continue;
        }

        $requestId = (int)$matches[1];
        $taskId = (int)($row['TASK_ID'] ?? 0);

        if ($requestId <= 0 || $taskId <= 0 || isset($taskMap[$requestId])) {
            continue;
        }

        $taskMap[$requestId] = $taskId;
    }

    return $taskMap;
}

function overtimeGetRequestRows(array $config): array
{
    $rows = [];

    $res = CIBlockElement::GetList(
        ['ID' => 'DESC'],
        [
            'IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'],
            'ACTIVE' => 'Y',
        ],
        false,
        ['nTopCount' => 200],
        [
            'ID',
            'NAME',
            'DATE_CREATE',
            'PROPERTY_' . $config['REQ_PROP_EMPLOYEE'],
        ]
    );

    while ($item = $res->Fetch()) {
        $employeeId = (int)($item['PROPERTY_' . $config['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0);
        $employee = overtimeGetUserDataById($employeeId);

        $rows[] = [
            'id' => (int)$item['ID'],
            'name' => (string)$item['NAME'],
            'date_create' => (string)$item['DATE_CREATE'],
            'employee_name' => $employee['name'] ?: 'Не указан',
        ];
    }

    return $rows;
}

function overtimeBuildTaskUrl(int $taskId): string
{
    $backUrl = '/forms/hr_administration/overtime/list.php';
    return '/company/personal/bizproc/' . $taskId . '/?back_url=' . urlencode($backUrl);
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Список заявок на сверхурочную работу');

global $USER;
$currentUserId = (is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 0;
$taskMap = overtimeGetCurrentUserTaskMapByRequestId($currentUserId);
$rows = overtimeGetRequestRows($overtimeConfig);
?>
<style>
    .overtime-list-table {width: 100%; border-collapse: collapse; margin-top: 12px;}
    .overtime-list-table th, .overtime-list-table td {border: 1px solid #d5d7db; padding: 10px; vertical-align: top;}
    .overtime-list-table th {background: #f5f7fa; text-align: left;}
    .overtime-actions {display: flex; gap: 8px; flex-wrap: wrap;}
    .overtime-action-btn {
        display: inline-block;
        border-radius: 6px;
        padding: 6px 10px;
        text-decoration: none;
        background: #1f76d2;
        color: #fff !important;
        font-size: 13px;
    }
    .overtime-action-btn-secondary {background: #3f8f3f;}
</style>

<table class="overtime-list-table">
    <thead>
    <tr>
        <th>ID</th>
        <th>Заявка</th>
        <th>Сотрудник</th>
        <th>Создана</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="5">Заявки не найдены.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $requestId = (int)$row['id'];
            $taskId = (int)($taskMap[$requestId] ?? 0);
            ?>
            <tr>
                <td><?= $requestId ?></td>
                <td><?= overtimeH($row['name']) ?></td>
                <td><?= overtimeH($row['employee_name']) ?></td>
                <td><?= overtimeH($row['date_create']) ?></td>
                <td>
                    <div class="overtime-actions">
                        <a class="overtime-action-btn" href="/forms/hr_administration/overtime/view.php?id=<?= $requestId ?>">Открыть</a>
                        <?php if ($taskId > 0): ?>
                            <a class="overtime-action-btn overtime-action-btn-secondary" href="<?= overtimeH(overtimeBuildTaskUrl($taskId)) ?>">Задание БП</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
