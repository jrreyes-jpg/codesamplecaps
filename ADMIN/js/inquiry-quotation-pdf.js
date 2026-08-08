// Print action lang para walang inline JavaScript sa quotation PDF page.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('[data-print-quotation]')?.addEventListener('click', function () {
        window.print();
    });
});
