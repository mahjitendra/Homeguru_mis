<?php

namespace App\Services\Auth;

use App\Models\User\User;
use App\Config\Auth;
use App\Libraries\Database\Connection;
use App\Helpers\SecurityHelper;
use App\Exceptions\AuthenticationException;

class AuthService
{
    private $connection;
    private $jwtService;
    private $twoFactorService;
    
    public function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->jwtService = new JWTService();
        $this->twoFactorService = new TwoFactorService();
    }
    
    /**
     * Authenticate user with email and password
     */
    public function authenticate($email, $password, $remember = false)
    {
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            throw new AuthenticationException('Invalid credentials');
        }
        
        if (!password_verify($password, $user->password)) {
            $this->recordFailedAttempt($user);
            throw new AuthenticationException('Invalid credentials');
        }
        
        if (!Auth::canLogin($user->status)) {
            throw new AuthenticationException('Account is not active');
        }
        
        // Check if account is locked
        if ($this->isAccountLocked($user)) {
            throw new AuthenticationException('Account is locked due to too many failed attempts');
        }
        
        // Clear failed attempts on successful login
        $this->clearFailedAttempts($user);
        
        // Update last login
        $this->updateLastLogin($user);
        
        // Generate session token
        $sessionToken = $this->generateSessionToken($user->id);
        
        // Set remember me token if requested
        if ($remember) {
            $this->setRememberToken($user);
        }
        
        return [
            'user' => $user,
            'session_token' => $sessionToken,
            'remember_token' => $remember ? $user->remember_token : null
        ];
    }
    
    /**
     * Register a new user
     */
    public function register($data)
    {
        // Validate required fields
        $this->validateRegistrationData($data);
        
        // Check if email already exists
        if (User::where('email', $data['email'])->exists()) {
            throw new AuthenticationException('Email already exists');
        }
        
        // Hash password
        $data['password'] = Auth::hashPassword($data['password']);
        
        // Set default values
        $data['status'] = Auth::STATUS_ACTIVE;
        $data['email_verified_at'] = null;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Create user
        $user = User::create($data);
        
        // Send verification email
        $this->sendVerificationEmail($user);
        
        return $user;
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($email)
    {
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            // Don't reveal if email exists
            return true;
        }
        
        // Generate reset token
        $token = $this->generatePasswordResetToken($user->id);
        
        // Store token in database
        $this->storePasswordResetToken($user->id, $token);
        
        // Send email
        $this->sendPasswordResetEmailToUser($user, $token);
        
        return true;
    }
    
    /**
     * Reset password with token
     */
    public function resetPassword($token, $password)
    {
        // Validate token
        $resetData = $this->validatePasswordResetToken($token);
        
        if (!$resetData) {
            throw new AuthenticationException('Invalid or expired reset token');
        }
        
        // Update password
        $user = User::find($resetData['user_id']);
        $user->password = Auth::hashPassword($password);
        $user->save();
        
        // Invalidate token
        $this->invalidatePasswordResetToken($token);
        
        // Invalidate all sessions
        $this->invalidateAllSessions($user->id);
        
        return true;
    }
    
    /**
     * Verify email address
     */
    public function verifyEmail($token)
    {
        $user = User::where('email_verification_token', $token)->first();
        
        if (!$user) {
            throw new AuthenticationException('Invalid verification token');
        }
        
        if ($user->email_verified_at) {
            throw new AuthenticationException('Email already verified');
        }
        
        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->email_verification_token = null;
        $user->save();
        
        return true;
    }
    
    /**
     * Resend verification email
     */
    public function resendVerificationEmail($email)
    {
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if ($user->email_verified_at) {
            throw new AuthenticationException('Email already verified');
        }
        
        // Generate new token
        $token = $this->generateEmailVerificationToken();
        $user->email_verification_token = $token;
        $user->save();
        
        // Send email
        $this->sendVerificationEmail($user);
        
        return true;
    }
    
    /**
     * Generate session token
     */
    public function generateSessionToken($userId)
    {
        $token = SecurityHelper::generateRandomString(64);
        
        // Store token in database
        $sql = "INSERT INTO user_sessions (user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?)";
        $expiresAt = date('Y-m-d H:i:s', time() + Auth::SESSION_LIFETIME);
        
        $this->connection->execute($sql, [
            $userId,
            $token,
            $expiresAt,
            date('Y-m-d H:i:s')
        ]);
        
        return $token;
    }
    
    /**
     * Verify session token
     */
    public function verifySessionToken($userId, $token)
    {
        $sql = "SELECT * FROM user_sessions WHERE user_id = ? AND token = ? AND expires_at > ? AND status = 'active'";
        
        $result = $this->connection->fetchOne($sql, [
            $userId,
            $token,
            date('Y-m-d H:i:s')
        ]);
        
        return $result !== null;
    }
    
    /**
     * Invalidate session token
     */
    public function invalidateSessionToken($userId, $token)
    {
        $sql = "UPDATE user_sessions SET status = 'inactive' WHERE user_id = ? AND token = ?";
        return $this->connection->execute($sql, [$userId, $token]);
    }
    
    /**
     * Invalidate all sessions for user
     */
    public function invalidateAllSessions($userId)
    {
        $sql = "UPDATE user_sessions SET status = 'inactive' WHERE user_id = ?";
        return $this->connection->execute($sql, [$userId]);
    }
    
    /**
     * Generate remember token
     */
    public function generateRememberToken($userId)
    {
        $token = SecurityHelper::generateRandomString(64);
        
        // Store token in database
        $sql = "INSERT INTO remember_tokens (user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?)";
        $expiresAt = date('Y-m-d H:i:s', time() + Auth::REMEMBER_ME_LIFETIME);
        
        $this->connection->execute($sql, [
            $userId,
            $token,
            $expiresAt,
            date('Y-m-d H:i:s')
        ]);
        
        return $token;
    }
    
    /**
     * Validate remember token
     */
    public function validateRememberToken($token)
    {
        $sql = "SELECT user_id FROM remember_tokens WHERE token = ? AND expires_at > ? AND status = 'active'";
        
        $result = $this->connection->fetchOne($sql, [
            $token,
            date('Y-m-d H:i:s')
        ]);
        
        return $result ? $result['user_id'] : null;
    }
    
    /**
     * Invalidate remember token
     */
    public function invalidateRememberToken($token)
    {
        $sql = "UPDATE remember_tokens SET status = 'inactive' WHERE token = ?";
        return $this->connection->execute($sql, [$token]);
    }
    
    /**
     * Update last login
     */
    public function updateLastLogin($user)
    {
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();
    }
    
    /**
     * Update last activity
     */
    public function updateLastActivity($userId)
    {
        $sql = "UPDATE users SET last_activity_at = ? WHERE id = ?";
        return $this->connection->execute($sql, [date('Y-m-d H:i:s'), $userId]);
    }
    
    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt($user)
    {
        $sql = "INSERT INTO failed_login_attempts (user_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?)";
        
        $this->connection->execute($sql, [
            $user->id,
            $this->getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Check if account is locked
     */
    private function isAccountLocked($user)
    {
        $sql = "SELECT COUNT(*) as count FROM failed_login_attempts 
                WHERE user_id = ? AND created_at > ?";
        
        $cutoffTime = date('Y-m-d H:i:s', time() - Auth::LOGIN_LOCKOUT_TIME);
        $result = $this->connection->fetchOne($sql, [$user->id, $cutoffTime]);
        
        return $result['count'] >= Auth::MAX_LOGIN_ATTEMPTS;
    }
    
    /**
     * Clear failed attempts
     */
    private function clearFailedAttempts($user)
    {
        $sql = "DELETE FROM failed_login_attempts WHERE user_id = ?";
        $this->connection->execute($sql, [$user->id]);
    }
    
    /**
     * Generate password reset token
     */
    private function generatePasswordResetToken($userId)
    {
        return SecurityHelper::generateRandomString(64);
    }
    
    /**
     * Store password reset token
     */
    private function storePasswordResetToken($userId, $token)
    {
        $sql = "INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?)";
        $expiresAt = date('Y-m-d H:i:s', time() + Auth::PASSWORD_RESET_EXPIRY);
        
        $this->connection->execute($sql, [
            $userId,
            $token,
            $expiresAt,
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Validate password reset token
     */
    private function validatePasswordResetToken($token)
    {
        $sql = "SELECT * FROM password_resets WHERE token = ? AND expires_at > ? AND status = 'active'";
        
        return $this->connection->fetchOne($sql, [
            $token,
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Invalidate password reset token
     */
    private function invalidatePasswordResetToken($token)
    {
        $sql = "UPDATE password_resets SET status = 'used' WHERE token = ?";
        return $this->connection->execute($sql, [$token]);
    }
    
    /**
     * Generate email verification token
     */
    private function generateEmailVerificationToken()
    {
        return SecurityHelper::generateRandomString(64);
    }
    
    /**
     * Send verification email
     */
    private function sendVerificationEmail($user)
    {
        // Implementation would depend on your email service
        // This is a placeholder
        return true;
    }
    
    /**
     * Send password reset email
     */
    private function sendPasswordResetEmailToUser($user, $token)
    {
        // Implementation would depend on your email service
        // This is a placeholder
        return true;
    }
    
    /**
     * Set remember token
     */
    private function setRememberToken($user)
    {
        $token = $this->generateRememberToken($user->id);
        $user->remember_token = $token;
        $user->save();
    }
    
    /**
     * Get client IP
     */
    private function getClientIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    /**
     * Validate registration data
     */
    private function validateRegistrationData($data)
    {
        $required = ['email', 'password', 'name'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new AuthenticationException("Field {$field} is required");
            }
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new AuthenticationException('Invalid email format');
        }
        
        $passwordValidation = Auth::validatePassword($data['password']);
        if ($passwordValidation !== true) {
            throw new AuthenticationException(implode(' ', $passwordValidation));
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        if (!password_verify($currentPassword, $user->password)) {
            throw new AuthenticationException('Current password is incorrect');
        }
        
        $passwordValidation = Auth::validatePassword($newPassword);
        if ($passwordValidation !== true) {
            throw new AuthenticationException(implode(' ', $passwordValidation));
        }
        
        $user->password = Auth::hashPassword($newPassword);
        $user->save();
        
        // Invalidate all sessions
        $this->invalidateAllSessions($userId);
        
        return true;
    }
    
    /**
     * Update profile
     */
    public function updateProfile($userId, $data)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        $allowedFields = ['name', 'phone', 'address', 'date_of_birth'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $user->$field = $data[$field];
            }
        }
        
        $user->save();
        
        return $user;
    }
    
    /**
     * Deactivate account
     */
    public function deactivateAccount($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            throw new AuthenticationException('User not found');
        }
        
        $user->status = Auth::STATUS_INACTIVE;
        $user->save();
        
        // Invalidate all sessions
        $this->invalidateAllSessions($userId);
        
        return true;
    }
    
    /**
     * Get user sessions
     */
    public function getUserSessions($userId)
    {
        $sql = "SELECT * FROM user_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC";
        return $this->connection->fetchAll($sql, [$userId]);
    }
    
    /**
     * Get failed login attempts
     */
    public function getFailedLoginAttempts($userId)
    {
        $sql = "SELECT * FROM failed_login_attempts WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
        return $this->connection->fetchAll($sql, [$userId]);
    }
    
    /**
     * Clean up expired tokens
     */
    public function cleanupExpiredTokens()
    {
        // Clean up expired session tokens
        $sql = "DELETE FROM user_sessions WHERE expires_at < ?";
        $this->connection->execute($sql, [date('Y-m-d H:i:s')]);
        
        // Clean up expired remember tokens
        $sql = "DELETE FROM remember_tokens WHERE expires_at < ?";
        $this->connection->execute($sql, [date('Y-m-d H:i:s')]);
        
        // Clean up expired password reset tokens
        $sql = "DELETE FROM password_resets WHERE expires_at < ?";
        $this->connection->execute($sql, [date('Y-m-d H:i:s')]);
        
        // Clean up old failed login attempts
        $sql = "DELETE FROM failed_login_attempts WHERE created_at < ?";
        $cutoffTime = date('Y-m-d H:i:s', time() - (30 * 24 * 60 * 60)); // 30 days
        $this->connection->execute($sql, [$cutoffTime]);
    }
}