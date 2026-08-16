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
        const defaultText = button.textContent;
        const wasInitiallyDisabled = button.disabled;

        if (!form) {
            return;
        }

        let isSubmitting = false;

        form.addEventListener('submit', (event) => {
            form.removeAttribute('target');
            form.target = '_self';

            if (!form.checkValidity()) {
                return;
            }

            if (isSubmitting) {
                event.preventDefault();
                return;
            }

            isSubmitting = true;
            button.disabled = true;
            button.textContent = button.dataset.loadingText ?? 'Processing...';

            try {
                localStorage.setItem('edge_login_flow', JSON.stringify({
                    status: 'submitting',
                    at: Date.now(),
                    source: window.name || 'login',
                }));
            } catch (error) {
                // Okay lang kahit blocked ang localStorage; login submit pa rin.
            }
        });

        window.addEventListener('pageshow', () => {
            isSubmitting = false;
            button.disabled = wasInitiallyDisabled;
            button.textContent = defaultText;
        });
    });
};

const initStaleLoginWindowGuard = () => {
    const form = document.querySelector('#loginForm form');
    const email = form?.querySelector('input[name="email"]');
    const password = form?.querySelector('input[name="password"]');
    const submitButton = form?.querySelector('button[type="submit"]');
    const defaultSubmitText = submitButton?.textContent || 'Login';

    if (window.lockoutConfig?.isLogoutPage) {
        localStorage.removeItem('edge_auth_state');
        try {
            localStorage.setItem('edge_login_flow', JSON.stringify({
                status: 'logged-out',
                at: Date.now(),
            }));
        } catch (error) {
            // Ignore localStorage errors.
        }

        if (window.history?.replaceState) {
            window.history.replaceState({}, document.title, '/codesamplecaps/LOGIN/php/login.php');
        }
    }

    if (!form || window.location.search.includes('logout=1')) {
        if (window.lockoutConfig?.isLogoutPage) {
            form?.reset();
            if (email) email.value = '';
            if (password) password.value = '';
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = defaultSubmitText;
            }
        }
        return;
    }

    form.removeAttribute('target');
    form.target = '_self';

    const resetSubmitState = () => {
        if (submitButton && submitButton.textContent === (submitButton.dataset.loadingText || 'Logging in...')) {
            submitButton.disabled = false;
            submitButton.textContent = defaultSubmitText;
        }
    };

    const clearStaleCredentials = () => {
        if (password) {
            password.value = '';
        }

        resetSubmitState();
    };

    const redirectAuthenticatedLoginPage = async () => {
        if (window.location.search.includes('logout=1')) {
            return;
        }

        try {
            const response = await fetch('/codesamplecaps/LOGIN/php/auth_status.php', {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });
            const data = await response.json();

            if (data.authenticated && data.dashboard) {
                clearStaleCredentials();
                window.location.replace(data.dashboard);
            }
        } catch (error) {
            resetSubmitState();
        }
    };

    window.addEventListener('storage', (event) => {
        if (event.key !== 'edge_auth_state' || !event.newValue) {
            return;
        }

        try {
            const state = JSON.parse(event.newValue);
            if (state.status === 'logged-in') {
                clearStaleCredentials();
                redirectAuthenticatedLoginPage();
            } else if (state.status === 'logged-out') {
                clearStaleCredentials();
            }
        } catch (error) {
            // Ignore invalid localStorage data.
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key !== 'edge_login_flow' || !event.newValue) {
            return;
        }

        try {
            const state = JSON.parse(event.newValue);
            if (state.status === 'logged-out') {
                clearStaleCredentials();
            }
        } catch (error) {
            // Ignore invalid localStorage data.
        }
    });

    try {
        const state = JSON.parse(localStorage.getItem('edge_auth_state') || '{}');
        if (state.status === 'logged-in') {
            clearStaleCredentials();
            redirectAuthenticatedLoginPage();
        }
    } catch (error) {
        // Ignore invalid localStorage data.
    }

    window.addEventListener('focus', () => {
        redirectAuthenticatedLoginPage();
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            redirectAuthenticatedLoginPage();
        }
    });
    window.addEventListener('pageshow', () => {
        resetSubmitState();
        redirectAuthenticatedLoginPage();
    });
};

const initLoginToast = () => {
    const toast = document.querySelector('.login-toast');
    if (!toast) {
        return;
    }

    window.setTimeout(() => {
        toast.classList.add('is-hiding');
        window.setTimeout(() => toast.remove(), 220);
    }, 4500);
};

