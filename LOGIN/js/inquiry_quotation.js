document.addEventListener('DOMContentLoaded', function () {
    const responseForm = document.querySelector('[data-public-quotation-form]');
    const responseMessages = {
        client_accept: 'You have approved the quotation. Please wait for the Admin to assign an Engineer and schedule the inspection.',
        client_revision: 'You have requested changes to the quotation. The Admin will review your feedback and send an updated quotation.',
        client_reject: 'You have rejected the quotation. The Admin will contact you to discuss next steps.',
    };
    const feedbackModal = document.createElement('div');
    const confirmationModal = document.createElement('div');
    let feedbackDismissAction = null;
    let pendingConfirmation = null;

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

    confirmationModal.className = 'public-quote-feedback-modal public-quote-confirmation-modal';
    confirmationModal.hidden = true;
    confirmationModal.innerHTML = [
        '<div class="public-quote-feedback-modal__panel" role="dialog" aria-modal="true" aria-labelledby="publicQuoteConfirmationTitle">',
        '<h2 id="publicQuoteConfirmationTitle"></h2>',
        '<p data-public-quote-confirmation-message></p>',
        '<div class="public-quote-confirmation-modal__actions">',
        '<button type="button" class="public-quote-button public-quote-confirmation-modal__cancel" data-public-quote-confirmation-cancel>Cancel</button>',
        '<button type="button" class="public-quote-button public-quote-button--accept" data-public-quote-confirmation-approve>Approve Quotation</button>',
        '</div>',
        '</div>',
    ].join('');
    document.body.appendChild(confirmationModal);

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

    const closeConfirmationModal = function () {
        pendingConfirmation = null;
        confirmationModal.hidden = true;
    };

    const setConfirmationLoading = function (isLoading) {
        const cancelButton = confirmationModal.querySelector('[data-public-quote-confirmation-cancel]');
        const approveButton = confirmationModal.querySelector('[data-public-quote-confirmation-approve]');
        if (cancelButton) {
            cancelButton.disabled = isLoading;
        }
        if (approveButton) {
            approveButton.disabled = isLoading;
        }
    };

    const confirmationDetails = {
        client_accept: {
            title: 'Approve initial quotation?',
            message: 'This confirms your acceptance of the initial quotation and allows the Admin to schedule the site inspection. Final scope and costs may be revised after the site inspection.',
            confirm: 'Approve Quotation',
            buttonClass: 'public-quote-button--accept',
        },
        client_revision: {
            title: 'Request quotation revision?',
            message: 'This will send the quotation back to the Admin for editing.',
            confirm: 'Request Revision',
            buttonClass: 'public-quote-button--revision',
        },
        client_reject: {
            title: 'Reject quotation?',
            message: 'This will permanently cancel the inquiry.',
            confirm: 'Reject Quotation',
            buttonClass: 'public-quote-button--reject',
        },
    };

    const showConfirmationModal = function (action, submitButton) {
        const details = confirmationDetails[action];
        if (!details) {
            return;
        }

        const title = confirmationModal.querySelector('#publicQuoteConfirmationTitle');
        const message = confirmationModal.querySelector('[data-public-quote-confirmation-message]');
        const approveButton = confirmationModal.querySelector('[data-public-quote-confirmation-approve]');
        if (title) title.textContent = details.title;
        if (message) message.textContent = details.message;
        if (approveButton) {
            approveButton.textContent = details.confirm;
            approveButton.classList.remove('public-quote-button--accept', 'public-quote-button--revision', 'public-quote-button--reject');
            approveButton.classList.add(details.buttonClass);
        }

        pendingConfirmation = { action: action, submitButton: submitButton, label: details.confirm };
        setConfirmationLoading(false);
        confirmationModal.hidden = false;
        confirmationModal.querySelector('[data-public-quote-confirmation-cancel]')?.focus();
    };

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

        const submitQuotationResponse = function (action, submitButton) {
            if (!submitButton || responseForm.dataset.submitting === '1') {
                return;
            }

            const formData = new FormData(responseForm);
            formData.set('action', action);
            const originalText = submitButton.textContent;
            responseForm.dataset.submitting = '1';
            submitButton.disabled = true;
            submitButton.textContent = action === 'client_accept' ? 'Approving...' : 'Saving...';
            if (action === 'client_accept') {
                const approveButton = confirmationModal.querySelector('[data-public-quote-confirmation-approve]');
                if (approveButton) approveButton.textContent = 'Approving...';
            }
            setConfirmationLoading(true);

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

                    const responseAction = result.data.action || action;
                    closeConfirmationModal();
                    showFeedbackModal(
                        responseMessages[responseAction] || result.data.message || 'Your response has been saved.',
                        function () { window.location.reload(); }
                    );
                })
                .catch(function (error) {
                    responseForm.dataset.submitting = '0';
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    const approveButton = confirmationModal.querySelector('[data-public-quote-confirmation-approve]');
                    if (approveButton && pendingConfirmation) {
                        approveButton.textContent = pendingConfirmation.label;
                    }
                    setConfirmationLoading(false);
                    window.alert(error.message || 'Unable to save your response.');
                });
        };

        responseForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const submitButton = event.submitter;
            if (!submitButton || responseForm.dataset.submitting === '1' || !confirmationModal.hidden) {
                return;
            }

            showConfirmationModal(submitButton.value, submitButton);
        });

        confirmationModal.querySelector('[data-public-quote-confirmation-cancel]')?.addEventListener('click', function () {
            if (responseForm.dataset.submitting !== '1') {
                closeConfirmationModal();
            }
        });
        confirmationModal.querySelector('[data-public-quote-confirmation-approve]')?.addEventListener('click', function () {
            if (!pendingConfirmation || responseForm.dataset.submitting === '1') {
                return;
            }

            submitQuotationResponse(pendingConfirmation.action, pendingConfirmation.submitButton);
        });
        confirmationModal.addEventListener('click', function (event) {
            if (event.target === confirmationModal && responseForm.dataset.submitting !== '1') {
                closeConfirmationModal();
            }
        });
    }

    document.querySelectorAll('[data-print-final-quotation], [data-download-review-pdf]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });
});
