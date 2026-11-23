(() => {
    'use strict';

    const deferred = '[data-ys-cache-carousel-deferred]';
    const activeClass = 'swiper-slide-active';
    const bulletSelector = '.swiper-pagination-bullet';

    function showSlide(slide) {
        if (!slide) {
            return;
        }
        for (const picture of slide.querySelectorAll(deferred)) {
            // Restore responsive sources before src so the browser requests just the selected image.
            for (const node of picture.querySelectorAll('[data-ys-cache-carousel-srcset]')) {
                node.srcset = node.getAttribute('data-ys-cache-carousel-srcset');
                node.removeAttribute('data-ys-cache-carousel-srcset');
            }
            const image = picture.querySelector('img[data-ys-cache-carousel-src]');
            image.src = image.getAttribute('data-ys-cache-carousel-src');
            image.removeAttribute('data-ys-cache-carousel-src');
            picture.removeAttribute('data-ys-cache-carousel-deferred');
            picture.hidden = false;
        }
    }

    function initialize(carousel) {
        const track = carousel.querySelector('.swiper-wrapper');
        if (!track) {
            return;
        }
        const slides = () => Array.from(track.children).filter(slide => slide.classList.contains('swiper-slide'));
        let boundSwiper = null;

        function showActive() {
            for (const slide of slides()) {
                if (slide.classList.contains(activeClass) || slide.classList.contains('swiper-slide-visible')) {
                    showSlide(slide);
                }
            }
        }

        function adjacent(steps) {
            const items = slides();
            if (items.length < 2) {
                return;
            }
            const index = Math.max(0, items.findIndex(slide => slide.classList.contains(activeClass)));
            for (const step of steps) {
                const target = index + step;
                if (carousel.swiper?.params?.loop || (target >= 0 && target < items.length)) {
                    showSlide(items[(target + items.length) % items.length]);
                }
            }
        }

        function selectBullet(event) {
            const bullet = event.target.closest?.(bulletSelector);
            if (!bullet || !carousel.contains(bullet)) {
                return;
            }
            const dots = Array.from(bullet.parentElement.children).filter(node => node.matches(bulletSelector));
            const index = dots.indexOf(bullet);
            const items = slides();
            showSlide(items.find(slide => slide.getAttribute('data-swiper-slide-index') === String(index)) ?? items[index]);
        }

        function sync() {
            showActive();
            if (!carousel.swiper || carousel.swiper === boundSwiper || typeof carousel.swiper.on !== 'function') {
                return;
            }
            boundSwiper = carousel.swiper;
            boundSwiper.on('slideChange slideChangeTransitionStart loopFix resize', showActive);
            boundSwiper.on('touchStart', () => adjacent([-1, 1]));
            boundSwiper.on('autoplayTimeLeft', (swiper, timeLeft) => {
                if (timeLeft <= 900) {
                    adjacent([swiper.params.autoplay?.reverseDirection ? -1 : 1]);
                }
            });
        }

        if (typeof MutationObserver !== 'function') {
            slides().forEach(showSlide);
            return;
        }
        new MutationObserver(sync).observe(carousel, {
            attributes: true,
            attributeFilter: ['class'],
            childList: true,
            subtree: true,
        });
        track.addEventListener('pointerdown', () => adjacent([-1, 1]), {passive: true});
        carousel.addEventListener('pointerdown', selectBullet, {capture: true, passive: true});
        carousel.addEventListener('click', selectBullet, true);
        sync();
    }

    document.querySelectorAll('[data-ys-cache-carousel]').forEach(initialize);
})();
