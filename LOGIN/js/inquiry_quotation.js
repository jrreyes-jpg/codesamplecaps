document.addEventListener('DOMContentLoaded', function () {
    const responseForm = document.querySelector('[data-public-quotation-form]');

    if (responseForm) {
        const decisionNote = responseForm.querySelector('[data-decision-note]');
        const noteTextarea = decisionNote?.querySelector('textarea[name="note"]');

        responseForm.querySelectorAll('[data-quotation-decision]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                if (!noteTextarea || !decisionNote) {
                    return;
                }

                if (button.value === 'client_accept') {
                    noteTextarea.required = false;
                    noteTextarea.disabled = true;
                    decisionNote.classList.remove('is-visible');
                    return;
                }

                noteTextarea.required = true;
                if (noteTextarea.disabled) {
                    event.preventDefault();
                    noteTextarea.disabled = false;
                    decisionNote.classList.add('is-visible');
                    window.requestAnimationFrame(function () {
                        noteTextarea.focus();
                    });
                }
            });
        });

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

    document.querySelectorAll('[data-print-final-quotation], [data-download-review-pdf]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });
});
