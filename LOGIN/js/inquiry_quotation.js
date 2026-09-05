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

            const confirmationMessages = {
                client_accept: 'Are you sure you want to APPROVE this quotation? This action is final and will freeze the breakdown pricing.',
                client_revision: 'Are you sure you want to request a revision? This will send the quotation back to the admin for editing.',
                client_reject: 'Are you sure you want to REJECT this quotation? This will permanently cancel the inquiry.',
            };
            const confirmationMessage = confirmationMessages[submitButton.value];
            if (confirmationMessage && !window.confirm(confirmationMessage)) {
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
