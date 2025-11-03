<?php
/**
 * Plugin Name: Universal Referral Link System
 * Description: Handles referral links via ?u= parameter and provides shortcodes for sharing referral links
 * Version: 1.0.1
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * Author: Bpract
 * License: GPL v2 or later
 * Text Domain: universal-referral
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle referral parameter from URL
 */
function universal_referral_handle_parameter() {
    if (isset($_GET['u']) && !empty($_GET['u'])) {
        $referral_username = sanitize_text_field($_GET['u']);
        
        // Validate the referral username
        if (universal_referral_validate_username($referral_username)) {
            if (!session_id()) {
                session_start();
            }
            $_SESSION['referral_username'] = $referral_username;
            
            // Store in sessionStorage via JavaScript
            add_action('wp_footer', function() use ($referral_username) {
                ?>
                <script type="text/javascript">
                    sessionStorage.setItem('sponsor', '<?php echo esc_js($referral_username); ?>');
                    sessionStorage.setItem('referral_username', '<?php echo esc_js($referral_username); ?>');
                </script>
                <?php
            }, 5);
        }
    }
}
add_action('init', 'universal_referral_handle_parameter');

/**
 * Validate referral username via API
 */
function universal_referral_validate_username($username) {
    $api_url = get_option('mlm_api_url');
    
    if (empty($api_url)) {
        return false;
    }
    
    $url = $api_url . '/api/wp/validate-sponsor/' . $username;
    
    $response = wp_remote_get($url, array(
        'headers' => array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer APIKEY'
        ),
        'timeout' => 15
    ));
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);
    
    return isset($result['status']) && $result['status'] === true;
}

/**
 * Enqueue scripts and styles
 */
function universal_referral_enqueue_scripts() {
    wp_enqueue_style(
        'universal-referral-styles',
        plugin_dir_url(__FILE__) . 'css/referral-styles.css',
        array(),
        '1.0.1'
    );
    
    wp_enqueue_script(
        'universal-referral-script',
        plugin_dir_url(__FILE__) . 'js/referral-script.js',
        array('jquery'),
        '1.0.1',
        true
    );
    
    wp_localize_script('universal-referral-script', 'referralData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'siteUrl' => get_site_url(),
        'nonce' => wp_create_nonce('referral_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'universal_referral_enqueue_scripts');

/**
 * Add copy button to product pages (top right)
 */
function universal_referral_product_button() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $product_url = get_permalink();
    $referral_url = add_query_arg('u', $username, $product_url);
    ?>
    <div class="product-referral-button-container">
        <button type="button" 
                class="product-referral-copy-btn copy-referral-btn" 
                data-link="<?php echo esc_url($referral_url); ?>"
                data-success="✓ Copied!"
                data-original="📋 Copy Link">
            📋 Copy Link
        </button>
    </div>
    <?php
}
add_action('woocommerce_before_single_product', 'universal_referral_product_button');

/**
 * Apply referral to checkout
 */
function universal_referral_inject_script() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            const referralUsername = sessionStorage.getItem('referral_username') || 
                                    sessionStorage.getItem('sponsor');
            
            if (referralUsername && $('#referral').length) {
                $('#referral').val(referralUsername);
            }
        });
    </script>
    <?php
}
add_action('wp_footer', 'universal_referral_inject_script');

/**
 * Clear referral on logout
 */
function universal_referral_clear_on_logout() {
    if (session_id()) {
        unset($_SESSION['referral_username']);
    }
    ?>
    <script type="text/javascript">
        sessionStorage.removeItem('referral_username');
        sessionStorage.removeItem('sponsor');
    </script>
    <?php
}
add_action('wp_logout', 'universal_referral_clear_on_logout');

/**
 * Shortcode: Copy referral link button
 * Usage: [copy_referral_button]
 */
function universal_referral_copy_button_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p class="referral-login-notice">Please login to get your referral link.</p>';
    }
    
    $atts = shortcode_atts(array(
        'url' => get_permalink(),
        'button_text' => 'Copy Referral Link',
        'success_text' => 'Link Copied!',
    ), $atts);
    
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $referral_url = add_query_arg('u', $username, $atts['url']);
    
    ob_start();
    ?>
    <div class="referral-link-container">
        <input type="text" 
               class="referral-link-input" 
               value="<?php echo esc_url($referral_url); ?>" 
               readonly>
        <button type="button" 
                class="copy-referral-btn" 
                data-link="<?php echo esc_url($referral_url); ?>"
                data-success="<?php echo esc_attr($atts['success_text']); ?>"
                data-original="<?php echo esc_attr($atts['button_text']); ?>">
            <?php echo esc_html($atts['button_text']); ?>
        </button>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('copy_referral_button', 'universal_referral_copy_button_shortcode');

/**
 * Shortcode: Display referral link
 * Usage: [referral_link]
 */
function universal_referral_link_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $atts = shortcode_atts(array(
        'url' => get_permalink(),
        'text' => 'My Referral Link',
    ), $atts);
    
    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $referral_url = add_query_arg('u', $username, $atts['url']);
    
    return sprintf(
        '<a href="%s" class="referral-link">%s</a>',
        esc_url($referral_url),
        esc_html($atts['text'])
    );
}
add_shortcode('referral_link', 'universal_referral_link_shortcode');

/**
 * Helper function: Get referral URL
 */
function get_referral_url($url = '', $username = '') {
    if (empty($username) && is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $username = $current_user->user_login;
    }
    
    if (empty($url)) {
        $url = get_permalink();
    }
    
    if ($username) {
        return add_query_arg('u', $username, $url);
    }
    
    return $url;
}