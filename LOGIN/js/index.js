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

    if (openButtons.length === 0 || !closeButton || !modal) {
        return;
    }

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
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
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openLightbox(index);
        });
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
    const items = Array.from(carousel?.querySelectorAll('.project-item') ?? []);

    if (!carousel || items.length < 2) {
        return;
    }

    let activeIndex = 0;
    let autoplayTimer = 0;
    let isPaused = false;
    let resumeId = 0;
    let isDragging = false;
    let dragStarted = false;
    let dragStartX = 0;
    let dragStartScrollLeft = 0;
    let resizeTick = 0;

    const setActive = (index) => {
        activeIndex = (index + items.length) % items.length;
        const prevIndex = (activeIndex - 1 + items.length) % items.length;
        const nextIndex = (activeIndex + 1) % items.length;

        items.forEach((item, itemIndex) => {
            item.classList.toggle('is-active-project', itemIndex === activeIndex);
            item.classList.toggle('is-prev-project', itemIndex === prevIndex);
            item.classList.toggle('is-next-project', itemIndex === nextIndex);
        });
    };

    function scrollToItem(index) {
        const targetIndex = (index + items.length) % items.length;
        const target = items[targetIndex];
        const isMobile = window.matchMedia('(max-width: 760px)').matches;
        const left = isMobile
            ? target.offsetLeft - ((carousel.clientWidth - target.offsetWidth) / 2)
            : target.offsetLeft - parseFloat(getComputedStyle(carousel).paddingLeft || '0');
        carousel.scrollTo({ left, behavior: 'smooth' });
        setActive(targetIndex);
    }

    const getCenteredIndex = () => {
        const leftEdge = carousel.scrollLeft + parseFloat(getComputedStyle(carousel).paddingLeft || '0');
        const nearestIndex = items.reduce((bestIndex, item, index) => {
            return Math.abs(item.offsetLeft - leftEdge) < Math.abs(items[bestIndex].offsetLeft - leftEdge) ? index : bestIndex;
        }, 0);

        return nearestIndex;
    };

    const stop = () => {
        window.clearInterval(autoplayTimer);
        autoplayTimer = 0;
    };

    const start = () => {
        if (autoplayTimer) {
            return;
        }

        isPaused = false;
        autoplayTimer = window.setInterval(() => {
            if (!isPaused) {
                scrollToItem(activeIndex + 1);
            }
        }, 4200);
    };

    const holdCarousel = () => {
        isPaused = true;
        stop();
        window.clearTimeout(resumeId);
    };

    let scrollTick = 0;
    carousel.addEventListener('scroll', () => {
        window.clearTimeout(scrollTick);
        scrollTick = window.setTimeout(() => setActive(getCenteredIndex()), 80);
    }, { passive: true });

    carousel.addEventListener('pointerdown', (event) => {
        isDragging = true;
        dragStarted = false;
        dragStartX = event.clientX;
        dragStartScrollLeft = carousel.scrollLeft;
        carousel.setPointerCapture?.(event.pointerId);
        carousel.classList.add('is-dragging');
        holdCarousel();
    });

    carousel.addEventListener('pointermove', (event) => {
        if (!isDragging) {
            return;
        }

        const deltaX = event.clientX - dragStartX;

        if (Math.abs(deltaX) > 6) {
            dragStarted = true;
            carousel.scrollLeft = dragStartScrollLeft - deltaX;
        }
    });

    const endDrag = (event) => {
        if (!isDragging) {
            return;
        }

        isDragging = false;
        carousel.releasePointerCapture?.(event.pointerId);
        carousel.classList.remove('is-dragging');
        stop();
        start();
    };

    carousel.addEventListener('pointerup', endDrag);
    carousel.addEventListener('pointercancel', endDrag);
    carousel.addEventListener('click', (event) => {
        if (dragStarted) {
            event.preventDefault();
            event.stopPropagation();
            dragStarted = false;
        }
    }, true);

    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTick);
        resizeTick = window.setTimeout(() => scrollToItem(activeIndex), 160);
    });

    setActive(0);
    scrollToItem(0);
    start();
};


document.addEventListener('DOMContentLoaded', () => {
    initMobileMenu();
    // initNavHighlight();
    initFormHandling();
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
