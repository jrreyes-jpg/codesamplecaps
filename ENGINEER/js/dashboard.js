/*
|--------------------------------------------------------------------------
| Engineer Dashboard JavaScript
|--------------------------------------------------------------------------
| Sakop nito ang dashboard-only actions tulad ng clickable cards at progress bars.
|--------------------------------------------------------------------------
*/

document.querySelectorAll('[data-card-url]').forEach((card) => {
    const targetUrl = card.getAttribute('data-card-url');

    card.addEventListener('click', () => {
        window.location = targetUrl;
    });

    card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            window.location = targetUrl;
        }
    });
});

document.querySelectorAll('[data-progress-width]').forEach((bar) => {
    const width = Number.parseInt(bar.getAttribute('data-progress-width') || '0', 10);
    bar.style.width = `${Math.max(0, Math.min(width, 100))}%`;
});
