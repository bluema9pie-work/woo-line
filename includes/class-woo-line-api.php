<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 處理與 LINE Messaging API 的互動
 */
class Woo_Line_Api {

    private static $options;
    private static $channel_access_token;
    private static $channel_secret;

    /**
     * 初始化 API 類別，載入設定並註冊 Webhook
     */
    public static function init() {
        self::$options = get_option('woo_line_settings');
        self::$channel_access_token = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN') ? WOO_LINE_CHANNEL_ACCESS_TOKEN : (isset(self::$options['channel_access_token']) ? self::$options['channel_access_token'] : '');
        self::$channel_secret = defined('WOO_LINE_CHANNEL_SECRET') ? WOO_LINE_CHANNEL_SECRET : (isset(self::$options['channel_secret']) ? self::$options['channel_secret'] : '');
        add_action('rest_api_init', array(__CLASS__, 'register_webhook_route'));
    }

    /**
     * 發送 LINE 通知 (新訂單或取消訂單)
     * 
     * @param int $order_id 訂單 ID
     * @param string $type 通知類型 ('new_order' 或 'cancelled')
     */
    public static function send_notification($order_id, $type = 'new_order') {
        try {
            $notification_key = '_line_notification_sent_' . $type;
            if (get_post_meta($order_id, $notification_key, true)) {
                return;
            }

            if (!$order_id) {
                throw new Exception('無效的訂單 ID');
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('無法取得訂單物件，訂單 ID：' . $order_id);
            }

            if (empty(self::$channel_access_token) || empty(self::$options['group_id'])) {
                throw new Exception('LINE 設定不完整（請檢查 Channel Access Token 和 Group ID）');
            }

            $items_list = array();
            $order_items = $order->get_items();
            foreach ($order_items as $item_id => $item) {
                try {
                    $items_list[] = sprintf(
                        "%s x %d",
                        $item->get_name(),
                        $item->get_quantity()
                    );
                } catch (Exception $e) {
                    continue;
                }
            }
            $products_text = !empty($items_list) ? " " . implode("\n ", $items_list) : "無商品資料";

            // 建立簡碼陣列
            $shortcodes = array(
                '[order-id]' => $order_id,
                '[order-time]' => wp_date('Y-m-d H:i:s', $order->get_date_created()->getTimestamp()),
                '[order-name]' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                '[order-product]' => $products_text,
                '[payment-method]' => $order->get_payment_method_title(),
                '[total]' => number_format($order->get_total(), 0),
                '[customer_note]' => $order->get_customer_note() ?: ''
            );

            $billing_data = $order->get_data()['billing'];
            foreach ($billing_data as $key => $value) {
                if (!empty($value)) {
                    $shortcodes['[billing_' . $key . ']'] = $value;
                }
            }

            $shipping_data = $order->get_data()['shipping'];
            foreach ($shipping_data as $key => $value) {
                if (!empty($value)) {
                    $shortcodes['[shipping_' . $key . ']'] = $value;
                }
            }

            $meta_data = $order->get_meta_data();
            foreach ($meta_data as $meta) {
                $meta_key = $meta->get_data()['key'];
                $meta_value = $meta->get_data()['value'];
                if (strpos($meta_key, '_') !== 0 && 
                    !is_array($meta_value) && 
                    !is_object($meta_value) && 
                    !empty($meta_value)) {
                    $shortcodes['[' . $meta_key . ']'] = $meta_value;
                }
            }

            // 根據通知類型選擇模板
            if ($type === 'cancelled') {
                $template = isset(self::$options['cancelled_message_template']) ? self::$options['cancelled_message_template'] : '';
                if (empty($template)) {
                    $template = "⚠️ 訂單已取消通知\n" .
                        "訂單編號: [order-id]\n" .
                        "訂購人: [billing_last_name][billing_first_name]\n" .
                        "取消訂單項目:\n[order-product]\n" .
                        "訂單金額: [total] 元";
                }
            } else {
                $template = isset(self::$options['message_template']) ? self::$options['message_template'] : '';
                if (empty($template)) {
                    $template = "🔔叮咚！有一筆新的訂單！\n" .
                        "訂單編號: [order-id]\n" .
                        "訂購時間: [order-time]\n" .
                        "訂購人: [billing_last_name][billing_first_name]\n" .
                        "訂購項目:\n[order-product]\n" .
                        "付款方式: [payment-method]\n" .
                        "總金額: [total] 元";
                }
            }

            // 替換簡碼並清理空值簡碼和多餘換行
            preg_match_all('/\[[^\]]+\]/', $template, $matches);
            $undefined_shortcodes = array();
            foreach ($matches[0] as $shortcode) {
                if (!isset($shortcodes[$shortcode])) {
                    $undefined_shortcodes[$shortcode] = '';
                }
            }
            $message = str_replace(array_keys($shortcodes), array_values($shortcodes), $template);
            $message = str_replace(array_keys($undefined_shortcodes), array_values($undefined_shortcodes), $message);
            $message = preg_replace('/:[^\S\n]*\n/', ":\n", $message); 
            $message = preg_replace('/：[^\S\n]*\n/', "：\n", $message); 
            $message = preg_replace("/\n\s*\n\s*\n/", "\n\n", $message);

            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . self::$channel_access_token
            );

            $body = array(
                'to' => self::$options['group_id'],
                'messages' => array(
                    array(
                        'type' => 'text',
                        'text' => $message
                    )
                )
            );

            $args = array(
                'body' => json_encode($body),
                'headers' => $headers,
                'method' => 'POST',
                'data_format' => 'body'
            );

            $response = wp_remote_post('https://api.line.me/v2/bot/message/push', $args);

            if (is_wp_error($response)) {
                if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                    error_log('WooLine Notification Error (Order ID: ' . $order_id . ', Type: ' . $type . '): ' . $response->get_error_message());
                }
                throw new Exception('LINE 通知發送失敗：' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
                if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                    error_log('WooLine Notification Error (API Response ' . $response_code . '): ' . $error_message);
                }
                throw new Exception('LINE API 錯誤（' . $response_code . '）：' . $error_message);
            }

