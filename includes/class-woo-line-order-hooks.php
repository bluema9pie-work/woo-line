<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Woo_Line_Order_Hooks {

    private $options;

    public function __construct() {
        $this->options = get_option('woo_line_settings');
        $this->setup_hooks();
    }

    /**
     * 設定訂單相關的勾點
     */
    private function setup_hooks() {
        // 移除舊的勾點 (如果存在)
        // remove_action('woocommerce_checkout_order_processed', 'send_line_notification', 10);
        // remove_action('woocommerce_payment_complete', 'send_line_notification', 10);

        // 新訂單通知
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_new_order_notification'), 10, 1);

        // 訂單取消通知
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 10, 3);
    }

    /**
     * 處理訂單狀態變更
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status) {
        // 只處理取消狀態
        if ($new_status !== 'cancelled') {
            return;
        }

        // 使用新的檢查方法
        if ($this->_should_send_notification($order_id, 'order_cancelled')) {
            Woo_Line_Api::send_notification($order_id, 'cancelled');
        }
    }

    /**
     * 處理新訂單通知
     */
    public function handle_new_order_notification($order_id) {
        // 使用新的檢查方法
        if ($this->_should_send_notification($order_id, 'new_order')) {
            Woo_Line_Api::send_notification($order_id, 'new_order');
        }
    }

    /**
     * 檢查是否應該發送通知
     *
     * @param int    $order_id    訂單 ID
     * @param string $trigger_key 通知觸發條件的鍵名 (e.g., 'new_order', 'order_cancelled')
     * @return bool True 如果應該發送，False 如果不應該
     */
    private function _should_send_notification($order_id, $trigger_key) {
        // 檢查選項是否存在
        if (empty($this->options)) {
            return false;
        }

        // 檢查觸發條件是否啟用
        $triggers = isset($this->options['notification_triggers']) ? (array)$this->options['notification_triggers'] : array('new_order');
        if (!in_array($trigger_key, $triggers)) {
            return false;
        }

        // 檢查訂單是否存在
        $order = wc_get_order($order_id);
        if (!$order) {
            // 可以考慮在此處添加日誌記錄 order_id 無效的情況
            // error_log("WooLine: 無法獲取訂單物件，訂單 ID: " . $order_id);
            return false;
        }

        return true; // 所有檢查通過
    }
} 