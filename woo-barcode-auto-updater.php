<?php
/**
 * Plugin Name: WooCommerce Barcode Auto Updater
 * Description: Automatically updates WooCommerce products missing images or barcodes using data from Barcode Lookup. Converts images to WebP before upload.
 * Version: 1.1
 * Author: George Nicolaou
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Function to update WooCommerce products
function update_woocommerce_product($product_id, $description, $image_url = null) {
    $product = wc_get_product($product_id);
    if ($product) {
        $product->set_description($description);
        if ($image_url) {
            $attachment_id = insert_product_image($image_url, $product_id);
            if ($attachment_id) {
                $product->set_image_id($attachment_id);
            }
        }
        $product->save();
        return true;
    }
    return false;
}

// Function to insert product image and convert it to WebP
function insert_product_image($image_url, $product_id) {
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    $filename = basename($image_url);

    // Convert image to WebP
    $image = imagecreatefromstring($image_data);
    $webp_filename = preg_replace('/\.[^.]+$/', '.webp', $filename);

    if ($image !== false) {
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $webp_filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $webp_filename;
        }

        imagewebp($image, $file);
        imagedestroy($image);

        $wp_filetype = wp_check_filetype($webp_filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($webp_filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file, $product_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    return false;
}

// Function to fetch product information from Barcode Lookup
function get_product_info_from_barcode($barcode) {
    $url = "https://www.barcodelookup.com/{$barcode}";
    $args = [
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ],
    ];
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $matches = [];
    
    preg_match('/<div class="product-description">(.+?)<\/div>/s', $body, $matches);
    $description = isset($matches[1]) ? strip_tags($matches[1]) : false;
    
    preg_match('/<img class="product-image" src="(.+?)"/s', $body, $image_match);
    $image_url = isset($image_match[1]) ? $image_match[1] : null;

    return ['description' => $description, 'image_url' => $image_url];
}

// Function to process products missing information
function process_missing_info_products() {
    $args = [
        'limit' => -1,
        'status' => 'publish',
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => '_sku',
                'compare' => 'EXISTS',
            ],
            [
                'key' => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ];
    
    $products = wc_get_products($args);

    foreach ($products as $product) {
        $barcode = $product->get_sku();
        if (!$barcode) {
            continue;
        }

        $product_info = get_product_info_from_barcode($barcode);
        if ($product_info && $product_info['description']) {
            $success = update_woocommerce_product($product->get_id(), $product_info['description'], $product_info['image_url']);
            log_barcode_update($product->get_id(), $barcode, $success);
        } else {
            log_barcode_update($product->get_id(), $barcode, false, 'Description or image not found.');
        }

        // Introduce delay between requests to avoid rate limiting
        sleep(rand(5, 15)); // 5 to 15 seconds delay
    }

    // Schedule the next run
    wp_schedule_single_event(time() + rand(1800, 3600), 'missing_info_update_event'); // 30 to 60 minutes delay
}

add_action('missing_info_update_event', 'process_missing_info_products');

// Logging function
function log_barcode_update($product_id, $barcode, $success, $message = '') {
    $log_message = sprintf(
        "[%s] Product ID: %d, Barcode: %s, Success: %s, Message: %s
",
        date('Y-m-d H:i:s'),
        $product_id,
        $barcode,
        $success ? 'Yes' : 'No',
        $message
    );
    
    $log_file = plugin_dir_path(__FILE__) . 'barcode_update_log.txt';
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Activation and deactivation hooks
function barcode_updater_activate() {
    if (! wp_next_scheduled('missing_info_update_event')) {
        wp_schedule_single_event(time() + rand(1800, 3600), 'missing_info_update_event'); // 30 to 60 minutes delay
    }
}

register_activation_hook(__FILE__, 'barcode_updater_activate');

function barcode_updater_deactivate() {
    $timestamp = wp_next_scheduled('missing_info_update_event');
    wp_unschedule_event($timestamp, 'missing_info_update_event');
}

register_deactivation_hook(__FILE__, 'barcode_updater_deactivate');

// Admin interface for manual trigger
function barcode_updater_menu() {
    add_submenu_page(
        'woocommerce',
        'Barcode Auto Updater',
        'Barcode Auto Updater',
        'manage_options',
        'barcode-updater',
        'barcode_updater_page'
    );
}

add_action('admin_menu', 'barcode_updater_menu');

function barcode_updater_page() {
    echo '<div class="wrap"><h1>Barcode Auto Updater</h1>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="barcode_update_action" value="trigger_update">';
    echo '<input type="submit" class="button-primary" value="Run Update">';
    echo '</form></div>';

    if (isset($_POST['barcode_update_action'])) {
        process_missing_info_products();
        echo '<div class="updated"><p>Update Processed!</p></div>';
    }
}
?>
