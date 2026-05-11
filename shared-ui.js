const sanitizePhMobile = (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 11);

    if (digits === '') {
        return '';
    }

    if (digits.startsWith('09')) {
        return digits;
    }

    if (digits.startsWith('9')) {
        return `0${digits}`.slice(0, 11);
    }

    if (digits.startsWith('63')) {
        return `0${digits.slice(2)}`.slice(0, 11);
    }

    return `09${digits}`.slice(0, 11);
};

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

const initCounterAnimations = () => {
    document.querySelectorAll('.counter').forEach((counter) => {
        const target = Number(counter.dataset.target ?? counter.textContent ?? 0);

        if (!Number.isFinite(target)) {
            return;
        }

        const step = Math.max(1, Math.ceil(target / 40));
        let currentValue = 0;

        const tick = () => {
            currentValue = Math.min(target, currentValue + step);
            counter.textContent = String(currentValue);

            if (currentValue < target) {
                window.setTimeout(tick, 30);
            }
        };

        tick();
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

const initQrPreviewModal = () => {
    const modal = document.getElementById('qrModal');
    const modalImage = document.getElementById('qrModalImg');

    if (!modal || !modalImage) {
        return;
    }

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modalImage.removeAttribute('src');
    };

    document.querySelectorAll('[data-qr-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            const source = button.dataset.qrPreview;

            if (!source) {
                return;
            }

            modalImage.src = source;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
};

const initPhoneInputs = () => {
    document.querySelectorAll('[data-phone-format="ph-mobile"]').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = sanitizePhMobile(input.value);
        });
    });
};

const initConfirmForms = () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.dataset.confirm ?? 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
};

const initRedirectButtons = () => {
    document.querySelectorAll('[data-href]').forEach((button) => {
        button.addEventListener('click', () => {
            const destination = button.dataset.href;

            if (destination) {
                window.location.href = destination;
            }
        });
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.body.classList.add('page-loaded');
    initPasswordToggles();
    initCounterAnimations();
    initLoadingButtons();
    initQrPreviewModal();
    initPhoneInputs();
    initConfirmForms();
    initRedirectButtons();
});
