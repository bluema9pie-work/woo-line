<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Woo_Line_Api {

    private static $options;
    private static $channel_access_token;
    private static $channel_secret;

    /**
     * 初始化 API 類別
     * @since 1.1.0
     */
    public static function init() {
        self::$options = get_option('woo_line_settings');
        // 優先使用常數，若未定義則使用設定值
        self::$channel_access_token = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN') ? WOO_LINE_CHANNEL_ACCESS_TOKEN : (isset(self::$options['channel_access_token']) ? self::$options['channel_access_token'] : '');
        self::$channel_secret = defined('WOO_LINE_CHANNEL_SECRET') ? WOO_LINE_CHANNEL_SECRET : (isset(self::$options['channel_secret']) ? self::$options['channel_secret'] : '');
        add_action('rest_api_init', array(__CLASS__, 'register_webhook_route'));
    }

    /**
     * 發送 LINE 通知
     * @since 1.0.0
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
            // Optionally re-throw if the caller needs to know about the failure
            // throw $e;
        }
    }

    /**
     * 發送測試訊息
     * @since 1.0.0
     * @return array 包含狀態和訊息的陣列
     */
    public static function send_test_message() {
        if (empty(self::$channel_access_token)) {
            return array(
                'status' => 'error',
                'message' => '請先設定 Channel Access Token（可於設定頁面或 wp-config.php 中設定）。'
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
                'message' => '請先將您的 LINE Bot 加入群組，並在上方設定群組 ID。取得群組 ID 的方式：<br>1. 將您的 LINE Bot 加入目標群組<br>2. 在群組中隨意發送一則訊息<br>3. 前往 LINE Developers Console 的 "Webhook" 頁面查看訊息紀錄<br>4. 在訊息紀錄中可以找到 "groupId" 欄位，即為群組 ID<br>5. 將群組 ID 複製並貼到上方的設定欄位中'
            );
        }

        $message = "🔍 這是一則測試訊息\n";
        $message .= "來自：" . $bot_name . "\n\n";
        $message .= "如果您看到這則訊息，代表：\n";
        $message .= "1. Channel Access Token 設定正確\n";
        $message .= "2. 群組 ID 設定正確\n";
        $message .= "3. Bot 已成功加入此群組\n\n";
        $message .= "✅ 設定完成！未來有新訂單時，會自動發送通知到此群組。";

        $headers_push = array(
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
            'headers' => $headers_push,
            'method' => 'POST',
            'data_format' => 'body'
        );
        $response = wp_remote_post('https://api.line.me/v2/bot/message/push', $args);

        if (is_wp_error($response)) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (wp_remote_post): ' . $response->get_error_message());
            }
            return array(
                'status' => 'error',
                'message' => 'LINE 訊息發送失敗：' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 200) {
            return array(
                'status' => 'success',
                'message' => '✅ 測試訊息發送成功！請檢查您的 LINE 群組是否收到訊息。'
            );
        } else {
            $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Test Message Error (API Response ' . $response_code . '): ' . $error_message);
            }
            if (strpos($error_message, 'Invalid to')) {
                $error_message = '無效的群組 ID，請確認：<br>1. 群組 ID 格式是否正確（應該以 "C" 開頭）<br>2. Bot 是否已經被加入該群組<br>3. 群組 ID 是否完整複製（不要有多餘的空格）';
            } elseif (strpos($error_message, 'Invalid reply token')) {
                $error_message = '回應 token 無效，請重新整理頁面後再試。' ;
            } elseif (strpos($error_message, 'The request body has 1 error(s)')) {
                $error_message = '請求格式錯誤，請確認群組 ID 是否正確設定。' ;
            }
            return array(
                'status' => 'error',
                'message' => 'LINE API 錯誤：' . $error_message
            );
        }
    }

    /**
     * 使用最新訂單發送測試訊息
     * @since 1.0.0
     * @return array 包含狀態和訊息的陣列
     */
    public static function send_latest_order_test() {
        if (empty(self::$channel_access_token)) {
            return array(
                'status' => 'error',
                'message' => '請先設定 Channel Access Token（可於設定頁面或 wp-config.php 中設定）。'
            );
        }

        if (empty(self::$options['group_id'])) {
            return array(
                'status' => 'error',
                'message' => '請先設定要接收通知的 LINE 群組。'
            );
        }

        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        if (empty($orders)) {
            return array(
                'status' => 'error',
                'message' => '找不到任何訂單，請先建立一筆測試訂單。'
            );
        }

        $latest_order = $orders[0];
        $order_id = $latest_order->get_id();

        try {
            delete_post_meta($order_id, '_line_notification_sent_new_order');
            self::send_notification($order_id, 'new_order');
            return array(
                'status' => 'success',
                'message' => sprintf(
                    '✅ 已使用訂單 #%s 發送測試通知！<br>訂購人：%s<br>訂單金額：%s<br>請檢查 LINE 群組是否收到通知。',
                    $order_id,
                    $latest_order->get_formatted_billing_full_name(),
                    wc_price($latest_order->get_total())
                )
            );
        } catch (Exception $e) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Latest Order Test Error: ' . $e->getMessage());
            }
            return array(
                'status' => 'error',
                'message' => '發送測試通知時發生錯誤：' . $e->getMessage()
            );
        }
    }

    /**
     * 註冊 Webhook 處理路由
     */
    public static function register_webhook_route() {
        register_rest_route('woo-line/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * 處理來自 LINE 的 Webhook 事件
     * @since 1.0.0
     * @param WP_REST_Request $request REST API 請求物件
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_webhook($request) {
        if (empty(self::$channel_secret)) {
             return new WP_Error('no_channel_secret', 'Channel Secret not configured (can be set in settings or wp-config.php)', array('status' => 403));
        }

        $signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : '';
        $body = $request->get_body();
        
        $hash = base64_encode(hash_hmac('sha256', $body, self::$channel_secret, true));
        if ($hash !== $signature) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Webhook Error: Invalid signature');
            }
            return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 403));
        }

        $events = json_decode($body, true)['events'];
        $groups = get_option('woo_line_groups', array());
        $updated = false;

        foreach ($events as $event) {
            if ($event['type'] === 'join' && $event['source']['type'] === 'group') {
                $group_id = $event['source']['groupId'];
                $group_name = self::get_group_name($group_id);
                if ($group_name) {
                    $groups[$group_id] = $group_name;
                    $updated = true;
                }
            } elseif ($event['type'] === 'message' && $event['source']['type'] === 'group') {
                $group_id = $event['source']['groupId'];
                if (!isset($groups[$group_id])) {
                   $group_name = self::get_group_name($group_id);
                    if ($group_name) {
                        $groups[$group_id] = $group_name;
                        $updated = true;
                    }
                }
            }
        }

        if ($updated) {
            update_option('woo_line_groups', $groups);
        }

        return new WP_REST_Response(null, 200);
    }

    /**
     * 取得群組名稱
     * @since 1.0.0
     * @param string $group_id 群組 ID
     * @return string|null 群組名稱或 null
     */
    private static function get_group_name($group_id) {
        if (empty(self::$channel_access_token)) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Get Group Name Error: Channel Access Token is empty.');
            }
            return new WP_Error('missing_token', 'Channel Access Token 未設定');
        }
        $headers = array(
            'Authorization' => 'Bearer ' . self::$channel_access_token
        );
        $response = wp_remote_get(
            'https://api.line.me/v2/bot/group/' . $group_id . '/summary',
            array('headers' => $headers)
        );
        if (!is_wp_error($response)) {
            $group_info = json_decode(wp_remote_retrieve_body($response), true);
            return isset($group_info['groupName']) ? $group_info['groupName'] : null;
        }
        if (is_wp_error($response)) {
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Get Group Name Error (wp_remote_get for ' . $group_id . '): ' . $response->get_error_message());
            }
            return new WP_Error('api_connection_error', '無法連接 LINE API：' . $response->get_error_message());
        }
        if ($response_code !== 200) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['message']) ? $response_body['message'] : '無法取得群組名稱，請檢查 Bot 是否為該群組成員以及 Token 是否正確';
            if (isset(self::$options['enable_logging']) && self::$options['enable_logging'] === 'yes') {
                error_log('WooLine Get Group Name Error (API Response ' . $response_code . ' for ' . $group_id . '): ' . $error_message);
            }
            return new WP_Error('api_error_' . $response_code, 'LINE API 錯誤 (' . $response_code . '): ' . $error_message);
        }
        return null;
    }
}

// 初始化 API 類別
Woo_Line_Api::init(); 