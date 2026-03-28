/**
 * AutoSEO WordPress Plugin - Frontend JavaScript
 * Version: 1.2.9
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Lazy load infographic images if needed
        $('.autoseo-infographic-container img').each(function() {
            const $img = $(this);
            if ($img.attr('data-src')) {
                $img.attr('src', $img.attr('data-src'));
                $img.removeAttr('data-src');
            }
        });

        // Add smooth scroll to anchor links within AutoSEO articles
        // Uses getElementById instead of jQuery selector to handle Unicode/URL-encoded IDs
        $('.autoseo-article a[href^="#"]').on('click', function(e) {
            var hash = this.getAttribute('href');
            if (!hash || hash === '#') return;

            var decoded;
            try {
                decoded = decodeURIComponent(hash.substring(1));
            } catch (ex) {
                decoded = hash.substring(1);
            }

            var el = document.getElementById(decoded);
            if (el) {
                e.preventDefault();
                $('html, body').stop().animate({
                    scrollTop: $(el).offset().top - 100
                }, 500);
            }
        });
    });

})(jQuery);

