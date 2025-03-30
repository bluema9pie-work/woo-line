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
        remove_action('woocommerce_checkout_order_processed', array($this, 'handle_new_order_notification'), 10);

        // 根據設定決定新訂單/處理中通知的觸發點
        $trigger_event = isset($this->options['trigger_event']) ? $this->options['trigger_event'] : 'new_order';
        if ($trigger_event === 'new_order') {
            add_action('woocommerce_checkout_order_processed', array($this, 'handle_new_order_notification'), 10, 1);
             // 如果主要觸發是新訂單建立，狀態變更時只需要監聽取消
             add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed_cancel_only'), 10, 3);
        } elseif ($trigger_event === 'order_processing') {
             // 如果主要觸發是處理中，則狀態變更時需要監聽處理中和取消
             add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed_processing_and_cancel'), 10, 3);
        }

        // 注意：原有的 add_action('woocommerce_order_status_changed', ...) 會被上面的條件式 add_action 取代
    }

    /**
     * 處理訂單狀態變更：只監聽取消狀態
     */
    public function handle_order_status_changed_cancel_only($order_id, $old_status, $new_status) {
        // 檢查是否啟用取消通知
        $notify_on_cancel = isset($this->options['notify_on_cancel']) && $this->options['notify_on_cancel'] === 'yes';

        if ($notify_on_cancel && $new_status === 'cancelled') {
            Woo_Line_Api::send_notification($order_id, 'cancelled');
        }
    }
    
    /**
     * 處理訂單狀態變更：監聽處理中和取消狀態
     */
    public function handle_order_status_changed_processing_and_cancel($order_id, $old_status, $new_status) {
         // 檢查是否啟用取消通知
         $notify_on_cancel = isset($this->options['notify_on_cancel']) && $this->options['notify_on_cancel'] === 'yes';

        // 處理訂單處理中通知 (因為設定了 trigger_event 為 order_processing)
        if ($new_status === 'processing') {
             // 注意：這裡直接發送，因為此函式被呼叫的前提就是設定要在此時觸發
             // 類型設為 'processing'，但 API 端會使用新訂單模板
             Woo_Line_Api::send_notification($order_id, 'processing');
             return; // 處理中通知已發送，不再檢查取消
        }

        // 處理訂單取消通知
        if ($notify_on_cancel && $new_status === 'cancelled') {
            Woo_Line_Api::send_notification($order_id, 'cancelled');
        }
    }

    /**
     * 處理新訂單通知 (結帳完成時觸發)
     * 只有當 trigger_event 設為 'new_order' 時，此函式才應被掛載和執行
     */
    public function handle_new_order_notification($order_id) {
        // 不需要再檢查 _should_send_notification，因為此函式被掛載就代表設定是要在此觸發
        Woo_Line_Api::send_notification($order_id, 'new_order');
    }
} 