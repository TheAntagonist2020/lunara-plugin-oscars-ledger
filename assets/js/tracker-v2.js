/**
 * Lunara Film — Awards Tracker V2 (frontend)
 * Lightweight: tabs + season selector.
 */
(function($) {
    'use strict';

    function initTrackerV2() {
        var $tracker = $('.aat-tracker-v2');
        if (!$tracker.length) return;

        // Tabs
        $tracker.on('click', '.aat-tracker-tab', function() {
            var $btn = $(this);
            var tier = $btn.data('tier');
            if (!tier) return;

            $tracker.find('.aat-tracker-tab').removeClass('active');
            $btn.addClass('active');

            $tracker.find('.aat-tracker-panel').removeClass('active');
            $tracker.find('.aat-tracker-panel[data-tier="' + tier + '"]').addClass('active');
        });

        // Season selector (reload with ?ceremony=)
        $tracker.on('change', '#aat-tracker-season', function() {
            var ceremony = $(this).val();
            if (!ceremony) return;

            try {
                var url = new URL(window.location.href);
                url.searchParams.set('ceremony', ceremony);
                window.location.href = url.toString();
            } catch (e) {
                // Fallback for older browsers
                window.location.href = window.location.pathname + '?ceremony=' + encodeURIComponent(ceremony);
            }
        });
    }

    $(document).ready(initTrackerV2);

})(jQuery);
