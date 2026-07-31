/**
 * Ceremony dossier reveal behavior.
 *
 * The hiding class is added only when this script runs, preserving visible
 * content for no-script and reduced-motion readers.
 */
/* Dossier reveal-on-scroll. Sections stay fully visible without JS or
           under reduced motion; the hiding class is only added here, right
           before the observer starts watching. */
        (function () {
            if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            var blocks = document.querySelectorAll('.aat-ceremony-dossier > section:not(.aat-ceremony-dossier-hero), .aat-ceremony-dossier > nav, .aat-ceremony-dossier > div.aat-hub-section');
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('aat-inview');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0, rootMargin: '0px 0px -60px 0px' });
            blocks.forEach(function (block) {
                block.classList.add('aat-dossier-reveal');
                io.observe(block);
            });
        })();
