<?php
global $ez_pin_class;
$ez_pin_class = ez_pin_class();

// Menu Handler
function ezpin_register_admin_menu()
{

    add_menu_page(
        __('EZ Pin', 'ezpin'),
        __('EZ Pin', 'ezpin'),
        'manage_options',
        'ez-pin',
        'ezpin_admin_page_contents',
        'dashicons-games',
        4
    );
    $ezpin_data = get_option('_ezpin_data');
    if (!empty($ezpin_data)) {
        add_submenu_page('ez-pin', 'ez-pin-data', 'Import Product', 'manage_options', 'ez-pin-data', 'ezpin_admin_page_get_products');
        add_submenu_page('ez-pin', 'ez-pin-orders', 'Orders List', 'manage_options', 'ez-pin-orders', 'ezpin_admin_page_get_orders');
    }
}
add_action('admin_menu', 'ezpin_register_admin_menu');

// Main Login page
function ezpin_admin_page_contents()
{
    global $ez_pin_class;
    $ezpin_data = get_option('_ezpin_data');
?>
    <div class="ezpin-form">
        <?php
        if (isset($_POST['action']) && $_POST['action'] == 'ezpin_option') {
            $access_token = $ez_pin_class->get_access_token($_POST['ezpin_clientId'], $_POST['ezpin_secretKey']);

            if ($access_token["status"] !== true) {
                echo '<div class="error">' . $access_token["errorMessage"] . '</div>';
            } else {
                update_option(
                    '_ezpin_data',
                    array(
                        'ezpin_clientId' => $_POST['ezpin_clientId'],
                        'ezpin_secretKey' => $_POST['ezpin_secretKey'],
                        'ezpin_access_token' => $access_token['accessToken'],
                    )
                );

                $check_user = $ez_pin_class->ezpin_API_remote('', 'GET', $access_token['accessToken']);
                if (is_wp_error($check_user)) {
                    echo '<div class="error">' . $check_user->get_error_message() . '</div>';
                } else {
                    wp_redirect(admin_url('admin.php?page=ez-pin-data'));
                    exit;
                }
            }
        }
        //  if not loged in
        if (empty($ezpin_data)) {
            // Clrear database on logout
            $ez_pin_class->clear_database_on_logout();
        ?>
            <form action="<?= admin_url('admin.php?page=ez-pin'); ?>" method="post" class="ezpin-form">
                <div class="box-block row">
                    <div class="input-block col-12">
                        <label for="email"> <?= __('client id', 'ezpin'); ?> </label>
                        <input type="text" name="ezpin_clientId" id="email" placeholder="client id" value="<?= isset($ezpin_data['ezpin_clientId']) ? $ezpin_data['ezpin_clientId'] : ''; ?>">
                    </div>
                    <div class="input-block col-12">
                        <label for="ezpin_secretKey"> <?= __('secretKey', 'ezpin'); ?> </label>
                        <input type="text" name="ezpin_secretKey" id="ezpin_secretKey" placeholder="ezpin secretKey" value="<?= isset($ezpin_data['ezpin_secretKey']) ? $ezpin_data['ezpin_secretKey'] : ''; ?>">
                    </div>
                    <input type="hidden" name="action" value="ezpin_option">
                    <input type="submit" value="save" class="btn-submit">
                </div>
            </form>
        <?php
        } else {
        ?>
            <div>
                <?php
                $access_token = $ez_pin_class->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);

                if ($access_token["status"] !== true) {
                    echo '<div class="error">' . $access_token["errorMessage"] . '</div>';
                    $ez_pin_class->ezpin_logout();
                } else {
                    $check_balance = $ez_pin_class->ezpin_API_remote('', 'GET', $access_token['accessToken']);
                    // $ez_pin_class->ezpin_logout();
                    // $ez_pin_class->clear_database_on_logout();
                    if (is_wp_error($check_balance)) {
                        echo '<div class="error">' . $check_balance->get_error_message() . '</div>';
                    } else {
                        echo "<div class='row'>";
                        echo "<div class='col col-4 user-data'>";
                        foreach ($check_balance as $item) {
                            $currency = $item['currency']['currency'];
                            $symbol = $item['currency']['symbol'];
                            $code = $item['currency']['code'];
                            $balance = $item['balance'];

                            echo "<div class='title'>Currency: </div>" . $currency . " (" . $symbol . " " . $code . ") <div class='data'>Balance: </div>" . $balance . "<br>";
                        }
                        echo "</div>";
                        echo "</div>";
                    }
                ?>
                    <div class="row">
                        <div class="col col-12 user-data">
                            <div class="title">Client ID : </div>
                            <div class="data"><?= $ezpin_data['ezpin_clientId']; ?></div>
                        </div>
                        <button class="logout" id="ezpin-logout-button"><?php esc_html_e('Logout', 'ezpin'); ?></button>
                    </div>
            </div>
    <?php
                }
            }
    ?>
    </div>
