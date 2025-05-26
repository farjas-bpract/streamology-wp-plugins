<?php
/*
Plugin Name: MLM Back Office Sync
Description: Syncs WooCommerce products and purchases with an MLM back office by sending product details and purchase data to a Laravel API. Includes log viewer to read and clear logs.
Version: 1.3.0
Author: Farjas T.
License: GPL-2.0+
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLM_Back_Office_Sync {
    private $log_file;

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/mlm-back-office-sync.log';
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_menu', [$this, 'add_log_viewer_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_log_download']);
        add_action('admin_init', [$this, 'handle_log_clear']);
        add_action('woocommerce_order_status_completed', [$this, 'sync_purchase']);
        add_action('woocommerce_new_product', [$this, 'sync_product']);
        add_action('woocommerce_update_product', [$this, 'sync_product']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_mlm_sync_all_products', [$this, 'sync_all_products']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    // Add settings page under WooCommerce menu
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'MLM Back Office Sync Settings',
            'MLM Sync Settings',
            'manage_options',
            'mlm-back-office-sync',
            [$this, 'render_settings_page']
        );
    }

    // Add log viewer page under WooCommerce menu
    public function add_log_viewer_page() {
        add_submenu_page(
            'woocommerce',
            'MLM Sync Log',
            'MLM Sync Log',
            'manage_options',
            'mlm-sync-log',
            [$this, 'render_log_viewer_page']
        );
    }

    // Register settings
    public function register_settings() {
        register_setting('mlm_back_office_sync_group', 'mlm_api_base_url', [
            'sanitize_callback' => [$this, 'sanitize_url'],
        ]);
        register_setting('mlm_back_office_sync_group', 'mlm_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        add_settings_section(
            'mlm_api_settings',
            'API Configuration',
            null,
            'mlm-back-office-sync'
        );

        add_settings_field(
            'mlm_api_base_url',
            'API Base URL',
            [$this, 'api_base_url_field'],
            'mlm-back-office-sync',
            'mlm_api_settings'
        );

        add_settings_field(
            'mlm_api_key',
            'API Key',
            [$this, 'api_key_field'],
            'mlm-back-office-sync',
            'mlm_api_settings'
        );
    }

    // Sanitize URL
    public function sanitize_url($input) {
        $input = trim($input);
        $sanitized = esc_url_raw($input);
        if (empty($sanitized) || !filter_var($input, FILTER_VALIDATE_URL)) {
            add_settings_error(
                'mlm_api_base_url',
                'invalid_url',
                'Please enter a valid URL (e.g., https://mlm-api.com).',
                'error'
            );
            return get_option('mlm_api_base_url');
        }
        return $sanitized;
    }

    // Render API URL field
    public function api_base_url_field() {
        $value = get_option('mlm_api_base_url', '');
        echo '<input type="url" name="mlm_api_base_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Enter the base URL of the MLM API (e.g., https://mlm-api.com). The plugin will append /api/wp/ to this URL for API calls.</p>';
    }

    // Render API Key field
    public function api_key_field() {
        $value = get_option('mlm_api_key', '');
        echo '<input type="text" name="mlm_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Enter the API key provided by your MLM back office.</p>';
    }

    // Render settings page
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>MLM Back Office Sync Settings</h1>
            <button id="mlm-sync-products" class="button button-primary">Sync All Products</button>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('mlm_back_office_sync_group');
                do_settings_sections('mlm-back-office-sync');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // Render log viewer page
    public function render_log_viewer_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }
        ?>
        <div class="wrap">
            <h1>MLM Back Office Sync Log</h1>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('mlm_download_log', '1'), 'mlm_download_log')); ?>" class="button">Download Log File</a>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('mlm_clear_log', '1'), 'mlm_clear_log')); ?>" class="button" onclick="return confirm('Are you sure you want to clear the log?');">Clear Log File</a>
            </p>
            <?php
            // Display success or error notices
            if (isset($_GET['mlm_clear_log']) && check_admin_referer('mlm_clear_log')) {
                echo '<div class="notice notice-success is-dismissible"><p>Log file cleared successfully.</p></div>';
            }
            ?>
            <pre style="background: #f5f5f5; padding: 10px; max-height: 600px; overflow-y: auto;">
                <?php echo esc_html($this->read_log_file()); ?>
            </pre>
        </div>
        <?php
    }

    // Read log file
    private function read_log_file() {
        if (file_exists($this->log_file)) {
            return file_get_contents($this->log_file);
        } else {
            return 'Log file does not exist.';
        }
    }

    // Handle log download
    public function handle_log_download() {
        if (isset($_GET['mlm_download_log']) && current_user_can('manage_options') && check_admin_referer('mlm_download_log')) {
            if (file_exists($this->log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="mlm-back-office-sync.log"');
                readfile($this->log_file);
                exit;
            } else {
                wp_die('Log file does not exist.');
            }
        }
    }

    // Handle log clear
    public function handle_log_clear() {
        if (isset($_GET['mlm_clear_log']) && current_user_can('manage_options') && check_admin_referer('mlm_clear_log')) {
            if (file_exists($this->log_file)) {
                file_put_contents($this->log_file, '');
            }
            // Redirect to avoid resubmission
            wp_safe_redirect(add_query_arg(['page' => 'mlm-sync-log', 'mlm_clear_log' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    // Enqueue scripts
    public function enqueue_scripts($hook) {
        if ($hook === 'woocommerce_page_mlm-back-office-sync') {
            wp_enqueue_script('mlm-sync', plugin_dir_url(__FILE__) . 'mlm-sync.js', ['jquery'], '1.0', true);
            wp_localize_script('mlm-sync', 'mlmSync', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mlm_sync_products'),
            ]);
        }
    }

    // Sync all products
    public function sync_all_products() {
        check_ajax_referer('mlm_sync_products', 'nonce');

        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(['message' => 'API URL or API Key not configured.']);
            return;
        }

        $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
        $total = count($products);
        $success_count = 0;
        $error_count = 0;

        foreach ($products as $product) {
            $product_id = $product->get_id();
            $name = $product->get_name();
            $price = $product->get_regular_price();

            if (empty($price)) {
                $this->log_error("Product ID $product_id has no regular price defined.");
                $error_count++;
                continue;
            }

            $response = wp_remote_post($api_url . '/api/wp/wordpress-product', [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'name' => $name,
                    'product_id' => (string) $product_id,
                    'price' => (float) $price,
                ]),
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $this->log_error('Product sync failed for ID ' . $product_id . ': ' . $response->get_error_message());
                $error_count++;
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($response_code === 200 || $response_code === 201) {
                $this->log_success("Product synced: ID $product_id, Name: $name, Price: $price");
                $success_count++;
            } else {
                $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
                $this->log_error("Product sync error for ID $product_id: HTTP $response_code - $error_message");
                $error_count++;
            }
        }

        wp_send_json_success([
            'message' => sprintf('Synced %d of %d products successfully. %d errors.', $success_count, $total, $error_count)
        ]);
    }

    // Sync purchase data to MLM API
    public function sync_purchase($order_id) {
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured.');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_error("Order not found for ID: $order_id");
            return;
        }

        $user_email = $order->get_billing_email();
        $items = $order->get_items();

        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $response = wp_remote_post($api_url . '/api/wp/external-wordpress-purchase', [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'product_id' => $product_id,
                    'user_email' => $user_email,
                ]),
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $this->log_error('Purchase sync failed for product ID ' . $product_id . ': ' . $response->get_error_message());
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($response_code === 200 && isset($data['status']) && $data['status']) {
                $this->log_success("Purchase synced for product ID $product_id, user $user_email");
            } else {
                $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
                $this->log_error("Purchase sync error for product ID $product_id: HTTP $response_code - $error_message");
            }
        }
    }

    // Sync product to MLM API
    public function sync_product($product_id) {
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured.');
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $this->log_error("Product not found for ID: $product_id");
            return;
        }

        $price = $product->get_regular_price();
        if (empty($price)) {
            $this->log_error("Product ID $product_id has no regular price defined.");
            return;
        }

        $response = wp_remote_post($api_url . '/api/wp/wordpress-product', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'name' => $product->get_name(),
                'product_id' => (string) $product_id,
                'price' => (float) $price,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log_error('Product sync failed for ID ' . $product_id . ': ' . $response->get_error_message());
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code === 200 || $response_code === 201) {
            $this->log_success("Product synced: ID $product_id, Name: " . $product->get_name() . ", Price: $price");
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log_error("Product sync error for ID $product_id: HTTP $response_code - $error_message");
        }
    }

    // Log error to file
    private function log_error($message) {
        $timestamp = current_time('mysql');
        $log_entry = "[$timestamp] ERROR: $message\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    // Log success to file
    private function log_success($message) {
        $timestamp = current_time('mysql');
        $log_entry = "[$timestamp] SUCCESS: $message\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    // Activation hook
    public function activate() {
        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            chmod($this->log_file, 0644);
        }
    }

    // Deactivation hook
    public function deactivate() {
        // Optionally clear settings or log
    }
}

new MLM_Back_Office_Sync();
?>