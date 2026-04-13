<?php
/**
 * Заявка на сверхурочную работу / работу в выходной день / дежурство
 * Версия: 1.7.2
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (
    !Loader::includeModule('iblock')
    || !Loader::includeModule('main')
    || !Loader::includeModule('ui')
    || !Loader::includeModule('intranet')
) {
    if (isset($_REQUEST['ajax_action'])) {
        header('Content-Type: application/json; charset=' . LANG_CHARSET);
        echo Json::encode([
            'success' => false,
            'errors' => ['Не удалось подключить модули iblock/main/ui/intranet'],
        ]);
        die();
    }

    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main/ui/intranet');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/inc/constants.php';
require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/logic.php';

$request = Context::getCurrent()->getRequest();

global $USER;
$currentUserId = (is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 0;

$debugQueryValue = mb_strtolower(trim((string)$request->getQuery('debug')));
if (in_array($debugQueryValue, ['1', 'y', 'yes', 'true'], true)) {
    $overtimeConfig['DEBUG'] = true;
}

$overtimeConfig['ALLOW_DUTY'] = overtimeCanCurrentUserUseDuty($currentUserId, $overtimeConfig);
$overtimeConfig['CURRENT_USER_ID'] = $currentUserId;
$overtimeConfig['CREATOR_ACCESS_MAP'] = overtimeGetCreatorAccessMap($currentUserId, $overtimeConfig);

/**
 * AJAX: предпросмотр
 */
if ($request->isPost() && $request->getPost('ajax_action') === 'preview') {
    header('Content-Type: application/json; charset=' . LANG_CHARSET);

    if (!check_bitrix_sessid()) {
        echo Json::encode([
            'success' => false,
            'errors' => ['Сессия истекла. Обновите страницу.'],
        ]);
        die();
    }

    try {
        $mode = trim((string)$request->getPost('mode'));
        $payloadJson = (string)$request->getPost('payload');
        $payload = Json::decode($payloadJson);

        $result = overtimeBuildModePreview(
            $mode,
            is_array($payload) ? $payload : [],
            $overtimeConfig
        );

        echo Json::encode($result);
    } catch (Throwable $e) {
        echo Json::encode([
            'success' => false,
            'errors' => ['Ошибка предпросмотра: ' . $e->getMessage()],
        ]);
    }

    die();
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Заявка на сверхурочную работу / работу в выходной день / дежурство');

$createResult = null;

if (
    $request->isPost()
    && $request->getPost('action') === 'create'
    && $request->getPost('confirm_create') === 'Y'
    && check_bitrix_sessid()
) {
    $mode = trim((string)$request->getPost('mode'));

    $createResult = overtimeCreateByMode(
        $mode,
        $_POST,
        $_FILES,
        $currentUserId,
        $overtimeConfig
    );

    if (!empty($createResult['success'])) {
        LocalRedirect('/forms/hr_administration/overtime/list.php');
    }
}

$formData = overtimeBuildDefaultFormData($currentUserId);

if ($request->isPost()) {
    $formData = overtimeMergePostedFormData($formData, $_POST);
}

require __DIR__ . '/inc/ui_form.php';
require __DIR__ . '/inc/js.php';

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
