document.addEventListener('DOMContentLoaded', function () {
    const responseForm = document.querySelector('[data-public-quotation-form]');

    if (responseForm) {
        responseForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const submitButton = event.submitter;
            if (!submitButton || responseForm.dataset.submitting === '1') {
                return;
            }

            const formData = new FormData(responseForm);
            formData.set('action', submitButton.value);
            const originalText = submitButton.textContent;
            responseForm.dataset.submitting = '1';
            submitButton.disabled = true;
            submitButton.textContent = submitButton.value === 'client_accept' ? 'Approving...' : 'Saving...';

            fetch(responseForm.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data.success) {
                        throw new Error(result.data.message || 'Unable to save your response.');
                    }

                    window.location.reload();
                })
                .catch(function (error) {
                    responseForm.dataset.submitting = '0';
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    window.alert(error.message || 'Unable to save your response.');
                });
        });
    }

    document.querySelector('[data-print-final-quotation]')?.addEventListener('click', function () {
        window.print();
    });
});
