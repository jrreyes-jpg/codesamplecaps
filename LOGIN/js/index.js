const initMobileMenu = () => {
    const hamburger = document.querySelector('.hamburger');
    const navMenu = document.querySelector('.nav-menu');

    if (!hamburger || !navMenu) {
        return;
    }

    const closeMenu = () => {
        navMenu.classList.remove('active');
        hamburger.classList.remove('active');
        hamburger.setAttribute('aria-expanded', 'false');
    };

    hamburger.addEventListener('click', () => {
        const isOpen = navMenu.classList.toggle('active');
        hamburger.classList.toggle('active', isOpen);
        hamburger.setAttribute('aria-expanded', String(isOpen));
    });

    document.querySelectorAll('.nav-link').forEach((link) => {
        link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
};


const validateForm = (formData) => {
    if (!formData.name.trim() || !formData.email.trim() || !formData.message.trim()) {
        return false;
    }

    return /^[^^@\s]+@[^\s@]+\.[^\s@]+$/.test(formData.email);
};

const showNotification = (message, type = 'info') => {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);

    window.setTimeout(() => {
        notification.classList.add('is-closing');
        window.setTimeout(() => notification.remove(), 300);
    }, 4000);
};

const inquiryFormReturnStatuses = new Set([
    'invalid',
    'contact_not_allowed',
    'contact_mismatch',
    'email_error',
    'email_not_verified',
    'expired',
    'server_error',
]);

const inquiryAutoOpenRequested = () => {
    const url = new URL(window.location.href);
    return url.searchParams.get('open') === 'inquiry';
};

const shouldAutoOpenInquiryModal = () => (
    inquiryFormReturnStatuses.has(String(window.edgeInquiryStatus || ''))
    || inquiryAutoOpenRequested()
);

const consumeInquiryAutoOpenRequest = () => {
    const url = new URL(window.location.href);
    if (url.searchParams.get('open') !== 'inquiry') {
        return;
    }

    url.searchParams.delete('open');
    window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`);
};

const showInquirySuccessModal = () => {
    const modal = document.createElement('div');
    modal.className = 'inquiry-success-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'inquirySuccessTitle');
    modal.innerHTML = [
        '<div class="inquiry-success-modal__card">',
        '<div class="inquiry-success-modal__icon" aria-hidden="true">✓</div>',
        '<h2 id="inquirySuccessTitle">Inquiry Received</h2>',
        '<p><strong>What happens next?</strong><br>Edge Automation Technology Services, Co. will confirm the Engineer\'s availability. If needed, we will call you to agree on another date. Your confirmed site inspection schedule will be sent by email.</p>',
        '<button type="button" class="btn btn-primary" data-inquiry-success-close>Back to Home</button>',
        '</div>',
    ].join('');

    document.body.appendChild(modal);

    const closeModal = () => {
        modal.classList.add('is-closing');
        window.setTimeout(() => modal.remove(), 220);
    };

    modal.querySelector('[data-inquiry-success-close]')?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function closeOnEscape(event) {
        if (event.key === 'Escape') {
            closeModal();
            document.removeEventListener('keydown', closeOnEscape);
        }
    });
};

const initInquiryStatusToast = () => {
    const status = window.edgeInquiryStatus || '';

    if (!status) {
        return;
    }

    const messages = {
        success: ['Inquiry sent. Please wait for our call or email.', 'success'],
        invalid: ['Please check the form and try again.', 'error'],
        contact_not_allowed: ['These contact details cannot be used for this inquiry. Please use your own contact information.', 'error'],
        contact_mismatch: ['The provided contact details do not match our records.', 'error'],
        email_error: ['We could not send the verification code. Please try again later.', 'error'],
        email_not_verified: ['Please verify your email before submitting.', 'error'],
        expired: ['The verification code expired. Please submit the inquiry again.', 'error'],
    };
    const [message, type] = messages[status] || ['Sorry, the inquiry service had a problem. Please try again later.', 'error'];

    if (status === 'success') {
        localStorage.removeItem('edgeInquiryFormDraft');
        document.querySelectorAll('.js-inquiry-form').forEach((form) => {
            form.reset();
            const contactInput = form.querySelector('.js-inquiry-contact');
            if (contactInput) {
                contactInput.value = '09';
            }
            form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
            form.querySelectorAll('.field-error').forEach((fieldError) => {
                fieldError.textContent = '';
                fieldError.classList.remove('is-visible');
            });
            const message = form.querySelector('.js-inquiry-message');
            if (message) {
                message.textContent = '';
                message.classList.remove('is-error');
            }
        });

        const inquiryModal = document.getElementById('inquiryModal');
        if (inquiryModal) {
            inquiryModal.classList.remove('is-open');
            inquiryModal.setAttribute('aria-hidden', 'true');
        }

        showInquirySuccessModal();
    } else {
        showNotification(message, type);
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('inquiry');
    window.history.replaceState({}, document.title, `${url.pathname}${url.search}${url.hash}`);
};

const initFormHandling = () => {
    const contactForm = document.getElementById('contactForm');

    if (!contactForm) {
        return;
    }

    contactForm.addEventListener('submit', (event) => {
        event.preventDefault();

        const formData = {
            name: document.getElementById('name')?.value ?? '',
            email: document.getElementById('email')?.value ?? '',
            message: document.getElementById('message')?.value ?? '',
        };

        if (!validateForm(formData)) {
            showNotification('Please fill in all fields correctly.', 'error');
            return;
        }

        showNotification('Thank you for your message. We will contact you soon.', 'success');
        contactForm.reset();
    });
};

const initScrollAnimations = () => {
    const elements = document.querySelectorAll('.service-card, .feature, .project-item');

    if (elements.length === 0) {
        return;
    }

    const observer = new IntersectionObserver((entries, intersectionObserver) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-visible');
            intersectionObserver.unobserve(entry.target);
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -100px 0px',
    });

    elements.forEach((element) => {
        element.classList.add('reveal-on-scroll');
        observer.observe(element);
    });
};

const initNavbarScroll = () => {
    const navbar = document.querySelector('.navbar');

    if (!navbar) {
        return;
    }

    window.addEventListener('scroll', () => {
        navbar.classList.toggle('scrolled', window.scrollY > 10);
        navbar.classList.toggle('navbar-scrolled-deep', window.scrollY > 100);
    });
};

const initNewClientTooltip = () => {
    const tooltip = document.getElementById('newClientTip');
    const dismissButton = document.getElementById('dismissTip');

    if (!tooltip || !dismissButton) return;

    tooltip.classList.remove('hidden');

    dismissButton.addEventListener('click', function () {
        tooltip.classList.add('hidden');
    });
};

const initConsultationModal = () => {
    const openButtons = document.querySelectorAll('#consultBtn, #consultBtnSecondary, #consultBtnMobile');
    const closeButton = document.getElementById('closeConsult');
    const modal = document.getElementById('consultModal');
    const openInquiryButton = document.getElementById('openInquiryModal');
    const inquiryModal = document.getElementById('inquiryModal');
    const closeInquiryButton = document.getElementById('closeInquiryModal');
    const closeInquiryXButton = document.getElementById('closeInquiryModalX');
    const inquiryModalStateKey = 'edgeInquiryModalOpen';

    if (openButtons.length === 0 || !closeButton || !modal) {
        return;
    }

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };

    const openInquiryModal = () => {
        closeModal();
        if (!inquiryModal) {
            window.location.href = '#contact';
            return;
        }

        inquiryModal.classList.add('is-open');
        inquiryModal.setAttribute('aria-hidden', 'false');
        try {
            window.sessionStorage.setItem(inquiryModalStateKey, '1');
        } catch (error) {
        }
    };

    const closeInquiryModal = () => {
        if (!inquiryModal) {
            return;
        }

        inquiryModal.classList.remove('is-open');
        inquiryModal.setAttribute('aria-hidden', 'true');
        try {
            window.sessionStorage.removeItem(inquiryModalStateKey);
        } catch (error) {
        }
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    closeButton.addEventListener('click', closeModal);

    if (openInquiryButton) {
        openInquiryButton.addEventListener('click', openInquiryModal);
    }

    if (closeInquiryButton) {
        closeInquiryButton.addEventListener('click', closeInquiryModal);
    }

    if (closeInquiryXButton) {
        closeInquiryXButton.addEventListener('click', closeInquiryModal);
    }

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    if (inquiryModal) {
        inquiryModal.addEventListener('click', (event) => {
            if (event.target === inquiryModal) {
                closeInquiryModal();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }

        if (event.key === 'Escape' && inquiryModal?.classList.contains('is-open')) {
            closeInquiryModal();
        }
    });

    let reopenInquiryAfterRefresh = false;
    try {
        reopenInquiryAfterRefresh = window.sessionStorage.getItem(inquiryModalStateKey) === '1';
        if (window.edgeInquiryStatus === 'success') {
            window.sessionStorage.removeItem(inquiryModalStateKey);
            reopenInquiryAfterRefresh = false;
        }
    } catch (error) {
    }

    if (shouldAutoOpenInquiryModal() || reopenInquiryAfterRefresh) {
        openInquiryModal();
        consumeInquiryAutoOpenRequest();
    } else {
        closeInquiryModal();
    }

    window.addEventListener('pageshow', (event) => {
        if (event.persisted && !shouldAutoOpenInquiryModal()) {
            closeInquiryModal();
        }
    });
};

const initServiceCards = () => {
    const cards = document.querySelectorAll('.service-card');

    cards.forEach((card) => {
        const button = card.querySelector('.service-more');

        if (!button) {
            return;
        }

        button.addEventListener('click', () => {
            const willExpand = !card.classList.contains('is-expanded');

            cards.forEach((serviceCard) => {
                serviceCard.classList.remove('is-expanded');
                const serviceButton = serviceCard.querySelector('.service-more');
                serviceButton?.setAttribute('aria-expanded', 'false');
                if (serviceButton) {
                    serviceButton.textContent = 'View details';
                }
            });

            card.classList.toggle('is-expanded', willExpand);
            const isExpanded = card.classList.contains('is-expanded');
            button.setAttribute('aria-expanded', String(isExpanded));
            button.textContent = isExpanded ? 'Hide details' : 'View details';
        });
    });
};

const initInquiryForm = () => {
    const inquiryForms = document.querySelectorAll('.js-inquiry-form');

    if (inquiryForms.length === 0) {
        return;
    }

    const phonePattern = /^09\d{9}$/;
    const emailPattern = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;
    const clearFieldError = (field) => {
        field.classList.remove('is-invalid', 'is-unverified');
        field.removeAttribute('aria-invalid');
        const error = field.closest('label')?.querySelector('.field-error');
        if (error) {
            error.textContent = '';
            error.classList.remove('is-visible', 'is-warning');
        }
    };

    const resetFieldState = (field) => {
        clearFieldError(field);
        field.classList.remove('is-valid');
    };

    const setFieldValid = (field) => {
        clearFieldError(field);
        field.classList.add('is-valid');
        field.setAttribute('aria-invalid', 'false');
    };

    const setFieldError = (field, text) => {
        field.classList.remove('is-valid', 'is-unverified');
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        const error = field.closest('label')?.querySelector('.field-error');
        if (error) {
            error.textContent = text;
            error.classList.remove('is-warning');
            error.classList.add('is-visible');
        }
    };

    const setFieldWarning = (field, text) => {
        clearFieldError(field);
        field.classList.remove('is-valid');
        field.classList.add('is-unverified');
        field.setAttribute('aria-invalid', 'false');
        const error = field.closest('label')?.querySelector('.field-error');
        if (error) {
            error.textContent = text;
            error.classList.add('is-visible', 'is-warning');
        }
    };

    const hasMeaningfulText = (value, minimumLetters = 2) => {
        const letters = value.match(/\p{L}/gu) || [];
        return letters.length >= minimumLetters;
    };

    const validateFullNameText = (field) => {
        const value = field.value.trim();

        if (value === '') {
            return true;
        }

        if (Array.from(value).length > 150) {
            setFieldError(field, 'Full name must be 150 characters or less.');
            return false;
        }

        if (!/^[\p{L}\p{M} .'\u2019\-]+$/u.test(value)) {
            setFieldError(field, 'Use letters, spaces, periods, hyphens, or apostrophes only.');
            return false;
        }

        if (!hasMeaningfulText(value, 2)) {
            setFieldError(field, 'Please enter a real full name.');
            return false;
        }

        setFieldValid(field);
        return true;
    };

    const validateMeaningfulTextField = (field, label, minimumLetters = 4) => {
        const value = field.value.trim();

        if (value === '') {
            return true;
        }

        if (!hasMeaningfulText(value, minimumLetters)) {
            setFieldError(field, `${label} must include real words, not only numbers or symbols.`);
            return false;
        }

        setFieldValid(field);
        return true;
    };

    const validateCompanyName = (field) => {
        const value = field.value.trim();
        if (value === '') {
            resetFieldState(field);
            return true;
        }
        if (Array.from(value).length < 2 || Array.from(value).length > 150) {
            setFieldError(field, 'Company name must be 2 to 150 characters.');
            return false;
        }
        if (!/^[\p{L}\p{M}\p{N} .,'\u2019&()\-]+$/u.test(value) || !hasMeaningfulText(value, 1)) {
            setFieldError(field, 'Use a real company name. Numbers and common business symbols are allowed.');
            return false;
        }
        setFieldValid(field);
        return true;
    };

    const validateSiteAddress = (field) => {
        const value = field.value.trim();
        if (value === '') {
            resetFieldState(field);
            return true;
        }
        if (Array.from(value).length < 5) {
            setFieldError(field, 'Enter at least 5 characters.');
            return false;
        }
        if (!/^[\p{L}\p{M}\p{N}\s#.,'\u2019\/()&\-]+$/u.test(value) || !hasMeaningfulText(value, 3)) {
            setFieldError(field, 'Enter a real address with at least 3 letters. Common address symbols are allowed.');
            return false;
        }
        setFieldValid(field);
        return true;
    };

    const validateProjectDescription = (field) => {
        const value = field.value.trim();

        if (value === '') {
            resetFieldState(field);
            return true;
        }

        if (Array.from(value).length < 10) {
            setFieldError(field, 'Enter at least 10 characters.');
            return false;
        }

        return validateMeaningfulTextField(field, 'Project Description', 5);
    };

    const normalizeContactNumber = (field) => {
        let digits = field.value.replace(/\D/g, '');

        if (digits.startsWith('639')) {
            digits = '09' + digits.slice(3);
        } else if (!digits.startsWith('09')) {
            digits = '09' + digits.replace(/^0+/, '').replace(/^9?/, '');
        }

        field.value = digits.slice(0, 11);
    };

    inquiryForms.forEach((inquiryForm) => {
        const contactInput = inquiryForm.querySelector('.js-inquiry-contact');
        const clientNameInput = inquiryForm.querySelector('input[name="client_name"]');
        const inspectionDateInput = inquiryForm.querySelector('.js-inspection-date');
        let preferredDateMin = inspectionDateInput?.min || '';
        let preferredDateMax = inspectionDateInput?.max || '';
        const datePickerButton = inquiryForm.querySelector('.js-date-picker-button');
        const dateInfoButton = inquiryForm.querySelector('.js-date-info-button');
        const dateTooltip = inquiryForm.querySelector('.js-date-tooltip');
        const serviceSelect = inquiryForm.querySelector('select[name="service_category"]');
        const regionSelect = inquiryForm.querySelector('.js-inquiry-region');
        const areaSelect = inquiryForm.querySelector('.js-inquiry-area');
        const provinceInput = inquiryForm.querySelector('.js-inquiry-province');
        const citySelect = inquiryForm.querySelector('.js-inquiry-city');
        const barangayInput = inquiryForm.querySelector('.js-inquiry-barangay');
        const otherServiceField = inquiryForm.querySelector('.other-service-field');
        const otherServiceInput = inquiryForm.querySelector('input[name="other_service_details"]');
        const message = inquiryForm.querySelector('.js-inquiry-message');
        const clearDraftButton = inquiryForm.querySelector('.js-clear-inquiry-draft');
        const submitButton = inquiryForm.querySelector('.inquiry-submit');
        const emailInput = inquiryForm.querySelector('input[name="email"]');
        const verifyEmailButton = inquiryForm.querySelector('.js-inquiry-email-verify');
        const emailVerifyStatus = inquiryForm.querySelector('.js-inquiry-email-status');
        const otpModal = document.querySelector('[data-inquiry-otp-modal]');
        const otpCodeInput = otpModal?.querySelector('.js-inquiry-otp-code');
        const otpEmail = otpModal?.querySelector('.js-inquiry-otp-email');
        const otpMessage = otpModal?.querySelector('.js-inquiry-otp-message');
        const otpVerifyButton = otpModal?.querySelector('.js-inquiry-otp-confirm');
        const otpResendButton = otpModal?.querySelector('.js-inquiry-otp-resend');
        const otpTimer = otpModal?.querySelector('.js-inquiry-otp-timer');
        const contactIdentityFields = [
            clientNameInput,
            inquiryForm.querySelector('input[name="email"]'),
            contactInput,
        ].filter(Boolean);
        const contactFeedback = inquiryForm.querySelector('[data-inquiry-contact-feedback]');
        const draftKey = 'edgeInquiryFormDraft';
        let isSubmittingInquiry = false;
        let isSubmitConfirmed = false;
        let verifiedEmail = (inquiryForm.dataset.verifiedEmail || '').toLowerCase();
        let isOtpBusy = false;
        let otpClock = 0;
        let otpExpiresAt = 0;
        let otpResendAt = 0;
        let contactCheckTimer = 0;
        let contactCheckId = 0;
        let contactCanVerifyEmail = false;
        let contactNote = '';

        if (!contactInput || !message) {
            return;
        }

        const inquiryModalContent = inquiryForm.closest('.inquiry-modal-content');

        const updateEmailVerification = () => {
            const currentEmail = (emailInput?.value || '').trim().toLowerCase();
            const isVerified = currentEmail !== '' && currentEmail === verifiedEmail;

            emailVerifyStatus.textContent = isVerified
                ? 'Email verified'
                : (contactNote || 'Email not verified');
            emailVerifyStatus.classList.toggle('is-verified', isVerified);
            emailInput.readOnly = isVerified;
            emailInput.classList.toggle('is-email-locked', isVerified);
            verifyEmailButton.textContent = isVerified ? 'Change Email' : 'Verify Email';
            verifyEmailButton.disabled = !isVerified && !emailPattern.test(currentEmail);
            if (isVerified) {
                setFieldValid(emailInput);
            } else if (emailInput.classList.contains('is-valid')) {
                resetFieldState(emailInput);
            }
        };

        const unlockVerifiedEmail = () => {
            verifiedEmail = '';
            inquiryForm.dataset.verifiedEmail = '';
            contactNote = '';
            updateEmailVerification();
            resetFieldState(emailInput);
            message.textContent = '';
            message.classList.remove('is-error');
            emailInput?.focus();
        };

        const applyContactCheck = (result) => {
            if (!result || result.status === 'checking') return false;

            if (result.complete === false) {
                contactCanVerifyEmail = false;
                contactNote = '';
                updateEmailVerification();
                return false;
            }

            if (!result.valid) {
                const field = result.field || 'email';
                const error = result.message || 'Invalid client details.';
                if (field === 'name' || field === 'both') setFieldError(clientNameInput, error);
                if (field === 'email' || field === 'both') setFieldError(emailInput, error);
                if (field === 'phone' || field === 'both') setFieldError(contactInput, error);
                verifiedEmail = '';
                inquiryForm.dataset.verifiedEmail = '';
                contactCanVerifyEmail = false;
                contactNote = 'Email cannot be verified.';
                updateEmailVerification();
                return false;
            }

            contactCanVerifyEmail = result.can_verify_email !== false || !!result.email_verified;
            if (result.status === 'existing_client' && result.email_verified) {
                verifiedEmail = (emailInput?.value || '').trim().toLowerCase();
                inquiryForm.dataset.verifiedEmail = verifiedEmail;
                contactNote = 'Existing client found.';
            } else if (result.status === 'existing_inquiry') {
                contactNote = 'Existing inquiry found. Verify email.';
            } else {
                contactNote = '';
            }
            updateEmailVerification();
            return contactCanVerifyEmail || !!result.email_verified;
        };

        const checkContactDetails = async () => {
            const name = (clientNameInput?.value || '').trim();
            const email = (emailInput?.value || '').trim();
            const phone = (contactInput?.value || '').trim();
            const requestId = ++contactCheckId;
            if (name === '' || !emailPattern.test(email) || !phonePattern.test(phone)) {
                contactCanVerifyEmail = false;
                contactNote = '';
                updateEmailVerification();
                return false;
            }

            try {
                const response = await fetch('check_inquiry_contact.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: new URLSearchParams({
                        client_name: name,
                        email,
                        contact_no: phone,
                        inquiry_form_token: inquiryForm.querySelector('input[name="inquiry_form_token"]')?.value || '',
                    }),
                    credentials: 'same-origin',
                });
                const result = await response.json();
                if (requestId === contactCheckId) return applyContactCheck(result);
                return false;
            } catch (error) {
                // Server validation will still protect the final submit.
                contactCanVerifyEmail = false;
                updateEmailVerification();
                return false;
            }
        };

        const queueContactCheck = () => {
            window.clearTimeout(contactCheckTimer);
            contactCheckTimer = window.setTimeout(checkContactDetails, 450);
        };

        const setOtpBusy = (busy, label = '') => {
            isOtpBusy = busy;
            otpModal?.classList.toggle('is-busy', busy);
            otpModal?.classList.toggle('is-sending', busy);
            otpModal?.setAttribute('aria-busy', busy ? 'true' : 'false');
            [otpCodeInput, otpVerifyButton, otpResendButton].forEach((control) => {
                if (control) control.disabled = busy;
            });
            if (busy && otpMessage) {
                otpMessage.textContent = label;
                otpMessage.classList.remove('is-error');
            }
        };

        const openOtpModal = (isSending = false) => {
            if (!otpModal || !emailInput) return;
            otpEmail.textContent = emailInput.value.trim();
            otpCodeInput.value = '';
            otpMessage.textContent = '';
            otpMessage.classList.remove('is-error');
            otpModal.classList.add('is-open');
            otpModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('has-inquiry-otp-open');
            otpModal.classList.toggle('is-sending', isSending);
            otpModal.setAttribute('aria-busy', isSending ? 'true' : 'false');
            if (!isSending) window.setTimeout(() => otpCodeInput.focus(), 80);
        };

        const closeOtpModal = (force = false) => {
            if (!otpModal || (isOtpBusy && !force)) return;
            if (!force && otpCodeInput?.value.trim()) {
                window.EdgePublicConfirmation?.open({
                    title: 'Close email verification?',
                    message: 'Your code will not be saved.',
                    cancelLabel: 'Keep Verifying',
                    confirmLabel: 'Close',
                    tone: 'danger',
                    onConfirm: (controls) => {
                        controls.close();
                        closeOtpModal(true);
                    },
                });
                return;
            }
            otpModal.classList.remove('is-open');
            otpModal.classList.remove('is-sending');
            otpModal.setAttribute('aria-hidden', 'true');
            otpModal.removeAttribute('aria-busy');
            document.body.classList.remove('has-inquiry-otp-open');
            window.clearInterval(otpClock);
            otpClock = 0;
        };

        const otpRequest = async (endpoint, body) => {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams(body),
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({ success: false, message: 'Could not connect. Check your internet.' }));
            if (!response.ok || !data.success) {
                const error = new Error(data.message || 'Could not complete verification.');
                error.retryAfter = Number(data.retry_after) || 0;
                error.expiresIn = Number(data.expires_in) || 0;
                error.locked = data.locked === true;
                error.field = data.field || '';
                throw error;
            }
            return data;
        };

        const startOtpClock = (expiresIn, resendIn) => {
            window.clearInterval(otpClock);
            otpExpiresAt = Date.now() + Math.max(0, Number(expiresIn) || 0) * 1000;
            otpResendAt = Date.now() + Math.max(0, Number(resendIn) || 0) * 1000;

            const render = () => {
                const expiresInSeconds = Math.max(0, Math.ceil((otpExpiresAt - Date.now()) / 1000));
                const resendInSeconds = Math.max(0, Math.ceil((otpResendAt - Date.now()) / 1000));
                const format = (seconds) => `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
                if (otpTimer) {
                    otpTimer.textContent = expiresInSeconds > 0 ? `Code expires in ${format(expiresInSeconds)}` : 'Code expired. Resend a new code.';
                    otpTimer.classList.toggle('is-warning', expiresInSeconds > 0 && expiresInSeconds <= 60);
                    otpTimer.classList.toggle('is-expired', expiresInSeconds === 0);
                }
                if (!otpResendButton) return;
                otpResendButton.disabled = resendInSeconds > 0;
                otpResendButton.textContent = resendInSeconds > 0
                    ? `Resend Code (${format(resendInSeconds)})`
                    : 'Resend Code';
            };

            render();
            otpClock = window.setInterval(() => {
                const expired = Date.now() >= otpExpiresAt;
                render();
                if (expired && Date.now() >= otpResendAt) window.clearInterval(otpClock);
            }, 1000);
        };

        const sendInquiryOtp = async (isResend = false) => {
            const name = clientNameInput?.value.trim() || '';
            const email = emailInput?.value.trim() || '';
            const phone = contactInput?.value.trim() || '';
            if (!isResend && !validateFullNameText(clientNameInput)) {
                gentlyRevealField(clientNameInput, true);
                return;
            }
            if (!emailPattern.test(email)) {
                setFieldError(emailInput, 'Please enter a valid email address.');
                gentlyRevealField(emailInput, true);
                emailInput?.focus();
                return;
            }
            if (!phonePattern.test(phone)) {
                setFieldError(contactInput, 'Enter a valid contact number.');
                gentlyRevealField(contactInput, true);
                contactInput?.focus();
                return;
            }
            if (!isResend && !contactCanVerifyEmail && !(await checkContactDetails())) {
                return;
            }
            if (!isResend && email.toLowerCase() === verifiedEmail) {
                updateEmailVerification();
                return;
            }

            verifyEmailButton.disabled = true;
            verifyEmailButton.textContent = 'Sending...';
            openOtpModal(true);
            setOtpBusy(true, 'Sending verification code...');
            let resendAfter = 0;
            let expiresIn = 0;
            let existingCode = false;
            let successMessage = '';
            try {
                const result = await otpRequest('send_inquiry_email_otp.php', {
                    client_name: name,
                    email,
                    contact_no: phone,
                    inquiry_form_token: inquiryForm.querySelector('input[name="inquiry_form_token"]')?.value || '',
                    resend: isResend ? '1' : '0',
                });
                resendAfter = Number(result.resend_after) || 0;
                expiresIn = Number(result.expires_in) || 180;
                successMessage = result.message || 'If the email is valid, a verification code was sent.';
            } catch (error) {
                if (error.locked) {
                    setFieldError(emailInput, error.message || 'Email verification is locked. Try again later.');
                    closeOtpModal(true);
                } else if (error.retryAfter > 0) {
                    resendAfter = error.retryAfter;
                    expiresIn = error.expiresIn || 180;
                    existingCode = true;
                } else {
                    const errorField = error.field === 'name'
                        ? clientNameInput
                        : (error.field === 'phone' ? contactInput : emailInput);
                    if (error.field === 'both') {
                        setFieldError(emailInput, error.message || 'Check the contact details.');
                        setFieldError(contactInput, error.message || 'Check the contact details.');
                    } else {
                        setFieldError(errorField, error.message || 'Could not send code.');
                    }
                    closeOtpModal(true);
                }
            } finally {
                setOtpBusy(false);
                if (resendAfter > 0) {
                    startOtpClock(expiresIn, resendAfter);
                    otpMessage.textContent = existingCode ? 'A code was already sent. Enter it below.' : successMessage;
                    otpMessage.classList.remove('is-error');
                    window.requestAnimationFrame(() => {
                        otpCodeInput?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        otpCodeInput?.focus({ preventScroll: true });
                    });
                }
                if ((emailInput?.value || '').trim().toLowerCase() !== verifiedEmail) updateEmailVerification();
            }
        };

        verifyEmailButton?.addEventListener('click', () => {
            const currentEmail = (emailInput?.value || '').trim().toLowerCase();
            if (currentEmail !== '' && currentEmail === verifiedEmail) {
                unlockVerifiedEmail();
                return;
            }

            const fullName = (clientNameInput?.value || '').trim();
            const contactNumber = (contactInput?.value || '').trim();
            const hasValidName = fullName !== '' && validateFullNameText(clientNameInput);
            const hasValidContact = phonePattern.test(contactNumber);

            if (!hasValidName) {
                setFieldError(clientNameInput, 'Please enter your Full Name first.');
            }
            if (!hasValidContact) {
                setFieldError(contactInput, 'Enter an 11-digit contact number starting with 09.');
            }
            if (!hasValidName || !hasValidContact) {
                (hasValidName ? contactInput : clientNameInput)?.focus({ preventScroll: true });
                return;
            }

            sendInquiryOtp(false);
        });
        otpModal?.querySelectorAll('.js-inquiry-otp-close').forEach((button) => {
            button.addEventListener('click', () => closeOtpModal());
        });
        otpModal?.addEventListener('click', (event) => {
            if (event.target === otpModal) closeOtpModal();
        });
        otpCodeInput?.addEventListener('input', () => {
            otpCodeInput.value = otpCodeInput.value.replace(/\D/g, '').slice(0, 6);
        });
        otpResendButton?.addEventListener('click', () => sendInquiryOtp(true));
        otpVerifyButton?.addEventListener('click', async () => {
            const otp = otpCodeInput?.value.trim() || '';
            if (!/^\d{6}$/.test(otp)) {
                otpMessage.textContent = 'Enter the 6-digit code.';
                otpMessage.classList.add('is-error');
                otpCodeInput?.focus();
                return;
            }

            setOtpBusy(true, 'Verifying code...');
            try {
                await otpRequest('verify_inquiry_email_otp.php', {
                    otp,
                    inquiry_form_token: inquiryForm.querySelector('input[name="inquiry_form_token"]')?.value || '',
                });
                verifiedEmail = (emailInput?.value || '').trim().toLowerCase();
                inquiryForm.dataset.verifiedEmail = verifiedEmail;
                clearFieldError(emailInput);
                message.textContent = '';
                message.classList.remove('is-error');
                updateEmailVerification();
                closeOtpModal(true);
            } catch (error) {
                otpMessage.textContent = error.message || 'Could not verify code.';
                otpMessage.classList.add('is-error');
            } finally {
                setOtpBusy(false);
            }
        });

        updateEmailVerification();

        const gentlyRevealField = (field, center = false) => {
            if (!field || !inquiryModalContent) {
                field?.scrollIntoView({ behavior: 'smooth', block: center ? 'center' : 'nearest' });
                return;
            }

            const fieldRect = field.getBoundingClientRect();
            const modalRect = inquiryModalContent.getBoundingClientRect();
            const isHiddenAbove = fieldRect.top < modalRect.top + 24;
            const isHiddenBelow = fieldRect.bottom > modalRect.bottom - 24;

            // I-scroll lang kapag hindi kita ang field para hindi hilo ang user habang nagta-tab.
            if (center || isHiddenAbove || isHiddenBelow) {
                field.scrollIntoView({ behavior: 'smooth', block: center ? 'center' : 'nearest' });
            }
        };

        const clearFormMessageIfReady = () => {
            if (!inquiryForm.querySelector('.is-invalid')) {
                message.textContent = '';
                message.classList.remove('is-error');
            }
        };

        const clearContactMismatchFeedback = (field = null) => {
            const fieldsToClear = field ? [field] : contactIdentityFields;
            fieldsToClear.forEach((contactField) => {
                if (contactField.dataset.contactMismatch !== '1') {
                    return;
                }

                contactField.classList.remove('is-invalid');
                contactField.removeAttribute('aria-invalid');
                delete contactField.dataset.contactMismatch;
            });

            if (!contactIdentityFields.some((contactField) => contactField.dataset.contactMismatch === '1')) {
                contactFeedback.textContent = '';
                contactFeedback.classList.remove('is-visible');
            }
        };

        const showContactMismatchFeedback = () => {
            contactIdentityFields.forEach((contactField) => {
                contactField.dataset.contactMismatch = '1';
                contactField.classList.add('is-invalid');
                contactField.setAttribute('aria-invalid', 'true');
            });

            contactFeedback.textContent = 'Please review the contact person, email address, and contact number.';
            contactFeedback.classList.add('is-visible');
            window.requestAnimationFrame(() => {
                gentlyRevealField(clientNameInput, true);
                clientNameInput?.focus();
            });
        };

        normalizeContactNumber(contactInput);

        const syncPreferredDateWindow = () => {
            if (!inspectionDateInput) {
                return;
            }

            const formatter = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                hourCycle: 'h23',
            });
            const parts = formatter.formatToParts(new Date()).reduce((values, part) => {
                if (part.type !== 'literal') values[part.type] = part.value;
                return values;
            }, {});
            const today = `${parts.year}-${parts.month}-${parts.day}`;
            const addDays = (dateValue, days) => {
                const values = dateValue.split('-').map(Number);
                return new Date(Date.UTC(values[0], values[1] - 1, values[2] + days)).toISOString().slice(0, 10);
            };

            preferredDateMin = Number.parseInt(parts.hour || '0', 10) >= 17 ? addDays(today, 1) : today;
            preferredDateMax = addDays(today, 30);
            inspectionDateInput.min = preferredDateMin;
            inspectionDateInput.max = preferredDateMax;
        };
        syncPreferredDateWindow();

        const validatePreferredDateRange = () => {
            if (!inspectionDateInput) {
                return true;
            }

            syncPreferredDateWindow();
            const value = inspectionDateInput.value;
            if (value !== '' && (value < preferredDateMin || value > preferredDateMax)) {
                setFieldError(inspectionDateInput, 'Before 5:00 PM, today is allowed. From 5:00 PM, choose tomorrow onward.');
                return false;
            }

            if (value !== '') {
                setFieldValid(inspectionDateInput);
            } else {
                resetFieldState(inspectionDateInput);
            }
            return true;
        };

        const closeCombobox = (input) => {
            const box = input?.closest('[data-combobox]');
            box?.classList.remove('is-open');
            const list = box?.querySelector('[data-combobox-list]');
            if (list) {
                list.innerHTML = '';
            }
        };

        const closeOtherComboboxes = (activeInput) => {
            inquiryForm.querySelectorAll('[data-combobox]').forEach((box) => {
                if (box !== activeInput?.closest('[data-combobox]')) {
                    box.classList.remove('is-open');
                }
            });
        };

        const renderComboboxOptions = (input, options, showAll = false, optionGroups = null) => {
            const box = input?.closest('[data-combobox]');
            const list = box?.querySelector('[data-combobox-list]');
            if (!input || !list) {
                return;
            }

            closeOtherComboboxes(input);
            const search = showAll ? '' : input.value.trim().toLowerCase();
            const matches = options.filter((option) => option.toLowerCase().includes(search));
            list.innerHTML = '';
            list.scrollTop = 0;

            const addOptionButton = (option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = option;
                button.addEventListener('click', () => {
                    input.dataset.comboboxSelectedValue = option;
                    input.value = option;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    closeCombobox(input);
                });
                list.appendChild(button);
            };

            if (optionGroups) {
                let groupedMatchCount = 0;
                Object.entries(optionGroups).forEach(([groupLabel, groupOptions]) => {
                    const regionMatches = groupLabel.toLowerCase().includes(search);
                    const visibleOptions = groupOptions.filter((option) => options.includes(option)
                        && (showAll || regionMatches || option.toLowerCase().includes(search)));

                    if (visibleOptions.length === 0) {
                        return;
                    }

                    const heading = document.createElement('span');
                    heading.className = 'inquiry-combobox-group';
                    heading.textContent = groupLabel;
                    list.appendChild(heading);
                    visibleOptions.forEach(addOptionButton);
                    groupedMatchCount += visibleOptions.length;
                });

                if (groupedMatchCount === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'inquiry-combobox-empty';
                    empty.textContent = 'No match. Please select from the list.';
                    list.appendChild(empty);
                }
            } else if (matches.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'inquiry-combobox-empty';
                empty.textContent = 'No match. Please select from the list.';
                list.appendChild(empty);
            } else {
                matches.forEach(addOptionButton);
            }

            box?.classList.add('is-open');
            list.scrollTop = 0;
        };

        const bindCombobox = (input, getOptions, optionGroups = null) => {
            if (!input) {
                return;
            }

            const showSelectFromListHint = () => {
                const value = input.value.trim();
                const exactMatch = getOptions().find((option) => option.toLowerCase() === value.toLowerCase());

                if (exactMatch && value !== (input.dataset.comboboxSelectedValue || '')) {
                    setFieldError(input, `Please select ${exactMatch} from the list to continue.`);
                    return;
                }

                if (!exactMatch) {
                    clearFieldError(input);
                }
            };

            const button = input.closest('[data-combobox]')?.querySelector('[data-combobox-toggle]');
            const showOptions = () => {
                if (!input.disabled && !input.readOnly) {
                    if (input.value !== (input.dataset.comboboxSelectedValue || '')) {
                        delete input.dataset.comboboxSelectedValue;
                    }
                    showSelectFromListHint();
                    renderComboboxOptions(input, getOptions(), false, optionGroups);
                }
            };

            input.addEventListener('input', showOptions);
            input.addEventListener('focus', () => {
                showSelectFromListHint();
                showOptions();
            });
            button?.addEventListener('click', () => {
                if (input.disabled || input.readOnly) {
                    return;
                }
                if (input.closest('[data-combobox]')?.classList.contains('is-open')) {
                    closeCombobox(input);
                    return;
                }

                input.focus();
                renderComboboxOptions(input, getOptions(), true, optionGroups);
            });
        };

        const isSystemSelected = (input) => {
            const value = input?.value?.trim() || '';
            return value !== '' && value === (input?.dataset.comboboxSelectedValue || '');
        };

        const getRegionAreas = () => window.edgeServiceHierarchy?.[regionSelect?.value?.trim()] || {};
        const getAreaOptions = () => Object.keys(getRegionAreas());
        const isValidRegion = () => Boolean(window.edgeServiceHierarchy?.[regionSelect?.value?.trim()])
            && isSystemSelected(regionSelect);
        const isValidArea = () => isValidRegion()
            && getAreaOptions().includes(areaSelect?.value?.trim() || '')
            && isSystemSelected(areaSelect);
        const getCityOptions = () => isValidArea()
            ? (getRegionAreas()[areaSelect.value.trim()] || [])
            : [];
        const isValidCity = () => {
            return getCityOptions().includes(citySelect?.value?.trim() || '') && isSystemSelected(citySelect);
        };
        let lastValidCity = '';

        const getBarangaySuggestions = () => {
            if (!isValidCity()) {
                return [];
            }

            return window.edgeServiceBarangays?.[regionSelect.value.trim()]?.[areaSelect.value.trim()]?.[citySelect.value.trim()] || [];
        };

        const isValidBarangay = () => {
            if (!barangayInput) {
                return false;
            }

            return getBarangaySuggestions().includes(barangayInput.value.trim()) && isSystemSelected(barangayInput);
        };

        const validateLiveField = (field, showRequiredError = false) => {
            if (!field || field.type === 'hidden' || field.disabled) {
                if (field) resetFieldState(field);
                return true;
            }

            const value = field.value.trim();
            const label = field.dataset.label || 'This field';
            if (value === '') {
                if (field.required && showRequiredError) {
                    setFieldError(field, `${label} is required.`);
                    return false;
                }
                resetFieldState(field);
                return !field.required;
            }

            if (field === clientNameInput) return validateFullNameText(field);
            if (field === contactInput) {
                if (!phonePattern.test(value)) {
                    setFieldError(field, 'Contact number must start with 09 and have 11 digits.');
                    return false;
                }
            } else if (field === emailInput) {
                if (!emailPattern.test(value)) {
                    setFieldError(field, 'Please enter a valid email address.');
                    return false;
                }
                if (value.toLowerCase() !== verifiedEmail) {
                    setFieldWarning(field, 'Verify this email to continue.');
                    return false;
                }
            } else if (field === inspectionDateInput) {
                return validatePreferredDateRange();
            } else if (field === otherServiceInput) {
                return validateMeaningfulTextField(field, 'Other Service Details');
            } else if (field.name === 'company_name') {
                return validateCompanyName(field);
            } else if (field.name === 'site_address') {
                return validateSiteAddress(field);
            } else if (field.name === 'description') {
                return validateProjectDescription(field);
            } else if (field === regionSelect && !isValidRegion()) {
                setFieldError(field, 'Please select a valid Luzon region from the list.');
                return false;
            } else if (field === areaSelect && !isValidArea()) {
                setFieldError(field, 'Please select a valid province or area from the list.');
                return false;
            } else if (field === citySelect && !isValidCity()) {
                setFieldError(field, 'Please select a valid city or municipality from the list.');
                return false;
            } else if (field === barangayInput && !isValidBarangay()) {
                setFieldError(field, 'Please select a valid barangay from the list.');
                return false;
            }

            setFieldValid(field);
            return true;
        };

        const syncBarangayState = () => {
            if (!barangayInput) {
                return;
            }

            const currentCity = isValidCity() ? citySelect.value : '';
            const barangays = getBarangaySuggestions();
            barangayInput.disabled = !isValidCity();
            barangayInput.placeholder = !isValidCity()
                ? 'Select city first'
                : (barangays.length === 0 ? 'Barangay list not imported yet' : 'Search or select barangay');

            if (!isValidCity() || barangays.length === 0 || currentCity !== lastValidCity) {
                barangayInput.value = '';
                resetFieldState(barangayInput);
                closeCombobox(barangayInput);
            }

            barangayInput.disabled = !isValidCity() || barangays.length === 0;

            lastValidCity = currentCity;
        };

        const clearLocationInput = (input) => {
            if (!input) {
                return;
            }
            input.value = '';
            delete input.dataset.comboboxSelectedValue;
            resetFieldState(input);
            closeCombobox(input);
        };

        const syncCityOptions = () => {
            if (!areaSelect || !citySelect) {
                return;
            }

            const cities = getCityOptions();
            const areaReady = isValidArea();
            citySelect.disabled = !areaReady || cities.length === 0;
            citySelect.placeholder = areaReady ? 'Search or select city / municipality' : 'Select province / area first';
            clearLocationInput(citySelect);
            syncBarangayState();

            if (provinceInput) {
                const area = areaSelect.value.trim();
                provinceInput.value = area === 'Independent City' || regionSelect.value.trim() === 'National Capital Region (NCR)'
                    ? ''
                    : area;
            }
        };

        const syncAreaOptions = () => {
            if (!regionSelect || !areaSelect) {
                return;
            }

            const regionReady = isValidRegion();
            const isNcr = regionSelect.value.trim() === 'National Capital Region (NCR)';
            clearLocationInput(areaSelect);
            areaSelect.disabled = !regionReady;
            areaSelect.readOnly = false;
            areaSelect.placeholder = regionReady ? 'Search or select province / area' : 'Select region first';

            if (regionReady && isNcr) {
                areaSelect.disabled = false;
                areaSelect.readOnly = true;
                areaSelect.value = 'Metro Manila (NCR)';
                areaSelect.dataset.comboboxSelectedValue = 'Metro Manila (NCR)';
                setFieldValid(areaSelect);
            }

            syncCityOptions();
        };

        bindCombobox(regionSelect, () => Object.keys(window.edgeServiceHierarchy || {}));
        regionSelect?.addEventListener('input', () => {
            if (regionSelect.value !== (regionSelect.dataset.comboboxSelectedValue || '')) {
                delete regionSelect.dataset.comboboxSelectedValue;
            }
            syncAreaOptions();
        });
        regionSelect?.addEventListener('change', () => {
            syncAreaOptions();
            clearFieldError(regionSelect);
            if (areaSelect) clearFieldError(areaSelect);
            if (citySelect) clearFieldError(citySelect);
            clearFormMessageIfReady();
        });
        bindCombobox(areaSelect, getAreaOptions);
        areaSelect?.addEventListener('input', () => {
            if (areaSelect.value !== (areaSelect.dataset.comboboxSelectedValue || '')) {
                delete areaSelect.dataset.comboboxSelectedValue;
            }
            syncCityOptions();
        });
        areaSelect?.addEventListener('change', () => {
            syncCityOptions();
            clearFieldError(areaSelect);
            if (citySelect) clearFieldError(citySelect);
            clearFormMessageIfReady();
        });
        syncAreaOptions();
        bindCombobox(citySelect, getCityOptions);
        bindCombobox(barangayInput, getBarangaySuggestions);
        citySelect?.addEventListener('input', syncBarangayState);
        citySelect?.addEventListener('change', () => {
            syncBarangayState();
            clearFieldError(citySelect);
            if (barangayInput) clearFieldError(barangayInput);
            clearFormMessageIfReady();
        });
        syncBarangayState();

        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-combobox]')) {
                inquiryForm.querySelectorAll('[data-combobox]').forEach((box) => box.classList.remove('is-open'));
            }
        });

        const hideDateTooltip = () => {
            dateTooltip?.classList.remove('is-visible');
            datePickerButton?.classList.remove('is-active');
            if (inspectionDateInput) {
                inspectionDateInput.dataset.pickerOpen = '0';
            }
        };

        const hideFieldTooltips = () => {
            inquiryForm.querySelectorAll('.js-field-tooltip').forEach((tooltip) => {
                tooltip.classList.remove('is-visible');
                const button = tooltip.closest('.field-info-wrap')?.querySelector('.js-field-info-button');
                button?.setAttribute('aria-expanded', 'false');
                if (button) {
                    delete button.dataset.tooltipPinned;
                    delete button.dataset.tooltipFocusPreview;
                }
            });
        };

        const showPinnedTooltip = (tooltip) => {
            hideFieldTooltips();
            hideDateTooltip();
            tooltip?.classList.add('is-visible');
            tooltip?.closest('.field-info-wrap')?.querySelector('.js-field-info-button')?.setAttribute('aria-expanded', 'true');
        };

        dateInfoButton?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const willShow = !dateTooltip?.classList.contains('is-visible');
            hideFieldTooltips();
            if (willShow) {
                dateTooltip?.classList.add('is-visible');
            } else {
                dateTooltip?.classList.remove('is-visible');
            }
        });

        inquiryForm.querySelectorAll('.js-field-info-button').forEach((button) => {
            button.addEventListener('focus', () => {
                const tooltip = button.closest('.field-info-wrap')?.querySelector('.js-field-tooltip');
                showPinnedTooltip(tooltip);
                button.dataset.tooltipFocusPreview = '1';
            });

            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const tooltip = button.closest('.field-info-wrap')?.querySelector('.js-field-tooltip');
                const isPinned = button.dataset.tooltipPinned === '1';

                if (!isPinned) {
                    showPinnedTooltip(tooltip);
                    button.dataset.tooltipPinned = '1';
                    delete button.dataset.tooltipFocusPreview;
                } else {
                    hideFieldTooltips();
                    hideDateTooltip();
                }
            });

            button.addEventListener('blur', () => {
                if (button.dataset.tooltipPinned !== '1') {
                    hideFieldTooltips();
                }
            });

            button.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                hideFieldTooltips();
                hideDateTooltip();
            });
        });

        inquiryForm.querySelectorAll('input, textarea, select').forEach((field) => {
            field.addEventListener('focus', () => {
                hideFieldTooltips();
                hideDateTooltip();
            });
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.field-info-wrap')) {
                hideFieldTooltips();
                dateTooltip?.classList.remove('is-visible');
            }
        });

        datePickerButton?.addEventListener('click', () => {
            if (!inspectionDateInput) {
                return;
            }

            const isOpen = inspectionDateInput.dataset.pickerOpen === '1';

            if (isOpen) {
                hideDateTooltip();
                inspectionDateInput.blur();
                return;
            }

            inspectionDateInput.dataset.pickerOpen = '1';
            dateTooltip?.classList.add('is-visible');
            datePickerButton.classList.add('is-active');

            if (typeof inspectionDateInput.showPicker === 'function') {
                inspectionDateInput.showPicker();
            } else {
                inspectionDateInput.focus();
            }
        });
        inspectionDateInput?.addEventListener('input', validatePreferredDateRange);
        inspectionDateInput?.addEventListener('change', () => {
            validatePreferredDateRange();
            hideDateTooltip();
        });
        inspectionDateInput?.addEventListener('blur', hideDateTooltip);

        const syncOtherServiceField = () => {
            const shouldShow = serviceSelect?.value === 'Other / Not sure yet';
            otherServiceField?.classList.toggle('is-hidden', !shouldShow);
            if (otherServiceInput) {
                otherServiceInput.disabled = !shouldShow;
                otherServiceInput.required = shouldShow;
                if (!shouldShow) {
                    otherServiceInput.value = '';
                    resetFieldState(otherServiceInput);
                    clearFormMessageIfReady();
                }
            }
        };

        if (serviceSelect) {
            serviceSelect.addEventListener('change', syncOtherServiceField);
            syncOtherServiceField();
        }

