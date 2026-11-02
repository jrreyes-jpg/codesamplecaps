// Buong preview ng inspection photo para sa Admin at Engineer.
document.addEventListener('DOMContentLoaded', function () {
    const lightbox = document.createElement('div');
    lightbox.className = 'inspection-photo-lightbox';
    lightbox.hidden = true;
    lightbox.innerHTML = [
        '<div class="inspection-photo-lightbox__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionPhotoCaption">',
        '<img class="inspection-photo-lightbox__image" data-inspection-lightbox-image alt="Inspection evidence">',
        '<div class="inspection-photo-lightbox__caption" id="inspectionPhotoCaption" data-inspection-lightbox-caption></div>',
        '<button type="button" class="inspection-photo-lightbox__close" data-inspection-lightbox-close aria-label="Close full photo">&times;</button>',
        '<button type="button" class="inspection-photo-lightbox__nav inspection-photo-lightbox__nav--previous" data-inspection-lightbox-previous aria-label="Previous photo">&#8249;</button>',
        '<button type="button" class="inspection-photo-lightbox__nav inspection-photo-lightbox__nav--next" data-inspection-lightbox-next aria-label="Next photo">&#8250;</button>',
        '</div>',
    ].join('');
    document.body.appendChild(lightbox);

    const image = lightbox.querySelector('[data-inspection-lightbox-image]');
    const caption = lightbox.querySelector('[data-inspection-lightbox-caption]');
    const previous = lightbox.querySelector('[data-inspection-lightbox-previous]');
    const next = lightbox.querySelector('[data-inspection-lightbox-next]');
    const close = lightbox.querySelector('[data-inspection-lightbox-close]');
    let photos = [];
    let currentIndex = 0;
    let trigger = null;

    const showPhoto = function () {
        const selected = photos[currentIndex];
        if (!selected || !image || !caption) return;
        image.src = selected.currentSrc || selected.src;
        image.alt = selected.alt || 'Inspection evidence';
        caption.textContent = selected.dataset.photoName || selected.alt || 'Inspection photo';
        const showNavigation = photos.length > 1;
        if (previous) previous.hidden = !showNavigation;
        if (next) next.hidden = !showNavigation;
    };

    const openLightbox = function (selectedImage) {
        const gallery = selectedImage.closest('[data-inspection-evidence-gallery]');
        photos = Array.from((gallery || document).querySelectorAll('[data-inspection-photo-lightbox]'));
        currentIndex = Math.max(0, photos.indexOf(selectedImage));
        trigger = selectedImage;
        showPhoto();
        lightbox.hidden = false;
        close?.focus();
    };

    const closeLightbox = function () {
        lightbox.hidden = true;
        if (image) image.removeAttribute('src');
        trigger?.focus();
        trigger = null;
        photos = [];
    };

    const movePhoto = function (step) {
        if (photos.length < 2) return;
        currentIndex = (currentIndex + step + photos.length) % photos.length;
        showPhoto();
    };

    document.addEventListener('click', function (event) {
        const selectedImage = event.target.closest('[data-inspection-photo-lightbox]');
        if (selectedImage) {
            openLightbox(selectedImage);
            return;
        }
        if (event.target === lightbox || event.target.closest('[data-inspection-lightbox-close]')) {
            closeLightbox();
            return;
        }
        if (event.target.closest('[data-inspection-lightbox-previous]')) movePhoto(-1);
        if (event.target.closest('[data-inspection-lightbox-next]')) movePhoto(1);
    });

    document.addEventListener('keydown', function (event) {
        const selectedImage = event.target.closest?.('[data-inspection-photo-lightbox]');
        if (lightbox.hidden && selectedImage && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openLightbox(selectedImage);
            return;
        }
        if (lightbox.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeLightbox();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            movePhoto(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            movePhoto(1);
        }
    });
});
