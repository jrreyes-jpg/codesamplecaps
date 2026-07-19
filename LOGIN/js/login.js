const initParticleCanvas = () => {
    const canvas = document.getElementById('particles');

    if (!canvas) {
        return;
    }

    const shouldReduceMotion = window.matchMedia('(max-width: 768px), (prefers-reduced-motion: reduce)').matches;
    if (shouldReduceMotion) {
        canvas.remove();
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
/*
|--------------------------------------------------------------------------
| Live Lockout Countdown
|--------------------------------------------------------------------------
| Shows a real-time countdown while the login form is locked.
| Format:
| 15:00
| 14:59
| 14:58
|--------------------------------------------------------------------------
*/

const initLockoutCountdown = () => {

    // Get the remaining lockout time from login.php
    const LOCKOUT_SECONDS = window.lockoutConfig?.seconds ?? 0;
    const lockType = window.lockoutConfig?.lockType ?? '';

    if (LOCKOUT_SECONDS <= 0) {
        return;
    }

    const countdown = document.getElementById('lockoutCountdown');

    if (!countdown) {
        return;
    }

    const email = document.querySelector('input[name="email"]');
    const password = document.querySelector('input[name="password"]');
    const loginButton = document.querySelector('button[type="submit"]');
    const showButton = document.querySelector('.togglePassword');

    if (lockType === 'email' && email) {
        email.addEventListener('input', () => {
            const hasNewEmail = email.value.trim() !== '';

            if (password) password.disabled = !hasNewEmail;
            if (loginButton) loginButton.disabled = !hasNewEmail;
            if (showButton) showButton.disabled = !hasNewEmail;
        });
    }

    let seconds = LOCKOUT_SECONDS;

    const update = () => {

        const minutes = Math.floor(seconds / 60);
        const remaining = seconds % 60;

        const timeLeft = `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
        countdown.textContent = lockType === 'ip'
            ? `Time remaining: ${timeLeft}`
            : `Please try again in ${timeLeft}`;

        if (seconds <= 0) {

            countdown.textContent = "";

            if (lockType === 'ip') {
                if (email) email.disabled = false;
                if (password) password.disabled = false;
                if (loginButton) loginButton.disabled = false;
                if (showButton) showButton.disabled = false;
            }

            location.reload();

            return;
        }

        seconds--;

        setTimeout(update, 1000);
    };

    update();

};
document.addEventListener('DOMContentLoaded', () => {
    initParticleCanvas();
    initPasswordToggles();
    initLoadingButtons();

    // Start the live lockout countdown if the login form is locked.
    initLockoutCountdown();

    document.body.classList.add('page-loaded');
});