const saveInquiryDraft = () => {
    const draft = {};

    inquiryForm.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
        if (field.name === 'inquiry_form_token') {
            return;
        }

        draft[field.name] = field.value;
    });

    localStorage.setItem(draftKey, JSON.stringify(draft));
};

const restoreInquiryDraft = () => {
    if (window.edgeInquiryStatus === 'success') {
        localStorage.removeItem(draftKey);
        return;
    }

    let draft = {};
    try {
        draft = JSON.parse(localStorage.getItem(draftKey) || '{}');
    } catch (error) {
        localStorage.removeItem(draftKey);
        return;
    }

    delete draft.inquiry_form_token;

    if (Object.keys(draft).length === 0) {
        return;
    }

    const regionDraft = typeof draft.region === 'string' && window.edgeServiceHierarchy?.[draft.region]
        ? draft.region
        : '';
    const areaDraft = regionDraft && typeof draft.location_area === 'string'
        && window.edgeServiceHierarchy?.[regionDraft]?.[draft.location_area]
        ? draft.location_area
        : '';
    const cityDraft = areaDraft && typeof draft.city_municipality === 'string'
        && (window.edgeServiceHierarchy?.[regionDraft]?.[areaDraft] || []).includes(draft.city_municipality)
        ? draft.city_municipality
        : '';
    const barangayDraft = cityDraft && typeof draft.barangay === 'string'
        && (window.edgeServiceBarangays?.[regionDraft]?.[areaDraft]?.[cityDraft] || []).includes(draft.barangay)
        ? draft.barangay
        : '';

    inquiryForm.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
        if (
            field.name !== 'inquiry_form_token'
            && !['region', 'location_area', 'province', 'city_municipality', 'barangay'].includes(field.name)
            && Object.prototype.hasOwnProperty.call(draft, field.name)
        ) {
            field.value = draft[field.name];
        }
    });

            if (regionSelect && regionDraft) {
                regionSelect.value = regionDraft;
                regionSelect.dataset.comboboxSelectedValue = regionDraft;
            }
            syncAreaOptions();
            if (areaSelect && areaDraft) {
                areaSelect.value = areaDraft;
                areaSelect.dataset.comboboxSelectedValue = areaDraft;
            }
            syncCityOptions();
            if (citySelect && cityDraft) {
                citySelect.value = cityDraft;
                citySelect.dataset.comboboxSelectedValue = cityDraft;
            }
            syncBarangayState();
            if (barangayInput && barangayDraft) {
                barangayInput.value = barangayDraft;
                barangayInput.dataset.comboboxSelectedValue = barangayDraft;
            }
            syncOtherServiceField();
            validatePreferredDateRange();

        };

        contactInput.addEventListener('input', () => {
            normalizeContactNumber(contactInput);
            clearContactMismatchFeedback(contactInput);
            clearFieldError(contactInput);
            clearFormMessageIfReady();
        });

        clientNameInput?.addEventListener('input', () => {
            clearContactMismatchFeedback(clientNameInput);
            validateFullNameText(clientNameInput);
            clearFormMessageIfReady();
            saveInquiryDraft();
            queueContactCheck();
        });

        inquiryForm.querySelector('input[name="email"]')?.addEventListener('input', (event) => {
            clearContactMismatchFeedback(event.currentTarget);
            if (event.currentTarget.value.trim().toLowerCase() !== verifiedEmail) {
                verifiedEmail = '';
                inquiryForm.dataset.verifiedEmail = '';
                updateEmailVerification();
            }
            resetFieldState(event.currentTarget);
        });

        emailInput?.addEventListener('blur', queueContactCheck);

        otherServiceInput?.addEventListener('input', () => {
            validateMeaningfulTextField(otherServiceInput, 'Other Service Details');
            clearFormMessageIfReady();
            saveInquiryDraft();
        });

        const projectDescriptionInput = inquiryForm.querySelector('textarea[name="description"]');
        projectDescriptionInput?.addEventListener('input', (event) => {
            validateProjectDescription(event.currentTarget);
            clearFormMessageIfReady();
            saveInquiryDraft();
        });
        projectDescriptionInput?.addEventListener('blur', (event) => {
            validateProjectDescription(event.currentTarget);
        });

        inquiryForm.querySelectorAll('input, select, textarea').forEach((field) => {
            field.addEventListener('focus', () => {
                gentlyRevealField(field);
            });
            field.addEventListener('input', () => {
                field.dataset.touched = '1';
                if (contactIdentityFields.includes(field)) {
                    contactCanVerifyEmail = false;
                    contactNote = '';
                    updateEmailVerification();
                }
                if (field === emailInput) {
                    resetFieldState(field);
                } else {
                    validateLiveField(field, true);
                }
                clearFormMessageIfReady();
                saveInquiryDraft();
                if (contactIdentityFields.includes(field) && field !== clientNameInput && field !== emailInput) {
                    queueContactCheck();
                }
            });
            field.addEventListener('change', () => {
                field.dataset.touched = '1';
                validateLiveField(field, true);
                clearFormMessageIfReady();
                saveInquiryDraft();
            });
            field.addEventListener('blur', () => {
                field.dataset.touched = '1';
                validateLiveField(field, true);
            });
        });

        const validateAutofilledIdentity = (field) => {
            if (field === clientNameInput && field.value.trim() !== '') {
                validateFullNameText(field);
            }
            if (field === contactInput && field.value.trim() !== '09') {
                normalizeContactNumber(field);
                validateLiveField(field, true);
            }
        };

        inquiryForm.addEventListener('animationstart', (event) => {
            if (event.animationName !== 'inquiry-autofill-detected') return;
            if (event.target !== clientNameInput && event.target !== contactInput) return;
            validateAutofilledIdentity(event.target);
            queueContactCheck();
        });

        const clearInquiryForm = () => {
            localStorage.removeItem(draftKey);
            inquiryForm.reset();
            contactInput.value = '09';
            clearContactMismatchFeedback();
            syncAreaOptions();
            syncOtherServiceField();
            inquiryForm.querySelectorAll('.is-invalid, .is-valid, .is-unverified').forEach((field) => {
                resetFieldState(field);
                delete field.dataset.touched;
            });
            message.textContent = '';
            message.classList.remove('is-error');
            verifiedEmail = '';
            contactCanVerifyEmail = false;
            inquiryForm.dataset.verifiedEmail = '';
            updateEmailVerification();
        };

        clearDraftButton?.addEventListener('click', () => {
            if (isSubmittingInquiry) {
                return;
            }

            window.EdgePublicConfirmation?.open({
                title: 'Clear inquiry form?',
                message: 'This will remove all entered inquiry details. This action cannot be undone.',
                cancelLabel: 'Cancel',
                confirmLabel: 'Clear Form',
                tone: 'danger',
                onConfirm: function (controls) {
                    clearInquiryForm();
                    controls.close();
                },
            });
        });

        restoreInquiryDraft();
        updateEmailVerification();
        checkContactDetails();
        [150, 700].forEach((delay) => {
            window.setTimeout(() => {
                validateAutofilledIdentity(clientNameInput);
                validateAutofilledIdentity(contactInput);
            }, delay);
        });

        if (window.edgeInquiryStatus === 'contact_mismatch') {
            showContactMismatchFeedback();
        }

        inquiryForm.addEventListener('submit', (event) => {
            if (isSubmittingInquiry) {
                event.preventDefault();
                return;
            }

            normalizeContactNumber(contactInput);
            syncPreferredDateWindow();
            const fields = Array.from(inquiryForm.querySelectorAll('input, select, textarea'));
            let firstInvalidField = null;

            message.textContent = '';
            message.classList.remove('is-error');
            fields.forEach(clearFieldError);

            for (const field of fields) {
                const label = field.dataset.label || 'This field';
                const value = field.value.trim();

                if (field.required && value === '') {
                    setFieldError(field, `${label} is required.`);
                    firstInvalidField = firstInvalidField || field;
                    continue;
                }

                if (field.name === 'client_name' && value !== '' && !validateFullNameText(field)) {
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.name === 'other_service_details' && value !== '' && !validateMeaningfulTextField(field, 'Other Service Details')) {
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.name === 'company_name' && value !== '' && !validateCompanyName(field)) {
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.name === 'site_address' && value !== '' && !validateSiteAddress(field)) {
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.type === 'email' && value !== '' && !emailPattern.test(value)) {
                    setFieldError(field, 'Please enter a valid email address.');
                    firstInvalidField = firstInvalidField || field;
                } else if (field === emailInput && value !== '' && value.toLowerCase() !== verifiedEmail) {
                    setFieldWarning(field, 'Verify this email to continue.');
                    firstInvalidField = firstInvalidField || field;
                }

                if (field === inspectionDateInput && value !== ''
                    && (value < preferredDateMin || value > preferredDateMax)) {
                    setFieldError(field, 'Before 5:00 PM, today is allowed. From 5:00 PM, choose tomorrow onward.');
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.name === 'description' && value !== '' && !validateProjectDescription(field)) {
                    firstInvalidField = firstInvalidField || field;
                }

                if (value !== ''
                    && !field.classList.contains('is-invalid')
                    && !field.classList.contains('is-unverified')) {
                    setFieldValid(field);
                }
            }

            if (!phonePattern.test(contactInput.value.trim())) {
                setFieldError(contactInput, 'Contact number must start with 09 and have 11 digits.');
                firstInvalidField = firstInvalidField || contactInput;
            }

            if (regionSelect && areaSelect && citySelect) {
                if (!isValidRegion()) {
                    setFieldError(regionSelect, 'Please select a valid Luzon region from the list.');
                    firstInvalidField = firstInvalidField || regionSelect;
                } else {
                    setFieldValid(regionSelect);
                }

                if (!isValidArea()) {
                    setFieldError(areaSelect, 'Please select a valid province or independent city area.');
                    firstInvalidField = firstInvalidField || areaSelect;
                } else {
                    setFieldValid(areaSelect);
                }

                if (!isValidCity()) {
                    setFieldError(citySelect, 'Please select a valid city or municipality for this area.');
                    firstInvalidField = firstInvalidField || citySelect;
                } else {
                    setFieldValid(citySelect);
                }
            }

            if (barangayInput && !isValidCity()) {
                setFieldError(barangayInput, 'Please select a valid city first.');
                firstInvalidField = firstInvalidField || barangayInput;
            } else if (barangayInput && !isValidBarangay()) {
                setFieldError(barangayInput, 'Please select a valid barangay under the selected city.');
                firstInvalidField = firstInvalidField || barangayInput;
            } else if (barangayInput) {
                setFieldValid(barangayInput);
            }

            if (firstInvalidField) {
                event.preventDefault();
                message.textContent = 'Please fix the highlighted fields.';
                message.classList.add('is-error');
                gentlyRevealField(firstInvalidField, true);
                firstInvalidField.focus();
                return;
            }

            if ((emailInput?.value || '').trim().toLowerCase() !== verifiedEmail) {
                event.preventDefault();
                setFieldWarning(emailInput, 'Verify your email before submitting.');
                message.textContent = 'Please verify your email first.';
                message.classList.add('is-error');
                gentlyRevealField(emailInput, true);
                emailInput?.focus();
                return;
            }

            if (!isSubmitConfirmed && window.EdgePublicConfirmation) {
                event.preventDefault();
                window.EdgePublicConfirmation.open({
                    title: 'Submit inquiry?',
                    message: 'Your inquiry will be sent to Admin for review.',
                    cancelLabel: 'Go Back',
                    confirmLabel: 'Submit Inquiry',
                    onConfirm: function (controls) {
                        controls.setBusy('Submitting...');
                        isSubmitConfirmed = true;
                        inquiryForm.requestSubmit();
                    },
                });
                return;
            }

            isSubmittingInquiry = true;
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.innerHTML = '<span class="inquiry-submit__spinner" aria-hidden="true"></span><span>Submitting Inquiry...</span>';
            }
        });

        window.addEventListener('pageshow', () => {
            if (!isSubmittingInquiry || !submitButton) {
                return;
            }

            isSubmittingInquiry = false;
            isSubmitConfirmed = false;
            submitButton.disabled = false;
            submitButton.removeAttribute('aria-busy');
            submitButton.textContent = 'Submit Inquiry';
        });
    });
};

