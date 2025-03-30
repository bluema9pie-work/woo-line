<?php
/**
 * Plugin Name: WooCommerce LINE 訂單通知
 * Plugin URI: https://aquarius.com.tw/
 * Description: 當有新訂單時，透過 LINE Messaging API 發送通知至指定群組
 * Version: 1.1.6
 * Author: Aquarius
 * Author URI: https://aquarius.com.tw/
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WooCommerce_LINE_Notification
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WOO_LINE_PLUGIN_FILE', __FILE__);
define('WOO_LINE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WOO_LINE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_LINE_VERSION', '1.1.6');

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
 * 顯示 WooCommerce 未啟用提示
 */
function woo_line_woocommerce_inactive_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce LINE 訂單通知需要啟用 WooCommerce。', 'woo-line-notification'); ?></p>
    </div>
    <?php
}

/**
 * 外掛啟用時檢查相依性
 */
function woo_line_plugin_activate() {
    if (!woo_line_check_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('WooCommerce LINE 訂單通知需要啟用 WooCommerce。請先安裝並啟用 WooCommerce。', 'woo-line-notification'),
            __('外掛相依性檢查', 'woo-line-notification'),
            array('back_link' => true)
        );
    }
   
}
register_activation_hook(__FILE__, 'woo_line_plugin_activate');

/**
 * 載入外掛主要檔案和初始化
 */
function woo_line_load_plugin() {
    if (!woo_line_check_woocommerce_active()) {
        return; 
    }

    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-api.php';
    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-settings.php';
    require_once WOO_LINE_PLUGIN_PATH . 'includes/class-woo-line-order-hooks.php';

    if (is_admin()) { 
        new Woo_Line_Settings();
    }
    new Woo_Line_Order_Hooks();

    load_plugin_textdomain('woo-line-notification', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'woo_line_load_plugin');

/**
 * 在外掛列表頁面加入設定連結
 */
function woo_line_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=woo_line_settings') . '">' . __('設定', 'woo-line-notification') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woo_line_add_settings_link');

/**
 * 在後台載入 CSS 樣式表和 JavaScript 腳本
 */
function woo_line_enqueue_admin_assets($hook) {
    // 只在此外掛的設定頁面載入
    if ('settings_page_woo_line_settings' !== $hook) {
         return;
    }

    // 載入 CSS
    $css_file_path = WOO_LINE_PLUGIN_PATH . 'assets/css/woo-line-styles.css';
    $css_file_url = WOO_LINE_PLUGIN_URL . 'assets/css/woo-line-styles.css';
    if (file_exists($css_file_path)) {
        $css_version = filemtime($css_file_path);
        wp_enqueue_style(
            'woo-line-admin-styles',
            $css_file_url,
            array(),
            $css_version
        );
    }

    // 載入 JavaScript
    $js_file_path = WOO_LINE_PLUGIN_PATH . 'assets/js/woo-line-admin.js';
    $js_file_url = WOO_LINE_PLUGIN_URL . 'assets/js/woo-line-admin.js';
    if (file_exists($js_file_path)) {
        $js_version = filemtime($js_file_path);
        wp_enqueue_script(
            'woo-line-admin-script',
            $js_file_url,
            array('jquery'), // 可根據需要加入依賴，此處暫不加 jquery
            $js_version,
            true // 在頁尾載入
        );

        // 將 PHP 資料 (包含翻譯字串) 傳遞給 JavaScript
        wp_localize_script('woo-line-admin-script', 'wooLineAdminData', array(
            'l10n' => array(
                'confirmClearGroupId' => __('您確定要清除已選擇的 LINE 群組 ID 嗎？這將使下拉選單恢復預設值，並在儲存設定後生效。', 'woo-line-notification'),
                'groupIdCleared'      => __('群組選擇已清除。請點擊「儲存設定」按鈕以完成操作。', 'woo-line-notification'),
                'copied'              => __('已複製！', 'woo-line-notification'),
                'copyFailed'          => __('複製失敗！', 'woo-line-notification'),
            )
        ));
    }
}
add_action('admin_enqueue_scripts', 'woo_line_enqueue_admin_assets');
