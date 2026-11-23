document.addEventListener('DOMContentLoaded', function () {
    const modal = document.querySelector('[data-project-confirm-modal]');
    const confirmButton = modal?.querySelector('[data-project-confirm-submit]');
    const cancelButtons = modal?.querySelectorAll('[data-project-confirm-cancel]') || [];
    const forms = document.querySelectorAll('[data-quotation-create-project]');
    let pendingForm = null;

    if (!modal || !confirmButton || forms.length === 0) return;

    if (modal.parentElement !== document.body) document.body.appendChild(modal);

    const closeModal = function () {
        if (confirmButton.disabled) return;
        modal.hidden = true;
        document.body.classList.remove('shared-project-confirm-open');
        pendingForm?.querySelector('button[type="submit"]')?.focus();
        pendingForm = null;
    };

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            pendingForm = form;
            modal.hidden = false;
            document.body.classList.add('shared-project-confirm-open');
            confirmButton.focus();
        });
    });

    cancelButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    confirmButton.addEventListener('click', function () {
        if (!pendingForm) return;
        confirmButton.disabled = true;
        confirmButton.textContent = 'Opening...';
        pendingForm.setAttribute('aria-busy', 'true');
        window.edgeActionLoading?.show({
            title: 'Opening Project Form...',
            message: 'Please wait. Do not close this page.',
        });
        pendingForm.submit();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
});
