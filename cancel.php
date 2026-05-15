<?php
use Bitrix\Main\Loader;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('iblock') || !Loader::includeModule('main')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$iblockId = 391;
$statusPropId = 3081;
$historyPropId = 3082;
$fioPropId = 3085;
$workTypePropId = 3080;
$payTypePropId = 3087;

$requestId = (int)($_REQUEST['id'] ?? 0);
if ($requestId <= 0) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не указан ID заявки.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$select = [
    'ID',
    'NAME',
    'PROPERTY_' . $statusPropId,
    'PROPERTY_' . $historyPropId,
    'PROPERTY_' . $fioPropId,
    'PROPERTY_' . $workTypePropId,
    'PROPERTY_' . $payTypePropId,
];

$res = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $requestId], false, false, $select);
$item = $res->GetNext();
if (!$item) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Заявка не найдена.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$statusEnumId = (int)($item['PROPERTY_' . $statusPropId . '_ENUM_ID'] ?? 0);
$statusName = trim((string)($item['PROPERTY_' . $statusPropId . '_VALUE'] ?? ''));
$fio = trim((string)($item['PROPERTY_' . $fioPropId . '_VALUE'] ?? ''));
$workType = trim((string)($item['PROPERTY_' . $workTypePropId . '_VALUE'] ?? ''));
$payType = trim((string)($item['PROPERTY_' . $payTypePropId . '_VALUE'] ?? ''));
$historyCurrent = trim((string)($item['PROPERTY_' . $historyPropId . '_VALUE'] ?? ''));

if ($statusEnumId <= 0 || mb_strtolower($statusName, 'UTF-8') !== 'выполнена') {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Отменить можно только заявку в статусе "Выполнена".');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

function overtimeCancelFindStatusEnumId(int $iblockId, int $propId, string $value): int
{
    $enumRes = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'PROPERTY_ID' => $propId]);
    while ($enum = $enumRes->Fetch()) {
        if (mb_strtolower(trim((string)$enum['VALUE']), 'UTF-8') === mb_strtolower(trim($value), 'UTF-8')) {
            return (int)$enum['ID'];
        }
    }
    return 0;
}

$error = '';
$ok = '';
$comment = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $comment = trim((string)($_POST['cancel_comment'] ?? ''));

    if ($comment === '') {
        $error = 'Комментарий обязателен.';
    } else {
        global $USER;
        $cancelledBy = '';
        if (is_object($USER) && method_exists($USER, 'GetID') && (int)$USER->GetID() > 0) {
            $cancelledBy = trim((string)$USER->GetFullName());
            if ($cancelledBy === '') {
                $cancelledBy = (string)$USER->GetLogin();
            }
        }
        if ($cancelledBy === '') {
            $cancelledBy = 'Неизвестный пользователь';
        }

        $cancelStatusEnumId = overtimeCancelFindStatusEnumId($iblockId, $statusPropId, 'Отменена');
        if ($cancelStatusEnumId <= 0) {
            $error = 'Не найден статус "Отменена" в справочнике статусов.';
        } else {
            $historyLine = sprintf('[%s] %s отменил(а) заявку. Комментарий: %s', date('d.m.Y H:i'), $cancelledBy, $comment);
            $newHistory = $historyCurrent !== '' ? ($historyCurrent . PHP_EOL . $historyLine) : $historyLine;

            $el = new CIBlockElement();
            CIBlockElement::SetPropertyValuesEx($requestId, $iblockId, [
                $statusPropId => $cancelStatusEnumId,
                $historyPropId => $newHistory,
            ]);

            $ok = 'Заявка успешно отменена.';
            $statusName = 'Отменена';
        }
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Отмена заявки #' . $requestId);
?>
<style>
.cancel-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999}
.cancel-modal{width:min(760px,96vw);background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.2);padding:20px}
.cancel-modal h2{margin-top:0}
.cancel-info{background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px}
.cancel-info p{margin:0 0 8px}
.cancel-info p:last-child{margin-bottom:0}
.req-text{margin:10px 0 14px;color:#8a4b00;background:#fff3cd;border:1px solid #ffe69c;padding:10px;border-radius:6px}
</style>

<div class="cancel-modal-backdrop">
    <div class="cancel-modal">
        <h2>Отмена заявки #<?= (int)$requestId ?></h2>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialcharsbx($error) ?></div>
        <?php endif; ?>
        <?php if ($ok !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialcharsbx($ok) ?></div>
        <?php endif; ?>

        <div class="cancel-info">
            <p><strong>Номер заявки:</strong> #<?= (int)$requestId ?></p>
            <p><strong>ФИО сотрудника:</strong> <?= htmlspecialcharsbx($fio !== '' ? $fio : '—') ?></p>
            <p><strong>Тип работ:</strong> <?= htmlspecialcharsbx($workType !== '' ? $workType : '—') ?></p>
            <p><strong>Тип оплаты:</strong> <?= htmlspecialcharsbx($payType !== '' ? $payType : '—') ?></p>
            <p><strong>Текущий статус:</strong> <?= htmlspecialcharsbx($statusName) ?></p>
        </div>

        <div class="req-text">
            Вы отменяете выполненную заявку. Проверьте, что в 1С также отменено проведение документов по этой заявке.
        </div>

        <?php if ($ok === ''): ?>
            <form method="post">
                <?= bitrix_sessid_post() ?>
                <div class="form-group">
                    <label for="cancel_comment"><strong>Комментарий при отмене (обязательно)</strong></label>
                    <textarea class="form-control" name="cancel_comment" id="cancel_comment" rows="4" required><?= htmlspecialcharsbx($comment) ?></textarea>
                </div>
                <div class="mt-3" style="margin-top:12px; display:flex; gap:8px;">
                    <button type="submit" class="btn btn-danger">Отменить заявку</button>
                    <a href="list.php" class="btn btn-secondary">К списку</a>
                </div>
            </form>
        <?php else: ?>
            <a href="list.php" class="btn btn-primary">Вернуться к списку</a>
        <?php endif; ?>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
