<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 管理 WooCommerce 訂單相關的鉤子和觸發 LINE 通知
 */
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
     * 處理訂單狀態變更，主要監聽取消狀態
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status) {
        if ($new_status !== 'cancelled') {
            return;
        }

        if ($this->_should_send_notification($order_id, 'order_cancelled')) {
            Woo_Line_Api::send_notification($order_id, 'cancelled');
        }
    }

    /**
     * 處理新訂單通知 (結帳完成時觸發)
     */
    public function handle_new_order_notification($order_id) {
        if ($this->_should_send_notification($order_id, 'new_order')) {
            Woo_Line_Api::send_notification($order_id, 'new_order');
        }
    }

    /**
     * 檢查是否應該根據設定和訂單狀態發送通知
     *
     * @param int    $order_id    訂單 ID
     * @param string $trigger_key 通知觸發條件的鍵名 (e.g., 'new_order', 'order_cancelled')
     * @return bool
     */
    private function _should_send_notification($order_id, $trigger_key) {
        if (empty($this->options)) {
            return false;
        }

        $triggers = isset($this->options['notification_triggers']) ? (array)$this->options['notification_triggers'] : array('new_order');
        if (!in_array($trigger_key, $triggers)) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        return true;
    }
} 