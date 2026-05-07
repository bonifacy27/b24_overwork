<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('iblock') || !Loader::includeModule('main') || !Loader::includeModule('ui') || !Loader::includeModule('bizproc')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули.');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/logic.php';

$request = Context::getCurrent()->getRequest();
global $USER;
$currentUserId = (int)$USER->GetID();
$requestId = (int)$request->getQuery('id');

function overtimeFindCurrentUserApprovalTaskRefine(int $requestId, int $userId, int $iblockId): ?array {
    if ($requestId <= 0 || $userId <= 0 || $iblockId <= 0 || !class_exists('CBPTaskService')) { return null; }
    $res = CBPTaskService::GetList(['ID'=>'DESC'], ['USER_STATUS'=>CBPTaskUserStatus::Waiting], false, false, ['ID','DOCUMENT_ID','WORKFLOW_ID','ACTIVITY_NAME','USER_ID','USERS','PARAMETERS']);
    while ($task = $res->GetNext()) {
        $doc = (string)($task['DOCUMENT_ID'] ?? '');
        if (strpos($doc, '_' . $iblockId . '_' . $requestId) === false && !preg_match('/' . preg_quote((string)$requestId, '/') . '$/', $doc)) { continue; }
        $raw = (string)($task['USER_ID'] ?? '');
        if ((int)preg_replace('/\D+/', '', $raw) === $userId) { return $task; }
    }
    return null;
}

function overtimeLoadRequestForRefine(int $requestId, array $config): ?array {
    $select = ['ID','NAME','CREATED_BY','PROPERTY_'.$config['REQ_PROP_EMPLOYEE'],'PROPERTY_'.$config['REQ_PROP_WORK_TYPE'],'PROPERTY_'.$config['REQ_PROP_WORK_START_DATE'],'PROPERTY_'.$config['REQ_PROP_WORK_END_DATE'],'PROPERTY_'.$config['REQ_PROP_WORK_START_TIME'],'PROPERTY_'.$config['REQ_PROP_WORK_END_TIME'],'PROPERTY_'.$config['REQ_PROP_JUSTIFICATION'],'PROPERTY_'.$config['REQ_PROP_PAYMENT_TYPE'],'PROPERTY_KOMMENTARIY_DLYA_DORABOTKI','PROPERTY_'.$config['REQ_PROP_LINKED_REQUESTS']];
    $res = CIBlockElement::GetList([], ['IBLOCK_ID'=>(int)$config['IBLOCK_REQUESTS'],'ID'=>$requestId], false, false, $select);
    $item = $res->Fetch();
    if (!$item) return null;
    $linked = $item['PROPERTY_'.$config['REQ_PROP_LINKED_REQUESTS'].'_VALUE'] ?? [];
    if (!empty($linked)) return null;
    return $item;
}

if ($request->isPost() && $request->getPost('ajax_action') === 'preview') {
    header('Content-Type: application/json; charset=' . LANG_CHARSET);
    $payload = Json::decode((string)$request->getPost('payload'));
    $result = overtimeBuildModePreview('single', is_array($payload) ? $payload : [], $overtimeConfig);
    echo Json::encode($result); die();
}

if ($request->isPost() && $request->getPost('ajax_action') === 'check_day') {
    header('Content-Type: application/json; charset=' . LANG_CHARSET);
    $date = (string)$request->getPost('date');
    echo Json::encode(['success'=>true,'is_workday'=>overtimeIsWorkday1C($date)]); die();
}

