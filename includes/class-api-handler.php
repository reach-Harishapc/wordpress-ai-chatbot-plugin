<?php
/**
 * API Handler for LLM Communication
 */
class AightBot_API_Handler {
    
    private $encryption;
    private $settings;
    private $logger;
    private $rag_handler;
    
    public function __construct($encryption) {
        $this->encryption = $encryption;
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        
        // Initialize logger with error handling
        try {
            $this->logger = new AightBot_Logger();
        } catch (Exception $e) {
            error_log('AightBot: Failed to initialize logger: ' . $e->getMessage());
            $this->logger = null;
        }
        
        // Initialize RAG handler
        try {
            $this->rag_handler = new AightBot_RAG_Handler();
        } catch (Exception $e) {
            error_log('AightBot: Failed to initialize RAG handler: ' . $e->getMessage());
            $this->rag_handler = null;
        }
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_aightbot_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_nopriv_aightbot_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_aightbot_create_session', [$this, 'ajax_create_session']);
        add_action('wp_ajax_nopriv_aightbot_create_session', [$this, 'ajax_create_session']);
    }
    
    /**
     * AJAX handler for creating new session
     */
    public function ajax_create_session() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_chat_nonce')) {
            wp_send_json_error(__('Security check failed', 'aightbot'));
        }
        
        $ip = $this->get_client_ip();
        $rate_limit_key = 'aightbot_session_create_' . md5($ip);
        $attempts = get_transient($rate_limit_key);
        
        if ($attempts && $attempts >= 10) {
            wp_send_json_error(__('Too many sessions created. Please wait an hour before trying again.', 'aightbot'));
        }
        
        global $wpdb;
        $total_sessions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}aightbot_sessions 
             WHERE last_active > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        
        $max_sessions = apply_filters('aightbot_max_active_sessions', 1000);
        if ($total_sessions >= $max_sessions) {
            wp_send_json_error(__('Service temporarily unavailable. Please try again later.', 'aightbot'));
        }
        
        $session_id = 'sess_' . bin2hex(random_bytes(32));
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
        
        // Attempt to create session in database for IP/UA tracking
        // If this fails, session will be created on first message instead
        $result = $wpdb->insert(
            $wpdb->prefix . 'aightbot_sessions',
            [
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'bot_name' => isset($this->settings['bot_name']) ? $this->settings['bot_name'] : 'AightBot',
                'history' => '[]',
                'created_at' => current_time('mysql'),
                'last_active' => current_time('mysql')
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            // Log for diagnostics but don't fail - session will be created on first message
            error_log('AightBot: Session pre-creation failed (will retry on first message) - ' . $wpdb->last_error);
        }
        
        set_transient($rate_limit_key, ($attempts ? $attempts + 1 : 1), HOUR_IN_SECONDS);
        
        wp_send_json_success([
            'session_id' => $session_id
        ]);
    }
    
    /**
     * AJAX handler for sending messages
     */
    public function ajax_send_message() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_chat_nonce')) {
            wp_send_json_error(__('Security check failed', 'aightbot'));
        }
        
        $message = isset($_POST['message']) ? trim($_POST['message']) : '';
        
        if (empty($message)) {
            wp_send_json_error(__('Message is required', 'aightbot'));
        }
        
        $message = sanitize_textarea_field($message);
        
        $max_length = apply_filters('aightbot_max_message_length', 10000);
        if (mb_strlen($message) > $max_length) {
            wp_send_json_error(
                sprintf(
                    __('Message is too long. Maximum %d characters allowed.', 'aightbot'),
                    $max_length
                )
            );
        }
        
        if (preg_match('/<script|javascript:|vbscript:|data:text\/html|on(click|load|error|mouse)/i', $message)) {
            error_log('AightBot: Suspicious message pattern detected from IP: ' . $this->get_client_ip());
            wp_send_json_error(__('Message contains invalid content.', 'aightbot'));
        }
        
        // Get session ID
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        if (!empty($session_id) && !preg_match('/^sess_[a-f0-9]{64}$/', $session_id)) {
            wp_send_json_error(__('Invalid session', 'aightbot'));
        }
        
        // Check rate limit
        if (!$this->check_rate_limit($session_id)) {
            wp_send_json_error(__('You\'re sending messages too quickly. Please wait a moment before trying again.', 'aightbot'));
        }
        
        try {
            // Get conversation history
            $history = $this->get_conversation_history($session_id);
            
            // If this is the first message and we have a starter message, inject it
            if (empty($history) && !empty($this->settings['starter_message'])) {
                $history[] = [
                    'role' => 'assistant',
                    'content' => $this->settings['starter_message']
                ];
            }
            
            // Add user message
            $history[] = [
                'role' => 'user',
                'content' => $message
            ];
            
            // Get user IP for logging
            $user_ip = $this->get_client_ip();
            
            // Log user message with IP
            if ($this->logger) {
                $this->logger->log_message($session_id, 'user', $message, $user_ip);
            }
            
            // Truncate context if needed (before sending to API)
            $history = $this->truncate_context($session_id, $history);
            
            // Send to API
            $response = $this->send_to_llm($history);
            
            // Log assistant response
            if ($this->logger) {
                $this->logger->log_message($session_id, 'assistant', $response);
            }
            
            // Add assistant response to history
            $history[] = [
                'role' => 'assistant',
                'content' => $response
            ];
            
            if (count($history) > 100) {
                $history = array_slice($history, -100);
            }
            
            $this->save_conversation_history($session_id, $history);
            
            // Record this request timestamp
            $this->record_request($session_id);
            
            wp_send_json_success([
                'message' => $response,
                'session_id' => $session_id
            ]);
            
        } catch (Exception $e) {
            error_log('AightBot Error [Session: ' . $session_id . ']: ' . $e->getMessage());
            
            $safe_message = __('Sorry, I encountered an error processing your message. Please try again.', 'aightbot');
            $safe_message = apply_filters('aightbot_user_error_message', $safe_message, $e);
            
            wp_send_json_error($safe_message);
        }
    }
    
    /**
     * Check if session has exceeded rate limit
     * 
     * @param string $session_id Session ID
     * @return bool True if within limit, false if exceeded
     */
    private function check_rate_limit($session_id) {
        if (empty($session_id)) {
            $ip = $this->get_client_ip();
            $key = 'aightbot_ratelimit_ip_' . md5($ip);
            $attempts = get_transient($key);
            $max_attempts = 5;
            $window = 300;
            
            if ($attempts && $attempts >= $max_attempts) {
                return false;
            }
            
            set_transient($key, ($attempts ? $attempts + 1 : 1), $window);
            return true;
        }
        
        $max_requests = isset($this->settings['rate_limit_requests']) ? (int)$this->settings['rate_limit_requests'] : 20;
        $time_window = isset($this->settings['rate_limit_window']) ? (int)$this->settings['rate_limit_window'] : 300;
        
        $timestamps = get_transient('aightbot_ratelimit_' . $session_id);
        
        if (!$timestamps || !is_array($timestamps)) {
            return true;
        }
        
        $current_time = time();
        $cutoff_time = $current_time - $time_window;
        $timestamps = array_filter($timestamps, function($ts) use ($cutoff_time) {
            return $ts > $cutoff_time;
        });
        
        return count($timestamps) < $max_requests;
    }
    
    /**
     * Record a request timestamp for rate limiting
     * 
     * @param string $session_id Session ID
     */
    private function record_request($session_id) {
        if (empty($session_id)) {
            return;
        }
        
        $time_window = isset($this->settings['rate_limit_window']) ? (int)$this->settings['rate_limit_window'] : 300;
        
        // Get existing timestamps
        $timestamps = get_transient('aightbot_ratelimit_' . $session_id);
        
        if (!$timestamps || !is_array($timestamps)) {
            $timestamps = [];
        }
        
        // Add current timestamp
        $timestamps[] = time();
        
        // Store with expiration = time window + buffer
        set_transient('aightbot_ratelimit_' . $session_id, $timestamps, $time_window + 60);
    }
    
    /**
     * Send request to LLM API
     * 
     * @param array $messages Conversation history
     * @return string Response from LLM
     * @throws Exception
     */
    private function send_to_llm($messages) {
        // Validate settings
        if (empty($this->settings['llm_url'])) {
            throw new Exception(__('LLM API is not configured', 'aightbot'));
        }
        
        // Prepare payload
        $payload = [
            'messages' => $messages
        ];
        
        // Add model if specified
        if (!empty($this->settings['model_name'])) {
            $payload['model'] = $this->settings['model_name'];
        }
        
        // Build system prompt with RAG enhancement
        $system_prompt = !empty($this->settings['system_prompt']) 
            ? $this->settings['system_prompt'] 
            : '';
        
        // Enhance with RAG context if enabled
        if ($this->rag_handler && $this->rag_handler->is_enabled()) {
            $user_message = '';
            foreach (array_reverse($messages) as $msg) {
                if ($msg['role'] === 'user') {
                    $user_message = $msg['content'];
                    break;
                }
            }
            
            if (!empty($user_message)) {
                $system_prompt = $this->rag_handler->get_enhanced_system_prompt(
                    $user_message, 
                    $system_prompt ?: 'You are a helpful assistant.'
                );
            }
        }
        
        if (!empty($system_prompt)) {
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => $system_prompt
            ]);
        }
        
        // Merge sampler overrides
        if (!empty($this->settings['sampler_overrides'])) {
            $overrides = json_decode($this->settings['sampler_overrides'], true);
            if (is_array($overrides)) {
                $payload = array_merge($payload, $overrides);
            }
        }
        
        // Ensure max_tokens is valid integer before sending
        // This must happen AFTER merge so we check the final value
        if (isset($payload['max_tokens'])) {
            // Convert to int (handles strings, floats, etc)
            $payload['max_tokens'] = intval($payload['max_tokens']);
            
            // Validate range
            if ($payload['max_tokens'] < 1) {
                unset($payload['max_tokens']); // Let API use its default
            } elseif ($payload['max_tokens'] > 100000) {
                $payload['max_tokens'] = 100000;
            }
        }
        
        // Prepare request headers
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Add API key if present
        if (!empty($this->settings['api_key'])) {
            try {
                $api_key = $this->encryption->decrypt($this->settings['api_key']);
                if (!empty($api_key)) {
                    $headers['Authorization'] = 'Bearer ' . $api_key;
                }
            } catch (Exception $e) {
                error_log('AightBot: Failed to decrypt API key');
            }
        }
        
        // Prepare request arguments
        $args = [
            'timeout' => 60,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'redirection' => 0, // Don't follow redirects on POST
        ];
        
        // Only verify SSL for HTTPS URLs
        // If URL starts with http:// there's no SSL to verify
        if (strpos($this->settings['llm_url'], 'https://') === 0) {
            // HTTPS - verify SSL unless explicitly disabled
            $args['sslverify'] = !isset($this->settings['disable_ssl_verify']) || $this->settings['disable_ssl_verify'] !== 'yes';
        } else {
            // HTTP - no SSL verification needed
            $args['sslverify'] = false;
        }
        
        // Make request
        $response = wp_remote_post($this->settings['llm_url'], $args);
        
        // Handle errors
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            if (!empty($error_data['error']['message'])) {
                throw new Exception($error_data['error']['message']);
            }
            
            throw new Exception(sprintf(__('API error (HTTP %d)', 'aightbot'), $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from API', 'aightbot'));
        }
        
        if (!is_array($data)) {
            throw new Exception(__('Invalid API response format: not an array', 'aightbot'));
        }
        
        if (!isset($data['choices']) || !is_array($data['choices']) || empty($data['choices'])) {
            if (isset($data['error'])) {
                $error_msg = is_array($data['error']) && isset($data['error']['message']) 
                    ? $data['error']['message'] 
                    : 'API returned an error';
                throw new Exception($error_msg);
            }
            throw new Exception(__('No choices in API response', 'aightbot'));
        }
        
        $choice = $data['choices'][0];
        
        if (isset($choice['message'])) {
            $message = $choice['message'];
            
            if (!is_array($message)) {
                throw new Exception(__('Invalid message format in API response', 'aightbot'));
            }
            
            if (isset($message['content']) && is_string($message['content']) && !empty($message['content'])) {
                return $message['content'];
            }
            if (isset($message['reasoning_content']) && is_string($message['reasoning_content']) && !empty($message['reasoning_content'])) {
                return $message['reasoning_content'];
            }
            if (isset($message['reasoning']) && is_string($message['reasoning']) && !empty($message['reasoning'])) {
                return $message['reasoning'];
            }
        }
        
        if (isset($choice['text']) && is_string($choice['text'])) {
            return $choice['text'];
        }
        if (isset($choice['delta']['content']) && is_string($choice['delta']['content'])) {
            return $choice['delta']['content'];
        }
        
        error_log('AightBot: Unexpected API response structure: ' . wp_json_encode($data));
        throw new Exception(__('Could not extract response content from API', 'aightbot'));
    }
    
    /**
     * Get conversation history from session
     * 
     * @param string $session_id Session ID
     * @return array Conversation history
     */
    private function get_conversation_history($session_id) {
        if (empty($session_id)) {
            return [];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT history FROM $table WHERE session_id = %s",
            $session_id
        ));
        
        if ($session && !empty($session->history)) {
            $history = json_decode($session->history, true);
            
            if (!is_array($history)) {
                return [];
            }
            
            $validated_history = [];
            foreach ($history as $message) {
                if (!is_array($message) || !isset($message['role']) || !isset($message['content'])) {
                    continue;
                }
                
                if (!in_array($message['role'], ['system', 'user', 'assistant'], true)) {
                    continue;
                }
                
                if (!is_string($message['content'])) {
                    continue;
                }
                
                $validated_history[] = [
                    'role' => $message['role'],
                    'content' => $message['content']
                ];
            }
            
            return $validated_history;
        }
        
        return [];
    }
    
    /**
     * Save conversation history to session
     * 
     * @param string $session_id Session ID
     * @param array $history Conversation history
     */
    private function save_conversation_history($session_id, $history) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $user_id = get_current_user_id();
        $bot_name = $this->settings['bot_name'] ?? 'AightBot';
        $now = current_time('mysql');
        
        // Check if session exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %s",
            $session_id
        ));
        
        if ($exists) {
            // Update existing session
            $wpdb->update(
                $table,
                [
                    'history' => wp_json_encode($history),
                    'last_active' => $now
                ],
                ['session_id' => $session_id],
                ['%s', '%s'],
                ['%s']
            );
        } else {
            // Create new session (fallback if not created via ajax_create_session)
            $ip = $this->get_client_ip();
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '';
            
            $wpdb->insert(
                $table,
                [
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'ip_address' => $ip,
                    'user_agent' => $user_agent,
                    'bot_name' => $bot_name,
                    'history' => wp_json_encode($history),
                    'created_at' => $now,
                    'last_active' => $now
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * Truncate context based on message count and/or word count limits
     * 
     * @param string $session_id Session ID for logging
     * @param array $history Conversation history
     * @return array Truncated history
     */
    private function truncate_context($session_id, $history) {
        $max_messages = isset($this->settings['max_context_messages']) ? absint($this->settings['max_context_messages']) : 40;
        $max_words = isset($this->settings['max_context_words']) ? absint($this->settings['max_context_words']) : 8000;
        
        $context = [];
        $context[] = [
            'role' => 'system',
            'content' => $this->settings['system_prompt'] ?? 'You are a helpful AI assistant.'
        ];
        
        $starter_included = false;
        if (!empty($this->settings['starter_message'])) {
            foreach ($history as $msg) {
                if ($msg['role'] === 'assistant' && $msg['content'] === $this->settings['starter_message']) {
                    $context[] = $msg;
                    $starter_included = true;
                    break;
                }
            }
        }
        
        $conversation = [];
        foreach ($history as $msg) {
            if ($starter_included && $msg['role'] === 'assistant' && $msg['content'] === $this->settings['starter_message']) {
                continue;
            }
            $conversation[] = $msg;
        }
        
        $original_count = count($conversation);
        $original_words = 0;
        $word_counts = [];
        
        foreach ($conversation as $msg) {
            $words = str_word_count($msg['content']);
            $word_counts[] = $words;
            $original_words += $words;
        }
        
        $truncated = false;
        
        if (count($conversation) > $max_messages) {
            $remove_count = count($conversation) - $max_messages;
            $conversation = array_slice($conversation, $remove_count);
            $word_counts = array_slice($word_counts, $remove_count);
            $truncated = true;
        }
        
        $current_words = array_sum($word_counts);
        
        while ($current_words > $max_words && count($conversation) > 1) {
            array_shift($conversation);
            $removed_words = array_shift($word_counts);
            $current_words -= $removed_words;
            $truncated = true;
        }
        
        if ($truncated) {
            $new_count = count($conversation);
            $new_words = array_sum($word_counts);
            
            $original_tokens = (int)($original_words * 1.3);
            $new_tokens = (int)($new_words * 1.3);
            
            if ($this->logger) {
                $this->logger->log_truncation($session_id, $original_count, $new_count, $original_words, $new_words, $original_tokens, $new_tokens);
            }
        }
        
        $final_context = array_merge($context, $conversation);
        
        return $final_context;
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }
        
        // CloudFlare support
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf_ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cf_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $cf_ip;
            }
        }
        
        $trusted_proxies = apply_filters('aightbot_trusted_proxies', []);
        
        if (!empty($trusted_proxies) && in_array($ip, $trusted_proxies, true)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                
                foreach ($ips as $potential_ip) {
                    if (filter_var($potential_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $potential_ip;
                    }
                }
            }
            elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $real_ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($real_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $real_ip;
                }
            }
        }
        
        return $ip;
    }
}
