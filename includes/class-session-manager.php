<?php
/**
 * Session Management Class
 */
class AightBot_Session_Manager {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('aightbot_cleanup_sessions', [$this, 'cleanup_old_sessions']);
    }
    
    /**
     * Generate new session ID
     * 
     * @return string Session ID
     */
    public function generate_session_id() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Get session by ID
     * 
     * @param string $session_id Session ID
     * @param bool $verify_ownership Whether to verify the user owns this session
     * @return object|null Session data
     */
    public function get_session($session_id, $verify_ownership = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        if ($verify_ownership && is_user_logged_in()) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE session_id = %s AND user_id = %d",
                $session_id,
                get_current_user_id()
            ));
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %s",
            $session_id
        ));
    }
    
    /**
     * Delete session
     * 
     * @param string $session_id Session ID
     * @return bool Success
     */
    public function delete_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        return $wpdb->delete(
            $table,
            ['session_id' => $session_id],
            ['%s']
        ) !== false;
    }
    
    /**
     * Clean up old sessions (older than 1 hour)
     * Sessions are browser-session based and should be cleaned up aggressively
     */
    public function cleanup_old_sessions() {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $hours_to_keep = apply_filters('aightbot_session_retention_hours', 1);
        $batch_size = 50;
        $iterations = 0;
        
        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table 
                 WHERE last_active < DATE_SUB(NOW(), INTERVAL %d HOUR) 
                 LIMIT %d",
                $hours_to_keep,
                $batch_size
            ));
            
            if ($deleted === false || ++$iterations >= 100) {
                break;
            }
            
            if ($deleted === $batch_size) {
                usleep(100000);
            }
        } while ($deleted === $batch_size);
    }
    
    /**
     * Get user's sessions
     * 
     * @param int $user_id User ID (0 for current user)
     * @param int $limit Number of sessions to retrieve
     * @return array Sessions
     */
    public function get_user_sessions($user_id = 0, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY last_active DESC LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    /**
     * Get session statistics
     * 
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $stats = [
            'total_sessions' => 0,
            'active_today' => 0,
            'active_this_week' => 0,
            'total_messages' => 0
        ];
        
        $stats['total_sessions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        $stats['active_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE DATE(last_active) = CURDATE()"
        );
        
        $stats['active_this_week'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE last_active >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        if (version_compare($wpdb->db_version(), '5.7.8', '>=')) {
            $stats['total_messages'] = (int) $wpdb->get_var(
                "SELECT COALESCE(SUM(JSON_LENGTH(history)), 0) 
                 FROM $table 
                 WHERE history IS NOT NULL AND history != ''"
            );
        } else {
            $total = 0;
            $batch_size = 100;
            $offset = 0;
            
            while (true) {
                $sessions = $wpdb->get_col($wpdb->prepare(
                    "SELECT history FROM $table WHERE history IS NOT NULL LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ));
                
                if (empty($sessions)) {
                    break;
                }
                
                foreach ($sessions as $history_json) {
                    $history = json_decode($history_json, true);
                    if (is_array($history)) {
                        $total += count($history);
                    }
                }
                
                if (count($sessions) < $batch_size) {
                    break;
                }
                
                $offset += $batch_size;
            }
            
            $stats['total_messages'] = $total;
        }
        
        return $stats;
    }
}