const initProjectLightbox = () => {
    const links = Array.from(document.querySelectorAll('.project-link, .image-lightbox-link'));
    const lightbox = document.getElementById('projectLightbox');
    const image = document.getElementById('projectLightboxImage');
    const caption = document.getElementById('projectLightboxCaption');
    const closeButton = lightbox?.querySelector('.project-lightbox-close');
    const prevButton = lightbox?.querySelector('.project-lightbox-prev');
    const nextButton = lightbox?.querySelector('.project-lightbox-next');

    if (!links.length || !lightbox || !image || !caption || !closeButton || !prevButton || !nextButton) {
        return;
    }

    const projects = links.map((link) => {
        const img = link.querySelector('img');
        const title = link.querySelector('h4')?.textContent?.trim()
            ?? link.dataset.lightboxTitle
            ?? link.getAttribute('aria-label')
            ?? img?.alt
            ?? 'Project image';

        return {
            src: link.getAttribute('href') ?? '',
            alt: img?.alt ?? title,
            title,
            isSingle: link.classList.contains('image-lightbox-link'),
        };
    });

    let activeIndex = 0;
    let lightboxTimer = 0;
    let lightboxResumeTimer = 0;
    let lightboxPointerStartX = 0;
    let lightboxPointerStartY = 0;

    const getNextProjectIndex = (delta) => {
        let nextIndex = activeIndex;

        for (let step = 0; step < projects.length; step += 1) {
            nextIndex = (nextIndex + delta + projects.length) % projects.length;

            if (!projects[nextIndex].isSingle) {
                return nextIndex;
            }
        }

        return activeIndex;
    };

    const showProject = (index) => {
        activeIndex = (index + projects.length) % projects.length;
        const project = projects[activeIndex];
        image.src = project.src;
        image.alt = project.alt;
        caption.textContent = project.title;
        lightbox.classList.toggle('is-single', project.isSingle);
    };

    const stopLightboxAutoplay = () => {
        window.clearInterval(lightboxTimer);
        lightboxTimer = 0;
    };

    const startLightboxAutoplay = () => {
        stopLightboxAutoplay();

        if (!lightbox.classList.contains('is-open') || projects[activeIndex]?.isSingle) {
            return;
        }

        lightboxTimer = window.setInterval(() => {
            showProject(getNextProjectIndex(1));
        }, 3600);
    };

    const pauseLightboxThenResume = () => {
        stopLightboxAutoplay();
        window.clearTimeout(lightboxResumeTimer);
        lightboxResumeTimer = window.setTimeout(startLightboxAutoplay, 3200);
    };

    const holdLightboxAutoplay = () => {
        stopLightboxAutoplay();
        window.clearTimeout(lightboxResumeTimer);
    };

    const openLightbox = (index) => {
        showProject(index);
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        closeButton.focus();
        startLightboxAutoplay();
    };

    const closeLightbox = () => {
        stopLightboxAutoplay();
        window.clearTimeout(lightboxResumeTimer);
        lightbox.classList.remove('is-open');
        lightbox.classList.remove('is-single');
        lightbox.setAttribute('aria-hidden', 'true');
        image.removeAttribute('src');
    };

    links.forEach((link, index) => {
        link.dataset.lightboxIndex = String(index);
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openLightbox(index);
        });
    });

    document.addEventListener('projectLightbox:open', (event) => {
        const link = event.detail?.link;
        const index = Number(link?.dataset.lightboxIndex ?? -1);

        if (index >= 0) {
            openLightbox(index);
        }
    });

    closeButton.addEventListener('click', closeLightbox);
    prevButton.addEventListener('click', () => {
        if (!projects[activeIndex]?.isSingle) {
            showProject(getNextProjectIndex(-1));
            pauseLightboxThenResume();
        }
    });

    nextButton.addEventListener('click', () => {
        if (!projects[activeIndex]?.isSingle) {
            showProject(getNextProjectIndex(1));
            pauseLightboxThenResume();
        }
    });

    image.addEventListener('pointerdown', (event) => {
        lightboxPointerStartX = event.clientX;
        lightboxPointerStartY = event.clientY;
        holdLightboxAutoplay();
    });

    image.addEventListener('pointerup', (event) => {
        if (projects[activeIndex]?.isSingle) {
            return;
        }

        const deltaX = event.clientX - lightboxPointerStartX;
        const deltaY = event.clientY - lightboxPointerStartY;

        if (Math.abs(deltaX) > 42 && Math.abs(deltaX) > Math.abs(deltaY)) {
            showProject(getNextProjectIndex(deltaX < 0 ? 1 : -1));
        }

        pauseLightboxThenResume();
    });

    image.addEventListener('mouseenter', holdLightboxAutoplay);
    image.addEventListener('mouseleave', startLightboxAutoplay);

    lightbox.addEventListener('click', (event) => {
        const clickedControl = event.target.closest('.project-lightbox-close, .project-lightbox-nav');
        const clickedImage = event.target.closest('#projectLightboxImage');

        if (!clickedControl && !clickedImage) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!lightbox.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'Escape') {
            closeLightbox();
        }

        if (!projects[activeIndex]?.isSingle && event.key === 'ArrowLeft') {
            showProject(getNextProjectIndex(-1));
            pauseLightboxThenResume();
        }

        if (!projects[activeIndex]?.isSingle && event.key === 'ArrowRight') {
            showProject(getNextProjectIndex(1));
            pauseLightboxThenResume();
        }
    });
};

