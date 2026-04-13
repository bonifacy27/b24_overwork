<script>
BX.ready(function () {
    const form = document.getElementById('overtime-form');
    const modeInput = document.getElementById('mode');
    const modeTabs = document.querySelectorAll('.overtime-mode-tab');
    const initialPaymentState = window.overtimeInitialPaymentState || {single:{}, rows_same:{}, rows_diff:{}};
    const creatableEmployeeIds = new Set((window.overtimeCreatableEmployeeIds || []).map(function(id){ return String(id); }));
    const retroAllowedEmployeeIds = new Set((window.overtimeRetroAllowedEmployeeIds || []).map(function(id){ return String(id); }));
    const todayDate = String(window.overtimeToday || '');
    const canCreateRequests = !!window.overtimeCanCreateRequests;
    const confirmInput = document.getElementById('confirm_create');
    const modalOverlay = document.getElementById('overtime-confirm-overlay');
    const modalContent = document.getElementById('overtime-confirm-content');
    const modalClose = document.getElementById('overtime-confirm-close');
    const modalCancel = document.getElementById('overtime-confirm-cancel');
    const modalSubmit = document.getElementById('overtime-confirm-submit');
    const isDebug = <?= !empty($overtimeConfig['DEBUG']) ? 'true' : 'false' ?>;
    const dutyAllowed = <?= !empty($overtimeConfig['ALLOW_DUTY']) ? 'true' : 'false' ?>;

    let lastPreviewResponse = null;
    let previewTimer = null;

    function canUsePastDates(employeeId) {
        return retroAllowedEmployeeIds.has(String(employeeId || '0'));
    }

    function applyMinDateToInput(input, minDate) {
        if (!input) {
            return;
        }

        if (minDate) {
            input.setAttribute('min', minDate);
        } else {
            input.removeAttribute('min');
        }
    }

    function updateDateConstraints() {
        if (!todayDate) {
            return;
        }

        if (modeInput.value === 'single') {
            const employeeInput = document.getElementById('single_employee_id');
            const minDate = canUsePastDates(employeeInput ? employeeInput.value : '0') ? '' : todayDate;
            applyMinDateToInput(document.getElementById('single_date_start'), minDate);
            applyMinDateToInput(document.getElementById('single_date_end'), minDate);
            return;
        }

        if (modeInput.value === 'multi_same') {
            const employeeInputs = document.querySelectorAll('input[name^="rows_same"][name$="[employee_id]"]');
            let allAllowPast = employeeInputs.length > 0;

            employeeInputs.forEach(function(input) {
                if (!input.value || input.value === '0' || !canUsePastDates(input.value)) {
                    allAllowPast = false;
                }
            });

            const minDate = allAllowPast ? '' : todayDate;
            applyMinDateToInput(document.getElementById('same_date_start'), minDate);
            applyMinDateToInput(document.getElementById('same_date_end'), minDate);
            return;
        }

        document.querySelectorAll('.diff-row').forEach(function(row) {
            const employeeInput = row.querySelector('input[name*="[employee_id]"]');
            const minDate = canUsePastDates(employeeInput ? employeeInput.value : '0') ? '' : todayDate;
            applyMinDateToInput(row.querySelector('.diff-date-start'), minDate);
            applyMinDateToInput(row.querySelector('.diff-date-end'), minDate);
        });
    }

    function escapeHtml(text) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return String(text || '').replace(/[&<>"']/g, function(m){ return map[m]; });
    }

    function showElement(el) {
        if (!el) {
            return;
        }

        el.classList.remove('overtime-hidden');
        el.style.display = '';
    }

    function hideElement(el) {
        if (!el) {
            return;
        }

        el.classList.add('overtime-hidden');
        el.style.display = 'none';
    }

    function isElementVisible(el) {
        if (!el) {
            return false;
        }

        return !el.classList.contains('overtime-hidden')
            && window.getComputedStyle(el).display !== 'none';
    }

    function getModeBlocks() {
        return {
            single: document.getElementById('mode-single'),
            multi_same: document.getElementById('mode-multi-same'),
            multi_diff: document.getElementById('mode-multi-diff')
        };
    }

    function setBlockDisabled(block, disabled) {
        if (!block) {
            return;
        }

        const fields = block.querySelectorAll('input, select, textarea, button');
        fields.forEach(function(field) {
            if (field.id === 'create-btn') {
                return;
            }

            if (!field.dataset.originalDisabled) {
                field.dataset.originalDisabled = field.disabled ? 'Y' : 'N';
            }

            if (disabled) {
                field.disabled = true;
            } else {
                field.disabled = (field.dataset.originalDisabled === 'Y');
            }
        });
    }

    function syncActiveModeFields(mode) {
        const blocks = getModeBlocks();

        Object.keys(blocks).forEach(function(key) {
            const isActive = key === mode;
            blocks[key].classList.toggle('overtime-hidden', !isActive);
            setBlockDisabled(blocks[key], !isActive);
        });

        modeInput.disabled = false;

        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) {
            actionInput.disabled = false;
        }

        const sessidInput = form.querySelector('input[name="sessid"]');
        if (sessidInput) {
            sessidInput.disabled = false;
        }

        if (confirmInput) {
            confirmInput.disabled = false;
        }
    }

    function getModeDutyCheckbox() {
        if (!dutyAllowed) {
            return null;
        }
        if (modeInput.value === 'single') {
            return document.getElementById('single_is_duty');
        }
        if (modeInput.value === 'multi_same') {
            return document.getElementById('same_is_duty');
        }
        return document.getElementById('diff_is_duty');
    }

    function toggleFileVisibility() {
        const isDuty = !!(getModeDutyCheckbox() && getModeDutyCheckbox().checked);

        const singleFileWrap = document.getElementById('single_justification_file_wrap');
        const sameFileWrap = document.getElementById('common_justification_file_wrap_same');
        const diffFileWrap = document.getElementById('common_justification_file_wrap_diff');

        if (singleFileWrap) {
            singleFileWrap.style.display = (modeInput.value === 'single' && isDuty) ? '' : 'none';
        }
        if (sameFileWrap) {
            sameFileWrap.style.display = (modeInput.value === 'multi_same' && isDuty) ? '' : 'none';
        }
        if (diffFileWrap) {
            diffFileWrap.style.display = (modeInput.value === 'multi_diff' && isDuty) ? '' : 'none';
        }
    }

    function hideAllLateWarningBlocks() {
        const ids = [
            'single_late_warning_box',
            'single_late_ack_wrap',
            'common_late_warning_box_same',
            'common_late_ack_wrap_same',
            'common_late_warning_box_diff',
            'common_late_ack_wrap_diff'
        ];

        ids.forEach(function(id){
            const el = document.getElementById(id);
            hideElement(el);
        });
    }

    function updateLateWarningUiFromPreview(response) {
        hideAllLateWarningBlocks();

        if (!response) {
            return;
        }

        if (modeInput.value === 'single' && response.single) {
            const item = response.single;
            if (item.late_warning_required) {
                const box = document.getElementById('single_late_warning_box');
                const text = document.getElementById('single_late_warning_text');
                const ack = document.getElementById('single_late_ack_wrap');

                if (box && text) {
                    text.textContent = item.late_warning_text || '';
                    showElement(box);
                }
                if (ack) {
                    showElement(ack);
                }
            }
        }

        if (modeInput.value === 'multi_same' && response.rows) {
            let need = false;
            let textValue = '';

            Object.keys(response.rows).forEach(function(idx){
                if (response.rows[idx] && response.rows[idx].late_warning_required) {
                    need = true;
                    textValue = response.rows[idx].late_warning_text || '';
                }
            });

            if (need) {
                const box = document.getElementById('common_late_warning_box_same');
                const text = document.getElementById('common_late_warning_text_same');
                const ack = document.getElementById('common_late_ack_wrap_same');

                if (box && text) {
                    text.textContent = textValue;
                    showElement(box);
                }
                if (ack) {
                    showElement(ack);
                }
            }
        }

        if (modeInput.value === 'multi_diff' && response.rows) {
            let need = false;
            let textValue = '';

            Object.keys(response.rows).forEach(function(idx){
                if (response.rows[idx] && response.rows[idx].late_warning_required) {
                    need = true;
                    textValue = response.rows[idx].late_warning_text || '';
                }
            });

            if (need) {
                const box = document.getElementById('common_late_warning_box_diff');
                const text = document.getElementById('common_late_warning_text_diff');
                const ack = document.getElementById('common_late_ack_wrap_diff');

                if (box && text) {
                    text.textContent = textValue;
                    showElement(box);
                }
                if (ack) {
                    showElement(ack);
                }
            }
        }
    }

    function switchMode(mode) {
        modeInput.value = mode;
        syncActiveModeFields(mode);

        modeTabs.forEach(function(tab){
            tab.classList.toggle('active', tab.dataset.mode === mode);
        });

        toggleFileVisibility();
        updateDateConstraints();
        requestPreview();
    }

    modeTabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            switchMode(tab.dataset.mode);
        });
    });

    function setEmployeeInfo(box, title, position) {
        const inputName = box.dataset.input || '';
        const infoId = inputName.replace('_id', '_info');
        const info = document.getElementById(infoId);
        if (info) {
            info.textContent = [title, position].filter(Boolean).join(' — ');
        }

        const titleInput = document.getElementById(box.dataset.title);
        if (titleInput) {
            titleInput.value = title || '';
        }

        const positionInput = document.getElementById(box.dataset.position);
        if (positionInput) {
            positionInput.value = position || '';
        }
    }

    function initSelector(box) {
        if (!box || box.dataset.inited === 'Y') {
            return;
        }
        box.dataset.inited = 'Y';

        const inputId = box.dataset.input;
        const input = document.getElementById(inputId);

        if (!input || !BX.UI || !BX.UI.EntitySelector || !BX.UI.EntitySelector.Dialog) {
            console.error('EntitySelector.Dialog недоступен');
            return;
        }

        const dialog = new BX.UI.EntitySelector.Dialog({
            targetNode: box,
            multiple: false,
            enableSearch: true,
            context: 'OVERTIME_EMPLOYEE_SELECTOR_' + inputId,
            entities: [{ id: 'user' }],
            events: {
                'Item:onSelect': function(event) {
                    const item = event.getData().item;
                    const title = typeof item.getTitle === 'function' ? item.getTitle() : '';
                    const subtitle = typeof item.getSubtitle === 'function' ? item.getSubtitle() : '';
                    const selectedId = String(item.getId());

                    if (!creatableEmployeeIds.has(selectedId)) {
                        alert('Можно выбирать только подчиненных сотрудников руководителя/заместителя.');
                        return;
                    }

                    input.value = selectedId;
                    box.innerHTML = escapeHtml(title || 'Выбран сотрудник');
                    setEmployeeInfo(box, title, subtitle);
                    updateDateConstraints();
                    requestPreview();
                },
                'Item:onDeselect': function() {
                    input.value = '';
                    box.innerHTML = 'Выберите сотрудника';
                    setEmployeeInfo(box, '', '');
                    updateDateConstraints();
                    requestPreview();
                }
            }
        });

        box.addEventListener('click', function () {
            dialog.show();
        });
    }

    document.querySelectorAll('.overtime-selector-single, .overtime-selector-row').forEach(initSelector);

    function getCurrentSelectValue(name) {
        const input = form.querySelector('[name="' + CSS.escape(name) + '"]');
        return input ? input.value : '';
    }

    function getSelectedPaymentValue(rowName, index) {
        const inputName = rowName + '[payment_type][' + index + ']';
        const currentValue = getCurrentSelectValue(inputName);
        if (currentValue !== '') {
            return currentValue;
        }

        if (rowName === 'single') {
            return (initialPaymentState.single && initialPaymentState.single[index] !== undefined)
                ? String(initialPaymentState.single[index])
                : '';
        }

        const sameMatch = rowName.match(/^rows_same\[(\d+)\]$/);
        if (sameMatch) {
            const rowIndex = sameMatch[1];
            return (initialPaymentState.rows_same[rowIndex] && initialPaymentState.rows_same[rowIndex][index] !== undefined)
                ? String(initialPaymentState.rows_same[rowIndex][index])
                : '';
        }

        const diffMatch = rowName.match(/^rows_diff\[(\d+)\]$/);
        if (diffMatch) {
            const rowIndex = diffMatch[1];
            return (initialPaymentState.rows_diff[rowIndex] && initialPaymentState.rows_diff[rowIndex][index] !== undefined)
                ? String(initialPaymentState.rows_diff[rowIndex][index])
                : '';
        }

        return '';
    }

    function buildSplitWarning(item) {
        if (!item || !item.split_warning_required || !item.split_warning_text) {
            return '';
        }

        return '<div class="overtime-alert overtime-alert-warning">' + escapeHtml(item.split_warning_text) + '</div>';
    }

    function buildSegmentsTable(rowName, segments) {
        if (!segments || !segments.length) {
            return '';
        }

        let html = '<table class="overtime-table"><thead><tr><th>№</th><th>Тип</th><th>Начало</th><th>Окончание</th><th>Часы</th><th>Тип оплаты</th></tr></thead><tbody>';

        segments.forEach(function(segment, index){
            const selectedValue = getSelectedPaymentValue(rowName, index);

            html += '<tr>';
            html += '<td>' + (index + 1) + '</td>';
            html += '<td>' + escapeHtml(segment.type_name) + '</td>';
            html += '<td>' + escapeHtml(segment.start) + '</td>';
            html += '<td>' + escapeHtml(segment.end) + '</td>';
            html += '<td>' + escapeHtml(segment.hours) + '</td>';
            html += '<td>';

            if (segment.payment_types && segment.payment_types.length) {
                html += '<select name="' + rowName + '[payment_type][' + index + ']">';
                html += '<option value="">Выберите тип оплаты</option>';
                segment.payment_types.forEach(function(payment){
                    const isSelected = selectedValue !== '' && String(payment.ID) === String(selectedValue);
                    html += '<option value="' + payment.ID + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(payment.NAME) + '</option>';
                });
                html += '</select>';
            } else {
                html += '<span style="color:#c00;">Нет типов оплаты</span>';
            }

            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function buildMessages(messages) {
        if (!messages || !messages.length) {
            return '';
        }

        return messages.map(function(message){
            const cls = message.type === 'warning'
                ? 'overtime-alert-warning'
                : (message.type === 'error' ? 'overtime-alert-error' : 'overtime-alert-success');

            return '<div class="overtime-alert ' + cls + '">' + escapeHtml(message.text) + '</div>';
        }).join('');
    }

    function buildErrors(errors) {
        if (!errors || !errors.length) {
            return '';
        }

        return errors.map(function(error){
            return '<div class="overtime-alert overtime-alert-error">' + escapeHtml(error) + '</div>';
        }).join('');
    }

    function collectPayload() {
        const mode = modeInput.value;

        if (mode === 'single') {
            return {
                mode: mode,
                single: {
                    employee_id: document.getElementById('single_employee_id').value,
                    date_start: document.getElementById('single_date_start').value,
                    time_start: document.getElementById('single_time_start').value,
                    date_end: document.getElementById('single_date_end').value,
                    time_end: document.getElementById('single_time_end').value,
                    is_duty: (dutyAllowed && document.getElementById('single_is_duty') && document.getElementById('single_is_duty').checked) ? 'Y' : 'N'
                }
            };
        }

        if (mode === 'multi_same') {
            const rows = [];
            document.querySelectorAll('.same-row').forEach(function(row){
                const index = row.dataset.index;
                rows.push({
                    employee_id: document.getElementById('rows_same_' + index + '_employee_id').value
                });
            });

            return {
                mode: mode,
                common: {
                    date_start: document.getElementById('same_date_start').value,
                    time_start: document.getElementById('same_time_start').value,
                    date_end: document.getElementById('same_date_end').value,
                    time_end: document.getElementById('same_time_end').value,
                    is_duty: (dutyAllowed && document.getElementById('same_is_duty') && document.getElementById('same_is_duty').checked) ? 'Y' : 'N'
                },
                rows: rows
            };
        }

        const rows = [];
        document.querySelectorAll('.diff-row').forEach(function(row){
            const index = row.dataset.index;
            rows.push({
                employee_id: document.getElementById('rows_diff_' + index + '_employee_id').value,
                date_start: row.querySelector('.diff-date-start').value,
                time_start: row.querySelector('.diff-time-start').value,
                date_end: row.querySelector('.diff-date-end').value,
                time_end: row.querySelector('.diff-time-end').value
            });
        });

        return {
            mode: mode,
            common: {
                is_duty: (dutyAllowed && document.getElementById('diff_is_duty') && document.getElementById('diff_is_duty').checked) ? 'Y' : 'N'
            },
            rows: rows
        };
    }

    function renderSinglePreview(single) {
        const target = document.getElementById('single_preview');
        if (!target) {
            return;
        }
        if (!single) {
            target.innerHTML = '';
            return;
        }

        let html = '';
        html += buildErrors(single.errors || []);
        html += buildSplitWarning(single);
        html += buildSegmentsTable('single', single.segments || []);
        target.innerHTML = html;
    }

    function renderRowsPreview(mode, rows) {
        Object.keys(rows || {}).forEach(function(index){
            const item = rows[index];
            const targetId = mode === 'multi_same' ? 'same_preview_' + index : 'diff_preview_' + index;
            const target = document.getElementById(targetId);
            if (!target) {
                return;
            }

            const namePrefix = mode === 'multi_same' ? 'rows_same[' + index + ']' : 'rows_diff[' + index + ']';
            let html = '';
            html += buildErrors(item.errors || []);
            html += buildSplitWarning(item);
            html += buildSegmentsTable(namePrefix, item.segments || []);
            target.innerHTML = html;
        });
    }

    function buildDebugBreakdownHtml(segment) {
        if (!isDebug || !segment || !segment.debug_payment_breakdown || !segment.debug_payment_breakdown.rows || !segment.debug_payment_breakdown.rows.length) {
            return '';
        }

        let html = '<div style="margin-top:8px;">';
        html += '<div style="font-weight:600; margin:6px 0;">Расчет часов для оплаты</div>';
        html += '<table class="overtime-table overtime-compact-table">';
        html += '<thead><tr><th>Показатель</th><th>Часы</th><th>Интервал</th><th>Основание</th></tr></thead><tbody>';

        segment.debug_payment_breakdown.rows.forEach(function(row) {
            html += '<tr>';
            html += '<td>' + escapeHtml(row.title) + '</td>';
            html += '<td>' + escapeHtml(row.hours) + '</td>';
            html += '<td>' + escapeHtml(row.interval) + '</td>';
            html += '<td>' + escapeHtml(row.basis) + '</td>';
            html += '</tr>';
        });

        if (segment.debug_payment_breakdown.summary && segment.debug_payment_breakdown.summary.length) {
            segment.debug_payment_breakdown.summary.forEach(function(row) {
                html += '<tr>';
                html += '<td><strong>' + escapeHtml(row.title) + '</strong></td>';
                html += '<td><strong>' + escapeHtml(row.hours) + '</strong></td>';
                html += '<td>' + escapeHtml(row.interval) + '</td>';
                html += '<td>' + escapeHtml(row.basis) + '</td>';
                html += '</tr>';
            });
        }

        html += '</tbody></table>';
        html += '</div>';
        return html;
    }

    function requestPreview() {
        if (previewTimer) {
            clearTimeout(previewTimer);
        }

        previewTimer = setTimeout(function(){
            const payload = collectPayload();

            BX.ajax({
                url: window.location.pathname,
                method: 'POST',
                dataType: 'json',
                data: {
                    sessid: BX.bitrix_sessid(),
                    ajax_action: 'preview',
                    mode: payload.mode,
                    payload: JSON.stringify(payload)
                },
                onsuccess: function(response){
                    toggleFileVisibility();
                    lastPreviewResponse = response;
                    updateLateWarningUiFromPreview(response);

                    if (!response || (response.success === false && response.errors)) {
                        if (payload.mode === 'single') {
                            const singlePreview = document.getElementById('single_preview');
                            if (singlePreview) {
                                singlePreview.innerHTML = buildErrors((response && response.errors) || ['Ошибка предпросмотра']);
                            }
                        }
                        return;
                    }

                    if (payload.mode === 'single') {
                        renderSinglePreview(response.single || {});
                    } else if (payload.mode === 'multi_same') {
                        renderRowsPreview('multi_same', response.rows || {});
                    } else {
                        renderRowsPreview('multi_diff', response.rows || {});
                    }
                },
                onfailure: function(xhr){
                    toggleFileVisibility();
                    const text = xhr && xhr.responseText ? xhr.responseText : 'Ошибка AJAX';
                    if (modeInput.value === 'single') {
                        const singlePreview = document.getElementById('single_preview');
                        if (singlePreview) {
                            singlePreview.innerHTML = buildErrors([text]);
                        }
                    }
                }
            });
        }, 250);
    }

    function bindDynamicPreviewEvents(scope) {
        scope.querySelectorAll('input, select, textarea').forEach(function(el){
            el.addEventListener('change', requestPreview);
        });
    }

    function getLabelTextBySelectName(selectName) {
        const select = form.querySelector('[name="' + CSS.escape(selectName) + '"]');
        if (!select || !select.value) {
            return '';
        }

        const option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : '';
    }

    function collectDebugMessagesForModal() {
        if (!isDebug || !lastPreviewResponse) {
            return '';
        }

        let messagesHtml = '';

        if (modeInput.value === 'single' && lastPreviewResponse.single) {
            messagesHtml += buildMessages(lastPreviewResponse.single.all_check_messages || []);
        }

        if (modeInput.value === 'multi_same' && lastPreviewResponse.rows) {
            Object.keys(lastPreviewResponse.rows).forEach(function(idx) {
                const employee = document.getElementById('rows_same_' + idx + '_employee_info');
                const employeeText = employee ? employee.textContent.trim() : '';
                const messages = lastPreviewResponse.rows[idx].all_check_messages || [];
                if (messages.length) {
                    messagesHtml += '<div style="margin-top:10px; font-weight:600;">' + escapeHtml(employeeText) + '</div>';
                    messagesHtml += buildMessages(messages);
                }
            });
        }

        if (modeInput.value === 'multi_diff' && lastPreviewResponse.rows) {
            Object.keys(lastPreviewResponse.rows).forEach(function(idx) {
                const employee = document.getElementById('rows_diff_' + idx + '_employee_info');
                const employeeText = employee ? employee.textContent.trim() : '';
                const messages = lastPreviewResponse.rows[idx].all_check_messages || [];
                if (messages.length) {
                    messagesHtml += '<div style="margin-top:10px; font-weight:600;">' + escapeHtml(employeeText) + '</div>';
                    messagesHtml += buildMessages(messages);
                }
            });
        }

        if (messagesHtml !== '') {
            messagesHtml = '<div style="margin-bottom:12px;"><div style="font-weight:600; margin-bottom:8px;">Информация об ограничениях</div>' + messagesHtml + '</div>';
        }

        return messagesHtml;
    }

    function validatePaymentTypesBeforeConfirm() {
        const errors = [];
        const selects = form.querySelectorAll('select[name*="[payment_type]"]');

        selects.forEach(function(select){
            if (select.disabled) {
                return;
            }
            if (!select.value) {
                errors.push('Не выбран тип оплаты для одной или нескольких заявок.');
            }
        });

        return errors;
    }

    function validateLateAckBeforeConfirm() {
        const errors = [];

        if (modeInput.value === 'single') {
            const wrap = document.getElementById('single_late_ack_wrap');
            const cb = document.getElementById('single_late_ack');
            if (isElementVisible(wrap) && cb && !cb.checked) {
                errors.push('Не установлен обязательный флажок ознакомления с условием о подаче заявки менее чем за 2 рабочих дня.');
            }
        }

        if (modeInput.value === 'multi_same') {
            const wrap = document.getElementById('common_late_ack_wrap_same');
            const cb = document.getElementById('common_late_ack_same');
            if (isElementVisible(wrap) && cb && !cb.checked) {
                errors.push('Не установлен обязательный флажок ознакомления с условием о подаче заявки менее чем за 2 рабочих дня.');
            }
        }

        if (modeInput.value === 'multi_diff') {
            const wrap = document.getElementById('common_late_ack_wrap_diff');
            const cb = document.getElementById('common_late_ack_diff');
            if (isElementVisible(wrap) && cb && !cb.checked) {
                errors.push('Не установлен обязательный флажок ознакомления с условием о подаче заявки менее чем за 2 рабочих дня.');
            }
        }

        return errors;
    }

    function buildConfirmTableHtml() {
        const rows = [];
        const mode = modeInput.value;

        function pushRow(employee, segment, payment, extraHtml) {
            rows.push({
                employee: employee,
                type: segment.type_name || '',
                start: segment.start || '',
                end: segment.end || '',
                hours: segment.hours || '',
                payment: payment || '',
                extraHtml: extraHtml || ''
            });
        }

        if (mode === 'single' && lastPreviewResponse && lastPreviewResponse.single) {
            const employeeNode = document.getElementById('single_employee_info');
            const employee = employeeNode ? employeeNode.textContent.trim() : '';
            (lastPreviewResponse.single.segments || []).forEach(function(segment, idx){
                const paymentName = getLabelTextBySelectName('single[payment_type][' + idx + ']');
                pushRow(employee, segment, paymentName, buildDebugBreakdownHtml(segment));
            });
        }

        if (mode === 'multi_same' && lastPreviewResponse && lastPreviewResponse.rows) {
            Object.keys(lastPreviewResponse.rows).forEach(function(idx) {
                const employeeNode = document.getElementById('rows_same_' + idx + '_employee_info');
                const employee = employeeNode ? employeeNode.textContent.trim() : '';
                (lastPreviewResponse.rows[idx].segments || []).forEach(function(segment, segIdx){
                    const paymentName = getLabelTextBySelectName('rows_same[' + idx + '][payment_type][' + segIdx + ']');
                    pushRow(employee, segment, paymentName, buildDebugBreakdownHtml(segment));
                });
            });
        }

        if (mode === 'multi_diff' && lastPreviewResponse && lastPreviewResponse.rows) {
            Object.keys(lastPreviewResponse.rows).forEach(function(idx) {
                const employeeNode = document.getElementById('rows_diff_' + idx + '_employee_info');
                const employee = employeeNode ? employeeNode.textContent.trim() : '';
                (lastPreviewResponse.rows[idx].segments || []).forEach(function(segment, segIdx){
                    const paymentName = getLabelTextBySelectName('rows_diff[' + idx + '][payment_type][' + segIdx + ']');
                    pushRow(employee, segment, paymentName, buildDebugBreakdownHtml(segment));
                });
            });
        }

        if (!rows.length) {
            return '<div class="overtime-alert overtime-alert-error">Нет заявок для создания. Проверьте заполнение формы и типы оплаты.</div>';
        }

        let html = collectDebugMessagesForModal();

        html += '<table class="overtime-table overtime-compact-table">';
        html += '<thead><tr><th>Сотрудник</th><th>Тип заявки</th><th>Начало</th><th>Окончание</th><th>Часы</th><th>Тип оплаты</th></tr></thead><tbody>';

        rows.forEach(function(row){
            html += '<tr>';
            html += '<td>' + escapeHtml(row.employee) + '</td>';
            html += '<td>' + escapeHtml(row.type) + '</td>';
            html += '<td>' + escapeHtml(row.start) + '</td>';
            html += '<td>' + escapeHtml(row.end) + '</td>';
            html += '<td>' + escapeHtml(row.hours) + '</td>';
            html += '<td>' + escapeHtml(row.payment) + '</td>';
            html += '</tr>';

            if (row.extraHtml) {
                html += '<tr><td colspan="6">' + row.extraHtml + '</td></tr>';
            }
        });

        html += '</tbody></table>';
        return html;
    }

    function openConfirmModal() {
        const errors = []
            .concat(validatePaymentTypesBeforeConfirm())
            .concat(validateLateAckBeforeConfirm());

        if (errors.length) {
            alert(errors.join('\n'));
            return;
        }

        modalContent.innerHTML = buildConfirmTableHtml();
        modalOverlay.style.display = 'flex';
    }

    function closeConfirmModal() {
        modalOverlay.style.display = 'none';
    }

    if (modalClose) {
        modalClose.addEventListener('click', closeConfirmModal);
    }
    if (modalCancel) {
        modalCancel.addEventListener('click', closeConfirmModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) {
                closeConfirmModal();
            }
        });
    }

    if (modalSubmit) {
        modalSubmit.addEventListener('click', function(){
            const errors = []
                .concat(validatePaymentTypesBeforeConfirm())
                .concat(validateLateAckBeforeConfirm());

            if (errors.length) {
                alert(errors.join('\n'));
                return;
            }

            confirmInput.value = 'Y';
            closeConfirmModal();
            form.submit();
        });
    }

    form.addEventListener('submit', function(e){
        if (confirmInput.value !== 'Y') {
            e.preventDefault();
            openConfirmModal();
        }
    });

    bindDynamicPreviewEvents(document);

    function rebuildSameIndexes() {
        document.querySelectorAll('.same-row').forEach(function(row, idx){
            row.dataset.index = idx;
            row.querySelector('.overtime-row-header strong').textContent = 'Сотрудник #' + (idx + 1);

            const selector = row.querySelector('.overtime-selector-row');
            selector.dataset.input = 'rows_same_' + idx + '_employee_id';
            selector.dataset.title = 'rows_same_' + idx + '_employee_title';
            selector.dataset.position = 'rows_same_' + idx + '_employee_position';
            selector.dataset.inited = 'N';

            row.querySelector('input[type="hidden"][id$="_employee_id"]').id = 'rows_same_' + idx + '_employee_id';
            row.querySelector('input[type="hidden"][id$="_employee_title"]').id = 'rows_same_' + idx + '_employee_title';
            row.querySelector('input[type="hidden"][id$="_employee_position"]').id = 'rows_same_' + idx + '_employee_position';
            row.querySelector('input[name*="[employee_id]"]').name = 'rows_same[' + idx + '][employee_id]';
            row.querySelector('.overtime-user-info').id = 'rows_same_' + idx + '_employee_info';
            row.querySelector('.row-preview').id = 'same_preview_' + idx;

            initSelector(selector);
        });
    }

    function rebuildDiffIndexes() {
        document.querySelectorAll('.diff-row').forEach(function(row, idx){
            row.dataset.index = idx;
            row.querySelector('.overtime-row-header strong').textContent = 'Строка #' + (idx + 1);

            const selector = row.querySelector('.overtime-selector-row');
            selector.dataset.input = 'rows_diff_' + idx + '_employee_id';
            selector.dataset.title = 'rows_diff_' + idx + '_employee_title';
            selector.dataset.position = 'rows_diff_' + idx + '_employee_position';
            selector.dataset.inited = 'N';

            row.querySelector('input[type="hidden"][id$="_employee_id"]').id = 'rows_diff_' + idx + '_employee_id';
            row.querySelector('input[type="hidden"][id$="_employee_title"]').id = 'rows_diff_' + idx + '_employee_title';
            row.querySelector('input[type="hidden"][id$="_employee_position"]').id = 'rows_diff_' + idx + '_employee_position';
            row.querySelector('input[name*="[employee_id]"]').name = 'rows_diff[' + idx + '][employee_id]';
            row.querySelector('.overtime-user-info').id = 'rows_diff_' + idx + '_employee_info';
            row.querySelector('.diff-date-start').name = 'rows_diff[' + idx + '][date_start]';
            row.querySelector('.diff-time-start').name = 'rows_diff[' + idx + '][time_start]';
            row.querySelector('.diff-date-end').name = 'rows_diff[' + idx + '][date_end]';
            row.querySelector('.diff-time-end').name = 'rows_diff[' + idx + '][time_end]';
            row.querySelector('.row-preview').id = 'diff_preview_' + idx;

            initSelector(selector);
        });
    }

    const addSameRowBtn = document.getElementById('add_same_row');
    if (addSameRowBtn) {
        addSameRowBtn.addEventListener('click', function(){
            const container = document.getElementById('rows_same_container');
            const idx = container.querySelectorAll('.same-row').length;
            const div = document.createElement('div');
            div.className = 'overtime-row-card same-row';
            div.dataset.index = idx;
            div.innerHTML = `
                <div class="overtime-row-header">
                    <strong>Сотрудник #${idx + 1}</strong>
                    <div class="overtime-row-actions">
                        <button type="button" class="ui-btn ui-btn-light-border remove-same-row">Удалить</button>
                    </div>
                </div>
                <div class="overtime-field">
                    <label>Сотрудник</label>
                    <div class="overtime-user-box overtime-selector-row" data-input="rows_same_${idx}_employee_id" data-title="rows_same_${idx}_employee_title" data-position="rows_same_${idx}_employee_position">Выберите сотрудника</div>
                    <input type="hidden" name="rows_same[${idx}][employee_id]" id="rows_same_${idx}_employee_id" value="0">
                    <input type="hidden" id="rows_same_${idx}_employee_title" value="">
                    <input type="hidden" id="rows_same_${idx}_employee_position" value="">
                    <div class="overtime-user-info" id="rows_same_${idx}_employee_info"></div>
                </div>
                <div class="overtime-preview-box row-preview" id="same_preview_${idx}"></div>
            `;
            container.appendChild(div);
            initSelector(div.querySelector('.overtime-selector-row'));
            bindDynamicPreviewEvents(div);
            updateDateConstraints();
            div.querySelector('.remove-same-row').addEventListener('click', function(){
                div.remove();
                rebuildSameIndexes();
                updateDateConstraints();
                requestPreview();
            });
            requestPreview();
        });
    }

    document.querySelectorAll('.remove-same-row').forEach(function(btn){
        btn.addEventListener('click', function(){
            btn.closest('.same-row').remove();
            rebuildSameIndexes();
            updateDateConstraints();
            requestPreview();
        });
    });

    const addDiffRowBtn = document.getElementById('add_diff_row');
    if (addDiffRowBtn) {
        addDiffRowBtn.addEventListener('click', function(){
            const singleTimeStart = document.querySelectorAll('#single_time_start option');
            const options = Array.from(singleTimeStart).map(function(opt){
                return '<option value="' + escapeHtml(opt.value) + '">' + escapeHtml(opt.textContent) + '</option>';
            }).join('');

            const container = document.getElementById('rows_diff_container');
            const idx = container.querySelectorAll('.diff-row').length;
            const div = document.createElement('div');
            div.className = 'overtime-row-card diff-row';
            div.dataset.index = idx;
            div.innerHTML = `
                <div class="overtime-row-header">
                    <strong>Строка #${idx + 1}</strong>
                    <div class="overtime-row-actions">
                        <button type="button" class="ui-btn ui-btn-light-border remove-diff-row">Удалить</button>
                    </div>
                </div>
                <div class="overtime-field">
                    <label>Сотрудник</label>
                    <div class="overtime-user-box overtime-selector-row" data-input="rows_diff_${idx}_employee_id" data-title="rows_diff_${idx}_employee_title" data-position="rows_diff_${idx}_employee_position">Выберите сотрудника</div>
                    <input type="hidden" name="rows_diff[${idx}][employee_id]" id="rows_diff_${idx}_employee_id" value="0">
                    <input type="hidden" id="rows_diff_${idx}_employee_title" value="">
                    <input type="hidden" id="rows_diff_${idx}_employee_position" value="">
                    <div class="overtime-user-info" id="rows_diff_${idx}_employee_info"></div>
                </div>
                <div class="overtime-subtitle">Выберите периоды работы</div>
                <div class="overtime-grid-4">
                    <div class="overtime-field"><label>Дата начала</label><input type="date" name="rows_diff[${idx}][date_start]" class="diff-date-start" min="${escapeHtml(todayDate)}"></div>
                    <div class="overtime-field"><label>Время начала</label><select name="rows_diff[${idx}][time_start]" class="diff-time-start">${options}</select></div>
                    <div class="overtime-field"><label>Дата окончания</label><input type="date" name="rows_diff[${idx}][date_end]" class="diff-date-end" min="${escapeHtml(todayDate)}"></div>
                    <div class="overtime-field"><label>Время окончания</label><select name="rows_diff[${idx}][time_end]" class="diff-time-end">${options}</select></div>
                </div>
                <div class="overtime-preview-box row-preview" id="diff_preview_${idx}"></div>
            `;
            container.appendChild(div);
            initSelector(div.querySelector('.overtime-selector-row'));
            bindDynamicPreviewEvents(div);
            updateDateConstraints();
            div.querySelector('.remove-diff-row').addEventListener('click', function(){
                div.remove();
                rebuildDiffIndexes();
                updateDateConstraints();
                requestPreview();
            });
            requestPreview();
        });
    }

    document.querySelectorAll('.remove-diff-row').forEach(function(btn){
        btn.addEventListener('click', function(){
            btn.closest('.diff-row').remove();
            rebuildDiffIndexes();
            updateDateConstraints();
            requestPreview();
        });
    });

    ['single_is_duty', 'same_is_duty', 'diff_is_duty'].forEach(function(id){
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', toggleFileVisibility);
        }
    });

    if (!canCreateRequests) {
        const createBtn = document.getElementById('create-btn');
        if (createBtn) {
            createBtn.disabled = true;
        }
    }

    updateDateConstraints();
    switchMode(modeInput.value || 'single');
});
</script>
