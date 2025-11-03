jQuery(document).ready(function($) {
    
    /**
     * Handle copy referral link button click
     */
    $(document).on('click', '.copy-referral-btn', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const link = $button.data('link');
        const successText = $button.data('success');
        const originalText = $button.data('original');
        
        // Try modern clipboard API first
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function() {
                showCopySuccess($button, successText, originalText);
            }).catch(function(err) {
                // Fallback to old method
                fallbackCopyToClipboard(link, $button, successText, originalText);
            });
        } else {
            // Fallback for older browsers
            fallbackCopyToClipboard(link, $button, successText, originalText);
        }
    });
    
    /**
     * Show success message after copying
     */
    function showCopySuccess($button, successText, originalText) {
        const originalBtnText = $button.text();
        $button.text(successText);
        $button.addClass('copied');
        
        setTimeout(function() {
            $button.text(originalText || originalBtnText);
            $button.removeClass('copied');
        }, 2000);
    }
    
    /**
     * Fallback copy method for older browsers
     */
    function fallbackCopyToClipboard(text, $button, successText, originalText) {
        const $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess($button, successText, originalText);
            } else {
                alert('Failed to copy link. Please copy manually.');
            }
        } catch (err) {
            alert('Failed to copy link. Please copy manually.');
        }
        
        $temp.remove();
    }
    
    /**
     * Handle referral link input click - select all text
     */
    $(document).on('click', '.referral-link-input', function() {
        $(this).select();
    });
    
    /**
     * Check URL parameters for referral and store in sessionStorage
     */
    function checkUrlForReferral() {
        const urlParams = new URLSearchParams(window.location.search);
        const referralUsername = urlParams.get('u');
        
        if (referralUsername) {
            // Store in sessionStorage
            sessionStorage.setItem('referral_username', referralUsername);
            sessionStorage.setItem('sponsor', referralUsername);
            
            // Validate via AJAX (optional)
            $.ajax({
                url: referralData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'validate_referral',
                    username: referralUsername,
                    nonce: referralData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Referral validated:', referralUsername);
                    } else {
                        console.log('Invalid referral username');
                        sessionStorage.removeItem('referral_username');
                    }
                }
            });
        }
    }
    
    /**
     * Apply referral to checkout form
     */
    function applyReferralToCheckout() {
        const referralUsername = sessionStorage.getItem('referral_username') || 
                                sessionStorage.getItem('sponsor');
        
        if (referralUsername && $('#referral').length) {
            $('#referral').val(referralUsername);
        }
    }
    
    // Initialize
    checkUrlForReferral();
    applyReferralToCheckout();
    
    // Re-apply on WooCommerce checkout update
    $(document.body).on('updated_checkout', function() {
        applyReferralToCheckout();
    });
    
    /**
     * Add referral parameter to all links if user is logged in
     * (Optional feature - uncomment to auto-append ?u= to all links)
     */
    /*
    function appendReferralToLinks() {
        const currentUsername = $('body').data('current-username');
        
        if (currentUsername) {
            $('a').each(function() {
                const $link = $(this);
                const href = $link.attr('href');
                
                // Only modify internal links that don't already have ?u=
                if (href && 
                    href.indexOf(referralData.siteUrl) === 0 && 
                    href.indexOf('?u=') === -1 && 
                    href.indexOf('&u=') === -1) {
                    
                    const separator = href.indexOf('?') > -1 ? '&' : '?';
                    $link.attr('href', href + separator + 'u=' + currentUsername);
                }
            });
        }
    }
    */
    
});