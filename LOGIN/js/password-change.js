const scorePassword = (value) => {
    let score = 0;

    if (value.length >= 12) score += 1;
    if (/[A-Z]/.test(value)) score += 1;
    if (/[a-z]/.test(value)) score += 1;
    if (/\d/.test(value)) score += 1;
    if (/[^A-Za-z0-9]/.test(value)) score += 1;

    return score;
};

const renderStrength = (input, indicator) => {
    const score = scorePassword(input.value);
    let label = 'Weak';
    let state = 'weak';

    if (score >= 5) {
        label = 'Super Strong';
        state = 'super-strong';
    } else if (score === 4) {
        label = 'Strong';
        state = 'strong';
    } else if (score === 3) {
        label = 'Medium';
        state = 'medium';
    }

    indicator.textContent = `Strength: ${label}`;
    indicator.className = `pass-indicator ${state}`;
    input.classList.remove('weak-border', 'medium-border', 'strong-border');

    if (state === 'weak') {
        input.classList.add('weak-border');
    } else if (state === 'medium') {
        input.classList.add('medium-border');
    } else {
        input.classList.add('strong-border');
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthIndicator = document.getElementById('newPassStrength');
    const matchIndicator = document.getElementById('confirmPassMatch');

    if (newPassword && strengthIndicator) {
        newPassword.addEventListener('input', () => {
            renderStrength(newPassword, strengthIndicator);

            if (confirmPassword && matchIndicator) {
                const matches = confirmPassword.value !== '' && confirmPassword.value === newPassword.value;
                matchIndicator.textContent = `Match: ${matches ? 'Yes' : 'No'}`;
                matchIndicator.className = `pass-indicator ${matches ? 'strong' : 'weak'}`;
            }
        });
    }

    if (newPassword && confirmPassword && matchIndicator) {
        confirmPassword.addEventListener('input', () => {
            const matches = confirmPassword.value !== '' && confirmPassword.value === newPassword.value;
            matchIndicator.textContent = `Match: ${matches ? 'Yes' : 'No'}`;
            matchIndicator.className = `pass-indicator ${matches ? 'strong' : 'weak'}`;
        });
    }
});
