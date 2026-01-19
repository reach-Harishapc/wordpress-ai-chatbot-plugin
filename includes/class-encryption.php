<?php
/**
 * Encryption/Decryption Handler
 */
class AightBot_Encryption {
    
    private $method = 'AES-256-CBC';
    private $key;
    
    public function __construct() {
        $this->key = $this->get_encryption_key();
    }
    
    private function get_encryption_key() {
        $key_option = AIGHTBOT_OPTION_PREFIX . 'encryption_key';
        $key = get_option($key_option);
        
        if (false === $key) {
            $key = bin2hex(random_bytes(32));
            add_option($key_option, $key, '', 'no');
        }
        
        return hex2bin($key);
    }
    
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        try {
            $iv_length = openssl_cipher_iv_length($this->method);
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            $encrypted = openssl_encrypt(
                $data,
                $this->method,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            $ciphertext = $iv . $encrypted;
            $hmac = hash_hmac('sha256', $ciphertext, $this->key, true);
            
            $result = base64_encode($hmac . $ciphertext);
            
            return 'encrypted:' . $result;
            
        } catch (Exception $e) {
            error_log('AightBot Encryption Error: ' . $e->getMessage());
            throw new Exception('Failed to encrypt data');
        }
    }
    
    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        if (strpos($data, 'encrypted:') !== 0) {
            return $data;
        }
        
        try {
            $data = substr($data, 10);
            $data = base64_decode($data);
            
            if ($data === false) {
                throw new Exception('Invalid encrypted data format');
            }
            
            $hmac_length = 32;
            $iv_length = openssl_cipher_iv_length($this->method);
            
            if (strlen($data) < $hmac_length + $iv_length) {
                throw new Exception('Invalid encrypted data length');
            }
            
            $received_hmac = substr($data, 0, $hmac_length);
            $ciphertext = substr($data, $hmac_length);
            $calculated_hmac = hash_hmac('sha256', $ciphertext, $this->key, true);
            
            if (!hash_equals($calculated_hmac, $received_hmac)) {
                throw new Exception('HMAC verification failed');
            }
            
            $iv = substr($ciphertext, 0, $iv_length);
            $encrypted = substr($ciphertext, $iv_length);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->method,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }
            
            return $decrypted;
            
        } catch (Exception $e) {
            error_log('AightBot Decryption Error: ' . $e->getMessage());
            throw new Exception('Failed to decrypt data');
        }
    }
    
    public function is_encrypted($data) {
        return !empty($data) && strpos($data, 'encrypted:') === 0;
    }
}
