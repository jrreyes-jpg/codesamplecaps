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
    document.querySelectorAll('.service-card').forEach((card) => {
        const button = card.querySelector('.service-more');

        if (!button) {
            return;
        }

        button.addEventListener('click', () => {
            const isExpanded = card.classList.toggle('is-expanded');
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

    const showProject = (index) => {
        activeIndex = (index + projects.length) % projects.length;
        const project = projects[activeIndex];
        image.src = project.src;
        image.alt = project.alt;
        caption.textContent = project.title;
        lightbox.classList.toggle('is-single', project.isSingle);
    };

    const openLightbox = (index) => {
        showProject(index);
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        closeButton.focus();
    };

    const closeLightbox = () => {
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
            showProject(activeIndex - 1);
        }
    });

    nextButton.addEventListener('click', () => {
        if (!projects[activeIndex]?.isSingle) {
            showProject(activeIndex + 1);
        }
    });

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
            showProject(activeIndex - 1);
        }

        if (!projects[activeIndex]?.isSingle && event.key === 'ArrowRight') {
            showProject(activeIndex + 1);
        }
    });
};

const initMobileProjectCarousel = () => {
    const carousel = document.querySelector('.projects-grid');
    const items = Array.from(carousel?.querySelectorAll('.project-item') ?? []);

    if (!carousel || items.length < 2) {
        return;
    }

    const mobileQuery = window.matchMedia('(max-width: 760px)');
    const controls = document.createElement('div');
    controls.className = 'projects-carousel-controls';
    controls.setAttribute('aria-label', 'Project carousel navigation');
    const clones = items.map((item, index) => {
        const clone = item.cloneNode(true);
        const cloneLink = clone.querySelector('.project-link');

        clone.classList.add('is-carousel-clone');
        clone.classList.remove('reveal-on-scroll');
        clone.classList.add('is-visible');
        clone.setAttribute('aria-hidden', 'true');

        cloneLink?.addEventListener('click', (event) => {
            event.preventDefault();
            items[index].querySelector('.project-link')?.dispatchEvent(new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
            }));
        });

        carousel.appendChild(clone);
        return clone;
    });
    const allItems = [...items, ...clones];

    const dots = items.map((item, index) => {
        const dot = document.createElement('button');
        dot.className = 'projects-carousel-dot';
        dot.type = 'button';
        dot.setAttribute('aria-label', `Go to project ${index + 1}`);
        dot.addEventListener('click', () => {
            scrollToItem(index);
            pauseThenResume();
        });
        controls.appendChild(dot);
        return dot;
    });

    carousel.after(controls);

    let activeIndex = 0;
    let animationId = 0;
    let lastFrameTime = 0;
    let isPaused = false;
    let resumeId = 0;

    const setActive = (index) => {
        activeIndex = (index + items.length) % items.length;
        dots.forEach((dot, dotIndex) => {
            dot.classList.toggle('is-active', dotIndex === activeIndex);
        });
    };

    function scrollToItem(index) {
        const targetIndex = (index + items.length) % items.length;
        const target = items[targetIndex];
        const left = target.offsetLeft - parseFloat(getComputedStyle(carousel).paddingLeft || '0');
        carousel.scrollTo({ left, behavior: 'smooth' });
        setActive(targetIndex);
    }

    const getCenteredIndex = () => {
        const center = carousel.scrollLeft + carousel.clientWidth / 2;
        const nearestIndex = allItems.reduce((bestIndex, item, index) => {
            const itemCenter = item.offsetLeft + item.offsetWidth / 2;
            const bestCenter = allItems[bestIndex].offsetLeft + allItems[bestIndex].offsetWidth / 2;
            return Math.abs(itemCenter - center) < Math.abs(bestCenter - center) ? index : bestIndex;
        }, 0);

        return nearestIndex % items.length;
    };

    const stop = () => {
        window.cancelAnimationFrame(animationId);
        animationId = 0;
        lastFrameTime = 0;
    };

    const animate = (timestamp) => {
        if (!mobileQuery.matches || isPaused) {
            stop();
            return;
        }

        if (!lastFrameTime) {
            lastFrameTime = timestamp;
        }

        const delta = Math.min(timestamp - lastFrameTime, 48);
        const loopPoint = clones[0].offsetLeft - parseFloat(getComputedStyle(carousel).paddingLeft || '0');
        const speed = 34;
        carousel.scrollLeft += (speed * delta) / 1000;

        if (carousel.scrollLeft >= loopPoint) {
            carousel.scrollLeft -= loopPoint;
        }

        setActive(getCenteredIndex());
        lastFrameTime = timestamp;
        animationId = window.requestAnimationFrame(animate);
    };

    const start = () => {
        if (!mobileQuery.matches || animationId) {
            return;
        }

        isPaused = false;
        animationId = window.requestAnimationFrame(animate);
    };

    const pauseThenResume = () => {
        isPaused = true;
        stop();
        window.clearTimeout(resumeId);
        resumeId = window.setTimeout(start, 3800);
    };

    let scrollTick = 0;
    carousel.addEventListener('scroll', () => {
        window.clearTimeout(scrollTick);
        scrollTick = window.setTimeout(() => setActive(getCenteredIndex()), 80);
    }, { passive: true });

    ['pointerdown', 'touchstart', 'focusin', 'mouseenter'].forEach((eventName) => {
        carousel.addEventListener(eventName, pauseThenResume, { passive: true });
    });

    carousel.addEventListener('mouseleave', start);

    const handleViewportChange = () => {
        stop();
        isPaused = false;
        carousel.scrollTo({ left: 0, behavior: 'auto' });
        setActive(0);
        start();
    };

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', handleViewportChange);
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(handleViewportChange);
    }

    setActive(0);
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
    initMobileProjectCarousel();
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
