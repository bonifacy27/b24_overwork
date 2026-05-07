<?php
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('iblock') || !Loader::includeModule('main') || !Loader::includeModule('ui') || !Loader::includeModule('bizproc')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
    ShowError('Не удалось подключить модули iblock/main/ui/bizproc');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/logic.php';

function overtimeRefineFindTask(int $requestId, int $userId, int $iblockId): ?array
{
    if ($requestId <= 0 || $userId <= 0 || $iblockId <= 0 || !class_exists('CBPTaskService')) { return null; }
    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['USER_STATUS' => CBPTaskUserStatus::Waiting], false, false, ['ID','DOCUMENT_ID','USER_ID']);
    while ($task = $res->GetNext()) {
        $doc = (string)($task['DOCUMENT_ID'] ?? '');
        if (strpos($doc, '_' . $iblockId . '_' . $requestId) === false) { continue; }
        if ((int)preg_replace('/\D+/u', '', (string)($task['USER_ID'] ?? '')) === $userId) { return $task; }
    }
    return null;
}

function overtimeRefineCompleteTask(array $task, int $userId, string $action): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0) return ['OK' => false, 'ERROR' => 'Не найдено задание БП'];
    $fields = ['USER_ID' => $userId, 'REAL_USER_ID' => $userId, 'COMMENT' => ''];
    $fields[$action === 'approve' ? 'approve' : 'nonapprove'] = 'Y';
    try {
        $errors = [];
        CBPDocument::PostTaskForm($taskId, $userId, $fields, $errors, '', $userId);
        if (!empty($errors)) { return ['OK' => false, 'ERROR' => 'Ошибка завершения задания БП']; }
    } catch (\Throwable $e) {
        return ['OK' => false, 'ERROR' => $e->getMessage()];
    }
    return ['OK' => true];
}

function overtimeRefineLoadRequest(int $requestId, array $config): ?array
{
    $select = [
        'ID','NAME','PROPERTY_'.$config['REQ_PROP_EMPLOYEE'],'PROPERTY_'.$config['REQ_PROP_WORK_TYPE'],
        'PROPERTY_'.$config['REQ_PROP_WORK_START_DATE'],'PROPERTY_'.$config['REQ_PROP_WORK_END_DATE'],
        'PROPERTY_'.$config['REQ_PROP_WORK_START_TIME'],'PROPERTY_'.$config['REQ_PROP_WORK_END_TIME'],
        'PROPERTY_'.$config['REQ_PROP_JUSTIFICATION'],'PROPERTY_'.$config['REQ_PROP_PAYMENT_TYPE'],
        'PROPERTY_KOMMENTARIY_DLYA_DORABOTKI','PROPERTY_'.$config['REQ_PROP_LINKED_REQUESTS']
    ];
    $res = CIBlockElement::GetList([], ['IBLOCK_ID' => (int)$config['IBLOCK_REQUESTS'], 'ID' => $requestId], false, false, $select);
    $item = $res->Fetch();
    if (!$item) return null;
    $linked = $item['PROPERTY_'.$config['REQ_PROP_LINKED_REQUESTS'].'_VALUE'] ?? [];
    if (!empty($linked)) return null;
    return $item;
}

$request = Context::getCurrent()->getRequest();
global $USER;
$currentUserId = (int)$USER->GetID();
$requestId = (int)$request->getQuery('id');
$item = overtimeRefineLoadRequest($requestId, $overtimeConfig);

if ($request->isPost() && $request->getPost('ajax_action') === 'preview') {
    header('Content-Type: application/json; charset=' . LANG_CHARSET);
    $payload = Json::decode((string)$request->getPost('payload'));
    echo Json::encode(overtimeBuildModePreview('single', is_array($payload) ? $payload : [], $overtimeConfig));
    die();
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Доработка заявки');
if (!$item) { ShowError('Заявка не найдена или является связанной.'); require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); return; }

