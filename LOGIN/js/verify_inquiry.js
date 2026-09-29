document.addEventListener('DOMContentLoaded', function () {
    const verifyInquiryForm = document.getElementById('verifyInquiryForm');
    const verifyInquiryButton = document.getElementById('verifyInquiryButton');
    const backToHomeLink = document.getElementById('backToHomeLink');
    const otpCodeInput = document.querySelector('.js-otp-code');
    const verifySentToast = document.getElementById('verifySentToast');
    const countdown = document.querySelector('[data-otp-countdown]');
    const resendButton = document.getElementById('resendInquiryOtpButton');
    const resendCooldown = document.querySelector('[data-resend-cooldown-until]');
    const expiryTimestamp = Date.parse(verifyInquiryForm?.dataset.otpExpiresAt || '');
    const otpState = verifyInquiryForm?.dataset.otpState || 'active';
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
                : 'Verification code expired.';
            countdown.classList.remove('is-warning');
            countdown.classList.add('is-expired');
        }
        if (otpCodeInput) {
            otpCodeInput.disabled = true;
        }
        if (verifyInquiryButton) {
            verifyInquiryButton.disabled = true;
            verifyInquiryButton.textContent = state === 'locked'
                ? 'Verification code locked'
                : 'Verification code expired';
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
        if (!resendCooldown || !resendButton) {
            return;
        }

        const resendUntil = Date.parse(resendCooldown.dataset.resendCooldownUntil || '');
        if (!Number.isFinite(resendUntil)) {
            return;
        }

        const secondsLeft = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
        if (secondsLeft <= 0) {
            window.clearInterval(resendCooldownTimer);
            resendCooldown.textContent = 'Request a new verification code.';
            if (!isResendingOtp) {
                resendButton.disabled = false;
            }
            return;
        }

        const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
        const seconds = String(secondsLeft % 60).padStart(2, '0');
        resendCooldown.textContent = `Send new code in ${minutes}:${seconds}`;
        resendButton.disabled = true;
    };

    if (resendCooldown && resendButton) {
        updateResendCooldown();
        resendCooldownTimer = window.setInterval(updateResendCooldown, 1000);
    }

    otpCodeInput?.addEventListener('input', function () {
        otpCodeInput.value = otpCodeInput.value.replace(/\D/g, '').slice(0, 6);
    });

    verifyInquiryForm?.addEventListener('submit', function (event) {
        if (event.submitter === resendButton) {
            if (isResendingOtp || resendButton.disabled) {
                event.preventDefault();
                return;
            }

            isResendingOtp = true;
            resendButton.disabled = true;
            resendButton.textContent = 'Sending code...';
            return;
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

    backToHomeLink?.addEventListener('click', function (event) {
        event.preventDefault();
        if (isVerifyingInquiry) {
            return;
        }

        const confirmation = window.EdgePublicConfirmation?.open({
            title: 'Leave verification?',
            message: 'Your inquiry has not been submitted yet. Leaving this page will cancel the current verification process.',
            cancelLabel: 'Stay & Verify',
            confirmLabel: 'Leave Verification',
            tone: 'danger',
            onConfirm: function (controls) {
                controls.setBusy('Leaving...');
                window.location.assign(backToHomeLink.href);
            },
        });

        if (!confirmation) {
            window.location.assign(backToHomeLink.href);
        }
    });
});
