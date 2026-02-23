<?php
declare(strict_types=1);

/**
 * Simple file-based session manager for Bitrix24 context
 * Stores auth data in local files instead of relying on PHP sessions
 */
class SessionManager
{
    private string $storageDir;
    private string $sessionId;

    public function __construct(string $storageDir = null)
    {
        if ($storageDir === null) {
            $storageDir = __DIR__ . '/../../var/sessions';
        }
        
        $this->storageDir = $storageDir;
        
        // Create directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
        
        // Generate or retrieve session ID
        $this->sessionId = isset($_COOKIE['b24_whatsapp_sid']) 
            ? $_COOKIE['b24_whatsapp_sid'] 
            : bin2hex(random_bytes(16));
        
        // Set cookie to persist session ID
        if (!isset($_COOKIE['b24_whatsapp_sid'])) {
            setcookie('b24_whatsapp_sid', $this->sessionId, [
                'expires' => time() + 86400 * 7,  // 7 days
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    }

    /**
     * Save auth data to file storage
     */
    public function saveAuth(array $authData): bool
    {
        try {
            $filePath = $this->getFilePath();
            $data = [
                'timestamp' => time(),
                'auth' => $authData
            ];
            
            $result = file_put_contents(
                $filePath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            
            error_log('SessionManager: Saved auth data to ' . $filePath);
            return $result !== false;
        } catch (\Exception $e) {
            error_log('SessionManager: Error saving auth data: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve auth data from file storage
     */
    public function getAuth(): ?array
    {
        try {
            $filePath = $this->getFilePath();
            
            if (!file_exists($filePath)) {
                error_log('SessionManager: No auth file found at ' . $filePath);
                return null;
            }
            
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            
            if (!is_array($data) || !isset($data['auth'])) {
                error_log('SessionManager: Invalid auth data format');
                return null;
            }
            
            // Check if data is recent (less than 24 hours old)
            if (time() - ($data['timestamp'] ?? 0) > 86400) {
                error_log('SessionManager: Auth data expired');
                return null;
            }
            
            error_log('SessionManager: Retrieved auth data from file');
            return $data['auth'];
        } catch (\Exception $e) {
            error_log('SessionManager: Error retrieving auth data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear auth data
     */
    public function clearAuth(): bool
    {
        try {
            $filePath = $this->getFilePath();
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log('SessionManager: Cleared auth data');
                return true;
            }
            return true;
        } catch (\Exception $e) {
            error_log('SessionManager: Error clearing auth data: ' . $e->getMessage());
            return false;
        }
    }

    private function getFilePath(): string
    {
        return $this->storageDir . '/' . 'auth_' . $this->sessionId . '.json';
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }
}
