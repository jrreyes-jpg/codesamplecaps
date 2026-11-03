document.addEventListener('DOMContentLoaded', function () {
    const editDraftLink = document.querySelector('[data-quotation-edit-draft]');
    const form = document.querySelector('[data-quotation-send-form]');
    const modal = document.querySelector('[data-quotation-send-modal]');
    const confirmButton = modal?.querySelector('[data-quotation-send-confirm]');
    const cancelButtons = modal?.querySelectorAll('[data-quotation-send-cancel]') || [];

    editDraftLink?.addEventListener('click', function (event) {
        event.preventDefault();
        window.location.replace(this.href);
    });

    if (!form || !modal || !confirmButton) {
        return;
    }

    const closeModal = function () {
        modal.hidden = true;
        document.body.classList.remove('admin-quotation-send-open');
        form.querySelector('button[type="submit"]')?.focus();
    };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        modal.hidden = false;
        document.body.classList.add('admin-quotation-send-open');
        confirmButton.focus();
    });

    cancelButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    confirmButton.addEventListener('click', function () {
        confirmButton.disabled = true;
        confirmButton.textContent = 'Sending...';
        form.submit();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden && !confirmButton.disabled) {
            closeModal();
        }
    });
});
