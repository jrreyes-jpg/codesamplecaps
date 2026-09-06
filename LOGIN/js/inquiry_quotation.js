document.addEventListener('DOMContentLoaded', function () {
    const responseForm = document.querySelector('[data-public-quotation-form]');
    const responseMessages = {
        client_accept: 'You have approved the quotation. Please wait for the Admin to assign an Engineer and schedule the inspection.',
        client_revision: 'You have requested changes to the quotation. The Admin will review your feedback and send an updated quotation.',
        client_reject: 'You have rejected the quotation. The Admin will contact you to discuss next steps.',
    };
    const feedbackModal = document.createElement('div');
    let feedbackDismissAction = null;

    feedbackModal.className = 'public-quote-feedback-modal';
    feedbackModal.hidden = true;
    feedbackModal.innerHTML = [
        '<div class="public-quote-feedback-modal__panel" role="dialog" aria-modal="true" aria-labelledby="publicQuoteFeedbackTitle">',
        '<h2 id="publicQuoteFeedbackTitle">Quotation Response Saved</h2>',
        '<p data-public-quote-feedback-message></p>',
        '<button type="button" class="public-quote-button public-quote-button--accept" data-public-quote-feedback-ok>OK</button>',
        '</div>',
    ].join('');
    document.body.appendChild(feedbackModal);

    const showFeedbackModal = function (message, onDismiss) {
        const messageBox = feedbackModal.querySelector('[data-public-quote-feedback-message]');
        if (messageBox) {
            messageBox.textContent = message;
        }

        feedbackDismissAction = typeof onDismiss === 'function' ? onDismiss : null;
        feedbackModal.hidden = false;
        feedbackModal.querySelector('[data-public-quote-feedback-ok]')?.focus();
    };

    const closeFeedbackModal = function () {
        const onDismiss = feedbackDismissAction;
        feedbackDismissAction = null;
        feedbackModal.hidden = true;
        onDismiss?.();
    };

    feedbackModal.querySelector('[data-public-quote-feedback-ok]')?.addEventListener('click', closeFeedbackModal);

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

                    const responseAction = result.data.action || submitButton.value;
                    showFeedbackModal(
                        responseMessages[responseAction] || result.data.message || 'Your response has been saved.',
                        function () { window.location.reload(); }
                    );
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
