# WooCommerce LINE 訂單通知

當 WooCommerce 有新訂單或訂單取消時，透過 LINE Messaging API 發送通知至指定的 LINE 群組。

## ✨ 功能

*   新訂單建立時發送通知。
*   訂單狀態變更為「已取消」時發送通知。
*   支援自訂通知訊息模板，可使用訂單相關的簡碼。
*   透過 Webhook 自動偵測可用的 LINE 群組。
*   優先使用 `wp-config.php` 中的常數設定 API 金鑰，提高安全性。

## 📋 需求

*   WordPress
*   WooCommerce 已安裝並啟用。
*   LINE Developer 帳號及一個 Messaging API Channel。

## 🚀 安裝

1.  下載此外掛的 `.zip` 檔。
2.  在 WordPress 後台，前往「外掛」>「安裝外掛」>「上傳外掛」。
3.  選擇下載的 `.zip` 檔並上傳。
4.  啟用此外掛。

## ⚙️ 設定

啟用外掛後，請進行以下設定：

1.  **取得 LINE API 金鑰**:
    *   前往 [LINE Developers Console](https://developers.line.biz/console/)。
    *   建立或選擇一個 Messaging API Channel。
    *   取得您的 **Channel Secret** 和一個 **Channel Access Token** (long-lived)。

2.  **設定 API 金鑰 (二選一)**:
    *   **(建議)** 在 `wp-config.php` 中加入 (放在 `/* That's all... */` 前面)：
        ```php
        define('WOO_LINE_CHANNEL_ACCESS_TOKEN', '你的存取權杖');
        define('WOO_LINE_CHANNEL_SECRET', '你的頻道密鑰');
        ```
    *   或在 WordPress 後台「設定」>「LINE 通知設定」頁面填寫。

3.  **設定 Webhook URL**:
    *   在「LINE 通知設定」頁面複製「Webhook URL」。
    *   貼到 LINE Developers Console 的 Messaging API Channel 設定中的「Webhook URL」欄位。
    *   啟用「Use webhook」。

4.  **讓外掛偵測群組**:
    *   將您的 LINE Bot 加入目標 LINE 群組。
    *   在該群組中**發送任意訊息**。
    *   <span style="color:red;">**重要提醒：**</span> 請務必到 [LINE Official Account Manager](https://manager.line.biz/) > 您的官方帳號 > 「設定」 > 「帳號設定」 > 「功能設定」 > 確認「允許被加入群組或多人聊天室」的選項是 **開啟** 的，否則您將無法將 Bot 加入群組。

5.  **選擇目標群組**:
    *   回到「LINE 通知設定」頁面。
    *   在「LINE 群組 ID」下拉選單中選擇您要接收通知的群組。

6.  **選擇通知時機**:
    *   勾選「新訂單建立時」和/或「訂單取消時」。

7.  **自訂訊息 (可選)**:
    *   修改「新訂單通知模板」和「取消訂單通知模板」。

8.  **啟用除錯紀錄 (可選)**:
    *   勾選「啟用除錯紀錄」選項，會將外掛運作時的詳細錯誤訊息記錄到伺服器的錯誤記錄檔中。這在排查問題時很有用，但在正常運作下建議保持關閉。

9.  **儲存設定**: 如果您在設定頁面做了任何修改，請點擊「儲存設定」。

10. **測試**: 點擊「發送簡單測試訊息」或「使用最新訂單測試」按鈕確認設定是否成功。

## 🛠️ 疑難排解

*   **收不到通知**:
    *   檢查 Channel Access Token、Channel Secret 和 Group ID 是否正確設定。
    *   確認 Webhook URL 已正確填入 LINE Developers Console 且已啟用。
    *   確認 Bot 確實是群組成員。
    *   確認測試訊息是否能成功發送。
*   **群組 ID 下拉選單是空的**:
    *   確認 Bot 已加入群組。
    *   確認您已在該群組中發送過訊息。
    *   稍待片刻，然後重新整理設定頁面。

## 📜 更新日誌 (Changelog)

### 1.1.7 (2025-03-30)
*   **新增**: 新增 `[shipping-method]` 簡碼，可在通知模板中顯示訂單的運送方式名稱。

### 1.1.6 (2025-03-30)
*   **改進**: JavaScript 分離。

### 1.1.5 (2025-03-30)
*   **修正**: 解決在剛儲存設定或沒有新訂單產生時，「使用最新訂單測試」按鈕可能因設定載入不及時而無法成功發送通知的問題。現在測試功能會即時讀取最新設定。

### 1.1.4 (2025-03-30)
*   **新增**: 在設定頁面加入「維護工具」，提供「清除已儲存群組列表」功能，方便用戶在需要時重置外掛自動儲存的群組紀錄。 