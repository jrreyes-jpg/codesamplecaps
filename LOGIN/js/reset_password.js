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

const initParticleCanvas = () => {
    const canvas = document.getElementById('particles');

    if (!canvas) {
        return;
    }

    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    const particleCount = 30;
    const particles = [];

    const setCanvasSize = () => {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    };

    class Particle {
        constructor() {
            this.reset();
            this.size = Math.random() * 2 + 1;
            this.opacity = Math.random() * 0.5 + 0.3;
        }

        reset() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.speedX = Math.random() - 0.5;
            this.speedY = Math.random() - 0.5;
        }

        update() {
            this.x += this.speedX;
            this.y += this.speedY;

            if (this.x > canvas.width) {
                this.x = 0;
            }

            if (this.x < 0) {
                this.x = canvas.width;
            }

            if (this.y > canvas.height) {
                this.y = 0;
            }

            if (this.y < 0) {
                this.y = canvas.height;
            }
        }

        draw() {
            context.fillStyle = `rgba(100, 200, 255, ${this.opacity})`;
            context.beginPath();
            context.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            context.fill();
        }
    }

    setCanvasSize();

    for (let index = 0; index < particleCount; index += 1) {
        particles.push(new Particle());
    }

    const animate = () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        particles.forEach((particle) => {
            particle.update();
            particle.draw();
        });
        window.requestAnimationFrame(animate);
    };

    animate();
    window.addEventListener('resize', setCanvasSize);
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
    initParticleCanvas();
    initPasswordToggles();
    initLoadingButtons();
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
        confirmInput.addEventListener('input', () => {
            const hasMismatch = confirmInput.value !== '' && confirmInput.value !== passwordInput.value;
            confirmInput.classList.toggle('is-invalid', hasMismatch);
        });
    }

    document.body.classList.add('page-loaded');
});
