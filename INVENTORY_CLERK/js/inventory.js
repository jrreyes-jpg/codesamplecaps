document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-add-asset-modal]');
    const openButton = document.querySelector('[data-add-asset-open]');
    const form = document.querySelector('[data-add-asset-form]');
    const submitButton = document.querySelector('[data-add-asset-submit]');
    const suggestButton = document.querySelector('[data-category-suggest]');
    const suggestFeedback = document.querySelector('[data-category-suggest-feedback]');
    const assetNameInput = document.querySelector('#add_asset_name');
    const categorySelect = document.querySelector('#add_asset_category');

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

    if (suggestButton && suggestFeedback && assetNameInput && categorySelect && form) {
        const defaultSuggestText = suggestButton.textContent;
        const showSuggestionFeedback = (message, type = '') => {
            suggestFeedback.textContent = message;
            suggestFeedback.classList.toggle('is-error', type === 'error');
            suggestFeedback.classList.toggle('is-success', type === 'success');
        };

        suggestButton.addEventListener('click', async () => {
            const assetName = assetNameInput.value.trim();
            if (assetName === '') {
                showSuggestionFeedback('Enter an asset name first.', 'error');
                assetNameInput.focus();
                return;
            }

            const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
            suggestButton.disabled = true;
            suggestButton.textContent = 'Searching...';
            showSuggestionFeedback('');

            try {
                const response = await fetch('/codesamplecaps/INVENTORY_CLERK/api/suggest_asset_category.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new URLSearchParams({
                        asset_name: assetName,
                        csrf_token: csrfToken,
                    }),
                });
                const result = await response.json();

                if (!response.ok || !result.success || !result.category) {
                    throw new Error('No category suggestion');
                }

                categorySelect.value = result.category;
                const selectedLabel = categorySelect.options[categorySelect.selectedIndex]?.text || result.category;
                showSuggestionFeedback(`Suggested from web: ${selectedLabel}`, 'success');
            } catch (error) {
                showSuggestionFeedback('Unable to suggest a category. Please select manually.', 'error');
            } finally {
                suggestButton.disabled = false;
                suggestButton.textContent = defaultSuggestText;
            }
        });
    }

    if (modal.dataset.open === 'true') {
        openModal();
    }
});