<?php
}


// Get Products
function ezpin_admin_page_get_products()
{
    global $ez_pin_class;
    $ezpin_data = get_option('_ezpin_data');

    // Get access token and product list
    $access_token = $ez_pin_class->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);
    $products_list = $ez_pin_class->ezpin_API_remote('catalogs/', 'GET', $access_token['accessToken']);
    $ez_pin_class->insert_data($products_list);

    // Handle category and pagination
    $selected_category = isset($_POST['product_category']) ? sanitize_text_field($_POST['product_category']) : '';
    $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $per_page = 10; // Set the number of products per page

    // Fetch categories and products from the database
    $db_file = plugin_dir_path(__FILE__) . '../ezpinproducts.db';
    $dbh = new PDO('sqlite:' . $db_file);

    $categories_stmt = $dbh->prepare('SELECT DISTINCT category_name FROM products');
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = $ez_pin_class->fetch_data($selected_category, $page, $per_page);
    $products = $data['results'];
    $total_count = $data['total_count'];
    $total_pages = ceil($total_count / $per_page);

?>
    <div class="ezpin-form">
        <h1 class="title">
            <?php esc_html_e('EZ Pin Import Products', 'ezpin'); ?>
        </h1>

        <form method="post" id="category-form">
            <label for="product_category">Select Category:</label>
            <select name="product_category" id="product_category" onchange="document.getElementById('category-form').submit();">
                <option value="">All Categories</option>
                <?php foreach ($categories as $category) : ?>
                    <option value="<?php echo esc_attr($category['category_name']); ?>" <?php selected($selected_category, $category['category_name']); ?>>
                        <?php echo esc_html($category['category_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <form method="post" id="bulk-select-form">
            <div class="products_show">
                <div class="row">
                    <?php if (!empty($products)) : ?>
                        <?php foreach ($products as $product) : ?>
                            <?php $exists_in_woocommerce = $ez_pin_class->product_exists_in_woocommerce($product['sku']); ?>
                            <div class="col col-4">
                                <div class="single-product <?= (!$exists_in_woocommerce) ? '' : 'out-stock' ?>" data-product-id="<?= $product['sku']; ?>" data-merchant-id="<?= $product['upc']; ?>">
                                    <input type="checkbox" name="selected_products[]" value="<?= $product['sku']; ?>" id="product_checkbox_<?= $product['sku']; ?>" class="product_checkbox" <?= $exists_in_woocommerce ? 'disabled' : ''; ?>>
                                    <label for="product_checkbox_<?= $product['sku']; ?>">
                                        <div class="productImage">
                                            <img src="<?= $product['image']; ?>" alt="<?= $product['title']; ?>" width="200" height="150">
                                        </div>
                                        <div class="product_data">
                                            <h4 class="productName"> <?= $product['title']; ?> </h4>
                                            <h4 class="sku"> SKU : <?= $product['sku']; ?> </h4>
                                            <div class="productPrice"> Price : <span><?= $product['min_price']; ?></span> <?= $product['currency']; ?> </div>
                                            <div class="productPrice recommended"> Max Price : <span><?= $product['max_price']; ?></span> <?= $product['currency']; ?> </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>No products found in this category.</p>
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" name="export_to_woocommerce">Export to WooCommerce</button>
        </form>
        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
            <div class="pagination">
                <?php
                $current_url = esc_url(add_query_arg(['paged' => false], $_SERVER['REQUEST_URI']));
                for ($i = 1; $i <= $total_pages; $i++) {
                    $page_url = esc_url(add_query_arg(['paged' => $i], $current_url));
                    $active_class = $i == $page ? ' class="active"' : '';
                    echo '<a href="' . $page_url . '"' . $active_class . '>' . $i . '</a> ';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php

    // Handle form submission for exporting products to WooCommerce
    if (isset($_POST['export_to_woocommerce']) && isset($_POST['selected_products'])) {
        $selected_products = $_POST['selected_products'];
        $successfully_exported = 0;

        foreach ($selected_products as $sku) {
            if (!$ez_pin_class->product_exists_in_woocommerce($sku)) {
                $product = $ez_pin_class->get_product_by_sku($sku);
                $ez_pin_class->import_product_to_woocommerce($product);
                $successfully_exported++;
            }
        }

        if ($successfully_exported > 0) {
            echo '<script>alert("Selected products have been successfully exported to WooCommerce.");</script>';
            echo '<script>window.location.href=window.location.href;</script>';
        } else {
            echo '<script>alert("No new products were exported. They might already exist in WooCommerce.");</script>';
        }
    } else {
        if (isset($_POST['export_to_woocommerce'])) {
            echo '<script>alert("Please select at least one product.");</script>';
        }
    }
}

// Get Orders
function ezpin_admin_page_get_orders()
{
    ?>
    <!-- Order Form -->
    <div class="wrap">
        <h1>EZPin Orders</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Start Date</th>
                    <td><input type="date" name="ezpin_start_date" value="<?php echo isset($_POST['ezpin_start_date']) ? esc_attr($_POST['ezpin_start_date']) : ''; ?>" required></td>
                </tr>
                <tr valign="top">
                    <th scope="row">End Date</th>
                    <td><input type="date" name="ezpin_end_date" value="<?php echo isset($_POST['ezpin_end_date']) ? esc_attr($_POST['ezpin_end_date']) : ''; ?>" required></td>
                </tr>
            </table>
            <?php submit_button('Get Orders'); ?>
        </form>
        <?php

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ezpin_start_date']) && isset($_POST['ezpin_end_date'])) {
            $start_date = sanitize_text_field($_POST['ezpin_start_date']);
            $end_date = sanitize_text_field($_POST['ezpin_end_date']);

            global $ez_pin_class;
            $ezpin_data = get_option('_ezpin_data');

            // Get access token and product list
            $access_token = $ez_pin_class->get_access_token($ezpin_data['ezpin_clientId'], $ezpin_data['ezpin_secretKey']);
            $bodyArr = array(
                'limit' => 10,
                'offset' => 0,
                'start_date' => $start_date,
                'end_date' => $end_date,
            );

            $all_orders = array();
            $has_more_pages = true;

            while ($has_more_pages) {
                $orders = $ez_pin_class->ezpin_API_remote('orders/', 'GET', $access_token['accessToken'], $bodyArr);

                if (isset($orders['results']) && is_array($orders['results'])) {
                    // Merge the current page results into the all_orders array
                    $all_orders = array_merge($all_orders, $orders['results']);
                }

                // Check if there is a next page
                if (isset($orders['next']) && !empty($orders['next'])) {
                    // Extract the offset from the next URL
                    $next_url = parse_url($orders['next']);
                    parse_str($next_url['query'], $query_params);
                    $bodyArr['offset'] = $query_params['offset'];
                } else {
                    $has_more_pages = false;
                }
            }

            // Display all orders
            if (!empty($all_orders)) {
                echo '<h2>Orders from ' . esc_html($start_date) . ' to ' . esc_html($end_date) . '</h2>';
                echo '<table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Product</th>
                            <th>Face Value</th>
                            <th>Total Customer Cost</th>
                            <th>Share Link</th>
                            <th>Status</th>
                            <th>Completed</th>
                            <th>Created Time</th>
                        </tr>
                    </thead>
                    <tbody>';

                foreach ($all_orders as $order) {
                    echo '<tr>
                        <td>' . esc_html($order['order_id']) . '</td>
                        <td>' . esc_html($order['product']['title']) . '</td>
                        <td>' . esc_html($order['face_value']) . '</td>
                        <td>' . esc_html($order['total_customer_cost']) . '</td>
                        <td>' . esc_html($order['share_link']) . '</td>
                        <td>' . esc_html($order['status_text']) . '</td>
                        <td>' . esc_html(
                            ($order['is_completed'] == 1) ? 'Yes' : 'No'
                         ) . '</td>
                        <td>' . esc_html($order['created_time']) . '</td>
                      </tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p>No orders found for the selected date range.</p>';
            }
        }
        ?>
    </div>
<?php
}



function ezpin_register_my_plugin_scripts($hook)
{
    wp_enqueue_style('ezpin-admin-style', plugins_url('../css/ezpin-admin.css', __FILE__));
    // wp_enqueue_script('pagination-js-file', 'https://pagination.js.org/dist/2.1.5/pagination.min.js', '', time());
    wp_register_script('ezpin-admin-script', plugins_url('../js/ezpin-admin.js', __FILE__), array('jquery'));
    // wp_enqueue_style('ezpin-admin-style');

    wp_enqueue_script('ezpin-admin-script');

    wp_localize_script('ezpin-admin-script', 'ezpin_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ezpin_logout_nonce')
    ]);
}

add_action('admin_enqueue_scripts', 'ezpin_register_my_plugin_scripts');