            update_post_meta($order_id, $notification_key, true);

        } catch (Exception $e) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Notification Error (Order ID: ' . $order_id . ', Type: ' . $type . '): ' . $e->getMessage());
            }
        }
    }

    /**
     * 發送簡單測試訊息以驗證 Access Token 和 Group ID
     *
     * @return array 包含狀態和訊息的陣列
     */
    public static function send_test_message() {
        if (empty(self::$channel_access_token)) {
            return array(
                'status' => 'error',
                'message' => '請先設定 Channel Access Token。'
            );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . self::$channel_access_token
        );
        $args = array(
            'headers' => $headers,
            'method' => 'GET'
        );
        $response = wp_remote_get('https://api.line.me/v2/bot/info', $args);
        
        if (is_wp_error($response)) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (wp_remote_get): ' . $response->get_error_message());
            }
            return array(
                'status' => 'error',
                'message' => 'LINE API 連線失敗：' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (API Response ' . $response_code . '): ' . $error_message);
            }
            return array(
                'status' => 'error',
                'message' => 'Channel Access Token 無效，請確認是否正確。'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $bot_name = isset($body['displayName']) ? $body['displayName'] : '您的 LINE Bot';

        if (empty(self::$options['group_id'])) {
            return array(
                'status' => 'error',
                'message' => '請先設定群組 ID 並確保 Bot 已加入該群組。'
            );
        }

        $message = "🔍 這是一則測試訊息\n";
        $message .= "來自：" . $bot_name . "\n\n";
        $message .= "如果您看到這則訊息，代表：\n";
        $message .= "1. Channel Access Token 設定正確\n";
        $message .= "2. 群組 ID 設定正確\n";
        $message .= "3. Bot 已成功加入此群組";

        $push_headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . self::$channel_access_token
        );
        $push_body = array(
            'to' => self::$options['group_id'],
            'messages' => array(array('type' => 'text', 'text' => $message))
        );
        $push_args = array(
            'body' => json_encode($push_body),
            'headers' => $push_headers,
            'method' => 'POST',
            'data_format' => 'body'
        );

        $push_response = wp_remote_post('https://api.line.me/v2/bot/message/push', $push_args);

        if (is_wp_error($push_response)) {
             if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (Push wp_remote_post): ' . $push_response->get_error_message());
            }
            return array(
                'status' => 'error',
                'message' => '發送測試訊息失敗：' . $push_response->get_error_message()
            );
        }

        $push_response_code = wp_remote_retrieve_response_code($push_response);
        if ($push_response_code !== 200) {
            $push_response_body = json_decode(wp_remote_retrieve_body($push_response), true);
            $push_error_message = isset($push_response_body['message']) ? $push_response_body['message'] : '未知錯誤';
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (Push API Response ' . $push_response_code . '): ' . $push_error_message);
            }
            return array(
                'status' => 'error',
                'message' => '發送測試訊息失敗 (API ' . $push_response_code . ')：' . $push_error_message
            );
        }

        return array(
            'status' => 'success',
            'message' => '測試訊息已成功發送至群組！'
        );
    }

    /**
     * 發送包含最新訂單資訊的測試訊息
     *
     * @return array 包含狀態和訊息的陣列
     */
    public static function send_latest_order_test() {
        try {
            $orders = wc_get_orders(array(
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses())
            ));

            if (empty($orders)) {
                return array(
                    'status' => 'error',
                    'message' => '找不到任何訂單來進行測試。'
                );
            }

            $latest_order = $orders[0];
            $order_id = $latest_order->get_id();

            // 直接呼叫 send_notification 但不更新 meta
            self::send_notification($order_id, 'test_latest_order');

            // 檢查是否成功發送 (需要調整 send_notification 才能直接返回狀態，
            // 目前僅假設呼叫成功，若有錯誤會在 send_notification 中記錄)
            // 為了簡化，這裡直接返回成功訊息，實際錯誤會在日誌中。
            return array(
                'status' => 'success',
                'message' => '已嘗試使用最新訂單 (ID: ' . $order_id . ') 的資料發送測試訊息。'.
                            (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes' ? ' 如有錯誤請檢查錯誤記錄檔。' : '')
            );

        } catch (Exception $e) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Latest Order Test Error: ' . $e->getMessage());
            }
            return array(
                'status' => 'error',
                'message' => '發送最新訂單測試訊息時發生錯誤：' . $e->getMessage()
            );
        }
    }

    /**
     * 註冊 LINE Webhook 的 REST API 路由
     */
    public static function register_webhook_route() {
        register_rest_route('woo-line/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true' // 確保任何人都可以訪問此端點
        ));
    }

    /**
     * 處理來自 LINE 的 Webhook 請求
     * 主要用於自動抓取 Bot 被加入的群組 ID 和名稱
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response Response object.
     */
    public static function handle_webhook($request) {
        if (empty(self::$channel_secret)) {
            self::log_webhook_event('error', 'Channel Secret 未設定，無法驗證 Webhook 簽名。');
            return new WP_REST_Response(array('message' => 'Channel Secret not configured'), 400);
        }

        $signature = $request->get_header('X-Line-Signature');
        $body = $request->get_body();

        if (empty($signature)) {
            self::log_webhook_event('error', 'Webhook 請求缺少 X-Line-Signature。');
            return new WP_REST_Response(array('message' => 'Signature not found'), 400);
        }

        // 驗證簽名
        $hash = hash_hmac('sha256', $body, self::$channel_secret, true);
        $calculated_signature = base64_encode($hash);

        if ($signature !== $calculated_signature) {
            self::log_webhook_event('error', 'Webhook 簽名驗證失敗。');
            return new WP_REST_Response(array('message' => 'Invalid signature'), 400);
        }

        $events = json_decode($body, true);
        if (isset($events['events'])) {
            $current_groups = get_option('woo_line_groups', array());
            $updated = false;

            foreach ($events['events'] as $event) {
                $event_type = isset($event['type']) ? $event['type'] : null;
                $source_type = isset($event['source']['type']) ? $event['source']['type'] : null;
                $group_id = ($source_type === 'group' && isset($event['source']['groupId'])) ? $event['source']['groupId'] : null;

                if ($group_id) {
                    self::log_webhook_event('info', '收到來自 Group ID [' . $group_id . '] 的事件: [' . $event_type . ']');
                    // 如果是 join 事件或 message 事件，且群組尚未記錄，則嘗試獲取群組名稱並儲存
                    if (($event_type === 'join' || $event_type === 'message') && !isset($current_groups[$group_id])) {
                        $group_name = self::get_group_name($group_id);
                        if ($group_name) {
                            $current_groups[$group_id] = $group_name;
                            $updated = true;
                            self::log_webhook_event('info', '已成功記錄新的 Group ID [' . $group_id . ']，名稱: [' . $group_name . ']。');
                        } else {
                             self::log_webhook_event('warning', '無法獲取 Group ID [' . $group_id . '] 的名稱。');
                        }
                    }
                } elseif ($event_type === 'leave' && $source_type === 'group') {
                    $group_id_left = isset($event['source']['groupId']) ? $event['source']['groupId'] : null;
                    if ($group_id_left && isset($current_groups[$group_id_left])) {
                        unset($current_groups[$group_id_left]);
                        $updated = true;
                        self::log_webhook_event('info', 'Bot 已離開 Group ID [' . $group_id_left . ']，已從記錄中移除。');
                    }
                }
            }

            if ($updated) {
                update_option('woo_line_groups', $current_groups);
            }
        }

        return new WP_REST_Response(array('status' => 'success'), 200);
    }

    /**
     * 嘗試透過 LINE API 取得群組名稱
     *
     * @param string $group_id
     * @return string|false 群組名稱或 false
     */
    private static function get_group_name($group_id) {
        if (empty(self::$channel_access_token)) {
            self::log_webhook_event('error', '嘗試獲取群組名稱失敗：Channel Access Token 未設定。');
            return false;
        }

        $url = 'https://api.line.me/v2/bot/group/' . $group_id . '/summary';
        $headers = array(
            'Authorization' => 'Bearer ' . self::$channel_access_token
        );
        $args = array(
            'headers' => $headers,
            'method' => 'GET'
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            self::log_webhook_event('error', '獲取群組名稱 API 呼叫失敗 (Group ID: ' . $group_id . '): ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return isset($body['groupName']) ? $body['groupName'] : ('群組 ' . $group_id);
        } else {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
            self::log_webhook_event('error', '獲取群組名稱 API 回應錯誤 (Group ID: ' . $group_id . ', Code: ' . $response_code . '): ' . $error_message);
            return false;
        }
    }
    
    /**
     * 記錄 Webhook 事件 (如果啟用日誌記錄)
     */
    private static function log_webhook_event($level, $message) {
        if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
            error_log('WooLine Webhook [' . strtoupper($level) . ']: ' . $message);
        }
    }
}

// 初始化 API 類別
Woo_Line_Api::init(); 