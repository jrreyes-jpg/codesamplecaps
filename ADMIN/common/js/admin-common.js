// Load shared duplicate-window guard for Admin pages.
(function () {
    if (document.querySelector('script[src$="/assets/js/app-window-guard.js"]')) {
        return;
    }

    const guardScript = document.createElement('script');
    guardScript.src = '/codesamplecaps/assets/js/app-window-guard.js';
    guardScript.defer = true;
    document.head.appendChild(guardScript);
})();

// ================================
// Canvas particle animation
// ================================
const canvas = document.getElementById('particles');
if (canvas) {
    const ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;

    const particles = [];
    const particleCount = 30;

    class Particle {
        constructor() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.size = Math.random() * 2 + 1;
            this.speedX = Math.random() * 1 - 0.5;
            this.speedY = Math.random() * 1 - 0.5;
            this.opacity = Math.random() * 0.5 + 0.3;
        }

        update() {
            this.x += this.speedX;
            this.y += this.speedY;
            if (this.x > canvas.width) this.x = 0;
            if (this.x < 0) this.x = canvas.width;
            if (this.y > canvas.height) this.y = 0;
            if (this.y < 0) this.y = canvas.height;
        }

        draw() {
            ctx.fillStyle = `rgba(100, 200, 255, ${this.opacity})`;
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    for (let i = 0; i < particleCount; i++) {
        particles.push(new Particle());
    }

    function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        particles.forEach((particle) => {
            particle.update();
            particle.draw();
        });
        requestAnimationFrame(animate);
    }

    animate();

    window.addEventListener('resize', () => {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    try {
        localStorage.setItem('edge_auth_state', JSON.stringify({
            status: 'logged-in',
            at: Date.now(),
            dashboardPath: window.location.pathname + window.location.search,
        }));
    } catch (error) {
        // Okay lang kahit blocked ang localStorage; normal login flow pa rin.
    }

    document.querySelectorAll('[data-progress-width]').forEach(function (bar) {
        const rawValue = Number(bar.getAttribute('data-progress-width') || '0');
        const normalized = Math.max(0, Math.min(100, rawValue));
        bar.style.setProperty('--pulse-progress', normalized + '%');
    });

    document.querySelectorAll('[data-fill-width]').forEach(function (fill) {
        const rawValue = Number(fill.getAttribute('data-fill-width') || '0');
        const normalized = Math.max(0, Math.min(100, rawValue));
        fill.style.width = normalized + '%';
    });

    document.body.classList.add('page-loaded');

    const counters = document.querySelectorAll('.counter');
    counters.forEach((counter) => {
        const updateCount = () => {
            const target = +counter.getAttribute('data-target');
            const currentValue = +counter.innerText;
            const increment = target / 40;

            if (currentValue < target) {
                counter.innerText = Math.ceil(currentValue + increment);
                setTimeout(updateCount, 30);
            } else {
                counter.innerText = target;
            }
        };

        updateCount();
    });

});
