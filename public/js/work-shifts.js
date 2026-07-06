(function () {
    const minAgents = 1;
    const maxAgents = 2;

    function selectedCount(form) {
        const checkboxes = Array.from(form.querySelectorAll('[data-agent-checkbox]'));

        if (checkboxes.length > 0) {
            return checkboxes.filter((checkbox) => checkbox.checked).length;
        }

        const select = form.querySelector('[data-agent-select]');

        return Array.from(select?.selectedOptions || []).length;
    }

    function updateAgentCount(form) {
        const count = form.querySelector('[data-agent-count]');

        if (!count) {
            return;
        }

        count.textContent = `${selectedCount(form)}/${maxAgents}`;
    }

    function syncAgentAvailability(form) {
        const checkboxes = Array.from(form.querySelectorAll('[data-agent-checkbox]'));

        if (checkboxes.length === 0) {
            return;
        }

        const limitReached = selectedCount(form) >= maxAgents;

        checkboxes.forEach(function (checkbox) {
            checkbox.disabled = limitReached && !checkbox.checked;
        });
    }

    function validateForm(form) {
        const startsAt = form.querySelector('[name="starts_time"]');
        const endsAt = form.querySelector('[name="ends_time"]');
        const message = form.querySelector('[data-form-message]');
        const submit = form.querySelector('button[type="submit"]');
        const agentCount = selectedCount(form);
        let error = '';

        if (agentCount < minAgents || agentCount > maxAgents) {
            error = 'Mỗi ca trực cần từ 1 đến 2 nhân viên.';
        }

        if (!error && (!startsAt?.value || !endsAt?.value)) {
            error = 'Chọn giờ bắt đầu và giờ kết thúc.';
        }

        form.classList.toggle('is-invalid', Boolean(error));

        if (message) {
            message.textContent = error || 'Chọn 1 đến 2 nhân viên cho mỗi ca trực.';
        }

        if (submit) {
            submit.disabled = Boolean(error);
        }

        return !error;
    }

    function activateTab(tabId, updateHash = true) {
        const tabs = Array.from(document.querySelectorAll('[data-work-shift-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-work-shift-panel]'));

        if (!tabs.length || !panels.length) {
            return;
        }

        const targetId = panels.some((panel) => panel.dataset.workShiftPanel === tabId)
            ? tabId
            : panels[0].dataset.workShiftPanel;

        tabs.forEach(function (tab) {
            const active = tab.dataset.workShiftTab === targetId;
            tab.classList.toggle('active', active);

            if (active) {
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.removeAttribute('aria-current');
            }
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.workShiftPanel !== targetId;
        });

        if (updateHash && targetId) {
            history.replaceState(null, '', `#${targetId}`);
        }
    }

    document.querySelectorAll('[data-work-shift-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            activateTab(tab.dataset.workShiftTab);
        });
    });

    const initialTab = window.location.hash ? window.location.hash.slice(1) : 'shift-overview';
    activateTab(initialTab, false);

    document.querySelectorAll('[data-shift-form]').forEach(function (form) {
        updateAgentCount(form);
        syncAgentAvailability(form);
        validateForm(form);

        form.addEventListener('change', function () {
            updateAgentCount(form);
            syncAgentAvailability(form);
            validateForm(form);
        });

        form.addEventListener('submit', function (event) {
            updateAgentCount(form);

            if (!validateForm(form)) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-open-shift-dialog]');
        const closeButton = event.target.closest('[data-close-shift-dialog]');

        if (openButton) {
            const dialog = document.getElementById(openButton.dataset.openShiftDialog || '');

            if (dialog?.showModal) {
                dialog.showModal();
                dialog.querySelector('input[name="name"]')?.focus();
            }
        }

        if (closeButton) {
            closeButton.closest('dialog')?.close();
        }
    });

    document.querySelectorAll('[data-shift-dialog]').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('[data-delete-shift]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm('Xóa ca trực này?')) {
                event.preventDefault();
            }
        });
    });
})();
