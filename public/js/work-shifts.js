(function () {
    const requiredAgents = 2;

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

        count.textContent = `${selectedCount(form)}/${requiredAgents}`;
    }

    function syncAgentAvailability(form) {
        const checkboxes = Array.from(form.querySelectorAll('[data-agent-checkbox]'));

        if (checkboxes.length === 0) {
            return;
        }

        const limitReached = selectedCount(form) >= requiredAgents;

        checkboxes.forEach(function (checkbox) {
            checkbox.disabled = limitReached && !checkbox.checked;
        });
    }

    function validateForm(form) {
        const startsAt = form.querySelector('[name="starts_at"]');
        const endsAt = form.querySelector('[name="ends_at"]');
        const message = form.querySelector('[data-form-message]');
        const submit = form.querySelector('button[type="submit"]');
        const agentCount = selectedCount(form);
        let error = '';

        if (agentCount !== requiredAgents) {
            error = 'Moi ca truc can dung 2 nhan vien.';
        }

        if (!error && startsAt?.value && endsAt?.value && new Date(endsAt.value) <= new Date(startsAt.value)) {
            error = 'Thoi gian ket thuc phai sau thoi gian bat dau.';
        }

        form.classList.toggle('is-invalid', Boolean(error));

        if (message) {
            message.textContent = error || 'Chon dung 2 nhan vien cho moi ca truc.';
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
            if (!window.confirm('Xoa ca truc nay?')) {
                event.preventDefault();
            }
        });
    });
})();