$task = overtimeRefineFindTask($requestId, $currentUserId, (int)$overtimeConfig['IBLOCK_REQUESTS']);
if (!$task) { ShowError('У вас нет активного задания на доработку по этой заявке.'); require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); return; }

$employee = overtimeGetUserDataById((int)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_EMPLOYEE'].'_VALUE']);
$workTypeId = (int)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_TYPE'].'_VALUE'];
$isWeekendType = $workTypeId === (int)$overtimeConfig['WORK_TYPE_WEEKEND_ID'];
$workTypeName = $isWeekendType ? 'Заявка на работу в выходной день' : 'Заявка на сверхурочную работу';
$error = '';

if ($request->isPost() && $request->getPost('action') === 'save_refine' && check_bitrix_sessid()) {
    $action = (string)$request->getPost('task_action');
    if ($action === 'approve') {
        $dateStart = (string)$request->getPost('date_start');
        $dateEnd = (string)$request->getPost('date_end');
        $isStartWorkday = overtimeIsWorkday1C($dateStart);
        $isEndWorkday = overtimeIsWorkday1C($dateEnd);
        if ($isWeekendType && ($isStartWorkday || $isEndWorkday)) { $error = 'Для заявки на работу в выходной день можно выбирать только выходные/праздничные дни.'; }
        if (!$isWeekendType && (!$isStartWorkday || !$isEndWorkday || $dateStart !== $dateEnd)) { $error = 'Для сверхурочной заявки выбирается только рабочий день, дата начала и окончания должна совпадать.'; }

        if ($error === '') {
            $fields = [
                $overtimeConfig['REQ_PROP_WORK_START_DATE'] => $dateStart,
                $overtimeConfig['REQ_PROP_WORK_END_DATE'] => $dateEnd,
                $overtimeConfig['REQ_PROP_WORK_START_TIME'] => (string)$request->getPost('time_start'),
                $overtimeConfig['REQ_PROP_WORK_END_TIME'] => (string)$request->getPost('time_end'),
                $overtimeConfig['REQ_PROP_JUSTIFICATION'] => (string)$request->getPost('justification'),
                $overtimeConfig['REQ_PROP_PAYMENT_TYPE'] => (string)$request->getPost('payment_type'),
            ];
            CIBlockElement::SetPropertyValuesEx($requestId, (int)$overtimeConfig['IBLOCK_REQUESTS'], $fields);
        }
    }
    if ($error === '') {
        $done = overtimeRefineCompleteTask($task, $currentUserId, $action === 'approve' ? 'approve' : 'reject');
        if (!empty($done['OK'])) { LocalRedirect('/forms/hr_administration/overtime/view.php?id=' . $requestId); }
        $error = (string)($done['ERROR'] ?? 'Не удалось завершить задание БП');
    }
}
$hours = overtimeGetHourOptions();
?>
<style>.overtime-wrap{max-width:1000px;margin:0 auto}.overtime-box{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px}.overtime-grid-4{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px}.overtime-field{margin-bottom:14px}.overtime-field label{display:block;font-weight:600;margin-bottom:6px}.overtime-field input,.overtime-field select,.overtime-field textarea{width:100%;padding:9px 10px;box-sizing:border-box}.overtime-alert{padding:12px;border-radius:6px;margin-bottom:12px;background:#fff1f0;border:1px solid #ffb3b3}.overtime-preview-box{margin-top:12px;padding:12px;background:#fafbfc;border:1px solid #e8eaed;border-radius:6px}</style>
<div class="overtime-wrap"><div class="overtime-box">
<?php if ($error !== ''): ?><div class="overtime-alert"><?=overtimeH($error)?></div><?php endif; ?>
<form method="post" id="refine-form"><?=bitrix_sessid_post()?>
<input type="hidden" name="action" value="save_refine"><input type="hidden" name="task_action" id="task_action" value="">
<div class="overtime-field"><label>Сотрудник</label><input type="text" value="<?=overtimeH($employee['display'])?>" readonly></div>
<div class="overtime-field"><label>Тип заявки</label><input type="text" value="<?=overtimeH($workTypeName)?>" readonly></div>
<div class="overtime-field"><label>Комментарий по доработке</label><textarea readonly rows="2"><?=overtimeH((string)($item['PROPERTY_KOMMENTARIY_DLYA_DORABOTKI_VALUE'] ?? ''))?></textarea></div>
<div class="overtime-grid-4">
<div class="overtime-field"><label>Дата начала</label><input type="date" id="date_start" name="date_start" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_START_DATE'].'_VALUE'])?>"></div>
<div class="overtime-field"><label>Время начала</label><select name="time_start" id="time_start"><?php foreach($hours as $h): ?><option value="<?=overtimeH($h)?>" <?=$h===(string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_START_TIME'].'_VALUE']?'selected':''?>><?=overtimeH($h)?></option><?php endforeach;?></select></div>
<div class="overtime-field"><label>Дата окончания</label><input type="date" id="date_end" name="date_end" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_END_DATE'].'_VALUE'])?>"></div>
<div class="overtime-field"><label>Время окончания</label><select name="time_end" id="time_end"><?php foreach($hours as $h): ?><option value="<?=overtimeH($h)?>" <?=$h===(string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_WORK_END_TIME'].'_VALUE']?'selected':''?>><?=overtimeH($h)?></option><?php endforeach;?></select></div>
</div>
<div class="overtime-field"><label>Обоснование</label><textarea id="justification" name="justification" rows="3"><?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_JUSTIFICATION'].'_VALUE'])?></textarea></div>
<div class="overtime-field"><label>Тип оплаты</label><input type="text" id="payment_type" name="payment_type" value="<?=overtimeH((string)$item['PROPERTY_'.$overtimeConfig['REQ_PROP_PAYMENT_TYPE'].'_VALUE'])?>"></div>
<div class="overtime-preview-box"><h3>Предпросмотр</h3><div id="single_preview"></div></div>
<button type="button" class="ui-btn ui-btn-primary" id="approve_btn">Согласовать</button>
<button type="button" class="ui-btn ui-btn-light-border" id="reject_btn">Отклонить</button>
</form></div></div>
<script>
BX.ready(function(){
 const isWeekendType = <?= $isWeekendType ? 'true' : 'false' ?>;
 function isWeekend(d){const day=(new Date(d+'T00:00:00')).getDay();return day===0||day===6;}
 function validateDates(){
   const ds=document.getElementById('date_start').value,de=document.getElementById('date_end').value;
   if(!ds||!de){return true;}
   if(isWeekendType){ if(!isWeekend(ds)||!isWeekend(de)){alert('Разрешены только выходные/праздничные дни.'); return false;} }
   else { if(isWeekend(ds)||isWeekend(de)||ds!==de){alert('Для сверхурочной заявки только рабочий день и одинаковые даты начала/окончания.'); return false;} }
   return true;
 }
 function preview(){
   const payload={single:{employee_id:'<?= (int)$employee['id'] ?>',date_start:date_start.value,time_start:time_start.value,date_end:date_end.value,time_end:time_end.value,justification:justification.value,payment_type:{0:payment_type.value}}};
   BX.ajax.post(location.pathname+'?id=<?= (int)$requestId ?>',{ajax_action:'preview',payload:JSON.stringify(payload),sessid:BX.bitrix_sessid()},function(r){try{const j=JSON.parse(r);document.getElementById('single_preview').innerHTML=(j.single&&j.single.preview_html)?j.single.preview_html:'';}catch(e){}});
 }
 ['date_start','date_end','time_start','time_end','justification','payment_type'].forEach(id=>document.getElementById(id).addEventListener('change',preview)); preview();
 document.getElementById('approve_btn').onclick=function(){if(!validateDates())return;document.getElementById('task_action').value='approve';document.getElementById('refine-form').submit();};
 document.getElementById('reject_btn').onclick=function(){document.getElementById('task_action').value='reject';document.getElementById('refine-form').submit();};
});
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
