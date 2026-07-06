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