$item = overtimeLoadRequestForRefine($requestId, $overtimeConfig);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Доработка заявки');
if (!$item) { ShowError('Заявка не найдена, связана с другими или недоступна для доработки.'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }

$task = overtimeFindCurrentUserApprovalTaskRefine($requestId, $currentUserId, (int)$overtimeConfig['IBLOCK_REQUESTS']);
if (!$task) { ShowError('У вас нет активного задания по этой заявке.'); require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return; }

$employeeId = (int)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_EMPLOYEE'].'_VALUE'];
$employee = overtimeGetUserDataById($employeeId);
$workTypeId = (int)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_TYPE'].'_VALUE'];
$workTypeName = $workTypeId === (int)$overtimeConfig['WORK_TYPE_WEEKEND_ID'] ? 'Заявка на работу в выходной день' : 'Заявка на сверхурочную работу';

if ($request->isPost() && $request->getPost('action') === 'save_refine' && check_bitrix_sessid()) {
    $action = (string)$request->getPost('task_action');
    if ($action === 'approve') {
        $fields = [
            $overtimeConfig['REQ_PROP_WORK_START_DATE'] => (string)$request->getPost('date_start'),
            $overtimeConfig['REQ_PROP_WORK_END_DATE'] => (string)$request->getPost('date_end'),
            $overtimeConfig['REQ_PROP_WORK_START_TIME'] => (string)$request->getPost('time_start'),
            $overtimeConfig['REQ_PROP_WORK_END_TIME'] => (string)$request->getPost('time_end'),
            $overtimeConfig['REQ_PROP_JUSTIFICATION'] => (string)$request->getPost('justification'),
            $overtimeConfig['REQ_PROP_PAYMENT_TYPE'] => (string)$request->getPost('payment_type'),
        ];
        CIBlockElement::SetPropertyValuesEx($requestId, (int)$overtimeConfig['IBLOCK_REQUESTS'], $fields);
    }
    $result = overtimeCompleteBizprocTask($task, $currentUserId, $action === 'approve' ? 'approve' : 'nonapprove', '');
    if (!empty($result['OK'])) { LocalRedirect('/forms/hr_administration/overtime/view.php?id=' . $requestId); }
    ShowError((string)$result['ERROR']);
}
?>
<form method="post" id="refine-form"><?=bitrix_sessid_post()?>
<input type="hidden" name="action" value="save_refine"><input type="hidden" name="task_action" id="task_action" value="">
<div>Сотрудник: <b><?=overtimeH($employee['display'])?></b></div>
<div>Тип заявки: <b><?=overtimeH($workTypeName)?></b></div>
<div>Комментарий по доработке: <?=overtimeH((string)($item['PROPERTY_KOMMENTARIY_DLYA_DORABOTKI_VALUE'] ?? ''))?></div>
<input type="date" name="date_start" id="date_start" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_START_DATE'].'_VALUE'])?>">
<select name="time_start" id="time_start"><?php foreach (overtimeGetHourOptions() as $h): ?><option <?=$h===(string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_START_TIME'].'_VALUE']?'selected':''?>><?=$h?></option><?php endforeach; ?></select>
<input type="date" name="date_end" id="date_end" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_END_DATE'].'_VALUE'])?>">
<select name="time_end" id="time_end"><?php foreach (overtimeGetHourOptions() as $h): ?><option <?=$h===(string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_END_TIME'].'_VALUE']?'selected':''?>><?=$h?></option><?php endforeach; ?></select>
<textarea name="justification"><?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_JUSTIFICATION'].'_VALUE'])?></textarea>
<input type="text" name="payment_type" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_PAYMENT_TYPE'].'_VALUE'])?>">
<div id="preview"></div>
<button type="button" id="btn-approve">Согласовать</button><button type="button" id="btn-reject">Отклонить</button>
</form>
<script>
BX.ready(function(){
 const workTypeId = <?=$workTypeId?>, weekendId=<?=(int)$overtimeConfig['WORK_TYPE_WEEKEND_ID']?>, overtimeId=<?=(int)$overtimeConfig['WORK_TYPE_OVERTIME_ID']?>;
 function validateDate(id){const d=document.getElementById(id).value;if(!d)return Promise.resolve(true);return BX.ajax.runComponentAction('', '', {mode:'ajax', data:{ajax_action:'check_day',date:d,sessid:BX.bitrix_sessid()}}).catch(()=>({data:{}}));}
 document.getElementById('btn-approve').onclick=function(){document.getElementById('task_action').value='approve';document.getElementById('refine-form').submit();};
 document.getElementById('btn-reject').onclick=function(){document.getElementById('task_action').value='reject';document.getElementById('refine-form').submit();};
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
