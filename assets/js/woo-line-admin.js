document.addEventListener('DOMContentLoaded', function() {

    // --- Group ID Clear Button Logic ---
    const clearGroupIdButton = document.getElementById('clear_group_id_button');
    const groupIdSelect = document.getElementById('woo_line_group_id_select');
    const clearGroupIdFlag = document.getElementById('clear_group_id_flag');

    if (clearGroupIdButton && groupIdSelect && clearGroupIdFlag && wooLineAdminData && wooLineAdminData.l10n) {
        clearGroupIdButton.addEventListener('click', function(e) {
            if (confirm(wooLineAdminData.l10n.confirmClearGroupId)) {
                groupIdSelect.value = '';
                clearGroupIdFlag.value = '1';
                this.disabled = true;
                alert(wooLineAdminData.l10n.groupIdCleared);
            }
        });

        groupIdSelect.addEventListener('change', function() {
            const shouldDisableButton = (this.value === '');
            clearGroupIdButton.disabled = shouldDisableButton;
            if (this.value !== '') {
                clearGroupIdFlag.value = '0';
            }
        });
    }

    // --- Shortcode List Toggle and Copy Logic ---
    const shortcodeCategories = document.querySelectorAll('.shortcode-category .category-title');
    shortcodeCategories.forEach(title => {
        title.addEventListener('click', function() {
            const categoryId = this.dataset.categoryId;
            if (categoryId) {
                toggleCategory(categoryId);
            }
        });
    });

    const shortcodeCodes = document.querySelectorAll('.shortcode-code');
    shortcodeCodes.forEach(codeElement => {
        codeElement.addEventListener('click', function() {
            const shortcode = this.dataset.shortcode;
            if (shortcode) {
                copyShortcode(this, shortcode);
            }
        });
    });

    // --- Webhook URL Copy Logic ---
    const webhookUrlElement = document.getElementById('webhook-url');
    if (webhookUrlElement && wooLineAdminData && wooLineAdminData.l10n) {
        webhookUrlElement.addEventListener('click', function() {
            copyWebhookUrl(this);
        });
    }

});

function toggleCategory(categoryId) {
    const content = document.getElementById(categoryId + '-content');
    const icon = document.getElementById(categoryId + '-icon');
    if (content && icon) {
        if (content.style.display === 'none') {
            content.style.display = 'grid';
            icon.textContent = '-';
        } else {
            content.style.display = 'none';
            icon.textContent = '+';
        }
    }
}

function copyShortcode(element, shortcode) {
    navigator.clipboard.writeText(shortcode).then(() => {
        const originalText = element.textContent;
        const originalBackground = element.style.backgroundColor;
        const originalColor = element.style.color;

        element.textContent = wooLineAdminData.l10n.copied; // Use localized string
        element.style.backgroundColor = '#d1e7dd';
        element.style.color = '#0f5132';

        setTimeout(() => {
            element.textContent = originalText;
            element.style.backgroundColor = originalBackground || ''; // Revert or remove
            element.style.color = originalColor || '';
        }, 1500);
    }).catch(err => {
        console.error('無法複製簡碼: ', err);
        // Optionally provide feedback to the user about the failure
    });
}

function copyWebhookUrl(element) {
    const url = element.textContent.trim();
    const tooltip = document.getElementById('webhook-copy-tooltip');

    navigator.clipboard.writeText(url).then(() => {
        const originalBackground = element.style.backgroundColor;
        element.style.backgroundColor = '#e2e4e7';

        if (tooltip) {
            tooltip.textContent = wooLineAdminData.l10n.copied; // Use localized string
            tooltip.style.display = 'block';
        }

        setTimeout(() => {
            element.style.backgroundColor = originalBackground || '';
             if (tooltip) {
                 tooltip.style.display = 'none';
             }
        }, 2000);
    }).catch(err => {
        console.error('無法複製 Webhook URL: ', err);
        if (tooltip) {
            tooltip.textContent = wooLineAdminData.l10n.copyFailed; // Use localized string
            tooltip.style.display = 'block';
            tooltip.style.backgroundColor = '#f8d7da';
            tooltip.style.color = '#842029';
             setTimeout(() => {
                 tooltip.style.display = 'none';
                 tooltip.style.backgroundColor = ''; // Reset style
                 tooltip.style.color = '';
             }, 3000);
        }
    });
} 