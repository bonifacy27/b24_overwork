<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

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
require_once __DIR__ . '/logic.php';

$request = Context::getCurrent()->getRequest();
$requestId = (int)$request->getQuery('id');

global $USER;
$currentUserId = (is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 0;
$adminName = trim(overtimeGetUserNameById($currentUserId));
if ($adminName === '') {
    $adminName = 'Пользователь #' . $currentUserId;
}

$errors = [];
$successMessage = '';

$sourceRequest = overtimeGetRequestById($requestId, $overtimeConfig);
if (!$sourceRequest) {
    $errors[] = 'Заявка не найдена.';
}

$workTypeId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_WORK_TYPE'] . '_VALUE'] ?? 0);
$isDuty = $workTypeId === (int)$overtimeConfig['WORK_TYPE_DUTY_ID'];

$currentStart = (string)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_START'] . '_VALUE'] ?? '');
$currentEnd = (string)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_END'] . '_VALUE'] ?? '');

$currentStartTs = $currentStart !== '' ? strtotime($currentStart) : 0;
$currentEndTs = $currentEnd !== '' ? strtotime($currentEnd) : 0;

$defaultDateStart = $currentStartTs > 0 ? date('d.m.Y', $currentStartTs) : '';
$defaultTimeStart = $currentStartTs > 0 ? date('H:i', $currentStartTs) : '';
$defaultDateEnd = $currentEndTs > 0 ? date('d.m.Y', $currentEndTs) : '';
$defaultTimeEnd = $currentEndTs > 0 ? date('H:i', $currentEndTs) : '';

if ($request->isPost() && $request->getPost('action') === 'edit_ka' && check_bitrix_sessid()) {
    $dateStart = trim((string)$request->getPost('date_start'));
    $timeStart = trim((string)$request->getPost('time_start'));
    $dateEnd = trim((string)$request->getPost('date_end'));
    $timeEnd = trim((string)$request->getPost('time_end'));

    if ($dateStart === '' || $timeStart === '' || $dateEnd === '' || $timeEnd === '') {
        $errors[] = 'Необходимо заполнить дату и время начала/окончания работ.';
    }

    if (empty($errors)) {
        try {
            $preview = overtimeBuildSinglePreviewItem(
                (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0),
                $dateStart,
                $timeStart,
                $dateEnd,
                $timeEnd,
                $isDuty,
                $overtimeConfig
            );
        } catch (Throwable $e) {
            $preview = ['errors' => ['Ошибка предпросмотра: ' . $e->getMessage()], 'segments_json' => '[]'];
        }

        if (!empty($preview['errors'])) {
            $errors = array_merge($errors, (array)$preview['errors']);
        }

        $segmentsRaw = [];
        if (empty($errors)) {
            $segmentsRaw = \Bitrix\Main\Web\Json::decode($preview['segments_json'] ?: '[]');
            if (empty($segmentsRaw)) {
                $errors[] = 'Не удалось сформировать сегменты новой заявки.';
            }
        }

        if (empty($errors)) {
            $paymentTypeId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_PAYMENT_TYPE'] . '_VALUE'] ?? 0);
            $paymentTypes = [];
            foreach ($segmentsRaw as $index => $_segment) {
                $paymentTypes[$index] = $paymentTypeId;
            }

            $justification = trim((string)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_JUSTIFICATION'] . '_VALUE'] ?? ''));
            $justificationFileId = (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_JUST_FILE'] . '_VALUE'] ?? 0);
            $justificationFile = $justificationFileId > 0 ? CFile::MakeFileArray($justificationFileId) : null;

            $createResult = overtimeCreateEmployeeRequestPack(
                (int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0),
                $segmentsRaw,
                $paymentTypes,
                $justification,
                $justificationFile,
                true,
                $currentUserId,
                $overtimeConfig,
                0,
                ['par_Start' => 'edit_ka']
            );

            if (empty($createResult['success'])) {
                $errors = array_merge($errors, (array)($createResult['errors'] ?? ['Не удалось создать новую заявку.']));
            } else {
                $newRequestId = (int)($createResult['created_ids'][0] ?? 0);
                if ($newRequestId <= 0) {
                    $errors[] = 'Новая заявка создана некорректно: не получен ID.';
                }

                if (empty($errors)) {
                    CIBlockElement::SetPropertyValuesEx(
                        $requestId,
                        (int)$overtimeConfig['IBLOCK_REQUESTS'],
                        [
                            $overtimeConfig['REQ_PROP_STATUS'] => (int)$overtimeConfig['STATUS_TRANSFERRED_ID'],
                        ]
                    );

                    $stopWorkflowError = overtimeTerminateRequestWorkflows(
                        $requestId,
                        'Перенос заявки кадровым администратором'
                    );
                    if ($stopWorkflowError !== null) {
                        $errors[] = 'Не удалось прервать бизнес-процесс старой заявки: ' . $stopWorkflowError;
                    }

                    $now = date('d.m.Y H:i:s');
                    overtimeAppendRequestHistory(
                        $requestId,
                        $now . ' перенесена в заявку #' . $newRequestId . ' (' . $adminName . ')',
                        $overtimeConfig
                    );
                    overtimeAppendRequestHistory(
                        $newRequestId,
                        $now . ' создана переносом после редактирования заявки #' . $requestId . ' (' . $adminName . ')',
                        $overtimeConfig
                    );

                    if (empty($errors)) {
                        $successMessage = 'Заявка #' . $requestId . ' перенесена в новую заявку #' . $newRequestId . '.';
                    }
                }
            }
        }

        $defaultDateStart = $dateStart;
        $defaultTimeStart = $timeStart;
        $defaultDateEnd = $dateEnd;
        $defaultTimeEnd = $timeEnd;
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Редактирование заявки кадровым администратором');
?>
<div style="max-width: 780px;">
    <h2>Редактирование заявки #<?= (int)$requestId ?></h2>

    <?php if (!empty($errors)): ?>
        <div style="padding:12px; border:1px solid #c44; background:#fff1f1; margin-bottom:16px;">
            <?php foreach ($errors as $error): ?>
                <div style="margin:4px 0;"><?= htmlspecialcharsbx((string)$error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div style="padding:12px; border:1px solid #2f8a3b; background:#f0fff2; margin-bottom:16px;">
            <?= htmlspecialcharsbx($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($sourceRequest): ?>
        <div style="margin-bottom: 16px;">
            <div><b>Сотрудник:</b> <?= htmlspecialcharsbx(overtimeGetUserNameById((int)($sourceRequest['PROPERTY_' . $overtimeConfig['REQ_PROP_EMPLOYEE'] . '_VALUE'] ?? 0))) ?></div>
            <div><b>Текущий период:</b> <?= htmlspecialcharsbx($defaultDateStart . ' ' . $defaultTimeStart) ?> — <?= htmlspecialcharsbx($defaultDateEnd . ' ' . $defaultTimeEnd) ?></div>
        </div>

        <form method="post" action="">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="edit_ka">

            <table class="adm-detail-content-table edit-table" style="width:100%; max-width:640px;">
                <tr>
                    <td style="width:220px;">Дата начала работ</td>
                    <td><input type="text" name="date_start" value="<?= htmlspecialcharsbx($defaultDateStart) ?>" placeholder="дд.мм.гггг"></td>
                </tr>
                <tr>
                    <td>Время начала работ</td>
                    <td><input type="text" name="time_start" value="<?= htmlspecialcharsbx($defaultTimeStart) ?>" placeholder="чч:мм"></td>
                </tr>
                <tr>
                    <td>Дата окончания работ</td>
                    <td><input type="text" name="date_end" value="<?= htmlspecialcharsbx($defaultDateEnd) ?>" placeholder="дд.мм.гггг"></td>
                </tr>
                <tr>
                    <td>Время окончания работ</td>
                    <td><input type="text" name="time_end" value="<?= htmlspecialcharsbx($defaultTimeEnd) ?>" placeholder="чч:мм"></td>
                </tr>
            </table>

            <div style="margin-top:16px;">
                <button type="submit" class="ui-btn ui-btn-success">Перенести заявку</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
