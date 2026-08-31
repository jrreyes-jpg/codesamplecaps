document.addEventListener('DOMContentLoaded', function () {
    const phoneInput = document.querySelector('[data-account-phone]');
    const photoInput = document.querySelector('[data-profile-photo-input]');
    const photoPreview = document.querySelector('[data-profile-photo-preview]');
    const photoState = document.querySelector('[data-profile-photo-state]');
    const newPassword = document.querySelector('[data-new-password]');
    const confirmPassword = document.querySelector('[data-confirm-password]');
    const strengthText = document.querySelector('[data-password-strength]');
    const matchText = document.querySelector('[data-password-match]');
    let previewUrl = '';

    phoneInput?.addEventListener('input', function () {
        let digits = phoneInput.value.replace(/\D/g, '');
        if (digits !== '' && !digits.startsWith('09')) {
            digits = '09' + digits.replace(/^0+/, '');
        }
        phoneInput.value = digits.slice(0, 11);
    });

    photoInput?.addEventListener('change', function () {
        const file = photoInput.files?.[0];
        if (!file || !photoPreview) {
            return;
        }

        if (previewUrl !== '') {
            URL.revokeObjectURL(previewUrl);
        }
        previewUrl = URL.createObjectURL(file);
        photoPreview.src = previewUrl;
        if (photoState) {
            photoState.textContent = 'New photo ready. Click Save Profile to upload.';
        }
    });

    function passwordScore(value) {
        return [
            value.length >= 12,
            /[A-Z]/.test(value),
            /[a-z]/.test(value),
            /\d/.test(value),
            /[^A-Za-z0-9]/.test(value),
        ].filter(Boolean).length;
    }

    function updatePasswordState() {
        if (newPassword && strengthText) {
            const score = passwordScore(newPassword.value);
            const labels = ['Weak', 'Weak', 'Weak', 'Medium', 'Strong', 'Super Strong'];
            const classes = ['weak', 'weak', 'weak', 'medium', 'strong', 'super-strong'];
            strengthText.textContent = 'Strength: ' + labels[score];
            strengthText.className = 'pass-indicator ' + classes[score];
        }

        if (newPassword && confirmPassword && matchText) {
            const matches = confirmPassword.value !== '' && newPassword.value === confirmPassword.value;
            matchText.textContent = matches ? 'Confirmation: Match' : 'Confirmation: Not matched';
            matchText.className = 'pass-indicator ' + (matches ? 'strong' : 'weak');
        }
    }

    newPassword?.addEventListener('input', updatePasswordState);
    confirmPassword?.addEventListener('input', updatePasswordState);
});
