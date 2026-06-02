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
