document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.delivery-verification-form').forEach((form) => {
        const quantity = document.querySelector(`[form="${form.id}"][name="verified_quantity"]`);
        const submitButton = form.querySelector('button[type="submit"]');
        const error = quantity instanceof HTMLInputElement
            ? document.getElementById(quantity.getAttribute('aria-describedby') || '')
            : null;

        if (!(quantity instanceof HTMLInputElement) || !(submitButton instanceof HTMLButtonElement)) {
            return;
        }

        const validateQuantity = (showError = false) => {
            const rawValue = quantity.value.trim();
            const value = Number(rawValue);
            let message = '';

            if (rawValue === '') {
                message = 'Enter the actual count.';
            } else if (!Number.isFinite(value) || value <= 0) {
                message = 'Enter a quantity above 0.';
            } else if (quantity.step === '1' && !Number.isInteger(value)) {
                message = 'Enter a whole number.';
            } else if (quantity.step === '0.01' && Math.abs((value * 100) - Math.round(value * 100)) > 0.000001) {
                message = 'Use up to 2 decimal places.';
            } else if (value > Number(quantity.max)) {
                message = 'Quantity is above the allowed limit.';
            }

            quantity.setCustomValidity(message);
            quantity.classList.toggle('is-invalid', showError && message !== '');
            if (error instanceof HTMLElement) {
                error.textContent = showError ? message : '';
            }
            submitButton.disabled = message !== '';
            return message === '';
        };

        quantity.addEventListener('input', () => validateQuantity(quantity.value.trim() !== ''));
        quantity.addEventListener('blur', () => validateQuantity(true));
        validateQuantity(false);

        form.addEventListener('submit', (event) => {
            if (!validateQuantity(true) || !form.checkValidity()) {
                event.preventDefault();
                return;
            }

            submitButton.disabled = true;
            window.edgeActionLoading?.show({
                title: 'Submitting delivery count',
                message: 'Please wait.',
            });
        });
    });
});
