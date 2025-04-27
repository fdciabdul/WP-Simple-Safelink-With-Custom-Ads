/**
 * SafeLink Admin JavaScript
 */
(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Handle copy URL buttons
        $('.copy-url').on('click', function() {
            var url = $(this).data('url');
            copyToClipboard(url);
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Copied!');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        });
        
        // Handle delete link buttons
        $('.delete-link').on('click', function(e) {
            e.preventDefault();
            
            if (confirm(wpSafelink.messages.confirmDelete)) {
                var linkId = $(this).data('id');
                
                $.ajax({
                    type: 'POST',
                    url: wpSafelink.ajaxurl,
                    data: {
                        action: 'safelink_delete_link',
                        nonce: wpSafelink.nonce,
                        link_id: linkId
                    },
                    success: function(response) {
                        if (response.success) {
                            // Show success message and remove the row
                            alert(response.data.message);
                            location.reload();
                        } else {
                            // Show error message
                            alert(response.data.message);
                        }
                    }
                });
            }
        });
        
        // Validate form submissions
        $('#safelink-form').on('submit', function() {
            var title = $('#link_title').val();
            var url = $('#destination_url').val();
            
            if (!title || !url) {
                alert('Title and Destination URL are required.');
                return false;
            }
            
            return true;
        });
        
        // Toggle notice dismissibility
        $(document).on('click', '.notice-dismiss', function() {
            $(this).parent().fadeOut(300, function() {
                $(this).remove();
            });
        });
    });
    
    // Helper function to copy text to clipboard
    function copyToClipboard(text) {
        // Create a temporary input element
        var $temp = $('<input>');
        $('body').append($temp);
        
        // Set the text and select it
        $temp.val(text).select();
        
        // Execute copy command
        document.execCommand('copy');
        
        // Remove the temporary element
        $temp.remove();
    }

})(jQuery);