const initProjectCarousel = () => {
    const carousel = document.querySelector('.projects-grid');
    const originals = Array.from(carousel?.querySelectorAll('.project-item') ?? []);

    if (!carousel || originals.length < 2) {
        return;
    }

    const shell = document.createElement('div');
    shell.className = 'projects-carousel-shell';
    carousel.before(shell);
    shell.append(carousel);

    originals.forEach((item, index) => {
        item.dataset.realIndex = String(index);
    });

    const itemCount = originals.length;
    const speed = window.matchMedia('(max-width: 760px)').matches ? 24 : 34;
    let cycleWidth = 0;
    let position = 0;
    let lastFrame = 0;
    let isDragging = false;
    let dragMoved = false;
    let dragStartX = 0;
    let dragStartPosition = 0;
    let resizeTick = 0;
    let nudge = null;

    const prevButton = document.createElement('button');
    const nextButton = document.createElement('button');
    prevButton.type = 'button';
    nextButton.type = 'button';
    prevButton.className = 'project-card-arrow project-card-prev';
    nextButton.className = 'project-card-arrow project-card-next';
    prevButton.setAttribute('aria-label', 'Previous project');
    nextButton.setAttribute('aria-label', 'Next project');
    shell.append(prevButton, nextButton);

    const createClone = (item, index) => {
        const clone = item.cloneNode(true);
        clone.classList.add('is-carousel-clone');
        clone.classList.remove('reveal-on-scroll');
        clone.dataset.realIndex = String(index);
        clone.setAttribute('aria-hidden', 'true');
        return clone;
    };

    const removeClones = () => {
        carousel.querySelectorAll('.is-carousel-clone').forEach((clone) => clone.remove());
    };

    const applyPosition = () => {
        carousel.style.transform = `translate3d(${-position}px, 0, 0)`;
    };

    const normalizePosition = () => {
        if (!cycleWidth) {
            position = 0;
            return;
        }

        while (position >= cycleWidth * 2) {
            position -= cycleWidth;
        }

        while (position < cycleWidth) {
            position += cycleWidth;
        }
    };

    const createCloneSet = () => originals.map((item, index) => createClone(item, index));

    const appendCloneSet = () => {
        carousel.append(...createCloneSet());
    };

    const buildTrack = () => {
        removeClones();
        carousel.style.transform = 'translate3d(0, 0, 0)';
        const beforeSet = createCloneSet();
        carousel.prepend(...beforeSet);
        appendCloneSet();

        cycleWidth = beforeSet[0]
            ? originals[0].offsetLeft - beforeSet[0].offsetLeft
            : carousel.scrollWidth;

        while (cycleWidth > 0 && carousel.scrollWidth < (cycleWidth * 2) + shell.clientWidth + 120) {
            appendCloneSet();
        }

        if (!position) {
            position = cycleWidth;
        }

        normalizePosition();
        applyPosition();
    };

    const animate = (time) => {
        if (!lastFrame) {
            lastFrame = time;
        }

        const delta = Math.min(time - lastFrame, 48);
        lastFrame = time;

        if (!isDragging && cycleWidth > 0) {
            if (nudge) {
                const progress = Math.min((time - nudge.startedAt) / nudge.duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                position = nudge.from + ((nudge.to - nudge.from) * eased);

                if (progress >= 1) {
                    nudge = null;
                    normalizePosition();
                }
            } else {
                position += (speed * delta) / 1000;
                normalizePosition();
            }

            applyPosition();
        }

        window.requestAnimationFrame(animate);
    };

    const getCardCenterPosition = (card) => {
        return card.offsetLeft + (card.offsetWidth / 2) - (shell.clientWidth / 2);
    };

    const getCenteredCard = () => {
        const cards = Array.from(carousel.querySelectorAll('.project-item'));
        const viewportCenter = position + (shell.clientWidth / 2);

        return cards.reduce((bestCard, card) => {
            const cardCenter = card.offsetLeft + (card.offsetWidth / 2);
            const bestCenter = bestCard.offsetLeft + (bestCard.offsetWidth / 2);
            return Math.abs(cardCenter - viewportCenter) < Math.abs(bestCenter - viewportCenter)
                ? card
                : bestCard;
        }, cards[0]);
    };

    const centerAdjacentCard = (direction) => {
        const cards = Array.from(carousel.querySelectorAll('.project-item'));

        if (!cards.length || !cycleWidth) {
            return;
        }

        const centeredCard = getCenteredCard();
        const centeredRealIndex = Number(centeredCard.dataset.realIndex ?? 0);
        const targetRealIndex = (centeredRealIndex + direction + itemCount) % itemCount;
        const targetCards = cards.filter((card) => Number(card.dataset.realIndex ?? -1) === targetRealIndex);

        if (!targetCards.length) {
            return;
        }

        const targetPositions = targetCards.flatMap((card) => {
            const cardPosition = getCardCenterPosition(card);
            return [cardPosition - cycleWidth, cardPosition, cardPosition + cycleWidth];
        });
        const directionalTargets = targetPositions.filter((value) => {
            return direction > 0 ? value > position + 1 : value < position - 1;
        });
        const fallbackTargets = targetPositions.length ? targetPositions : [position];
        const targetPosition = (directionalTargets.length ? directionalTargets : fallbackTargets)
            .reduce((bestValue, value) => {
                const bestDistance = Math.abs(bestValue - position);
                const valueDistance = Math.abs(value - position);
                return valueDistance < bestDistance ? value : bestValue;
            });

        nudge = {
            from: position,
            to: targetPosition,
            duration: 480,
            startedAt: performance.now(),
        };
    };

    const openProjectFromCard = (card) => {
        const realIndex = Number(card?.dataset.realIndex ?? -1);
        const sourceLink = Number.isInteger(realIndex)
            ? originals[realIndex]?.querySelector('.project-link')
            : null;

        if (!sourceLink) {
            return;
        }

        document.dispatchEvent(new CustomEvent('projectLightbox:open', {
            detail: { link: sourceLink },
        }));
    };

    carousel.addEventListener('pointerdown', (event) => {
        isDragging = true;
        dragMoved = false;
        dragStartX = event.clientX;
        dragStartPosition = position;
        carousel.setPointerCapture?.(event.pointerId);
        carousel.classList.add('is-dragging');
    });

    carousel.addEventListener('pointermove', (event) => {
        if (!isDragging) {
            return;
        }

        const deltaX = event.clientX - dragStartX;

        if (Math.abs(deltaX) > 8) {
            dragMoved = true;
        }

        position = dragStartPosition - deltaX;
        normalizePosition();
        applyPosition();
    });

    const endDrag = (event) => {
        if (!isDragging) {
            return;
        }

        isDragging = false;
        carousel.releasePointerCapture?.(event.pointerId);
        carousel.classList.remove('is-dragging');
    };

    carousel.addEventListener('pointerup', endDrag);
    carousel.addEventListener('pointercancel', endDrag);

    prevButton.addEventListener('click', () => centerAdjacentCard(-1));
    nextButton.addEventListener('click', () => centerAdjacentCard(1));

    shell.addEventListener('wheel', (event) => {
        const horizontalDelta = Math.abs(event.deltaX) >= Math.abs(event.deltaY)
            ? event.deltaX
            : 0;

        if (!horizontalDelta || !cycleWidth) {
            return;
        }

        event.preventDefault();
        nudge = null;
        position += horizontalDelta;
        normalizePosition();
        applyPosition();
    }, { passive: false });

    carousel.addEventListener('click', (event) => {
        if (dragMoved) {
            event.preventDefault();
            event.stopPropagation();
            dragMoved = false;
            return;
        }

        const clickedLink = event.target.closest('.project-link');

        if (clickedLink) {
            event.preventDefault();
            event.stopPropagation();

            openProjectFromCard(clickedLink.closest('.project-item'));
        }
    }, true);

    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTick);
        resizeTick = window.setTimeout(buildTrack, 160);
    });

    buildTrack();
    window.requestAnimationFrame(animate);
};


document.addEventListener('DOMContentLoaded', () => {
    initMobileMenu();
    // initNavHighlight();
    initFormHandling();
    initInquiryForm();
    initScrollAnimations();
    initNavbarScroll();
    initConsultationModal();
    initInquiryStatusToast();
    initNewClientTooltip();
    initServiceCards();
    initProjectLightbox();
    initProjectCarousel();
});


document.querySelectorAll('.nav-link').forEach((link) => {
    link.addEventListener('click', function (e) {
        const href = this.getAttribute('href') ?? '';

        if (!href.startsWith('#')) {
            return;
        }

        const navMenu = document.querySelector('.nav-menu');
        const hamburger = document.querySelector('.hamburger');
        if (navMenu?.classList.contains('active')) {
            navMenu.classList.remove('active');
            hamburger?.classList.remove('active');
            hamburger?.setAttribute('aria-expanded', 'false');
        }
    });
});
