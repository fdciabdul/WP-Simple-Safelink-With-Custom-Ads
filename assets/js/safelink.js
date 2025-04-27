/**
 * SafeLink JavaScript functionality
 */
(function($) {
    'use strict';

    // Function to start countdown
    function startCountdown() {
        var timerElement = $('#timer');
        var continueButton = $('#continue-button');
        
        if (timerElement.length === 0) {
            return;
        }
        
        var seconds = parseInt(timerElement.text(), 10);
        var redirectUrl = continueButton.find('a').attr('href');
        
        // Check if we have valid seconds and URL
        if (isNaN(seconds) || !redirectUrl) {
            return;
        }
        
        // Start the countdown
        var interval = setInterval(function() {
            seconds--;
            timerElement.text(seconds);
            
            if (seconds <= 0) {
                clearInterval(interval);
                timerElement.parent().hide();
                continueButton.show();
                
                // Uncomment the line below to enable auto-redirect after countdown
                // window.location.href = redirectUrl;
            }
        }, 1000);
    }

    // Handle link clicks
    function handleLinkClicks() {
        $('.safelink-button a').on('click', function(e) {
            // You can add tracking code here if needed
            // For example, to track clicks via Google Analytics:
            if (typeof ga !== 'undefined') {
                ga('send', 'event', 'SafeLink', 'click', $(this).attr('href'));
            }
            
            // Allow the default action (following the link)
            return true;
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        startCountdown();
        handleLinkClicks();
    });

})(jQuery);