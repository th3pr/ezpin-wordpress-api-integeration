<?php
/*
* Plugin Name: EZ pin API
* Description: You can now use electronic cards directly
* Author: Mohamed Ahmed Bahnsawy
* Version: 2.0.0
* Author URI: https://www.linkedin.com/in/bahnsawy
* GitHub Plugin URI: https://github.com/th3pr/ezpin-wordpress-api-integeration
* Text Domain: ezpin
*/


if (!defined('ABSPATH')) {
    die('Invalid request.');
}

define('ezpin_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
include_once(ezpin_PLUGIN_DIR_PATH . 'init/register.php');

// Create SQLite Database Method
function create_products_table()
{
    global $wpdb;

    $db_file = plugin_dir_path(__FILE__) . 'ezpinproducts.db';
    $dbh = new PDO("sqlite:$db_file");

    $query = "
    CREATE TABLE IF NOT EXISTS products (
        sku INTEGER PRIMARY KEY,
        upc INTEGER,
        upc_string TEXT,
        title TEXT,
        min_price REAL,
        max_price REAL,
        pre_order BOOLEAN,
        activation_fee REAL,
        showing_price REAL,
        percentage_of_buying_price REAL,
        currency TEXT,
        symbol TEXT,
        code TEXT,
        category_name TEXT,
        region_name TEXT,
        region_code TEXT,
        image TEXT,
        description TEXT,
        reward_type INTEGER,
        reward_type_text TEXT
    )";

    $dbh->exec($query);
}
// Create the database After plugin activated
register_activation_hook(__FILE__, 'create_products_table');


class EZPIN
{

    function __construct()
    {
        // Load language file
        load_plugin_textdomain('ezpin', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // logout
        add_action('wp_ajax_ezpin_logout', [$this, 'ezpin_logout']);
        add_action('wp_ajax_nopriv_ezpin_logout', [$this, 'ezpin_logout']);
        // check if the product is ezpin product before the checkout form
        add_action('woocommerce_before_checkout_form', [$this, 'ez_pin_before_checkout_form_action']);

        add_action('woocommerce_payment_complete', [$this, 'ezpin_new_order']);

        add_action('woocommerce_thankyou', [$this, 'ezpin_thankyou_page']);

        // Add custom interval for the cron job
        add_filter('cron_schedules', array($this, 'ezpin_custom_cron_schedule'));

        // Add the action hook for the cron event
        add_action('ezpin_cron_event', array($this, 'ezpin_cron_event_callback'));

        // Schedule the cron event if it's not already scheduled
        if (!wp_next_scheduled('ezpin_cron_event')) {
            wp_schedule_event(time(), 'every_hour', 'ezpin_cron_event');
        }

        // Deactivation hook to clear the cron event
        register_deactivation_hook(__FILE__, array('EZPIN', 'ezpin_deactivate'));
    }

    // Main Method to handle the API Request not POST I need TO change the wp_remote_request to wp_remote_post or create new Method
    public function ezpin_API_remote($url = '', $method, $access_token, $body = [])
    {
        $ezpin_data = get_option('_ezpin_data', []);
        $api_url = 'https://api.ezpaypin.com/vendors/v2/';
        $url = empty($url) ? $api_url . 'balance/' : $api_url . $url;

        $response = wp_remote_request($url, array(
            'method'      => $method,
            'timeout'     => 30,
            'headers'     => array(
                'Content-Type' => 'application/json',
                "Authorization" => $access_token,
            ),
            'body'        => $body,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            // Handle error
            $error_message = $response->get_error_message();
            echo '<div class="error">' . esc_html($error_message) . '</div>';
            return false;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            // var_dump($ezpin_data);

            if ($response_code != 200) {
                $error_message = isset($response_body['message']) ? $response_body['message'] : 'Unknown error';
                echo '<div class="error">' . esc_html("Error $response_code: $error_message") . '</div>';
                return false;
            }
            return $response_body;
        }
    }


    // Generate Access token
    function get_access_token($client_id, $secret_key)
    {
        $api = 'https://api.ezpaypin.com/vendors/v2/auth/token/';
        $response = wp_remote_post($api, array(
            'body' => json_encode(array(
                'client_id' => $client_id,
                'secret_key' => $secret_key,
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));

        if (empty($client_id) || empty($secret_key)) {
            return array('status' => false, 'errorMessage' => 'Client ID and Secret Key are required.');
        }

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            return array('status' => false, 'errorMessage' => $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code != 200) {
            $error_message = isset($response_body['message']) ? $response_body['message'] : 'Please Check Your Credentials';
            return array('status' => false, 'errorMessage' => "Error $response_code: $error_message");
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('status' => false, 'errorMessage' => 'Invalid JSON response');
        }

        if (isset($response_body['error'])) {
            return array('status' => false, 'errorMessage' => $response_body['error']);
        }

        if (!isset($response_body['access'])) {
            return array('status' => false, 'errorMessage' => 'Access token not found in the response');
        }

        $access_token = $response_body['access'];

        // Ensure the option is always an array
        $ezpin_data = get_option('_ezpin_data', array());
        if (!is_array($ezpin_data)) {
            $ezpin_data = array();
        }

        $ezpin_data['ezpin_access_token'] = $access_token;
        update_option('_ezpin_data', $ezpin_data);

        return array('status' => true, 'accessToken' => "Bearer $access_token");
    }

    // Logout
    public function ezpin_logout()
    {
        check_ajax_referer('ezpin_logout_nonce', '_ajax_nonce');

        update_option('_ezpin_data', '');
        wp_send_json(['state' => 'LogOut']);
    }

    // Insert Data To SQLite Database
    function insert_data($data)
    {
        $db_file = plugin_dir_path(__FILE__) . 'ezpinproducts.db';
        $dbh = new PDO('sqlite:' . $db_file);

        foreach ($data['results'] as $result) {
            $query = 'INSERT OR REPLACE INTO products (
                sku, upc, upc_string, title, min_price, max_price, pre_order, activation_fee, showing_price,
                percentage_of_buying_price, currency, symbol, code, category_name, region_name, region_code,
                image, description, reward_type, reward_type_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            $stmt = $dbh->prepare($query);
            $stmt->execute([
                $result['sku'], $result['upc'], $result['upc_string'], $result['title'], $result['min_price'], $result['max_price'],
                $result['pre_order'], $result['activation_fee'], $result['showing_price'], $result['percentage_of_buying_price'],
                $result['currency']['currency'], $result['currency']['symbol'], $result['currency']['code'],
                $result['categories'][0]['name'], $result['regions'][0]['name'], $result['regions'][0]['code'],
                $result['image'], $result['description'], $result['reward_type'], $result['reward_type_text']
            ]);
        }
    }


    // Fetch Data from SQLite Database With Pagination and Categories Selection
    function fetch_data($category = null, $page = 1, $per_page = 10)
    {
        $db_file = plugin_dir_path(__FILE__) . 'ezpinproducts.db';
        $dbh = new PDO('sqlite:' . $db_file);

        $offset = ($page - 1) * $per_page;

        if ($category) {
            $stmt = $dbh->prepare('SELECT * FROM products WHERE category_name = :category_name LIMIT :limit OFFSET :offset');
            $stmt->bindParam(':category_name', $category, PDO::PARAM_STR);
        } else {
            $stmt = $dbh->prepare('SELECT * FROM products LIMIT :limit OFFSET :offset');
        }
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch total count for pagination
        if ($category) {
            $count_stmt = $dbh->prepare('SELECT COUNT(*) FROM products WHERE category_name = :category_name');
            $count_stmt->bindParam(':category_name', $category, PDO::PARAM_STR);
        } else {
            $count_stmt = $dbh->prepare('SELECT COUNT(*) FROM products');
        }
        $count_stmt->execute();
        $total_count = $count_stmt->fetchColumn();

        return ['results' => $results, 'total_count' => $total_count];
    }


    // Clear SQLite Database
    function clear_database_on_logout()
    {
        $db_file = plugin_dir_path(__FILE__) . 'ezpinproducts.db';
        $dbh = new PDO('sqlite:' . $db_file);

        $query = "DELETE FROM products";
        $dbh->exec($query);
    }

    // Cehck if Product exsist in woocommerce 
    function product_exists_in_woocommerce($sku)
    {
        global $wpdb;
        $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value=%s", $sku));
        return $product_id ? true : false;
    }

    // Import Product To WooCommerce 
    function import_product_to_woocommerce($product)
    {
        // Fetch category ID by name
        $category_id = $this->get_category_id_by_name($product['category_name']);

        $new_product = new WC_Product();
        $new_product->set_name($product['title']);
        $new_product->set_sku($product['sku']);
        $new_product->set_price($product['min_price']);
        $new_product->set_regular_price($product['max_price']);
        $new_product->set_description($product['description']);
        $new_product->set_category_ids([$category_id]);
        $new_product->set_sold_individually(true);
        $new_product->set_virtual(true);

        // Import and set the product image
        $image_id = $this->get_image_id_from_url($product['image']);
        if ($image_id) {
            $new_product->set_image_id($image_id);
        } else {
            error_log("Failed to import image for product SKU: " . $product['sku']);
        }

        $new_product->update_meta_data('_is_ezpin_product', 'yes');
        $new_product->save();
    }

    // fetch category ID by name
    function get_category_id_by_name($category_name)
    {
        $category = get_term_by('name', $category_name, 'product_cat');
        if ($category) {
            return $category->term_id;
        } else {
            // Optionally, create the category if it does not exist
            $new_category = wp_insert_term($category_name, 'product_cat');
            if (!is_wp_error($new_category)) {
                return $new_category['term_id'];
            }
        }
        return 0; // Return a default value if no category is found or created
    }


    // Import Product Image to Woocommerce
    function get_image_id_from_url($image_url)
    {
        $upload_dir = wp_upload_dir();
        $image_data = @file_get_contents($image_url);

        if ($image_data === false) {
            error_log("Failed to fetch image data from URL: " . $image_url);
            return 0; // Return a default value if image data is not fetched
        }

        $filename = basename($image_url);
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file);
        if (is_wp_error($attach_id)) {
            error_log("Failed to insert attachment for image: " . $filename);
            return 0; // Return a default value if attachment creation fails
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }


    // Method to get product by SKU From The SQLite Database
    public function get_product_by_sku($sku)
    {
        $db_file = plugin_dir_path(__FILE__) . 'ezpinproducts.db';
        $dbh = new PDO('sqlite:' . $db_file);

        // Query to fetch product details by SKU
        $stmt = $dbh->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();

        // Fetch the product data
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        return $product;
    }

    // Generate UUID V4
    function generate_uuid_v4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    // Check if the product is belong to ezpin before checkout process 
    function ez_pin_before_checkout_form_action($checkout)
    {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = wc_get_product($product_id);

            $is_ezpin_product = $product->get_meta('_is_ezpin_product');

            if ($is_ezpin_product === 'yes') {
                $ezpin_data = get_option('_ezpin_data');
                $ezpinSKU = $product->get_sku();

                $access_token = $this->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);
                $bodyArr = [
                    'product_sku' => $ezpinSKU,
                    'item_count' => $cart_item['quantity'],
                    'price' => $product->get_price(),
                ];

                $availability = $this->ezpin_API_remote("/catalogs/$ezpinSKU/availability/", 'GET', $access_token['accessToken'], $bodyArr);
                if (isset($availability['availability']) && !$availability['availability']) {
                    $product->set_stock_status('outofstock');
                    $product->save();

                    WC()->cart->remove_cart_item($cart_item_key);
                    wc_add_notice("Product with SKU {$ezpinSKU} is out of stock and has been removed from your cart.", 'error');
                    continue; // Skip balance check if the product is out of stock
                }

                // Check Balance
                $balanceCheck = $this->ezpin_API_remote('', 'GET', $access_token['accessToken']);

                // Get user's balance
                $user_balance = 0;
                foreach ($balanceCheck as $balanceInfo) {
                    if ($balanceInfo['currency']['code'] === 'USD') {
                        $user_balance = $balanceInfo['balance'];
                        break;
                    }
                }

                if ($product->get_price() > $user_balance) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    wc_add_notice("Balance error. Product with SKU {$ezpinSKU} has been removed from your cart. Please contact the admin.", 'error');
                }
            }
        }
    }


    // Create the order on the API when the payment is completed
    public function ezpin_new_order($order_id)
    {
        // $order = wc_get_order($order_id);

        // // Check if the order is paid or completed
        // if ($order->get_date_paid()) {
        //     foreach ($order->get_items() as $item_id => $item) {
        //         $ezpin_order_id = wc_get_order_item_meta($item_id, '_ezpin_order_id', true);
        //         $ezpin_share_link = wc_get_order_item_meta($item_id, '_ezpin_share_link', true);
        //         $ezpin_reference_code = wc_get_order_item_meta($item_id, '_ezpin_reference_code', true);
        //         $ezpin_pin_code = wc_get_order_item_meta($item_id, '_ezpin_pin_code', true);
        //         $ezpin_card_number = wc_get_order_item_meta($item_id, '_ezpin_card_number', true);

        //         if ($ezpin_order_id && $ezpin_share_link && $ezpin_pin_code && $ezpin_card_number) {
        //             // echo '<div class="ezpin-order-details">';
        //             // echo '<p>Order ID: ' . esc_html($ezpin_order_id) . '</p><br>';
        //             // echo '<p>Reference Code: ' . esc_html($ezpin_reference_code) . '</p>';
        //             echo '<p>Pin Code: ' . esc_html($ezpin_pin_code) . '</p><br>';
        //             echo '<p>Card Number: ' . esc_html($ezpin_card_number) . '</p>';
        //             // echo '<iframe src="' . esc_url($ezpin_share_link) . '" frameborder="0" width="100%" height="400"></iframe>';
        //             // echo '</div>';
        //         } else {
        //             $product_id = $item->get_product_id();
        //             $product = wc_get_product($product_id);

        //             $is_ezpin_product = $product->get_meta('_is_ezpin_product');

        //             if ($is_ezpin_product === 'yes') {
        //                 $ezpin_data = get_option('_ezpin_data');
        //                 $ezpinSKU = $product->get_sku();
        //                 $quantity = $item->get_quantity();

        //                 $access_token = $this->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);

        //                 // Generate a unique UUID v4 reference code
        //                 $reference_code = $this->generate_uuid_v4();

        //                 $bodyArr = [
        //                     'sku'             => $ezpinSKU,
        //                     'quantity'        => $quantity,
        //                     'pre_order'       => false,
        //                     'price'           => $product->get_price(),
        //                     'delivery_type'   => 0, // 0-None 1-E-mail 2-SMS 3-WhatsApp
        //                     'reference_code'  => $reference_code
        //                 ];

        //                 $order_create = $this->ezpin_create_order($access_token['accessToken'], json_encode($bodyArr));

        //                 if ($order_create === false) {
        //                     wc_add_notice('There was an issue processing your EZPin product. Please contact support.', 'error');
        //                 } else {
        //                     // Retrieve the pin code and card number after the order is created
        //                     $getPinCode = $this->ezpin_API_remote("/orders/$reference_code/cards/", 'GET', $access_token['accessToken']);
        //                     if (isset($getPinCode['results'][0])) {
        //                         $card_data = $getPinCode['results'][0];
        //                         $pin_code = $card_data['pin_code'];
        //                         $card_number = $card_data['card_number'];

        //                         // Handle EZPin response for each order item, passing the reference code, pin code, and card number
        //                         $this->handle_ezpin_response_for_item($order, $order_create, $item_id, $reference_code, $pin_code, $card_number);
        //                         wc_add_notice('Your order has been processed successfully.', 'success');
        //                     } else {
        //                         wc_add_notice('Failed to retrieve card details. Please contact support.', 'error');
        //                     }
        //                 }
        //             }
        //         }
        //     }
        // }
    }


    // Create Order
    public function ezpin_create_order($access_token, $body)
    {
        $api_url = 'https://api.ezpaypin.com/vendors/v2/orders/';

        $response = wp_remote_post($api_url, array(
            'method'      => 'POST',
            'timeout'     => 30,
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => $access_token,
            ),
            'body'        => $body,
        ));

        echo "<pre>";
        var_dump($body);
        echo "<br>";
        var_dump($response);
        echo "</pre>";


        $response_code = wp_remote_retrieve_response_code($response);

        $response_body = wp_remote_retrieve_body($response);
        $response_decode = json_decode($response_body);

        if ($response_code != 200) {
            $error_message = isset($response_data['detail']) ? $response_data['detail'] : 'Unknown error';
            echo "Error During Creating the order, Please Contact the admin. ref $error_message : $response_code";
            error_log("EZPin API Error $response_code: $error_message");
            return false;
        } else {          
            $response_data = json_decode($response_body, true);
            return $response_data;
        }
    }



    // Response Handling
    public function handle_ezpin_response_for_item($order, $response, $item_id, $reference_code, $pin_code, $card_number)
    {
        if (isset($response['status']) && $response['status'] == 1) {
            // Order was accepted
            $order_id = $response['order_id'];
            $delivery_type = $response['delivery_type'];
            $status_text = $response['status_text'];
            $share_link = $response['share_link'];

            error_log("EZPin Order ID: $order_id created successfully. Status: $status_text. Share link: $share_link");

            // Update metadata for each order item
            wc_update_order_item_meta($item_id, '_ezpin_order_id', $order_id);
            wc_update_order_item_meta($item_id, '_ezpin_delivery_type', $delivery_type);
            wc_update_order_item_meta($item_id, '_ezpin_status', $status_text);
            wc_update_order_item_meta($item_id, '_ezpin_share_link', $share_link);
            wc_update_order_item_meta($item_id, '_ezpin_reference_code', $reference_code);
            wc_update_order_item_meta($item_id, '_ezpin_pin_code', $pin_code);
            wc_update_order_item_meta($item_id, '_ezpin_card_number', $card_number);

            $order->save();
        } else {
            // Order was not accepted
            $error_message = isset($response['status_text']) ? $response['status_text'] : 'Unknown error';
            error_log("EZPin Order Error: $error_message");
            wc_add_notice("There was an issue processing your EZPin product. Error: $error_message", 'error');
        }
    }


    // Thank you page and show the share link
    public function ezpin_thankyou_page($order_id)
    {
        // $this->ezpin_new_order($order_id);
        $order = wc_get_order($order_id);

        // Check if the order is paid or completed
        if ($order->get_date_paid()) {
            foreach ($order->get_items() as $item_id => $item) {
                $ezpin_order_id = wc_get_order_item_meta($item_id, '_ezpin_order_id', true);
                $ezpin_share_link = wc_get_order_item_meta($item_id, '_ezpin_share_link', true);
                $ezpin_reference_code = wc_get_order_item_meta($item_id, '_ezpin_reference_code', true);
                $ezpin_pin_code = wc_get_order_item_meta($item_id, '_ezpin_pin_code', true);
                $ezpin_card_number = wc_get_order_item_meta($item_id, '_ezpin_card_number', true);

                if ($ezpin_order_id && $ezpin_share_link && $ezpin_pin_code && $ezpin_card_number) {
                    // echo '<div class="ezpin-order-details">';
                    // echo '<p>Order ID: ' . esc_html($ezpin_order_id) . '</p><br>';
                    // echo '<p>Reference Code: ' . esc_html($ezpin_reference_code) . '</p>';
                    echo '<p>Pin Code: ' . esc_html($ezpin_pin_code) . '</p><br>';
                    echo '<p>Card Number: ' . esc_html($ezpin_card_number) . '</p>';
                    // echo '<iframe src="' . esc_url($ezpin_share_link) . '" frameborder="0" width="100%" height="400"></iframe>';
                    // echo '</div>';
                } else {
                    $product_id = $item->get_product_id();
                    $product = wc_get_product($product_id);

                    $is_ezpin_product = $product->get_meta('_is_ezpin_product');

                    if ($is_ezpin_product === 'yes') {
                        $ezpin_data = get_option('_ezpin_data');
                        $ezpinSKU = $product->get_sku();
                        $quantity = $item->get_quantity();

                        $access_token = $this->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);

                        // Generate a unique UUID v4 reference code
                        $reference_code = $this->generate_uuid_v4();

                        $bodyArr = [
                            'sku'             => $ezpinSKU,
                            'quantity'        => $quantity,
                            'pre_order'       => false,
                            'price'           => $product->get_price(),
                            'delivery_type'   => 0, // 0-None 1-E-mail 2-SMS 3-WhatsApp
                            'reference_code'  => $reference_code
                        ];

                        $order_create = $this->ezpin_create_order($access_token['accessToken'], json_encode($bodyArr));

                        if ($order_create === false) {
                            wc_add_notice('There was an issue processing your EZPin product. Please contact support.', 'error');
                        } else {
                            // Retrieve the pin code and card number after the order is created
                            $getPinCode = $this->ezpin_API_remote("/orders/$reference_code/cards/", 'GET', $access_token['accessToken']);
                            if (isset($getPinCode['results'][0])) {
                                $card_data = $getPinCode['results'][0];
                                $pin_code = $card_data['pin_code'];
                                $card_number = $card_data['card_number'];

                                // Handle EZPin response for each order item, passing the reference code, pin code, and card number
                                $this->handle_ezpin_response_for_item($order, $order_create, $item_id, $reference_code, $pin_code, $card_number);
                                wc_add_notice('Your order has been processed successfully.', 'success');
                            } else {
                                wc_add_notice('Failed to retrieve card details. Please contact support.', 'error');
                            }
                        }
                    }
                }
            }
        }

    }


    // Custom cron schedule
    public function ezpin_custom_cron_schedule($schedules)
    {
        $schedules['every_hour'] = array(
            'interval' => 3600,
            'display' => __('Every Hour')
        );
        return $schedules;
    }

    // Cron event callback
    public function ezpin_cron_event_callback()
    {
        $ezpin_data = get_option('_ezpin_data');

        if (!$ezpin_data || !isset($ezpin_data['ezpin_clientId']) || !isset($ezpin_data['ezpin_secretKey'])) {
            error_log('EZPin data not set in options');
            return;
        }

        $access_token = $this->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);

        if (!$access_token || !isset($access_token['accessToken'])) {
            error_log('Failed to retrieve access token from EZPin API');
            return;
        }

        $products_list = $this->ezpin_API_remote('catalogs/', 'GET', $access_token['accessToken']);

        if (!$products_list) {
            error_log('Failed to retrieve product list from EZPin API');
            return;
        }

        $this->insert_data($products_list);
    }

    // Deactivation hook to clear the cron event
    public static function ezpin_deactivate()
    {
        $timestamp = wp_next_scheduled('ezpin_cron_event');
        wp_unschedule_event($timestamp, 'ezpin_cron_event');
    }
}
// End Of Class


// instance Creation Method
function ez_pin_class()
{
    global $ez_pin_class;
    $ez_pin_class = new EZPIN;
    return $ez_pin_class;
}
add_action('admin_init', 'ez_pin_class');


// Disable COD, Cheques and BACS payment methods
function ez_pin_filter_gateways($gateways)
{
    unset($gateways['cod']); // Unset the 'Cash on Delivery' payment method
    unset($gateways['cheque']); // Unset the 'Check Payments' payment method
    unset($gateways['bacs']); // Unset the 'حوالة مصرفية مباشرة' payment method

    return $gateways;
}
add_filter('woocommerce_available_payment_gateways', 'ez_pin_filter_gateways');
