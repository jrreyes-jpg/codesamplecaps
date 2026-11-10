// Print action lang para walang inline JavaScript sa quotation PDF page.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('[data-quotation-back]')?.addEventListener('click', function (event) {
        if (!document.referrer || !document.referrer.startsWith(window.location.origin)) return;
        event.preventDefault();
        window.history.back();
    });

    document.querySelector('[data-print-quotation]')?.addEventListener('click', function () {
        window.print();
    });
});
