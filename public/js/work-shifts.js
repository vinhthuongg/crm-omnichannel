(function () {
    const requiredAgents = 2;

    function selectedCount(select) {
        return Array.from(select?.selectedOptions || []).length;
    }

    function updateAgentCount(form) {
        const select = form.querySelector('[data-agent-select]');
        const count = form.querySelector('[data-agent-count]');

        if (!select || !count) {
            return;
        }

        count.textContent = `${selectedCount(select)}/${requiredAgents}`;
    }

    function validateForm(form) {
        const select = form.querySelector('[data-agent-select]');
        const startsAt = form.querySelector('[name="starts_at"]');
        const endsAt = form.querySelector('[name="ends_at"]');
        const message = form.querySelector('[data-form-message]');
        const submit = form.querySelector('button[type="submit"]');
        const agentCount = selectedCount(select);
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
        validateForm(form);

        form.addEventListener('change', function () {
            updateAgentCount(form);
            validateForm(form);
        });

        form.addEventListener('submit', function (event) {
            updateAgentCount(form);

            if (!validateForm(form)) {
                event.preventDefault();
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
