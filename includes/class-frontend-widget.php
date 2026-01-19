<?php
/**
 * Frontend Chat Widget
 */
class AightBot_Frontend_Widget {
    
    private $settings;
    private $appearance_settings;
    
    public function __construct() {
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $this->appearance_settings = get_option(AIGHTBOT_OPTION_PREFIX . 'appearance_settings', []);
        $this->init_hooks();
        
        add_action('wp_head', [$this, 'add_security_headers'], 1);
        add_action('wp_head', [$this, 'output_custom_styles'], 20);
    }
    
    private function init_hooks() {
        add_action('wp_footer', [$this, 'render_chat_widget']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    public function add_security_headers() {
        if (!is_admin()) {
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: SAMEORIGIN");
            header("Referrer-Policy: strict-origin-when-cross-origin");
        }
    }
    
    /**
     * Output custom CSS styles based on appearance settings
     */
    public function output_custom_styles() {
        $primary = $this->appearance_settings['primary_color'] ?? '#667eea';
        $secondary = $this->appearance_settings['secondary_color'] ?? '#764ba2';
        $header_text = $this->appearance_settings['header_text_color'] ?? '#ffffff';
        $bot_bg = $this->appearance_settings['bot_message_bg'] ?? '#ffffff';
        $bot_text = $this->appearance_settings['bot_message_text'] ?? '#1f2937';
        $chat_bg = $this->appearance_settings['chat_background'] ?? '#f9fafb';
        $position = $this->appearance_settings['widget_position'] ?? 'right';
        
        ?>
        <style id="aightbot-custom-styles">
            .aightbot-widget {
                <?php echo $position === 'left' ? 'left: 20px; right: auto;' : 'right: 20px; left: auto;'; ?>
            }
            .aightbot-widget-toggle,
            .aightbot-widget-header,
            .aightbot-send-btn {
                background: linear-gradient(135deg, <?php echo esc_attr($primary); ?> 0%, <?php echo esc_attr($secondary); ?> 100%);
            }
            .aightbot-widget-toggle,
            .aightbot-widget-header,
            .aightbot-widget-header h3,
            .aightbot-widget-actions button,
            .aightbot-send-btn {
                color: <?php echo esc_attr($header_text); ?>;
            }
            .aightbot-user-message .aightbot-message-content {
                background: linear-gradient(135deg, <?php echo esc_attr($primary); ?> 0%, <?php echo esc_attr($secondary); ?> 100%);
                color: <?php echo esc_attr($header_text); ?>;
            }
            .aightbot-widget-messages {
                background: <?php echo esc_attr($chat_bg); ?>;
            }
            .aightbot-bot-message .aightbot-message-content {
                background: <?php echo esc_attr($bot_bg); ?>;
                color: <?php echo esc_attr($bot_text); ?>;
            }
            .aightbot-widget-input input:focus {
                border-color: <?php echo esc_attr($primary); ?>;
                box-shadow: 0 0 0 3px <?php echo esc_attr($primary); ?>1a;
            }
            .aightbot-bot-message .aightbot-message-content a {
                color: <?php echo esc_attr($primary); ?>;
            }
            .aightbot-bot-message .aightbot-message-content blockquote {
                border-left-color: <?php echo esc_attr($primary); ?>;
            }
            <?php if ($position === 'left'): ?>
            .aightbot-widget-window {
                right: auto;
                left: 0;
            }
            @media (max-width: 480px) {
                .aightbot-widget-toggle {
                    margin-left: 0;
                    margin-right: auto;
                }
            }
            <?php endif; ?>
        </style>
        <?php
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // CSS
        wp_enqueue_style(
            'aightbot-widget',
            AIGHTBOT_PLUGIN_URL . 'assets/css/widget-style.css',
            [],
            AIGHTBOT_VERSION
        );
        
        // JavaScript (no external dependencies)
        wp_enqueue_script(
            'aightbot-widget',
            AIGHTBOT_PLUGIN_URL . 'assets/js/widget-script.js',
            ['jquery'],
            AIGHTBOT_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('aightbot-widget', 'aightbotWidget', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aightbot_chat_nonce'),
            'bot_name' => $this->settings['bot_name'] ?? 'AightBot',
            'starter_message' => $this->settings['starter_message'] ?? '',
            'strings' => [
                'placeholder' => __('Type your message...', 'aightbot'),
                'send' => __('Send', 'aightbot'),
                'error' => __('An error occurred. Please try again.', 'aightbot'),
                'connecting' => __('Connecting...', 'aightbot'),
                'new_chat' => __('New Chat', 'aightbot'),
            ]
        ]);
    }
    
    /**
     * Render chat widget HTML
     */
    public function render_chat_widget() {
        $bot_name = esc_html($this->settings['bot_name'] ?? 'AightBot');
        ?>
        <div id="aightbot-widget" class="aightbot-widget" aria-live="polite">
            <div class="aightbot-widget-toggle" title="<?php echo esc_attr(sprintf(__('Open %s', 'aightbot'), $bot_name)); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            
            <div class="aightbot-widget-window">
                <div class="aightbot-widget-header">
                    <h3><?php echo esc_html($bot_name); ?></h3>
                    <div class="aightbot-widget-actions">
                        <button type="button" class="aightbot-new-chat" title="<?php esc_attr_e('Start new conversation', 'aightbot'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                        </button>
                        <button type="button" class="aightbot-widget-close" title="<?php esc_attr_e('Close', 'aightbot'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="aightbot-widget-messages" role="log" aria-label="<?php esc_attr_e('Chat messages', 'aightbot'); ?>">
                    <!-- Initial message will be added by JavaScript after marked.js loads -->
                </div>
                
                <div class="aightbot-widget-input">
                    <form id="aightbot-chat-form">
                        <input 
                            type="text" 
                            id="aightbot-message-input" 
                            placeholder="<?php esc_attr_e('Type your message...', 'aightbot'); ?>"
                            autocomplete="off"
                            aria-label="<?php esc_attr_e('Message input', 'aightbot'); ?>"
                        >
                        <button type="submit" class="aightbot-send-btn" aria-label="<?php esc_attr_e('Send message', 'aightbot'); ?>">
                            ➤
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
