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
    };

    const closeInquiryModal = () => {
        if (!inquiryModal) {
            return;
        }

        inquiryModal.classList.remove('is-open');
        inquiryModal.setAttribute('aria-hidden', 'true');
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

    if (new URLSearchParams(window.location.search).has('inquiry')) {
        openInquiryModal();
    }
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
    const formatLocalDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const now = new Date();
    const todayDate = formatLocalDate(now);
    const tomorrowDate = (() => {
        const tomorrow = new Date(now);
        tomorrow.setDate(tomorrow.getDate() + 1);
        return formatLocalDate(tomorrow);
    })();
    const preferredDateMin = now.getHours() >= 17 ? tomorrowDate : todayDate;

    const clearFieldError = (field) => {
        field.classList.remove('is-invalid');
        const error = field.closest('label')?.querySelector('.field-error');
        if (error) {
            error.textContent = '';
            error.classList.remove('is-visible');
        }
    };

    const setFieldError = (field, text) => {
        field.classList.add('is-invalid');
        const error = field.closest('label')?.querySelector('.field-error');
        if (error) {
            error.textContent = text;
            error.classList.add('is-visible');
        }
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
        const inspectionDateInput = inquiryForm.querySelector('.js-inspection-date');
        const datePickerButton = inquiryForm.querySelector('.js-date-picker-button');
        const dateInfoButton = inquiryForm.querySelector('.js-date-info-button');
        const dateTooltip = inquiryForm.querySelector('.js-date-tooltip');
        const serviceSelect = inquiryForm.querySelector('select[name="service_category"]');
        const provinceSelect = inquiryForm.querySelector('.js-inquiry-province');
        const citySelect = inquiryForm.querySelector('.js-inquiry-city');
        const barangayInput = inquiryForm.querySelector('.js-inquiry-barangay');
        const otherServiceField = inquiryForm.querySelector('.other-service-field');
        const otherServiceInput = inquiryForm.querySelector('input[name="other_service_details"]');
        const message = inquiryForm.querySelector('.js-inquiry-message');
        const clearDraftButton = inquiryForm.querySelector('.js-clear-inquiry-draft');
        const draftKey = 'edgeInquiryFormDraft';

        if (!contactInput || !message) {
            return;
        }

        normalizeContactNumber(contactInput);

        if (inspectionDateInput) {
            inspectionDateInput.min = preferredDateMin;
        }

        const enforcePreferredDateMin = () => {
            if (!inspectionDateInput) {
                return;
            }

            // Kapag manual edit ang date, ibalik agad sa pinaka-maagang allowed date.
            if (inspectionDateInput.value && inspectionDateInput.value < preferredDateMin) {
                inspectionDateInput.value = preferredDateMin;
            }
        };

        enforcePreferredDateMin();

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

        const renderComboboxOptions = (input, options, showAll = false) => {
            const box = input?.closest('[data-combobox]');
            const list = box?.querySelector('[data-combobox-list]');
            if (!input || !list) {
                return;
            }

            closeOtherComboboxes(input);
            const search = showAll ? '' : input.value.trim().toLowerCase();
            const matches = options.filter((option) => option.toLowerCase().includes(search)).slice(0, 80);
            list.innerHTML = '';
            list.scrollTop = 0;

            if (matches.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'inquiry-combobox-empty';
                empty.textContent = 'No match. You can type it manually.';
                list.appendChild(empty);
            } else {
                matches.forEach((option) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = option;
                    button.addEventListener('click', () => {
                        input.value = option;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        closeCombobox(input);
                    });
                    list.appendChild(button);
                });
            }

            box?.classList.add('is-open');
            list.scrollTop = 0;
        };

        const bindCombobox = (input, getOptions) => {
            if (!input) {
                return;
            }

            const button = input.closest('[data-combobox]')?.querySelector('[data-combobox-toggle]');
            const showOptions = () => {
                if (!input.disabled) {
                    renderComboboxOptions(input, getOptions());
                }
            };

            input.addEventListener('input', showOptions);
            input.addEventListener('focus', showOptions);
            button?.addEventListener('click', () => {
                if (input.closest('[data-combobox]')?.classList.contains('is-open')) {
                    closeCombobox(input);
                    return;
                }

                input.focus();
                renderComboboxOptions(input, getOptions(), true);
            });
        };

        const isValidProvince = () => Boolean(window.edgeServiceAreas?.[provinceSelect?.value]);
        const isValidCity = () => {
            const cities = window.edgeServiceAreas?.[provinceSelect?.value] || [];
            return cities.includes(citySelect?.value || '');
        };
        let lastValidCity = '';

        const getBarangaySuggestions = () => {
            if (!isValidCity()) {
                return [];
            }

            return window.edgeServiceBarangays?.[provinceSelect.value]?.[citySelect.value] || [];
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
                closeCombobox(barangayInput);
            }

            barangayInput.disabled = !isValidCity() || barangays.length === 0;

            lastValidCity = currentCity;
        };

        const syncCityOptions = () => {
            if (!provinceSelect || !citySelect) {
                return;
            }

            const cities = window.edgeServiceAreas?.[provinceSelect.value] || [];
            citySelect.disabled = cities.length === 0;
            citySelect.placeholder = cities.length === 0 ? 'Select province first' : 'Search or select city / municipality';
            citySelect.value = '';
            closeCombobox(citySelect);
            syncBarangayState();
        };

        bindCombobox(provinceSelect, () => Object.keys(window.edgeServiceAreas || {}));
        provinceSelect?.addEventListener('input', () => {
            syncCityOptions();
        });
        provinceSelect?.addEventListener('change', () => {
            syncCityOptions();
            clearFieldError(provinceSelect);
            if (citySelect) clearFieldError(citySelect);
        });
        syncCityOptions();
        bindCombobox(citySelect, () => window.edgeServiceAreas?.[provinceSelect?.value] || []);
        bindCombobox(barangayInput, getBarangaySuggestions);
        citySelect?.addEventListener('input', syncBarangayState);
        citySelect?.addEventListener('change', () => {
            syncBarangayState();
            clearFieldError(citySelect);
            if (barangayInput) clearFieldError(barangayInput);
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

        dateInfoButton?.addEventListener('click', (event) => {
            event.preventDefault();
            dateTooltip?.classList.toggle('is-visible');
        });

        inquiryForm.querySelectorAll('.js-field-info-button').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const tooltip = button.closest('.field-info-wrap')?.querySelector('.js-field-tooltip');
                tooltip?.classList.toggle('is-visible');
            });
        });

        inquiryForm.querySelectorAll('[data-help-tooltip-target]').forEach((field) => {
            const tooltip = field.closest('label')?.querySelector('.js-field-tooltip');
            field.addEventListener('focus', () => tooltip?.classList.add('is-visible'));
            field.addEventListener('blur', () => tooltip?.classList.remove('is-visible'));
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
        inspectionDateInput?.addEventListener('input', enforcePreferredDateMin);
        inspectionDateInput?.addEventListener('change', () => {
            enforcePreferredDateMin();
            hideDateTooltip();
        });
        inspectionDateInput?.addEventListener('blur', hideDateTooltip);

        const syncOtherServiceField = () => {
            const shouldShow = serviceSelect?.value === 'Other / Not sure yet';
            otherServiceField?.classList.toggle('is-hidden', !shouldShow);
            if (otherServiceInput) {
                otherServiceInput.required = shouldShow;
                if (!shouldShow) {
                    otherServiceInput.value = '';
                    clearFieldError(otherServiceInput);
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
                draft[field.name] = field.value;
            });
            localStorage.setItem(draftKey, JSON.stringify(draft));
        };

        const restoreInquiryDraft = () => {
            let draft = {};
            try {
                draft = JSON.parse(localStorage.getItem(draftKey) || '{}');
            } catch (error) {
                localStorage.removeItem(draftKey);
                return;
            }

            if (Object.keys(draft).length === 0) {
                return;
            }

            inquiryForm.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
                if (Object.prototype.hasOwnProperty.call(draft, field.name)) {
                    field.value = draft[field.name];
                }
            });

            syncCityOptions();
            if (citySelect && draft.city_municipality) {
                citySelect.value = draft.city_municipality;
            }
            syncBarangayState();
            if (barangayInput && draft.barangay) {
                barangayInput.value = draft.barangay;
            }
            syncOtherServiceField();

            const inquiryModal = document.getElementById('inquiryModal');
            if (inquiryModal) {
                inquiryModal.classList.add('is-open');
                inquiryModal.setAttribute('aria-hidden', 'false');
            }
        };

        contactInput.addEventListener('input', () => {
            normalizeContactNumber(contactInput);
            clearFieldError(contactInput);
        });

        inquiryForm.querySelectorAll('input, select, textarea').forEach((field) => {
            field.addEventListener('input', () => {
                clearFieldError(field);
                saveInquiryDraft();
            });
            field.addEventListener('change', () => {
                clearFieldError(field);
                saveInquiryDraft();
            });
        });

        clearDraftButton?.addEventListener('click', () => {
            localStorage.removeItem(draftKey);
            inquiryForm.reset();
            contactInput.value = '09';
            syncCityOptions();
            syncBarangayState();
            syncOtherServiceField();
            inquiryForm.querySelectorAll('.is-invalid').forEach((field) => clearFieldError(field));
            message.textContent = '';
            message.classList.remove('is-error');
        });

        restoreInquiryDraft();

        inquiryForm.addEventListener('submit', (event) => {
            enforcePreferredDateMin();
            normalizeContactNumber(contactInput);
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

                if (field.type === 'email' && value !== '' && !emailPattern.test(value)) {
                    setFieldError(field, 'Please enter a valid email address.');
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.type === 'date' && value !== '' && value < preferredDateMin) {
                    setFieldError(field, now.getHours() >= 17
                        ? 'Please choose tomorrow or later because working hours are done today.'
                        : 'Preferred inspection date cannot be in the past.');
                    firstInvalidField = firstInvalidField || field;
                }

                if (field.name === 'description' && value !== '' && value.length < 10) {
                    setFieldError(field, 'Project description must be at least 10 characters.');
                    firstInvalidField = firstInvalidField || field;
                }
            }

            if (!phonePattern.test(contactInput.value.trim())) {
                setFieldError(contactInput, 'Contact number must start with 09 and have 11 digits.');
                firstInvalidField = firstInvalidField || contactInput;
            }

            if (provinceSelect && citySelect) {
                const allowedCities = window.edgeServiceAreas?.[provinceSelect.value] || [];
                if (!isValidProvince()) {
                    setFieldError(provinceSelect, 'Please select a province in our service area.');
                    firstInvalidField = firstInvalidField || provinceSelect;
                }

                if (!allowedCities.includes(citySelect.value)) {
                    setFieldError(citySelect, 'Please select a city in our CALABARZON service area.');
                    firstInvalidField = firstInvalidField || citySelect;
                }
            }

            if (barangayInput && !isValidCity()) {
                setFieldError(barangayInput, 'Please select a valid city first.');
                firstInvalidField = firstInvalidField || barangayInput;
            } else if (barangayInput && !getBarangaySuggestions().includes(barangayInput.value.trim())) {
                setFieldError(barangayInput, 'Please select a barangay under the selected city.');
                firstInvalidField = firstInvalidField || barangayInput;
            }

            if (firstInvalidField) {
                event.preventDefault();
                message.textContent = 'Please fix the highlighted fields.';
                message.classList.add('is-error');
                firstInvalidField.focus();
            } else {
                localStorage.removeItem(draftKey);
            }
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
