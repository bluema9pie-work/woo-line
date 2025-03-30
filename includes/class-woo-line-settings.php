<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 管理外掛設定頁面和選項
 */
class Woo_Line_Settings {

    private $options;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        $this->options = get_option('woo_line_settings');
    }

    /**
     * 註冊後台選單頁面
     */
    public function add_admin_menu() {
        add_options_page(
            'WooCommerce LINE 通知設定', 
            'LINE 通知設定',
            'manage_options',
            'woo_line_settings',
            array($this, 'options_page')
        );
    }

    /**
     * 初始化外掛設定欄位和區段
     */
    public function settings_init() {
        register_setting(
            'woo_line_settings', 
            'woo_line_settings',
            array('sanitize_callback' => array($this, 'sanitize_settings'))
        );

        add_settings_section(
            'woo_line_settings_section',
            '設定 LINE Messaging API',
            array($this, 'settings_section_callback'),
            'woo_line_settings'
        );

        $fields = array(
            'channel_access_token' => array(
                'title' => 'Channel Access Token',
                'callback' => array($this, 'channel_access_token_render')
            ),
            'channel_secret' => array(
                'title' => 'Channel Secret',
                'callback' => array($this, 'channel_secret_render')
            ),
            'group_id' => array(
                'title' => 'LINE 群組 ID',
                'callback' => array($this, 'group_id_render')
            ),
            'notification_triggers' => array(
                'title' => '通知觸發條件',
                'callback' => array($this, 'notification_triggers_render')
            ),
            'message_template' => array(
                'title' => '新訂單通知模板',
                'callback' => array($this, 'message_template_render')
            ),
            'cancelled_message_template' => array(
                'title' => '取消訂單通知模板',
                'callback' => array($this, 'cancelled_message_template_render')
            ),
            'enable_logging' => array(
                'title' => '啟用除錯紀錄',
                'callback' => array($this, 'enable_logging_render')
            )
        );

        foreach ($fields as $id => $field) {
            add_settings_field(
                $id,
                $field['title'],
                $field['callback'],
                'woo_line_settings',
                'woo_line_settings_section'
            );
        }
    }

    public function settings_section_callback() {
        echo '請輸入您的 LINE Messaging API 相關設定';
    }

    public function channel_access_token_render() {
        $disabled = defined('WOO_LINE_CHANNEL_ACCESS_TOKEN');
        $value = $disabled ? '**********' : (isset($this->options['channel_access_token']) ? esc_attr($this->options['channel_access_token']) : '');
        ?>
        <input type='text' name='woo_line_settings[channel_access_token]' value='<?php echo $value; ?>' <?php disabled($disabled, true); ?>>
        <?php if ($disabled): ?>
            <p class="description">
                <?php 
                printf(
                    esc_html__( '已在 %s 常數中定義。若要更改，請修改您的 wp-config.php 檔案。', 'woo-line-notification' ),
                    '<code>WOO_LINE_CHANNEL_ACCESS_TOKEN</code>'
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public function channel_secret_render() {
        $disabled = defined('WOO_LINE_CHANNEL_SECRET');
        $value = $disabled ? '**********' : (isset($this->options['channel_secret']) ? esc_attr($this->options['channel_secret']) : '');
        ?>
        <input type='text' name='woo_line_settings[channel_secret]' value='<?php echo $value; ?>' <?php disabled($disabled, true); ?>>
        <?php if ($disabled): ?>
            <p class="description">
                 <?php 
                printf(
                    esc_html__( '已在 %s 常數中定義。若要更改，請修改您的 wp-config.php 檔案。', 'woo-line-notification' ),
                    '<code>WOO_LINE_CHANNEL_SECRET</code>'
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public function group_id_render() {
        $groups = get_option('woo_line_groups', array());
        $current_group_id = isset($this->options['group_id']) ? $this->options['group_id'] : '';
        ?>
        <select name='woo_line_settings[group_id]' id='woo_line_group_id_select'>
            <option value=''><?php _e('請選擇群組', 'woo-line-notification'); ?></option>
            <?php foreach ($groups as $group_id => $group_name): ?>
                <option value='<?php echo esc_attr($group_id); ?>' <?php selected($current_group_id, $group_id); ?>>
                    <?php echo esc_html($group_name); ?> (<?php echo esc_html($group_id); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="clear_group_id_button" class="button" style="margin-left: 10px;" <?php disabled(empty($current_group_id)); ?>>
            <?php _e('清除選擇', 'woo-line-notification'); ?>
        </button>
        <input type="hidden" name="woo_line_settings[clear_group_id]" id="clear_group_id_flag" value="0">

        <p class="description"><?php _e('當 Bot 被加入群組時，群組會自動出現在這裡。如果沒有看到群組，請確保：', 'woo-line-notification'); ?></p>
        <ol>
            <li><?php _e('已設定好 Channel Secret', 'woo-line-notification'); ?></li>
            <li><?php _e('已在 LINE Developers 設定 Webhook URL', 'woo-line-notification'); ?></li>
            <li><?php _e('已將 Bot 加入目標群組', 'woo-line-notification'); ?></li>
            <li><?php _e('在群組中發送一則訊息', 'woo-line-notification'); ?></li>
        </ol>
        <?php
    }

    public function notification_triggers_render() {
        $triggers = isset($this->options['notification_triggers']) ? $this->options['notification_triggers'] : array('new_order');
        ?>
        <div>
            <label>
                <input type="checkbox" name="woo_line_settings[notification_triggers][]" value="new_order"
                    <?php checked(in_array('new_order', $triggers)); ?>>
                新訂單建立時發送通知
            </label>
            <label>
                <input type="checkbox" name="woo_line_settings[notification_triggers][]" value="order_cancelled"
                    <?php checked(in_array('order_cancelled', $triggers)); ?>>
                訂單取消時發送通知
            </label>
        </div>
        <p class="description">選擇要在哪些情況下發送 LINE 通知</p>
        <?php
    }

    public function cancelled_message_template_render() {
        $default_cancelled_template = "⚠️ 訂單已取消通知\n" .
            "訂單編號: [order-id]\n" .
            "訂購人: [billing_last_name][billing_first_name]\n" .
            "取消訂單項目:\n[order-product]\n" .
            "訂單金額: [total] 元";
        
        $template = isset($this->options['cancelled_message_template']) ? $this->options['cancelled_message_template'] : $default_cancelled_template;
        ?>
        <div class="message-template-container">
            <div class="template-editor">
                <div class="shortcodes-header">
                    <h3>📝 取消訂單通知模板編輯</h3>
                </div>
                <textarea name='woo_line_settings[cancelled_message_template]' rows='10'><?php echo esc_textarea($template); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function enable_logging_render() {
        $checked = isset($this->options['enable_logging']) && $this->options['enable_logging'] === 'yes';
        ?>
        <input type="hidden" name="woo_line_settings[enable_logging]" value="no"> 
        <input type="checkbox" id="enable_logging" name="woo_line_settings[enable_logging]" value="yes" <?php checked($checked, true); ?>>
        <label for="enable_logging">啟用記錄功能</label>
        <p class="description">勾選後，外掛執行時的錯誤和詳細資訊將會被記錄到伺服器的錯誤記錄檔中。請只在除錯時啟用。</p>
        <?php
    }

    /**
     * 清理和驗證設定選項
     * @param array $input 使用者提交的設定值
     * @return array 清理過的設定值
     */
    public function sanitize_settings($input) {
        $new_input = array();

        $clear_group_id = isset($_POST['woo_line_settings']['clear_group_id']) ? $_POST['woo_line_settings']['clear_group_id'] : '0';
        
        if (isset($input['channel_access_token']) && !defined('WOO_LINE_CHANNEL_ACCESS_TOKEN')) {
            $new_input['channel_access_token'] = sanitize_text_field($input['channel_access_token']);
        } else {
            $existing_options = get_option('woo_line_settings');
            $new_input['channel_access_token'] = isset($existing_options['channel_access_token']) ? $existing_options['channel_access_token'] : '';
        }

        if (isset($input['channel_secret']) && !defined('WOO_LINE_CHANNEL_SECRET')) {
             $new_input['channel_secret'] = sanitize_text_field($input['channel_secret']);
        } else {
            $existing_options = get_option('woo_line_settings');
             $new_input['channel_secret'] = isset($existing_options['channel_secret']) ? $existing_options['channel_secret'] : '';
        }

        if ($clear_group_id === '1') {
            $new_input['group_id'] = ''; 
        } elseif (isset($input['group_id'])) {
             $new_input['group_id'] = sanitize_text_field($input['group_id']);
        } else {
            $new_input['group_id'] = '';
        }
        
        if (isset($input['notification_triggers']) && is_array($input['notification_triggers'])) {
            $new_input['notification_triggers'] = array_map('sanitize_text_field', $input['notification_triggers']);
        } else {
            $new_input['notification_triggers'] = array();
        }

        if (isset($input['message_template'])) {
            $new_input['message_template'] = sanitize_textarea_field($input['message_template']);
        } else {
             $new_input['message_template'] = $this->get_default_message_template();
        }

        if (isset($input['cancelled_message_template'])) {
             $new_input['cancelled_message_template'] = sanitize_textarea_field($input['cancelled_message_template']);
        } else {
             $new_input['cancelled_message_template'] = $this->get_default_cancelled_message_template();
        }
        
        $new_input['enable_logging'] = (isset($input['enable_logging']) && $input['enable_logging'] === 'yes') ? 'yes' : 'no';

        return $new_input;
    }
    
    private function get_default_message_template() {
        return "🔔 您有新訂單！\\n訂單編號: [order-id]\\n訂購時間: [order-time]\\n訂購人: [order-name]\\n訂購項目:\\n[order-product]\\n付款方式: [payment-method]\\n總金額: [total] 元\\n訂單備註: [customer_note]";
    }
    
    private function get_default_cancelled_message_template() {
        return "⚠️ 訂單已取消通知\\n訂單編號: [order-id]\\n訂購人: [billing_last_name][billing_first_name]\\n取消訂單項目:\\n[order-product]\\n訂單金額: [total] 元";
    }

    /**
     * 取得所有可用於訊息模板中的欄位 (包含從最新訂單動態讀取的欄位)
     */
    private function get_available_fields() {
        $fields = array(
            '預設項目' => array(
                '[order-id]' => array(
                    '說明' => '訂單編號',
                    '範例' => '123'
                ),
                '[order-time]' => array(
                    '說明' => '訂購時間',
                    '範例' => date('Y-m-d H:i:s')
                ),
                '[order-name]' => array(
                    '說明' => '訂購人',
                    '範例' => '王小明'
                ),
                '[order-product]' => array(
                    '說明' => '訂購項目',
                    '範例' => "商品A x 2\\n商品B x 1"
                ),
                '[payment-method]' => array(
                    '說明' => '付款方式',
                    '範例' => '信用卡付款'
                ),
                '[shipping-method]' => array(
                    '說明' => '運送方式',
                    '範例' => '統一費率'
                ),
                '[total]' => array(
                    '說明' => '訂單總金額 (格式化)',
                    '範例' => '1,234'
                ),
                '[customer_note]' => array(
                    '說明' => '訂單備註',
                    '範例' => '請於12點前送達'
                )
            )
        );

        $billing_fields = array();
        $shipping_fields = array();
        $additional_fields = array();

        // 嘗試取得最新的訂單來分析其他可用欄位
        try {
            $orders = wc_get_orders(array(
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses())
            ));

            if (!empty($orders)) {
                $order = $orders[0];

                if (!$order instanceof WC_Order) {
                    return $fields;
                }

                $order_data = $order->get_data();

                // 帳單欄位
                if (isset($order_data['billing']) && is_array($order_data['billing'])) {
                    $billing_data = $order_data['billing'];
                    foreach ($billing_data as $key => $value) {
                        if (is_scalar($value) && $value !== '') {
                            $billing_fields['[billing_' . $key . ']'] = array(
                                '說明' => '帳單 ' . ucfirst($key),
                                '範例' => $value
                            );
                        }
                    }
                }
                if (!empty($billing_fields)) {
                    $fields['購買人欄位'] = $billing_fields;
                }

                // 運送欄位
                if (isset($order_data['shipping']) && is_array($order_data['shipping'])) {
                    $shipping_data = $order_data['shipping'];
                    foreach ($shipping_data as $key => $value) {
                        if (is_scalar($value) && $value !== '') {
                            $shipping_fields['[shipping_' . $key . ']'] = array(
                                '說明' => '運送 ' . ucfirst($key),
                                '範例' => $value
                            );
                        }
                    }
                }
                if (!empty($shipping_fields)) {
                    $fields['收件人欄位'] = $shipping_fields;
                }

                // 自訂欄位 (Order Meta)
                $meta_data_array = $order->get_meta_data();
                if (is_array($meta_data_array)) {
                    foreach ($meta_data_array as $meta) {
                        if (!$meta instanceof WC_Meta_Data) continue;

                        $current_meta_data = $meta->get_data(); 
                        if (!is_array($current_meta_data) || !isset($current_meta_data['key']) || !isset($current_meta_data['value'])) continue;

                        $meta_key = $current_meta_data['key'];
                        $meta_value = $current_meta_data['value'];

                        // 排除內部或重複欄位
                        if (is_scalar($meta_value) && $meta_value !== '' &&
                            strpos($meta_key, '_') !== 0 &&
                            !isset($additional_fields['[' . $meta_key . ']']) &&
                            !isset($billing_fields['[billing_' . $meta_key . ']']) && 
                            !isset($shipping_fields['[shipping_' . $meta_key . ']'])) { 

                            $additional_fields['[' . $meta_key . ']'] = array(
                                '說明' => str_replace('_', ' ', ucfirst($meta_key)),
                                '範例' => $meta_value 
                            );
                        }
                    }
                }

                if (!empty($additional_fields)) {
                    $fields['自訂欄位 (來自訂單 Meta)'] = $additional_fields;
                }
            }

        } catch (Exception $e) {
            $options = get_option('woo_line_settings');
            if (isset($options['enable_logging']) && $options['enable_logging'] === 'yes') {
                error_log('WooLine Settings Error (get_available_fields): Exception caught. Error: ' . $e->getMessage());
            }
            // 發生錯誤時返回基礎欄位
            return array(
                '預設項目' => $fields['預設項目']
            );
        }

        return $fields;
    }

    /**
     * 渲染訊息模板編輯器 (包含簡碼列表)
     */
    public function message_template_render() {
        $default_template = "🔔叮咚！有一筆新的訂單！\n" .
            "訂單編號: [order-id]\n" .
            "訂購時間: [order-time]\n" .
            "訂購人: [billing_last_name][billing_first_name]\n" .
            "訂購項目:\n[order-product]\n" .
            "付款方式: [payment-method]\n" .
            "總金額: [total] 元";
        
        $template = isset($this->options['message_template']) ? $this->options['message_template'] : $default_template;
        ?>
        <div class="message-template-container">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="template-editor">
                    <div class="shortcodes-header">
                        <h3>📝 訊息模板編輯</h3>
                    </div>
                    <textarea name='woo_line_settings[message_template]' rows='20'><?php echo esc_textarea($template); ?></textarea>
                    <p class="description">在模板中使用簡碼來插入訂單資料。點擊右側的簡碼可直接複製。</p>
                    <p class="description" style="color: #d63638;">注意：「購買人欄位」、「收件人欄位」、「訂單額外欄位」和「自訂欄位」需要有訂單資料後才會顯示完整的可用簡碼。</p>
                </div>

                <div class="shortcodes-container">
                    <div class="shortcodes-header">
                        <h3>🔍 可用簡碼列表</h3>
                        <p class="description" style="margin: 5px 0 0 0; padding: 0;">預設項目永遠可用，其他欄位需要有訂單資料後才會顯示。</p>
                    </div>
                    <div id="shortcodesList">
                        <?php
                        $fields = $this->get_available_fields();
                        foreach ($fields as $category => $items) {
                            $category_id = sanitize_title($category);
                            ?>
                            <div class="shortcode-category">
                                <div class="category-title" data-category-id="<?php echo esc_attr($category_id); ?>">
                                    <h4><?php echo esc_html($category); ?></h4>
                                    <span class="toggle-icon" id="<?php echo $category_id; ?>-icon">-</span>
                                </div>
                                <div class="shortcode-grid" id="<?php echo $category_id; ?>-content">
                                    <?php
                                    foreach ($items as $code => $info) {
                                        ?>
                                        <div class="shortcode-item">
                                            <div class="shortcode-name"><?php echo esc_html($info['說明']); ?></div>
                                            <div class="shortcode-code" data-shortcode="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></div>
                                            <div class="shortcode-example">範例：<?php echo esc_html($info['範例']); ?></div>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染設定頁面整體結構
     */
    public function options_page() {
        // 處理清除已儲存群組列表的請求
        if (isset($_POST['clear_stored_groups']) && check_admin_referer('clear_stored_groups_action', 'clear_groups_nonce')) {
            if (delete_option('woo_line_groups')) {
                $this->display_admin_notice('success', __('已成功清除所有已儲存的群組列表紀錄。', 'woo-line-notification'));
            } else {
                $this->display_admin_notice('error', __('清除已儲存的群組列表紀錄時發生錯誤，或目前沒有儲存任何群組。', 'woo-line-notification'));
            }
        }
        
        // 處理測試訊息發送
        if (isset($_POST['send_test_message']) && check_admin_referer('send_test_message', 'test_message_nonce')) {
            $test_result = Woo_Line_Api::send_test_message();
            $this->display_admin_notice($test_result['status'], $test_result['message']);
        }

        if (isset($_POST['send_latest_order_test']) && check_admin_referer('send_latest_order_test', 'latest_order_test_nonce')) {
            $test_result = Woo_Line_Api::send_latest_order_test();
            $this->display_admin_notice($test_result['status'], $test_result['message']);
        }
        ?>
        <div class="wrap">
            <h2>WooCommerce LINE 通知設定</h2>
            
            <form action='options.php' method='post'>
                <?php
                settings_fields('woo_line_settings');
                do_settings_sections('woo_line_settings');
                submit_button();
                ?>
            </form>

            <hr>
            <h3>🔗 Webhook URL 設定說明</h3>
            <p>請在 LINE Developers Console 中設定以下 Webhook URL：</p>
            <div class="webhook-url-container" style="position: relative;">
                <code id="webhook-url">
                    <?php echo esc_url(get_rest_url(null, 'woo-line/v1/webhook')); ?>
                </code>
                <div id="webhook-copy-tooltip">已複製！</div>
            </div>
            <p class="description" style="margin-top: 5px; color: #646970;">點擊上方網址可直接複製</p>

            <h3>📝 設定步驟：</h3>
            <ol>
                <li>在 LINE Developers Console 中設定上方的 Webhook URL</li>
                <li>將 Channel Secret 填入設定</li>
                <li>將 Channel Access Token 填入設定</li>
                <li>將 Bot 加入目標群組</li>
                <li>在群組中發送一則訊息</li>
                <li>群組會自動出現在上方的下拉選單中</li>
                <li>選擇要接收通知的群組後儲存設定</li>
            </ol>

            <hr>
            <h3>🔔 測試通知</h3>
            <div>
                <form method="post" action="">
                    <?php wp_nonce_field('send_test_message', 'test_message_nonce'); ?>
                    <input type="submit" name="send_test_message" class="button button-secondary" value="發送簡單測試訊息">
                    <p class="description">發送一則簡單的測試訊息，確認連線是否正常。</p>
                </form>

                <form method="post" action="">
                    <?php wp_nonce_field('send_latest_order_test', 'latest_order_test_nonce'); ?>
                    <input type="submit" name="send_latest_order_test" class="button button-primary" value="使用最新訂單測試">
                    <p class="description">使用最新一筆訂單資料發送測試訊息，測試完整通知格式。</p>
                </form>
            </div>

            <hr> 
            <h3>⚙️ 維護工具</h3>
            <div>
                 <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('您確定要清除所有過去儲存的群組列表嗎？此操作無法復原，下拉選單將會被清空，需要重新讓 Bot 加入群組並發送訊息才會再次出現。', 'woo-line-notification')); ?>');">
                    <?php wp_nonce_field('clear_stored_groups_action', 'clear_groups_nonce'); ?>
                    <input type="submit" name="clear_stored_groups" class="button button-warning" value="清除已儲存群組列表">
                    <p class="description">如果您遇到群組列表顯示錯誤或需要重置，可以使用此按鈕清除所有外掛自動儲存的群組紀錄。</p>
                </form>
            </div>

        </div>
        <?php
    }

    private function display_admin_notice($status, $message) {
        $class = ($status === 'success') ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . $class . ' is-dismissible"><p>' . $message . '</p></div>';
    }
} 