document.addEventListener('DOMContentLoaded', function () {
    const verifyInquiryForm = document.getElementById('verifyInquiryForm');
    const verifyInquiryButton = document.getElementById('verifyInquiryButton');
    const backToHomeLink = document.getElementById('backToHomeLink');
    const otpCodeInput = document.querySelector('.js-otp-code');
    const verifySentToast = document.getElementById('verifySentToast');
    const countdown = document.querySelector('[data-otp-countdown]');
    const expiryTimestamp = Date.parse(verifyInquiryForm?.dataset.otpExpiresAt || '');
    let isVerifyingInquiry = false;
    let isOtpExpired = false;
    let countdownTimer = null;

    if (verifySentToast) {
        const url = new URL(window.location.href);
        url.searchParams.delete('sent');
        window.history.replaceState({}, document.title, url.toString());

        window.setTimeout(function () {
            verifySentToast.classList.add('is-closing');
            window.setTimeout(function () { verifySentToast.remove(); }, 250);
        }, 4200);
    }

    const showExpiredState = function () {
        isOtpExpired = true;
        window.clearInterval(countdownTimer);
        if (countdown) {
            countdown.textContent = 'Verification code expired.';
            countdown.classList.remove('is-warning');
            countdown.classList.add('is-expired');
        }
        if (verifyInquiryButton) {
            verifyInquiryButton.disabled = true;
            verifyInquiryButton.textContent = 'Verification code expired';
        }
    };

    const updateCountdown = function () {
        if (!Number.isFinite(expiryTimestamp)) {
            return;
        }

        const secondsLeft = Math.max(0, Math.ceil((expiryTimestamp - Date.now()) / 1000));
        if (secondsLeft <= 0) {
            showExpiredState();
            return;
        }

        const minutes = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
        const seconds = String(secondsLeft % 60).padStart(2, '0');
        if (countdown) {
            countdown.textContent = `Code expires in: ${minutes}:${seconds}`;
            countdown.classList.toggle('is-warning', secondsLeft <= 60);
        }
    };

    if (Number.isFinite(expiryTimestamp)) {
        updateCountdown();
        if (!isOtpExpired) {
            countdownTimer = window.setInterval(updateCountdown, 1000);
        }
    }

    otpCodeInput?.addEventListener('input', function () {
        otpCodeInput.value = otpCodeInput.value.replace(/\D/g, '').slice(0, 6);
    });

    verifyInquiryForm?.addEventListener('submit', function (event) {
        if (isOtpExpired || isVerifyingInquiry) {
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
        if (!window.confirm('Leave verification? Your inquiry is not submitted yet.')) {
            event.preventDefault();
        }
    });
});
