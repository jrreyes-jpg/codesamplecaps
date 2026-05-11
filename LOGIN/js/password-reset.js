const getStrengthDetails = (password) => {
    let score = 0;

    if (password.length >= 8) score += 1;
    if (/[a-z]/.test(password)) score += 1;
    if (/[A-Z]/.test(password)) score += 1;
    if (/\d/.test(password)) score += 1;
    if (/[@$!%*?&]/.test(password)) score += 1;

    return {
        score,
        labels: ['', 'Very Weak', 'Weak', 'Fair', 'Good', 'Strong'],
        colors: ['#dc3545', '#dc3545', '#fd7e14', '#ffc107', '#20c997', '#28a745'],
        widths: [0, 20, 40, 60, 80, 100],
    };
};

document.addEventListener('DOMContentLoaded', () => {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');

    if (passwordInput && strengthBar && strengthText) {
        passwordInput.addEventListener('input', () => {
            const { score, labels, colors, widths } = getStrengthDetails(passwordInput.value);
            strengthBar.style.width = `${widths[score]}%`;
            strengthBar.style.backgroundColor = colors[score];
            strengthText.textContent = labels[score];
            strengthText.style.color = colors[score];
        });
    }

    if (passwordInput && confirmInput) {
        confirmInput.addEventListener('blur', () => {
            const hasMismatch = confirmInput.value !== '' && confirmInput.value !== passwordInput.value;
            confirmInput.classList.toggle('is-invalid', hasMismatch);
        });
    }
});
