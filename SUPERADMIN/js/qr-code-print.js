document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('printPageButton')?.addEventListener('click', () => {
        window.print();
    });
});
