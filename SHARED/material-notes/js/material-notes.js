// Isang maliit na modal para sa material notes ng Admin at Engineer.
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.createElement('div');
    modal.className = 'shared-material-notes-modal';
    modal.hidden = true;
    modal.innerHTML = [
        '<div class="shared-material-notes-modal__panel" role="dialog" aria-modal="true" aria-labelledby="sharedMaterialNotesTitle">',
        '<h2 class="shared-material-notes-modal__title" id="sharedMaterialNotesTitle" data-shared-material-notes-title>Material Notes</h2>',
        '<p class="shared-material-notes-modal__material" data-shared-material-notes-name></p>',
        '<p class="shared-material-notes-modal__text" data-shared-material-notes-text></p>',
        '<div class="shared-material-notes-modal__actions">',
        '<button type="button" class="shared-material-notes-modal__close" data-shared-material-notes-close>Close</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(modal);

    let trigger = null;

    const closeModal = function () {
        modal.hidden = true;
        trigger?.focus();
    };

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-material-notes-open]');
        if (openButton) {
            trigger = openButton;
            const title = modal.querySelector('[data-shared-material-notes-title]');
            const name = modal.querySelector('[data-shared-material-notes-name]');
            const notes = modal.querySelector('[data-shared-material-notes-text]');
            if (title) title.textContent = openButton.dataset.materialNotesTitle || 'Material Notes';
            if (name) {
                name.hidden = openButton.hasAttribute('data-material-notes-hide-subject');
                name.textContent = openButton.dataset.materialName || 'Material';
            }
            if (notes) notes.textContent = openButton.dataset.materialNotes || 'No notes.';
            modal.hidden = false;
            modal.querySelector('[data-shared-material-notes-close]')?.focus();
            return;
        }

        if (event.target === modal || event.target.closest('[data-shared-material-notes-close]')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            event.preventDefault();
            closeModal();
        }
    });
});
