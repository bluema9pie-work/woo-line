<?php
/**
 * Plugin Name: WooCommerce LINE 訂單通知
 * Plugin URI: https://aquarius.com.tw/
 * Description: 當有新訂單時，透過 LINE Messaging API 發送通知至指定群組
 * Version: 1.1.0
 * Author: Aquarius
 * Author URI: https://aquarius.com.tw/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WooCommerce_LINE_Notification
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
 
define('WOO_LINE_PLUGIN_FILE', __FILE__);
define('WOO_LINE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WOO_LINE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_LINE_VERSION', '1.1.0');
 
/**
 * 檢查 WooCommerce 是否啟用
 */
function woo_line_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'woo_line_woocommerce_inactive_notice');
        return false;
    }
    return true;
}

/**
 * 顯示 WooCommerce 未啟用通知
 */
function woo_line_woocommerce_inactive_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce LINE Notification requires WooCommerce to be activated.', 'woo-line-notification'); ?></p>
    </div>
    <?php
}

/**
 * 外掛啟用時的動作
 */
function woo_line_plugin_activate() {
    if (!woo_line_check_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('WooCommerce LINE Notification requires WooCommerce to be activated. Please install and activate WooCommerce first.', 'woo-line-notification'), 
            __('Plugin dependency check', 'woo-line-notification'), 
            array('back_link' => true)
        );
    }
    // 可以加入其他啟用時的初始化代碼
}
register_activation_hook(__FILE__, 'woo_line_plugin_activate');

/**
 * 載入外掛核心功能
 */
function woo_line_load_plugin() {
    if (!woo_line_check_woocommerce_active()) {
        return; // 如果 WooCommerce 未啟用，則不載入核心功能
    }

    // 載入依賴檔案
    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-api.php';
    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-settings.php';
    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-order-hooks.php';

    // 初始化核心類別
    if (is_admin()) { // 只在後台載入設定頁面
        new Woo_Line_Settings();
    }
    new Woo_Line_Order_Hooks();
    // Woo_Line_Api is initialized via its static init() method called in its file.

    // 載入文字域
    load_plugin_textdomain('woo-line-notification', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'woo_line_load_plugin');
