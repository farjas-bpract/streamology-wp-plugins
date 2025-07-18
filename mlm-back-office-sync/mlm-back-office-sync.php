<?php
/*
Plugin Name: MLM Back Office Sync
Description: Syncs WooCommerce products, purchases, and user registrations with Cloud MLM back office and helps SSO Login. Includes log viewer.
Version: 1.5.4
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
        add_action('woocommerce_order_status_processing', [$this, 'sync_purchase']);
        add_action('woocommerce_new_product', [$this, 'sync_product']);
        add_action('woocommerce_update_product', [$this, 'sync_product']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_mlm_sync_all_products', [$this, 'sync_all_products']);
        add_filter('woocommerce_process_registration_errors', [$this, 'validate_registration'], 10, 3);
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_registration']);
        add_action('user_register', [$this, 'sync_user_registration'], 10, 1);
        add_action('profile_update', [$this, 'sync_user_update'], 10, 2);
        add_action('delete_user', [$this, 'sync_user_deletion']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('mlm_redirect', [$this, 'mlm_redirect_shortcode']);
    }

    // Register REST API routes
    public function register_rest_routes() {
        register_rest_route('custom-login/v1', '/login', [
            'methods' => 'GET',
            'callback' => [$this, 'mlm_sso_login'],
            'permission_callback' => '__return_true', // Public access; token validation handled in callback
        ]);
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

        register_setting('mlm_back_office_sync_group', 'mlm_frontend_url', [
            'sanitize_callback' => [$this, 'sanitize_frontend_url'],
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

        add_settings_field(
            'mlm_frontend_url',
            'Frontend URL',
            [$this, 'frontend_url_field'],
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
                'Please enter a valid URL.',
                'error'
            );
            return get_option('mlm_api_base_url');
        }
        return $sanitized;
    }

    public function sanitize_frontend_url($input) {
        $input = trim($input);
        $sanitized = esc_url_raw($input);
        if (empty($sanitized) || !filter_var($input, FILTER_VALIDATE_URL)) {
            add_settings_error(
                'mlm_frontend_url',
                'invalid_url',
                'Please enter a valid Frontend URL.',
                'error'
            );
            return get_option('mlm_frontend_url');
        }
        return $sanitized;
    }

    // Render API URL field
    public function api_base_url_field() {
        $value = get_option('mlm_api_base_url', '');
        echo '<input type="url" name="mlm_api_base_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Enter the base URL of the MLM API. The plugin will append /api/wp/ to this URL for API calls.</p>';
    }

    // Render API Key field
    public function api_key_field() {
        $value = get_option('mlm_api_key', '');
        echo '<input type="text" name="mlm_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Enter the API key provided by your MLM back office.</p>';
    }

    // Render Frontend URL field
    public function frontend_url_field() {
        $value = get_option('mlm_frontend_url', '');
        echo '<input type="url" name="mlm_frontend_url" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Enter the frontend URL of your MLM back office.</p>';
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
            wp_safe_redirect(add_query_arg(['page' => 'mlm-sync-log'], admin_url('admin.php')));
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

        // Check if order has already been synced
        $sync_status = $order->get_meta('_mlm_sync_status');
        if ($sync_status === 'synced') {
            $this->log_success("Order ID $order_id already synced, skipping.");
            return;
        }
		
		$user_id = $order->get_user_id();
		if ($user_id) {
			$user = get_userdata($user_id);
			$username = $user ? $user->user_login : '';
		} else {
			$username = ''; // Handle guest users
		}
    
        $items = $order->get_items();

        $all_items_synced = true;

        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $response = wp_remote_post($api_url . '/api/wp/external-wordpress-purchase', [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'product_id' => $product_id,
                    'username' => $username,
                    'order_id' => $order_id
                ]),
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $this->log_error('Purchase sync failed for product ID ' . $product_id . ': ' . $response->get_error_message());
                $all_items_synced = false;
                continue;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($response_code === 200 && isset($data['status']) && $data['status']) {
                $this->log_success("Purchase synced for product ID $product_id, user $username");
            } else {
                $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
                $this->log_error("Purchase sync error for product ID $product_id: HTTP $response_code - $error_message response:" . print_r($data, true));
                $all_items_synced = false;
            }
        }
    
        // Mark order as synced only if all items were successfully synced
        if ($all_items_synced) {
            $order->update_meta_data('_mlm_sync_status', 'synced');
            $order->save();
            $this->log_success("Order ID $order_id marked as synced.");
        } else {
            $this->log_error("Order ID $order_id not marked as synced due to partial failures.");
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

    // Validate user registration via API (for standalone registration page)
    public function validate_registration($errors, $username, $password) {
        $email = isset($_POST['email']) ? sanitize_text_field($_POST['email']) : '';
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $this->log_success("Standalone registration validation for email: $email");
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in validate_registration.');
            $errors->add('api_config_error', 'API configuration is missing.');
            return $errors;
        }

        $referral = isset($_POST['referral']) ? sanitize_text_field($_POST['referral']) : '';

        if (empty($password)) {
            $this->log_error('Password field is empty in standalone registration form.');
            $errors->add('password_error', 'Password is required.');
            return $errors;
        }

        if (empty($username)) {
            $username = sanitize_user(current(explode('@', $email)), true);
            $append = 1;
            $original_username = $username;
            while (username_exists($username)) {
                $username = $original_username . $append;
                $append++;
            }
        }

        $data = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ];

        if (!empty($referral)) {
            $data['referral'] = $referral;
        }

        $optional_fields = ['name', 'address', 'mobile', 'gender', 'country', 'city', 'zipcode', 'date_of_birth'];
        foreach ($optional_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }

        // $this->log_success("Attempting standalone validation API call with data: " . json_encode($data));
        $response = wp_remote_post($api_url . '/api/wp/validate-user', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error("Standalone validation API call failed: $error_message");
            $errors->add('api_error', 'Failed to connect to MLM API: ' . $error_message);
            return $errors;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $this->log_success("Standalone validation API response code: $response_code, body: $body");
        $result = json_decode($body, true);

        if ($response_code === 200 && isset($result['status']) && $result['status']) {
            $transient_key = 'mlm_temp_password_' . md5($email);
            set_transient($transient_key, $password, 300);
            $this->log_success("Standalone validation successful, transient set with key: $transient_key");
            return $errors;
        } else {
            $this->log_error("Standalone validation API failed: " . print_r($result, true));
            if (isset($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors->add($field . '_error', $message);
                    }
                }
            } else {
                $errors->add('api_error', 'MLM API validation failed: ' . ($result['message'] ?? 'Unknown error'));
            }
            return $errors;
        }
    }

    // Validate user registration during checkout
    public function validate_checkout_registration() {
        // if (!isset($_POST['createaccount']) || !$_POST['createaccount']) {
        //     $this->log_success("Checkout registration not requested (createaccount not set).");
        //     return;
        // }
        $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
        $username = isset($_POST['account_username']) ? sanitize_text_field($_POST['account_username']) : '';
        $password = isset($_POST['account_password']) ? sanitize_text_field($_POST['account_password']) : '';
        $referral = isset($_POST['referral']) ? sanitize_text_field($_POST['referral']) : '';

        if (empty($email) || empty($password)) {
            $this->log_success("Checkout registration not requested.");
            return;
        }

        $this->log_success("Checkout registration validation started.");
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in validate_checkout_registration.');
            wc_add_notice(__('API configuration is missing.', 'mlm-back-office-sync'), 'error');
            return;
        }

        $data = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ];

        if (!empty($referral)) {
            $data['referral'] = $referral;
        }

        $optional_fields = ['name', 'address', 'mobile', 'gender', 'country', 'city', 'zipcode', 'date_of_birth'];
        foreach ($optional_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }

        // $this->log_success("Attempting checkout validation API call with data: " . json_encode($data));
        $response = wp_remote_post($api_url . '/api/wp/validate-user', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error("Checkout validation API call failed: $error_message");
            wc_add_notice(__('Failed to connect to MLM API: ' . $error_message, 'mlm-back-office-sync'), 'error');
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $this->log_success("Checkout validation API response code: $response_code, body: $body");
        $result = json_decode($body, true);

        if ($response_code === 200 && isset($result['status']) && $result['status']) {
            $transient_key = 'mlm_temp_password_' . md5($email);
            set_transient($transient_key, $password, 300);
            $this->log_success("Checkout validation successful, transient set with key: $transient_key");
        } else {
            $this->log_error("Checkout validation API failed: " . print_r($result, true));
            if (isset($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    foreach ($messages as $message) {
                        wc_add_notice($message, 'error');
                    }
                }
            } else {
                wc_add_notice(__('MLM API validation failed: ' . ($result['message'] ?? 'Unknown error'), 'mlm-back-office-sync'), 'error');
            }
        }
    }

    // Sync user registration to MLM API
    public function sync_user_registration($user_id) {
        $this->log_success("User registration sync started for user ID: $user_id");
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        $referral = isset($_POST['referral']) ? sanitize_text_field($_POST['referral']) : 1;

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in sync_user_registration.');
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->log_error("User not found for ID: $user_id");
            return;
        }

        $email = $user->user_email;
        $transient_key = 'mlm_temp_password_' . md5($email);
        $password = get_transient($transient_key);

        // $this->log_success("Attempting to retrieve transient password for user ID: $user_id, email: $email, key: $transient_key");
        if (!$password) {
            $this->log_error("Transient password not found for user ID: $user_id, email: $email");
            return;
        }

        $data = [
            'username' => $user->user_login,
            'email' => $email,
            'password' => $password,
            'wp_user_id' => $user_id,
            'referral' => $referral,
        ];

        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        if ($first_name || $last_name) {
            $data['name'] = trim($first_name . ' ' . $last_name);
        }

        // $this->log_success("Attempting user sync API call for user ID: $user_id with data: " . json_encode($data));
        $response = wp_remote_post($api_url . '/api/wp/register-user', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log_error('User sync failed for ID ' . $user_id . ': ' . $response->get_error_message() . ' Request data: ' . json_encode($data) . ' Response body: ' . wp_remote_retrieve_body($response));
            delete_transient($transient_key);
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($response_code === 200 && isset($result['status']) && $result['status']) {
            $this->log_success("User synced: ID $user_id, Username: " . $user->user_login);
        } else {
            $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
            $this->log_error("User sync error for ID $user_id: HTTP $response_code - $error_message");
        }

        delete_transient($transient_key);
    }

    /**
     * SSO Login Function
     */
    function mlm_sso_login(WP_REST_Request $request) {
        
        $jwt_token = $request->get_param('token');

        $api_url = get_option('mlm_api_base_url');

        $response = wp_remote_get($api_url . '/api/wp/token-verify', [
            'headers' => ['Authorization' => 'Bearer ' . $jwt_token]
        ]);

        if (is_wp_error($response)) {
            wp_die('User Verification Failed!');
        }

        $user_data = json_decode(wp_remote_retrieve_body($response), true);

        if ($user_data && isset($user_data['wp_user_id'])) {

            $wp_id = $user_data['wp_user_id'] ?? null;
            $user = get_user_by('ID', $wp_id);

            if ($user === null) {
                wp_die('User Not Found!');
            }

            $sponsor = $user_data['sponsor'] ?? '';

            // Log in user and redirect
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            
            $redirect_url = home_url('/');
            if (!empty($sponsor)) {
                $redirect_url = home_url('/#') . $sponsor;
            }
            
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            wp_die('User Verification Failed!');
        }
    }

    // Shortcode to handle MLM redirect
    public function mlm_redirect_shortcode($atts) {
        $atts = shortcode_atts([
            'path' => '/wp-register', // e.g., 'dashboard'
            'text' => 'Login to Back Office', // Button text
            'class' => 'mlm-redirect-button', // Additional CSS class
            'error' => 'show', // 'show' or 'hide' error messages
        ], $atts, 'mlm_redirect');

        $path = sanitize_text_field($atts['path']);
        $button_text = sanitize_text_field($atts['text']);
        $custom_class = sanitize_html_class($atts['class']);

        $user_id = get_current_user_id();
        if (!$user_id) {
            return '';
        }

        $token = $this->get_user_token($user_id);
        if (!$token) {
            $this->log_error('Failed to generate token for user ID ' . $user_id . ' in MLM redirect shortcode.');
            return '';
        }

        $frontend_url = get_option('mlm_frontend_url');
        if (empty($frontend_url)) {
            $this->log_error('Frontend URL not configured in MLM redirect shortcode.');
            return '';
        }

        // Construct the redirect URL
        $path = ltrim($path, '/');
        $nonce = wp_create_nonce('mlm_redirect_' . $user_id);
        $redirect_url = trailingslashit($frontend_url) . ($path ? $path . '/' : '') . '?token=' . urlencode($token) . '&nonce=' . urlencode($nonce);

        // Generate the button HTML
        $button_classes = 'mlm-redirect-button ' . $custom_class;
        $button_html = sprintf(
            '<a href="%s" class="%s" aria-label="%s">%s</a>',
            esc_url($redirect_url),
            esc_attr($button_classes),
            esc_attr('Log in to MLM Back Office' . ($path ? ' - ' . $path : '')),
            esc_html($button_text)
        );

        return $button_html;
    }

    // Get user token from API
    private function get_user_token($user_id) {
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in get_user_token.');
            return null;
        }

        $response = wp_remote_get($api_url . '/api/wp/get-token/' . $user_id, [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log_error('Failed to retrieve token for user ID ' . $user_id . ': ' . $response->get_error_message());
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code === 200 && isset($data['token'])) {
            return $data['token'];
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log_error("Token retrieval error for user ID $user_id: HTTP $response_code - $error_message");
            return null;
        }
    }

    public function sync_user_update($user_id, $old_user_data) {
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');
    
        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in sync_user_update.');
            return;
        }
    
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->log_error("User not found during update sync: ID $user_id");
            return;
        }
    
        $response = wp_remote_post($api_url . '/api/wp/update-user', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'wp_user_id' => $user_id,
                'email' => $user->user_email,
                'username' => $user->user_login,
            ]),
            'timeout' => 15,
        ]);
    
        if (is_wp_error($response)) {
            $this->log_error('Failed to sync updated user ID ' . $user_id . ': ' . $response->get_error_message());
        } else {
            $this->log_success("User update synced for ID $user_id.");
        }
    }    

    public function sync_user_deletion($user_id) {
        $api_url = get_option('mlm_api_base_url');
        $api_key = get_option('mlm_api_key');
    
        if (empty($api_url) || empty($api_key)) {
            $this->log_error('API URL or API Key not configured in sync_user_deletion.');
            return;
        }
    
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_error("User not found for deletion sync: ID $user_id");
            return;
        }
    
        $response = wp_remote_post($api_url . '/api/wp/deactivate-user', [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'wp_user_id' => $user_id,
            ]),
            'timeout' => 15,
        ]);
    
        if (is_wp_error($response)) {
            $this->log_error('Failed to deactivate deleted user ID ' . $user_id . ': ' . $response->get_error_message());
        } else {
            $this->log_success("User deletion synced for ID $user_id.");
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
        add_rewrite_rule('^mlm-redirect/(.*)', 'index.php?mlm_redirect=$matches[1]', 'top');
        flush_rewrite_rules();
        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            chmod($this->log_file, 0644);
        }
    }

    // Deactivation hook
    public function deactivate() {
        // Remove rewrite rules
        flush_rewrite_rules();

        // Optionally clear settings or log
        delete_option('mlm_api_base_url');
        delete_option('mlm_api_key');
        delete_option('mlm_frontend_url');

        // Optionally delete the log file
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
        }
    }
}

new MLM_Back_Office_Sync();
?>