const initEmailLockStatus = () => {
    const statusUrl = window.lockoutConfig?.statusUrl;
    const currentLockType = window.lockoutConfig?.lockType ?? '';

    if (!statusUrl || currentLockType === 'ip') {
        return;
    }

    const email = document.querySelector('input[name="email"]');
    const password = document.querySelector('input[name="password"]');
    const loginButton = document.querySelector('button[type="submit"]');
    const showButton = document.querySelector('.togglePassword');
    const statusBox = document.getElementById('emailLockStatus');
    const serverError = document.querySelector('.error-box');

    if (!email || !password || !loginButton || !statusBox) {
        return;
    }

    let timerId = null;
    let countdownId = null;
    let activeUnlockAtMs = 0;
    let requestId = 0;

    const setLoginControls = (isLocked) => {
        password.disabled = isLocked;
        loginButton.disabled = isLocked;
        if (showButton) showButton.disabled = isLocked;
    };

    const hideStatus = () => {
        if (countdownId) {
            clearTimeout(countdownId);
            countdownId = null;
        }

        activeUnlockAtMs = 0;
        statusBox.className = 'client-lock-status is-hidden';
        statusBox.textContent = '';
    };

    const formatTime = (seconds) => {
        const minutes = Math.floor(seconds / 60);
        const remaining = seconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
    };

    const showCountdown = (message, seconds, lockType, unlockAt = 0) => {
        const nextUnlockAtMs = unlockAt > 0
            ? unlockAt * 1000
            : Date.now() + (seconds * 1000);

        // Huwag hayaang tumaas ulit ang oras kapag may bagong status check.
        activeUnlockAtMs = activeUnlockAtMs > 0
            ? Math.min(activeUnlockAtMs, nextUnlockAtMs)
            : nextUnlockAtMs;

        statusBox.className = 'client-lock-status error-locked';

        const tick = () => {
            const timeLeft = Math.max(0, Math.ceil((activeUnlockAtMs - Date.now()) / 1000));
            statusBox.textContent = `${message} Time remaining: ${formatTime(timeLeft)}`;

            if (timeLeft <= 0) {
                hideStatus();
                setLoginControls(false);
                if (lockType === 'ip') {
                    email.disabled = false;
                    location.reload();
                }
                return;
            }

            countdownId = setTimeout(tick, 1000);
        };

        if (lockType === 'ip') {
            email.disabled = true;
        }

        setLoginControls(true);
        tick();
    };

    const checkEmail = async () => {
        const typedEmail = email.value.trim();
        const thisRequest = ++requestId;

        hideStatus();
        setLoginControls(false);

        if (serverError) {
            serverError.classList.add('is-hidden');
        }

        if (typedEmail === '') {
            return;
        }

        try {
            const response = await fetch(`${statusUrl}?email=${encodeURIComponent(typedEmail)}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

            if (thisRequest !== requestId) {
                return;
            }

            if (data.locked && data.type === 'ip') {
                email.disabled = true;
                setLoginControls(true);
                showCountdown(
                    data.message || 'This device has been temporarily locked due to multiple failed login attempts.',
                    Number(data.seconds) || 0,
                    'ip',
                    Number(data.unlockAt) || 0
                );
                return;
            }

            if (data.locked && data.type === 'email') {
                showCountdown(
                    data.message || 'This login is temporarily locked.',
                    Number(data.seconds) || 0,
                    'email',
                    Number(data.unlockAt) || 0
                );
            }
        } catch (error) {
            hideStatus();
            setLoginControls(false);
        }
    };

    email.addEventListener('input', () => {
        if (timerId) {
            clearTimeout(timerId);
        }

        timerId = setTimeout(checkEmail, 350);
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
    const unlockAt = window.lockoutConfig?.unlockAt ?? 0;
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
            if (password) password.disabled = true;
            if (loginButton) loginButton.disabled = true;
            if (showButton) showButton.disabled = true;
            countdown.closest('.error-box')?.classList.add('is-hidden');
        });
    }

    const unlockAtMs = unlockAt > 0
        ? unlockAt * 1000
        : Date.now() + (LOCKOUT_SECONDS * 1000);

    const update = () => {
        const seconds = Math.max(0, Math.ceil((unlockAtMs - Date.now()) / 1000));

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

        setTimeout(update, 1000);
    };

    update();

};
document.addEventListener('DOMContentLoaded', () => {
    initParticleCanvas();
    initPasswordToggles();
    initLoadingButtons();
    initLoginToast();
    initStaleLoginWindowGuard();

    // Start the live lockout countdown if the login form is locked.
    initLockoutCountdown();
    initEmailLockStatus();

    document.body.classList.add('page-loaded');
});
