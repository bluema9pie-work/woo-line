<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 處理與 LINE Messaging API 的互動
 */
class Woo_Line_Api {

    /**
     * 初始化 API 類別，載入設定並註冊 Webhook
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_webhook_route'));
    }

    /**
     * 發送 LINE 通知 (新訂單或取消訂單)
     * 
     * @param int $order_id 訂單 ID
     * @param string $type 通知類型 ('new_order', 'cancelled', 或 'test_latest_order')
     */
    public static function send_notification($order_id, $type = 'new_order') {
        $options = get_option('woo_line_settings');
        $channel_access_token = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN') ? WOO_LINE_CHANNEL_ACCESS_TOKEN : (isset($options['channel_access_token']) ? $options['channel_access_token'] : '');
        $enable_logging = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';

        try {
            $notification_key = '_line_notification_sent_' . $type;
            if ($type !== 'test_latest_order' && get_post_meta($order_id, $notification_key, true)) {
                return;
            }

            if (!$order_id) {
                throw new Exception('無效的訂單 ID');
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('無法取得訂單物件，訂單 ID：' . $order_id);
            }

            $group_id = isset($options['group_id']) ? $options['group_id'] : '';
            if (empty($channel_access_token) || empty($group_id)) {
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

            // 獲取運送方式名稱
            $shipping_methods = $order->get_shipping_methods();
            $shipping_method_names = [];
            if (!empty($shipping_methods)) {
                foreach ($shipping_methods as $shipping_method) {
                     // 使用 get_name() 通常能獲取更簡潔的名稱，例如 "Flat rate"
                     // 如果需要包含實例標題 (例如 "Flat rate - Domestic")，可以使用 get_method_title()
                    $shipping_method_names[] = $shipping_method->get_name(); 
                }
                $shortcodes['[shipping-method]'] = implode(', ', $shipping_method_names);
            } else {
                 // 如果訂單沒有運送方式 (例如虛擬商品)，則給予預設值
                 $shortcodes['[shipping-method]'] = __('無', 'woocommerce'); // 或者 'N/A', 或空字串 ''
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
            $template = '';
            switch ($type) {
                case 'cancelled':
                    $template = isset($options['cancelled_message_template']) ? $options['cancelled_message_template'] : '';
                    if (empty($template)) {
                        $template = self::get_default_cancelled_message_template(); // 使用輔助函數取得預設模板
                    }
                    break;
                case 'new_order':
                case 'test_latest_order': // 測試最新訂單也使用新訂單模板
                default:
                    $template = isset($options['message_template']) ? $options['message_template'] : '';
                    if (empty($template)) {
                        $template = self::get_default_message_template(); // 使用輔助函數取得預設模板
                    }
                    break;
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
                'Authorization' => 'Bearer ' . $channel_access_token
            );

            $body = array(
                'to' => $group_id,
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
                if ($enable_logging) {
                    error_log('WooLine Notification Error (Order ID: ' . $order_id . ', Type: ' . $type . '): ' . $response->get_error_message());
                }
                throw new Exception('LINE 通知發送失敗：' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
                if ($enable_logging) {
                    error_log('WooLine Notification Error (API Response ' . $response_code . '): ' . $error_message);
                }
                throw new Exception('LINE API 錯誤（' . $response_code . '）：' . $error_message);
            }

            if ($type !== 'test_latest_order') {
                update_post_meta($order_id, $notification_key, true);
            }

        } catch (Exception $e) {
            if ($enable_logging) {
                error_log('WooLine Notification Error (Order ID: ' . $order_id . ', Type: ' . $type . '): ' . $e->getMessage());
            }
            if ($type === 'test_latest_order') {
                throw $e;
            }
        }
    }

    // 新增：取得預設的新訂單模板
    private static function get_default_message_template() {
        return "🔔叮咚！有一筆新的訂單！\n" .
            "訂單編號: [order-id]\n" .
            "訂購時間: [order-time]\n" .
            "訂購人: [billing_last_name][billing_first_name]\n" .
            "訂購項目:\n[order-product]\n" .
            "付款方式: [payment-method]\n" .
            "總金額: [total] 元";
    }

    // 新增：取得預設的取消訂單模板
    private static function get_default_cancelled_message_template() {
        return "⚠️ 訂單已取消通知\n" .
            "訂單編號: [order-id]\n" .
            "訂購人: [billing_last_name][billing_first_name]\n" .
            "取消訂單項目:\n[order-product]\n" .
            "訂單金額: [total] 元";
    }

    /**
     * 發送簡單測試訊息以驗證 Access Token 和 Group ID
     *
     * @return array 包含狀態和訊息的陣列
     */
    public static function send_test_message() {
        $options = get_option('woo_line_settings');
        $channel_access_token = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN') ? WOO_LINE_CHANNEL_ACCESS_TOKEN : (isset($options['channel_access_token']) ? $options['channel_access_token'] : '');
        $group_id = isset($options['group_id']) ? $options['group_id'] : '';
        $enable_logging = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';

        if (empty($channel_access_token)) {
            return array(
                'status' => 'error',
                'message' => '請先設定 Channel Access Token。'
            );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $channel_access_token
        );
        $args = array(
            'headers' => $headers,
            'method' => 'GET'
        );
        $response = wp_remote_get('https://api.line.me/v2/bot/info', $args);
        
        if (is_wp_error($response)) {
            if ($enable_logging) {
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
            if ($enable_logging) {
                error_log('WooLine Test Message Error (API Response ' . $response_code . '): ' . $error_message);
            }
            return array(
                'status' => 'error',
                'message' => 'Channel Access Token 無效，請確認是否正確。'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $bot_name = isset($body['displayName']) ? $body['displayName'] : '您的 LINE Bot';

        if (empty($group_id)) {
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
        $message .= "3. Bot 確實是此群組成員\n";

        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $channel_access_token
        );

        $body = array(
            'to' => $group_id,
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
            if ($enable_logging) {
                error_log('WooLine Test Message Error (Push wp_remote_post): ' . $response->get_error_message());
            }
            return array(
                'status' => 'error',
                'message' => '發送測試訊息失敗：' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $push_error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
            if ($enable_logging) {
                error_log('WooLine Test Message Error (Push API Response ' . $response_code . '): ' . $push_error_message);
            }
            return array(
                'status' => 'error',
                'message' => '發送測試訊息失敗 (API ' . $response_code . ')：' . $push_error_message
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
        $options = get_option('woo_line_settings');
        $enable_logging = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';

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

            self::send_notification($order_id, 'test_latest_order');

            return array(
                'status' => 'success',
                'message' => '已成功使用最新訂單 (ID: ' . $order_id . ') 的資料發送測試訊息到指定的群組。'
            );

        } catch (Exception $e) {
            if ($enable_logging) {
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
        $options = get_option('woo_line_settings');
        $channel_secret = defined('WOO_LINE_CHANNEL_SECRET') ? WOO_LINE_CHANNEL_SECRET : (isset($options['channel_secret']) ? $options['channel_secret'] : '');
        $enable_logging = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';

        if (empty($channel_secret)) {
            self::log_webhook_event('error', 'Channel Secret 未設定，無法驗證 Webhook 簽名。', $enable_logging);
            return new WP_REST_Response(array('message' => 'Channel Secret not configured'), 400);
        }

        $signature = $request->get_header('X-Line-Signature');
        $body = $request->get_body();

        if (empty($signature)) {
            self::log_webhook_event('error', 'Webhook 請求缺少 X-Line-Signature。', $enable_logging);
            return new WP_REST_Response(array('message' => 'Signature not found'), 400);
        }

        // 驗證簽名
        $hash = hash_hmac('sha256', $body, $channel_secret, true);
        $calculated_signature = base64_encode($hash);

        if ($signature !== $calculated_signature) {
            self::log_webhook_event('error', 'Webhook 簽名驗證失敗。', $enable_logging);
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
                    self::log_webhook_event('info', '收到來自 Group ID [' . $group_id . '] 的事件: [' . $event_type . ']', $enable_logging);
                    // 如果是 join 事件或 message 事件，且群組尚未記錄，則嘗試獲取群組名稱並儲存
                    if (($event_type === 'join' || $event_type === 'message') && !isset($current_groups[$group_id])) {
                        $group_name = self::get_group_name($group_id);
                        if ($group_name) {
                            $current_groups[$group_id] = $group_name;
                            $updated = true;
                            self::log_webhook_event('info', '已成功記錄新的 Group ID [' . $group_id . ']，名稱: [' . $group_name . ']。', $enable_logging);
                        } else {
                             self::log_webhook_event('warning', '無法獲取 Group ID [' . $group_id . '] 的名稱。', $enable_logging);
                        }
                    }
                } elseif ($event_type === 'leave' && $source_type === 'group') {
                    $group_id_left = isset($event['source']['groupId']) ? $event['source']['groupId'] : null;
                    if ($group_id_left && isset($current_groups[$group_id_left])) {
                        unset($current_groups[$group_id_left]);
                        $updated = true;
                        self::log_webhook_event('info', 'Bot 已離開 Group ID [' . $group_id_left . ']，已從記錄中移除。', $enable_logging);
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
        $options = get_option('woo_line_settings');
        $channel_access_token = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN') ? WOO_LINE_CHANNEL_ACCESS_TOKEN : (isset($options['channel_access_token']) ? $options['channel_access_token'] : '');
        $enable_logging = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';

        if (empty($channel_access_token)) {
            self::log_webhook_event('error', '嘗試獲取群組名稱失敗：Channel Access Token 未設定。', $enable_logging);
            return false;
        }

        $url = 'https://api.line.me/v2/bot/group/' . $group_id . '/summary';
        $headers = array(
            'Authorization' => 'Bearer ' . $channel_access_token
        );
        $args = array(
            'headers' => $headers,
            'method' => 'GET'
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            self::log_webhook_event('error', '獲取群組名稱 API 呼叫失敗 (Group ID: ' . $group_id . '): ' . $response->get_error_message(), $enable_logging);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return isset($body['groupName']) ? $body['groupName'] : ('群組 ' . $group_id);
        } else {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['message']) ? $response_body['message'] : '未知錯誤';
            self::log_webhook_event('error', '獲取群組名稱 API 回應錯誤 (Group ID: ' . $group_id . ', Code: ' . $response_code . '): ' . $error_message, $enable_logging);
            return false;
        }
    }
    
    /**
     * 記錄 Webhook 事件 (如果啟用日誌記錄)
     */
    private static function log_webhook_event($level, $message, $enable_logging) {
        if ($enable_logging) {
            error_log('WooLine Webhook [' . strtoupper($level) . ']: ' . $message);
        }
    }
}

// 初始化 API 類別
Woo_Line_Api::init(); 