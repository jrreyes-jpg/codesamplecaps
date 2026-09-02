/* Engineer common scripts. Shared ng Engineer pages. */

document.querySelectorAll('[data-progress-width]').forEach((bar) => {
    const width = Number.parseInt(bar.getAttribute('data-progress-width') || '0', 10);

    bar.style.width = `${Math.max(0, Math.min(width, 100))}%`;
});
