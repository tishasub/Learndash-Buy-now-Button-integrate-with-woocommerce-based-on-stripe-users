<?php
/**
 * LearnDash Buy Now Button Shortcode
 */

// Register the shortcode
add_shortcode('learndash_buy_button', 'learndash_buy_button_shortcode');

function learndash_buy_button_shortcode($atts) {
    // Get shortcode attributes
    $atts = shortcode_atts(array(
        'course_id' => get_the_ID(), // Default to current course
        'button_text' => 'Buy Now',
        'show_price' => 'yes',
        'class' => '',
        'style' => 'default' // default, plain, or minimal
    ), $atts);

    // If not a course, return empty
    if (!$atts['course_id']) {
        return '';
    }

    // Get course settings
    $course_settings = get_post_meta($atts['course_id'], '_sfwd-courses', true);

    // Get the custom button URL
    if(isset($course_settings['sfwd-courses_custom_button_url']) && $course_settings['sfwd-courses_custom_button_url'] !== '') {
        $button_url = $course_settings['sfwd-courses_custom_button_url'];
    } else {
        $button_url = '';
    }

    if (empty($button_url)) {
        return '';
    }

    // Extract product ID and create checkout URL
    $product_id = extract_product_id_from_url($button_url);
    if (!$product_id) {
        return '';
    }

    // Create direct checkout URL
    $checkout_url = add_query_arg(
        array(
            'add-to-cart' => $product_id,
            'quantity' => 1,
        ),
        wc_get_checkout_url()
    );

    // Get product info
    $product = wc_get_product($product_id);
    if (!$product) {
        return '';
    }

    // Build CSS classes based on style attribute
    $button_classes = array('ld-buy-now-button');
    $wrapper_classes = array('ld-buy-now-wrapper');

    if (!empty($atts['class'])) {
        $button_classes[] = $atts['class'];
    }

    switch ($atts['style']) {
        case 'plain':
            $button_classes[] = 'plain-style';
            break;
        case 'minimal':
            $button_classes[] = 'minimal-style';
            break;
        default:
            $button_classes[] = 'default-style';
    }

    // Start building output
    $output = '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';

    // Add price if enabled
    if ($atts['show_price'] === 'yes') {
        $output .= '<div class="ld-buy-now-price">' . wc_price($product->get_price()) . '</div>';
    }

    $user_id = get_current_user_id();
    $course_id = (int) $atts['course_id'];

    if (is_user_logged_in() && function_exists('sfwd_lms_has_access')) {
        if (sfwd_lms_has_access($course_id, $user_id)) {
            return '<h4 class="text-center">You are already enrolled in this course.</h4>'; // User is enrolled — hide the buy button
        }
    }

    // Add button
    $output .= '<button type="button" id="instant-buy-now" class="button alt ' . esc_attr(implode(' ', $button_classes)) . '">' . esc_html($atts['button_text']) . '</button>';
    $output .= '<input type="hidden" name="product_id" id="product_id" value="' . esc_attr($product_id) . '">';

    // Add error modal HTML
    $output .= '
    <div id="payment-error-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:20px; border-radius:5px; width:90%; max-width:400px; box-shadow:0 4px 8px rgba(0,0,0,0.1); border-top:5px solid #e74c3c;">
            <h3 style="margin-top:0; color:#e74c3c;">Payment Failed</h3>
            <p id="error-message" style="margin-bottom:20px;">Payment Failed. Please check your card and return to the checkout page to try again.</p>
            <div style="text-align:center; margin-top:20px;">
                <p>Redirecting to checkout...</p>
                <div style="display:inline-block; width:30px; height:30px; border:3px solid rgba(19,118,197,0.3); border-radius:50%; border-top-color:#1376c5; animation:ldbnb-spin 1s ease-in-out infinite; margin:10px auto;"></div>
            </div>
        </div>
    </div>
    <style>
        @keyframes ldbnb-spin {
            to { transform: rotate(360deg); }
        }
    </style>
    ';

    // Add JavaScript
    $output .= '
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $("#instant-buy-now").on("click", function() {
                let productId = $("#product_id").val(); // Get the hidden input value

                if (!productId) {
                    alert("Product ID is missing!");
                    return;
                }

                $.ajax({
                    type: "POST",
                    url: "' . admin_url("admin-ajax.php") . '",
                    data: {
                        action: "instant_buy_now",
                        product_id: productId
                    },
                    beforeSend: function() {
                        $("#instant-buy-now").text("Processing...");
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.redirect;
                        } else {
                            // Show error modal
                            if (response.message) {
                                $("#error-message").text(response.message);
                            }
                            $("#payment-error-modal").fadeIn(300);

                            // Reset button text
                            $("#instant-buy-now").text("' . esc_html($atts['button_text']) . '");

                            // Redirect to checkout after 3 seconds
                            setTimeout(function() {
                                window.location.href = "' . esc_js($checkout_url) . '";
                            }, 3000);
                        }
                    },
                    error: function() {
                        alert("An error occurred. Please try again.");
                        $("#instant-buy-now").text("' . esc_html($atts['button_text']) . '");
                    }
                });
            });
        });
    </script>';

    $output .= '</div>';

    // Add the CSS
    add_action('wp_footer', 'add_learndash_buy_button_styles');

    return $output;
}

