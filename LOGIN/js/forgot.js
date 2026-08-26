const initPasswordToggles = () => {
    document.querySelectorAll('.togglePassword').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.target;
            const input = targetId ? document.getElementById(targetId) : null;

            if (!input) {
                return;
            }

            const nextType = input.type === 'password' ? 'text' : 'password';
            input.type = nextType;
            button.textContent = nextType === 'password' ? 'Show' : 'Hide';
            button.setAttribute('aria-pressed', String(nextType === 'text'));
        });
    });
};

const initLoadingButtons = () => {
    document.querySelectorAll('button[data-loading-text]').forEach((button) => {
        const form = button.closest('form');

        if (!form) {
            return;
        }

        form.addEventListener('submit', () => {
            if (!form.checkValidity()) {
                return;
            }

            button.disabled = true;
            button.textContent = button.dataset.loadingText ?? 'Processing...';
        });
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initPasswordToggles();
    initLoadingButtons();
    document.body.classList.add('page-loaded');
});
