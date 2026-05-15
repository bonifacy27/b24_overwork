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

$statusElementId = (int)($item['PROPERTY_' . $statusPropId . '_VALUE'] ?? 0);
$statusName = '';
if ($statusElementId > 0) {
    $statusRes = CIBlockElement::GetList([], ['ID' => $statusElementId], false, false, ['ID', 'NAME']);
    if ($statusRow = $statusRes->Fetch()) {
        $statusName = trim((string)$statusRow['NAME']);
    }
}
$fio = trim((string)($item['PROPERTY_' . $fioPropId . '_VALUE'] ?? ''));
$workType = overtimeCancelResolveLinkedValue($item['PROPERTY_' . $workTypePropId . '_VALUE'] ?? '');
$payType = overtimeCancelResolveLinkedValue($item['PROPERTY_' . $payTypePropId . '_VALUE'] ?? '');
$historyCurrent = trim((string)($item['PROPERTY_' . $historyPropId . '_VALUE'] ?? ''));

if ($statusElementId <= 0 || mb_strtolower($statusName, 'UTF-8') !== 'выполнена') {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Отменить можно только заявку в статусе "Выполнена".');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}


function overtimeCancelResolveLinkedValue($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $resolved = overtimeCancelResolveLinkedValue($item);
            if ($resolved !== '') {
                $parts[] = $resolved;
            }
        }
        return implode(', ', $parts);
    }

    $id = (int)$value;
    if ($id > 0) {
        $res = CIBlockElement::GetList([], ['ID' => $id], false, false, ['ID', 'NAME']);
        if ($row = $res->Fetch()) {
            return trim((string)$row['NAME']);
        }
    }

    return (string)$value;
}

function overtimeCancelFindStatusElementId(string $value): int
{
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => 388, 'NAME' => $value, 'ACTIVE' => 'Y'],
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME']
    );
    if ($row = $res->Fetch()) {
        return (int)$row['ID'];
    }

    $res = CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => 388, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME']);
    while ($row = $res->Fetch()) {
        if (mb_strtolower(trim((string)$row['NAME']), 'UTF-8') === mb_strtolower(trim($value), 'UTF-8')) {
            return (int)$row['ID'];
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

        $cancelStatusElementId = overtimeCancelFindStatusElementId('Отменена');
        if ($cancelStatusElementId <= 0) {
            $error = 'Не найден статус "Отменена" в справочнике статусов.';
        } else {
            $historyLine = sprintf('[%s] %s отменил(а) заявку. Комментарий: %s', date('d.m.Y H:i'), $cancelledBy, $comment);
            $newHistory = $historyCurrent !== '' ? ($historyCurrent . PHP_EOL . $historyLine) : $historyLine;

            $el = new CIBlockElement();
            CIBlockElement::SetPropertyValuesEx($requestId, $iblockId, [
                $statusPropId => $cancelStatusElementId,
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
.cancel-page-wrap{max-width:900px;margin:24px auto;padding:0 12px}
.cancel-card{background:#fff;border:1px solid #dfe3e8;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,.06)}
.cancel-card-head{padding:16px 20px;border-bottom:1px solid #eef1f4}
.cancel-card-body{padding:20px}
.cancel-grid{display:grid;grid-template-columns:220px 1fr;gap:10px 14px;margin-bottom:16px}
.cancel-grid-label{color:#6c757d}
.cancel-warning{margin:12px 0 16px;color:#8a4b00;background:#fff3cd;border:1px solid #ffe69c;padding:10px 12px;border-radius:6px}
@media (max-width: 680px){.cancel-grid{grid-template-columns:1fr}}
</style>

<div class="cancel-page-wrap">
    <div class="cancel-card">
        <div class="cancel-card-head">
            <h2 style="margin:0;">Отмена заявки #<?= (int)$requestId ?></h2>
        </div>
        <div class="cancel-card-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialcharsbx($error) ?></div>
            <?php endif; ?>
            <?php if ($ok !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialcharsbx($ok) ?></div>
            <?php endif; ?>

            <div class="cancel-grid">
                <div class="cancel-grid-label">Номер заявки</div><div><strong>#<?= (int)$requestId ?></strong></div>
                <div class="cancel-grid-label">ФИО сотрудника</div><div><?= htmlspecialcharsbx($fio !== '' ? $fio : '—') ?></div>
                <div class="cancel-grid-label">Тип работ</div><div><?= htmlspecialcharsbx($workType !== '' ? $workType : '—') ?></div>
                <div class="cancel-grid-label">Тип оплаты</div><div><?= htmlspecialcharsbx($payType !== '' ? $payType : '—') ?></div>
                <div class="cancel-grid-label">Текущий статус</div><div><?= htmlspecialcharsbx($statusName) ?></div>
            </div>

            <div class="cancel-warning">
                Вы отменяете выполненную заявку. Проверьте, что в 1С также отменено проведение документов по этой заявке.
            </div>

            <?php if ($ok === ''): ?>
                <form method="post">
                    <?= bitrix_sessid_post() ?>
                    <div class="form-group">
                        <label for="cancel_comment"><strong>Комментарий при отмене (обязательно)</strong></label>
                        <textarea class="form-control" name="cancel_comment" id="cancel_comment" rows="4" required><?= htmlspecialcharsbx($comment) ?></textarea>
                    </div>
                    <div style="margin-top:12px; display:flex; gap:8px;">
                        <button type="submit" class="btn btn-danger">Отменить заявку</button>
                        <a href="list.php" class="btn btn-secondary">К списку</a>
                    </div>
                </form>
            <?php else: ?>
                <a href="list.php" class="btn btn-primary">Вернуться к списку</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
