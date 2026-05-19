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
    const links = Array.from(document.querySelectorAll('.project-link'));
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
        const title = link.querySelector('h4')?.textContent?.trim() ?? img?.alt ?? 'Project image';

        return {
            src: link.getAttribute('href') ?? '',
            alt: img?.alt ?? title,
            title,
        };
    });

    let activeIndex = 0;

    const showProject = (index) => {
        activeIndex = (index + projects.length) % projects.length;
        const project = projects[activeIndex];
        image.src = project.src;
        image.alt = project.alt;
        caption.textContent = project.title;
    };

    const openLightbox = (index) => {
        showProject(index);
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        closeButton.focus();
    };

    const closeLightbox = () => {
        lightbox.classList.remove('is-open');
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
    prevButton.addEventListener('click', () => showProject(activeIndex - 1));
    nextButton.addEventListener('click', () => showProject(activeIndex + 1));

    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) {
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

        if (event.key === 'ArrowLeft') {
            showProject(activeIndex - 1);
        }

        if (event.key === 'ArrowRight') {
            showProject(activeIndex + 1);
        }
    });
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
