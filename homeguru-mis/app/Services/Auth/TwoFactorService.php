<?php

namespace App\Services\Auth;

use App\Config\Auth;
use App\Models\User\User;
use App\Libraries\Database\Connection;
use App\Helpers\SecurityHelper;
use App\Exceptions\AuthenticationException;

class TwoFactorService
{
    private $connection;
    private $issuer;
    private $window;
    private $backupCodesCount;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $config = Auth::getConfig();
        $this->issuer = $config['two_factor']['totp_issuer'];
        $this->window = $config['two_factor']['totp_window'];
        $this->backupCodesCount = $config['two_factor']['backup_codes_count'];
    }
    
    /**
     * Enable 2FA for user
     */
    public function enableTwoFactor($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if ($user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is already enabled');
        }
        
        // Generate secret
        $secret = $this->generateSecret();
        
        // Generate backup codes
        $backupCodes = $this->generateBackupCodes();
        
        // Store secret and backup codes
        $user->two_factor_secret = $secret;
        $user->two_factor_backup_codes = json_encode($backupCodes);
        $user->two_factor_enabled = false; // Will be enabled after verification
        $user->save();
        
        return [
            'secret' => $secret,
            'qr_code' => $this->generateQRCode($user, $secret),
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * Verify 2FA setup
     */
    public function verifyTwoFactorSetup($userId, $code)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!$user->two_factor_secret) {
            throw new AuthenticationException('Two-factor authentication not initialized');
        }
        
        if ($user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is already enabled');
        }
        
        // Verify the code
        if (!$this->verifyCode($user->two_factor_secret, $code)) {
            throw new AuthenticationException('Invalid verification code');
        }
        
        // Enable 2FA
        $user->two_factor_enabled = true;
        $user->save();
        
        return true;
    }
    
    /**
     * Disable 2FA for user
     */
    public function disableTwoFactor($userId, $password)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!$user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is not enabled');
        }
        
        // Verify password
        if (!password_verify($password, $user->password)) {
            throw new AuthenticationException('Invalid password');
        }
        
        // Disable 2FA
        $user->two_factor_enabled = false;
        $user->two_factor_secret = null;
        $user->two_factor_backup_codes = null;
        $user->save();
        
        return true;
    }
    
    /**
     * Verify 2FA code
     */
    public function verifyTwoFactorCode($userId, $code)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!$user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is not enabled');
        }
        
        // Check if it's a backup code
        if ($this->isBackupCode($user, $code)) {
            $this->useBackupCode($user, $code);
            return true;
        }
        
        // Verify TOTP code
        if (!$this->verifyCode($user->two_factor_secret, $code)) {
            throw new AuthenticationException('Invalid verification code');
        }
        
        return true;
    }
    
    /**
     * Generate new backup codes
     */
    public function generateNewBackupCodes($userId, $password)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!$user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is not enabled');
        }
        
        // Verify password
        if (!password_verify($password, $user->password)) {
            throw new AuthenticationException('Invalid password');
        }
        
        // Generate new backup codes
        $backupCodes = $this->generateBackupCodes();
        
        // Store new backup codes
        $user->two_factor_backup_codes = json_encode($backupCodes);
        $user->save();
        
        return $backupCodes;
    }
    
    /**
     * Get backup codes
     */
    public function getBackupCodes($userId, $password)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!$user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is not enabled');
        }
        
        // Verify password
        if (!password_verify($password, $user->password)) {
            throw new AuthenticationException('Invalid password');
        }
        
        $backupCodes = json_decode($user->two_factor_backup_codes, true);
        
        return $backupCodes ?: [];
    }
    
    /**
     * Generate secret key
     */
    private function generateSecret()
    {
        return $this->base32Encode(random_bytes(20));
    }
    
    /**
     * Generate backup codes
     */
    private function generateBackupCodes()
    {
        $codes = [];
        
        for ($i = 0; $i < $this->backupCodesCount; $i++) {
            $codes[] = strtoupper(substr(md5(random_bytes(16)), 0, 8));
        }
        
        return $codes;
    }
    
    /**
     * Generate QR code
     */
    private function generateQRCode($user, $secret)
    {
        $issuer = $this->issuer;
        $accountName = $user->email;
        
        $otpauth = "otpauth://totp/{$issuer}:{$accountName}?secret={$secret}&issuer={$issuer}";
        
        return $otpauth;
    }
    
    /**
     * Verify TOTP code
     */
    private function verifyCode($secret, $code)
    {
        $timeSlice = floor(time() / 30);
        
        // Check current time slice
        if ($this->verifyCodeAtTime($secret, $code, $timeSlice)) {
            return true;
        }
        
        // Check previous time slice (for clock drift)
        if ($this->verifyCodeAtTime($secret, $code, $timeSlice - 1)) {
            return true;
        }
        
        // Check next time slice (for clock drift)
        if ($this->verifyCodeAtTime($secret, $code, $timeSlice + 1)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Verify code at specific time slice
     */
    private function verifyCodeAtTime($secret, $code, $timeSlice)
    {
        $expectedCode = $this->generateCodeAtTime($secret, $timeSlice);
        
        return hash_equals($expectedCode, $code);
    }
    
    /**
     * Generate code at specific time slice
     */
    private function generateCodeAtTime($secret, $timeSlice)
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hm[19]) & 0xf;
        $code = (
            ((ord($hm[$offset + 0]) & 0x7f) << 24) |
            ((ord($hm[$offset + 1]) & 0xff) << 16) |
            ((ord($hm[$offset + 2]) & 0xff) << 8) |
            (ord($hm[$offset + 3]) & 0xff)
        ) % 1000000;
        
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Check if code is a backup code
     */
    private function isBackupCode($user, $code)
    {
        $backupCodes = json_decode($user->two_factor_backup_codes, true);
        
        if (!$backupCodes) {
            return false;
        }
        
        return in_array($code, $backupCodes);
    }
    
    /**
     * Use backup code
     */
    private function useBackupCode($user, $code)
    {
        $backupCodes = json_decode($user->two_factor_backup_codes, true);
        
        if (!$backupCodes) {
            throw new AuthenticationException('No backup codes available');
        }
        
        $key = array_search($code, $backupCodes);
        
        if ($key === false) {
            throw new AuthenticationException('Invalid backup code');
        }
        
        // Remove used backup code
        unset($backupCodes[$key]);
        $backupCodes = array_values($backupCodes);
        
        $user->two_factor_backup_codes = json_encode($backupCodes);
        $user->save();
        
        return true;
    }
    
    /**
     * Base32 encode
     */
    private function base32Encode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0, $j = strlen($data); $i < $j; $i++) {
            $v <<= 8;
            $v += ord($data[$i]);
            $vbits += 8;
            
            while ($vbits >= 5) {
                $vbits -= 5;
                $output .= $alphabet[$v >> $vbits];
                $v &= ((1 << $vbits) - 1);
            }
        }
        
        if ($vbits > 0) {
            $v <<= (5 - $vbits);
            $output .= $alphabet[$v];
        }
        
        return $output;
    }
    
    /**
     * Base32 decode
     */
    private function base32Decode($data)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0, $j = strlen($data); $i < $j; $i++) {
            $v <<= 5;
            $v += strpos($alphabet, $data[$i]);
            $vbits += 5;
            
            if ($vbits >= 8) {
                $vbits -= 8;
                $output .= chr($v >> $vbits);
                $v &= ((1 << $vbits) - 1);
            }
        }
        
        return $output;
    }
    
    /**
     * Get 2FA status
     */
    public function getTwoFactorStatus($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return [
                'enabled' => false,
                'has_secret' => false,
                'backup_codes_count' => 0
            ];
        }
        
        $backupCodes = json_decode($user->two_factor_backup_codes, true);
        
        return [
            'enabled' => $user->two_factor_enabled,
            'has_secret' => !empty($user->two_factor_secret),
            'backup_codes_count' => $backupCodes ? count($backupCodes) : 0
        ];
    }
    
    /**
     * Get 2FA setup URL
     */
    public function getSetupUrl($userId)
    {
        $user = User::find($userId);
        
        if (!$user || !$user->two_factor_secret) {
            return null;
        }
        
        return $this->generateQRCode($user, $user->two_factor_secret);
    }
    
    /**
     * Validate 2FA setup
     */
    public function validateSetup($userId, $code)
    {
        $user = User::find($userId);
        
        if (!$user || !$user->two_factor_secret) {
            return false;
        }
        
        return $this->verifyCode($user->two_factor_secret, $code);
    }
    
    /**
     * Get remaining backup codes
     */
    public function getRemainingBackupCodes($userId)
    {
        $user = User::find($userId);
        
        if (!$user || !$user->two_factor_enabled) {
            return 0;
        }
        
        $backupCodes = json_decode($user->two_factor_backup_codes, true);
        
        return $backupCodes ? count($backupCodes) : 0;
    }
    
    /**
     * Check if user needs to set up 2FA
     */
    public function needsSetup($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return false;
        }
        
        return !$user->two_factor_enabled && !empty($user->two_factor_secret);
    }
    
    /**
     * Force 2FA setup
     */
    public function forceSetup($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if ($user->two_factor_enabled) {
            throw new AuthenticationException('Two-factor authentication is already enabled');
        }
        
        // Generate secret and backup codes
        $secret = $this->generateSecret();
        $backupCodes = $this->generateBackupCodes();
        
        // Store secret and backup codes
        $user->two_factor_secret = $secret;
        $user->two_factor_backup_codes = json_encode($backupCodes);
        $user->save();
        
        return [
            'secret' => $secret,
            'qr_code' => $this->generateQRCode($user, $secret),
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * Get 2FA statistics
     */
    public function getStatistics()
    {
        $sql = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN two_factor_enabled = 1 THEN 1 ELSE 0 END) as enabled_2fa,
                    SUM(CASE WHEN two_factor_secret IS NOT NULL AND two_factor_enabled = 0 THEN 1 ELSE 0 END) as pending_setup
                FROM users";
        
        $result = $this->connection->fetchOne($sql);
        
        return [
            'total_users' => $result['total_users'],
            'enabled_2fa' => $result['enabled_2fa'],
            'pending_setup' => $result['pending_setup'],
            'enabled_percentage' => $result['total_users'] > 0 ? 
                round(($result['enabled_2fa'] / $result['total_users']) * 100, 2) : 0
        ];
    }
    
    /**
     * Clean up expired 2FA data
     */
    public function cleanup()
    {
        // This could include cleaning up old backup codes or other 2FA-related data
        // For now, we'll just return true
        return true;
    }
}