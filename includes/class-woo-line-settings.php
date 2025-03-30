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
        register_setting('woo_line_settings', 'woo_line_settings');

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
        ?>
        <select name='woo_line_settings[group_id]'>
            <option value=''>請選擇群組</option>
            <?php foreach ($groups as $group_id => $group_name): ?>
                <option value='<?php echo esc_attr($group_id); ?>' <?php selected(isset($this->options['group_id']) ? $this->options['group_id'] : '', $group_id); ?>>
                    <?php echo esc_html($group_name); ?> (<?php echo esc_html($group_id); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">當 Bot 被加入群組時，群組會自動出現在這裡。如果沒有看到群組，請確保：</p>
        <ol>
            <li>已設定好 Channel Secret</li>
            <li>已在 LINE Developers 設定 Webhook URL</li>
            <li>已將 Bot 加入目標群組</li>
            <li>在群組中發送一則訊息</li>
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
                    '範例' => "商品A x 2\n商品B x 1"
                ),
                '[payment-method]' => array(
                    '說明' => '付款方式',
                    '範例' => '信用卡付款'
                ),
                '[total]' => array(
                    '說明' => '總金額',
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
                                <div class="category-title" onclick="toggleCategory('<?php echo $category_id; ?>')">
                                    <h4><?php echo esc_html($category); ?></h4>
                                    <span class="toggle-icon" id="<?php echo $category_id; ?>-icon">-</span>
                                </div>
                                <div class="shortcode-grid" id="<?php echo $category_id; ?>-content">
                                    <?php
                                    foreach ($items as $code => $info) {
                                        ?>
                                        <div class="shortcode-item">
                                            <div class="shortcode-name"><?php echo esc_html($info['說明']); ?></div>
                                            <div class="shortcode-code" onclick="copyShortcode(this, '<?php echo esc_attr($code); ?>')"><?php echo esc_html($code); ?></div>
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

        <script>
        function toggleCategory(categoryId) {
            const content = document.getElementById(categoryId + '-content');
            const icon = document.getElementById(categoryId + '-icon');
            if (content.style.display === 'none') {
                content.style.display = 'grid';
                icon.textContent = '-';
            } else {
                content.style.display = 'none';
                icon.textContent = '+';
            }
        }

        function copyShortcode(element, shortcode) {
            navigator.clipboard.writeText(shortcode);
            
            const originalText = element.textContent;
            const originalBackground = element.style.backgroundColor;

            element.textContent = '已複製！';
            element.style.background = '#d1e7dd'; 
            
            setTimeout(() => {
                element.textContent = originalText;
                element.style.background = originalBackground || '#f0f0f1';
            }, 1500);
        }
        </script>
        <?php
    }

    /**
     * 渲染設定頁面整體結構
     */
    public function options_page() {
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
                <code id="webhook-url" onclick="copyWebhookUrl(this)">
                    <?php echo esc_url(get_rest_url(null, 'woo-line/v1/webhook')); ?>
                </code>
                <div id="webhook-copy-tooltip">已複製！</div>
            </div>
            <p class="description" style="margin-top: 5px; color: #646970;">點擊上方網址可直接複製</p>

            <script>
            function copyWebhookUrl(element) {
                const url = element.textContent.trim();
                navigator.clipboard.writeText(url);
                
                const originalBackground = element.style.backgroundColor;
                element.style.backgroundColor = '#e2e4e7';
                
                const tooltip = document.getElementById('webhook-copy-tooltip');
                tooltip.style.display = 'block';
                
                setTimeout(() => {
                    element.style.backgroundColor = originalBackground;
                    tooltip.style.display = 'none';
                }, 2000);
            }
            </script>

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
        </div>
        <?php
    }

    private function display_admin_notice($status, $message) {
        $class = ($status === 'success') ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . $class . ' is-dismissible"><p>' . $message . '</p></div>';
    }
} 