/**
 * Extract product ID from WooCommerce URL
 */
function extract_product_id_from_url($url) {
    // Parse the URL
    $parsed_url = parse_url($url);

    if (!isset($parsed_url['path'])) {
        return false;
    }

    // Get the path and remove trailing slash
    $path = rtrim($parsed_url['path'], '/');

    // Try to get product ID from query string if it exists
    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_vars);
        if (isset($query_vars['product_id'])) {
            return $query_vars['product_id'];
        }
        if (isset($query_vars['add-to-cart'])) {
            return $query_vars['add-to-cart'];
        }
    }

    // Try to get product ID from the slug
    $slug = basename($path);

    // First try to get product by slug
    $product = get_page_by_path($slug, OBJECT, 'product');
    if ($product) {
        return $product->ID;
    }

    // If slug is numeric, it might be the ID
    if (is_numeric($slug)) {
        return $slug;
    }

    // If we still don't have an ID, try to find the product by its slug
    $products = get_posts(array(
        'post_type' => 'product',
        'name' => $slug,
        'posts_per_page' => 1,
        'post_status' => 'publish'
    ));

    if (!empty($products)) {
        return $products[0]->ID;
    }

    return false;
}

// Add styles
function add_learndash_buy_button_styles() {
    ?>
    <style>
        .ld-buy-now-wrapper {
            margin: 20px 0;
            text-align: center;
        }
        .ld-buy-now-price {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }
        .ld-buy-now-button {
            display: inline-block;
            text-decoration: none;
            margin: 5px 0;
            transition: all 0.3s ease;
        }
        .ld-buy-now-wrapper button#instant-buy-now {
            width: 100%;
            font-size: 20px;
        }
        /* Default style */
        .ld-buy-now-button.default-style {
            background-color: #1376c5;
            color: white !important;
            padding: 15px 30px;
            border-radius: 4px;
            font-weight: bold;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
        }
        .ld-buy-now-button.default-style:hover {
            background-color: #1976D2;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        /* Plain style */
        .ld-buy-now-button.plain-style {
            background-color: #f8f9fa;
            color: #2196F3 !important;
            padding: 12px 24px;
            border: 2px solid #2196F3;
            border-radius: 4px;
        }
        .ld-buy-now-button.plain-style:hover {
            background-color: #2196F3;
            color: white !important;
        }
        /* Minimal style */
        .ld-buy-now-button.minimal-style {
            color: #2196F3 !important;
            padding: 10px 20px;
            border-bottom: 2px solid transparent;
        }
        .ld-buy-now-button.minimal-style:hover {
            border-bottom-color: #2196F3;
        }
    </style>
    <?php
}

add_action('wp_ajax_instant_buy_now', 'process_instant_buy_now');
add_action('wp_ajax_nopriv_instant_buy_now', 'process_instant_buy_now');

