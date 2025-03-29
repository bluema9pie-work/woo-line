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
        add_action('woocommerce_payment_complete', array($this, 'handle_new_order_notification'), 10, 1);

        // 訂單取消通知
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 10, 3);
    }

    /**
     * 處理訂單狀態變更
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status) {
        if ($new_status !== 'cancelled') {
            return;
        }

        if (empty($this->options)) {
            return;
        }

        $triggers = isset($this->options['notification_triggers']) ? (array)$this->options['notification_triggers'] : array('new_order');
        
        if (!in_array('order_cancelled', $triggers)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        Woo_Line_Api::send_notification($order_id, 'cancelled');
    }

    /**
     * 處理新訂單通知
     */
    public function handle_new_order_notification($order_id) {
        if (empty($this->options)) {
            return;
        }

        $triggers = isset($this->options['notification_triggers']) ? (array)$this->options['notification_triggers'] : array('new_order');
        
        if (!in_array('new_order', $triggers)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        Woo_Line_Api::send_notification($order_id, 'new_order');
    }
} 