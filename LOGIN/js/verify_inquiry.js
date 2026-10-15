document.addEventListener('DOMContentLoaded', function () {
    const verifyInquiryForm = document.getElementById('verifyInquiryForm');
    const verifyInquiryButton = document.getElementById('verifyInquiryButton');
    const backToHomeLink = document.querySelector('.verify-brand-home-link');
    const verifyInquiryAction = document.getElementById('verifyInquiryAction');
    const otpCodeInput = document.querySelector('.js-otp-code');
    const verifySentToast = document.getElementById('verifySentToast');
    const otpErrorBox = document.querySelector('[data-otp-error]');
    const countdown = document.querySelector('[data-otp-countdown]');
    const resendButton = document.getElementById('resendInquiryOtpButton');
    const expiryTimestamp = Date.parse(verifyInquiryForm?.dataset.otpExpiresAt || '');
    const otpState = verifyInquiryForm?.dataset.otpState || 'active';
    const isVerificationLimitReached = verifyInquiryForm?.dataset.verificationLimitReached === 'true';
    let isVerifyingInquiry = false;
    let isResendingOtp = false;
    let isOtpUnavailable = otpState === 'expired' || otpState === 'locked';
    let countdownTimer = null;
    let resendCooldownTimer = null;

    if (verifySentToast) {
        const url = new URL(window.location.href);
        url.searchParams.delete('sent');
        window.history.replaceState({}, document.title, url.toString());

        window.setTimeout(function () {
            verifySentToast.classList.add('is-closing');
            window.setTimeout(function () { verifySentToast.remove(); }, 250);
        }, 4200);
    }

    const showUnavailableState = function (state) {
        isOtpUnavailable = true;
        window.clearInterval(countdownTimer);
        if (countdown) {
            countdown.textContent = state === 'locked'
                ? 'Verification code locked.'
                : 'Your code has expired. Request a new code.';
            countdown.classList.remove('is-warning');
            countdown.classList.add('is-expired');
        }
        if (state === 'expired' && otpErrorBox && !isVerificationLimitReached) {
            otpErrorBox.remove();
        }
        if (otpCodeInput) {
            otpCodeInput.disabled = true;
        }
        if (verifyInquiryButton) {
            verifyInquiryButton.disabled = true;
            verifyInquiryButton.textContent = 'Verify Inquiry';
        }
    };

    const updateCountdown = function () {
        if (!Number.isFinite(expiryTimestamp)) {
            return;
        }

        const secondsLeft = Math.max(0, Math.ceil((expiryTimestamp - Date.now()) / 1000));
        if (secondsLeft <= 0) {
            showUnavailableState('expired');
            return;
        }

        const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
        const seconds = String(secondsLeft % 60).padStart(2, '0');
        if (countdown) {
            countdown.textContent = `Code expires in: ${minutes}:${seconds}`;
            countdown.classList.toggle('is-warning', secondsLeft <= 60);
        }
    };

    if (isOtpUnavailable) {
        showUnavailableState(otpState);
    } else if (Number.isFinite(expiryTimestamp)) {
        updateCountdown();
        if (!isOtpUnavailable) {
            countdownTimer = window.setInterval(updateCountdown, 1000);
        }
    }

    const updateResendCooldown = function () {
        if (!resendButton) {
            return;
        }

        const resendUntil = Date.parse(resendButton.dataset.resendCooldownUntil || '');
        if (!Number.isFinite(resendUntil)) {
            resendButton.textContent = 'Resend Code';
            resendButton.disabled = isResendingOtp;
            return;
        }

        const secondsLeft = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
        if (secondsLeft <= 0) {
            window.clearInterval(resendCooldownTimer);
            resendButton.textContent = 'Resend Code';
            if (!isResendingOtp) {
                resendButton.disabled = false;
            }
            return;
        }

        const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
        const seconds = String(secondsLeft % 60).padStart(2, '0');
        resendButton.textContent = `Resend Code (${minutes}:${seconds})`;
        resendButton.disabled = true;
    };

    if (resendButton) {
        updateResendCooldown();
        resendCooldownTimer = window.setInterval(updateResendCooldown, 1000);
    }

    otpCodeInput?.addEventListener('input', function () {
        otpCodeInput.value = otpCodeInput.value.replace(/\D/g, '').slice(0, 6);
    });

    verifyInquiryForm?.addEventListener('submit', function (event) {
        const isResendSubmission = verifyInquiryAction?.value === 'resend';
        if (isResendSubmission) {
            if (isResendingOtp || resendButton?.disabled) {
                event.preventDefault();
                return;
            }

            isResendingOtp = true;
            resendButton.disabled = true;
            resendButton.textContent = 'Sending code...';
            return;
        }

        if (verifyInquiryAction) {
            verifyInquiryAction.value = 'verify';
        }

        if (isOtpUnavailable || isVerifyingInquiry) {
            event.preventDefault();
            return;
        }

        isVerifyingInquiry = true;
        if (verifyInquiryButton) {
            verifyInquiryButton.disabled = true;
            verifyInquiryButton.textContent = 'Verifying...';
        }
    });

    const openLeaveVerificationConfirmation = function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!backToHomeLink) {
            return;
        }

        if ((otpCodeInput?.value || '').trim() === '') {
            window.location.assign(backToHomeLink.href);
            return;
        }

        if (isVerifyingInquiry || isResendingOtp) {
            return;
        }

        const confirmation = window.EdgePublicConfirmation?.open({
            title: 'Leave verification?',
            message: 'Your inquiry is not submitted yet.',
            cancelLabel: 'Stay',
            confirmLabel: 'Back to Home',
            tone: 'danger',
            onConfirm: function (controls) {
                controls.setBusy('Leaving...');
                window.location.assign(backToHomeLink.href);
            },
        });

        if (!confirmation) {
            // Huwag lumipat kapag hindi handa ang shared custom modal.
            return;
        }
    };

    const submitResend = function () {
        if (!verifyInquiryForm || !resendButton || !verifyInquiryAction) {
            return;
        }
        verifyInquiryAction.value = 'resend';
        verifyInquiryForm.requestSubmit(resendButton);
    };

    const openResendConfirmation = function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (isResendingOtp || resendButton?.disabled) {
            return;
        }

        if (isOtpUnavailable) {
            submitResend();
            return;
        }

        const confirmation = window.EdgePublicConfirmation?.open({
            title: 'Send a new code?',
            message: 'Your current code will stop working.',
            cancelLabel: 'Cancel',
            confirmLabel: 'Send New Code',
            onConfirm: function (controls) {
                controls.setBusy('Sending...');
                submitResend();
            },
        });

        if (!confirmation) {
            return;
        }
    };

    // Capture phase para hindi tumakbo ang luma o cached na native handler.
    document.addEventListener('click', function (event) {
        if (event.target instanceof Element && event.target.closest('.verify-brand-home-link')) {
            openLeaveVerificationConfirmation(event);
        }
        if (event.target instanceof Element && event.target.closest('#resendInquiryOtpButton')) {
            openResendConfirmation(event);
        }
    }, true);
});