function process_instant_buy_now() {
    try {
        if (!is_user_logged_in()) {
            wp_send_json(['success' => false, 'message' => 'Please log in first.']);
        }

        if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
            wp_send_json(['success' => false, 'message' => 'Invalid Product ID.']);
        }

        $user_id = get_current_user_id();
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);

        if (!$product) {
            wp_send_json(['success' => false, 'message' => 'Product not found.']);
        }

        $checkout_url = add_query_arg(
            array(
                'add-to-cart' => $product_id,
                'quantity' => 1,
            ),
            wc_get_checkout_url()
        );

        // Get Stripe Customer ID from FunnelKit (or WooCommerce)
        $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);

        if(!$stripe_customer_id){
            wp_send_json(['success' => true, 'redirect' => $checkout_url]);
        }

        if (!$stripe_customer_id) {
            wp_send_json(['success' => false, 'message' => 'No saved payment method found.', 'checkout_url' => $checkout_url]);
        }

        // Create a new WooCommerce order
        $order = wc_create_order();
        $order->set_customer_id($user_id);
        $order->add_product($product, 1);
        $order->calculate_totals();

        // Get saved payment tokens from WooCommerce
        $payment_methods = WC_Payment_Tokens::get_customer_tokens($user_id);

        if (empty($payment_methods)) {
            wp_send_json(['success' => false, 'message' => 'No saved payment methods available.', 'checkout_url' => $checkout_url]);
        }

        // Find the first valid Stripe payment method
        $payment_method_id = null;
        foreach ($payment_methods as $method) {
            if ($method->get_gateway_id() === 'fkwcs_stripe') { // Ensures we use Stripe
                $payment_method_id = $method->get_token();
                break;
            }
        }

        if (!$payment_method_id) {
            wp_send_json(['success' => false, 'message' => 'No valid Stripe payment method found.', 'checkout_url' => $checkout_url]);
        }

        // Get Stripe API key
        $test_secret_key = get_option('fkwcs_test_secret_key');
        $live_secret_key = get_option('fkwcs_secret_key');
        $stripe_secret = isset($live_secret_key) ? get_option('fkwcs_secret_key') : null;

        if (!$stripe_secret) {
            wp_send_json(['success' => false, 'message' => 'Stripe secret key is missing in FunnelKit settings.', 'checkout_url' => $checkout_url]);
        }

        $stripe = new \Stripe\StripeClient($stripe_secret);

        $payment_intent = $stripe->paymentIntents->create([
            'amount' => $order->get_total() * 100, // Convert to cents
            'currency' => strtolower(get_woocommerce_currency()),
            'customer' => $stripe_customer_id,
            'payment_method' => $payment_method_id,
            'confirm' => true,
            'off_session' => true,
        ]);

        if ($payment_intent->status === 'succeeded') {
            $order->payment_complete($payment_intent->id);
            $order->update_status('completed', 'Order paid successfully via Buy Now button.');
            wp_send_json(['success' => true, 'message' => 'Payment successful!', 'redirect' => $order->get_checkout_order_received_url()]);
        } else {
            wp_send_json([
                'success' => false,
                'message' => 'Payment Failed. Please check your card and return to the checkout page to try again.',
                'checkout_url' => $checkout_url
            ]);
        }
    } catch (Exception $e) {
        error_log('Buy Now Error: ' . $e->getMessage());

        // Create checkout URL if it doesn't exist
        if (!isset($checkout_url) && isset($product_id)) {
            $checkout_url = add_query_arg(
                array(
                    'add-to-cart' => $product_id,
                    'quantity' => 1,
                ),
                wc_get_checkout_url()
            );
        } else if (!isset($checkout_url)) {
            $checkout_url = wc_get_checkout_url();
        }

        wp_send_json([
            'success' => false,
            'message' => 'Payment Failed. Please check your card and return to the checkout page to try again.',
            'error_details' => $e->getMessage(),
            'checkout_url' => $checkout_url
        ]);
    }
}
?>
