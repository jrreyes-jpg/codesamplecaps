document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-add-asset-modal]');
    const openButton = document.querySelector('[data-add-asset-open]');
    const form = document.querySelector('[data-add-asset-form]');
    const submitButton = document.querySelector('[data-add-asset-submit]');

    if (!modal || !openButton) {
        return;
    }

    const closeModal = () => {
        modal.hidden = true;
        document.body.classList.remove('inventory-add-asset-modal-open');
    };

    const openModal = () => {
        modal.hidden = false;
        document.body.classList.add('inventory-add-asset-modal-open');
        window.setTimeout(() => modal.querySelector('#add_asset_name')?.focus(), 0);
    };

    openButton.addEventListener('click', openModal);

    modal.querySelectorAll('[data-add-asset-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (form && submitButton) {
        form.addEventListener('submit', () => {
            if (form.checkValidity()) {
                submitButton.disabled = true;
                submitButton.textContent = 'Adding Asset...';
            }
        });
    }

    if (modal.dataset.open === 'true') {
        openModal();
    }
});
