<?php

namespace App\Config;

class Auth
{
    // Authentication Methods
    const METHOD_PASSWORD = 'password';
    const METHOD_2FA = '2fa';
    const METHOD_SSO = 'sso';
    const METHOD_OTP = 'otp';
    
    // Password Requirements
    const PASSWORD_MIN_LENGTH = 8;
    const PASSWORD_REQUIRE_UPPERCASE = true;
    const PASSWORD_REQUIRE_LOWERCASE = true;
    const PASSWORD_REQUIRE_NUMBERS = true;
    const PASSWORD_REQUIRE_SYMBOLS = true;
    
    // Session Settings
    const SESSION_LIFETIME = 7200; // 2 hours
    const REMEMBER_ME_LIFETIME = 2592000; // 30 days
    const MAX_SESSIONS_PER_USER = 5;
    
    // Login Security
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 minutes
    const PASSWORD_RESET_EXPIRY = 3600; // 1 hour
    const ACCOUNT_LOCKOUT_TIME = 1800; // 30 minutes
    
    // Two-Factor Authentication
    const TOTP_ISSUER = 'HomeGuru MIS';
    const TOTP_WINDOW = 1;
    const BACKUP_CODES_COUNT = 10;
    
    // JWT Settings
    const JWT_ALGORITHM = 'HS256';
    const JWT_ACCESS_TOKEN_TTL = 3600; // 1 hour
    const JWT_REFRESH_TOKEN_TTL = 2592000; // 30 days
    
    // OAuth Providers
    const OAUTH_GOOGLE = 'google';
    const OAUTH_FACEBOOK = 'facebook';
    const OAUTH_MICROSOFT = 'microsoft';
    
    // User Status
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_PENDING = 'pending';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_LOCKED = 'locked';
    
    // Role Hierarchy
    const ROLE_HIERARCHY = [
        'superadmin' => 100,
        'admin' => 90,
        'hr' => 80,
        'teacher' => 70,
        'employee' => 60,
        'parent' => 50,
        'student' => 40
    ];
    
    /**
     * Get authentication configuration
     */
    public static function getConfig()
    {
        return [
            'default_guard' => 'web',
            'guards' => [
                'web' => [
                    'driver' => 'session',
                    'provider' => 'users'
                ],
                'api' => [
                    'driver' => 'jwt',
                    'provider' => 'users'
                ]
            ],
            'providers' => [
                'users' => [
                    'driver' => 'eloquent',
                    'model' => \App\Models\User\User::class
                ]
            ],
            'passwords' => [
                'users' => [
                    'provider' => 'users',
                    'table' => 'password_resets',
                    'expire' => self::PASSWORD_RESET_EXPIRY,
                    'throttle' => 60
                ]
            ],
            'password' => [
                'min_length' => self::PASSWORD_MIN_LENGTH,
                'require_uppercase' => self::PASSWORD_REQUIRE_UPPERCASE,
                'require_lowercase' => self::PASSWORD_REQUIRE_LOWERCASE,
                'require_numbers' => self::PASSWORD_REQUIRE_NUMBERS,
                'require_symbols' => self::PASSWORD_REQUIRE_SYMBOLS
            ],
            'session' => [
                'lifetime' => self::SESSION_LIFETIME,
                'remember_me_lifetime' => self::REMEMBER_ME_LIFETIME,
                'max_sessions_per_user' => self::MAX_SESSIONS_PER_USER
            ],
            'security' => [
                'max_login_attempts' => self::MAX_LOGIN_ATTEMPTS,
                'login_lockout_time' => self::LOGIN_LOCKOUT_TIME,
                'password_reset_expiry' => self::PASSWORD_RESET_EXPIRY,
                'account_lockout_time' => self::ACCOUNT_LOCKOUT_TIME
            ],
            'two_factor' => [
                'enabled' => true,
                'totp_issuer' => self::TOTP_ISSUER,
                'totp_window' => self::TOTP_WINDOW,
                'backup_codes_count' => self::BACKUP_CODES_COUNT
            ],
            'jwt' => [
                'algorithm' => self::JWT_ALGORITHM,
                'access_token_ttl' => self::JWT_ACCESS_TOKEN_TTL,
                'refresh_token_ttl' => self::JWT_REFRESH_TOKEN_TTL,
                'secret' => $_ENV['JWT_SECRET'] ?? 'your-secret-key'
            ],
            'oauth' => [
                'google' => [
                    'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => '/auth/google/callback'
                ],
                'facebook' => [
                    'client_id' => $_ENV['FACEBOOK_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['FACEBOOK_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => '/auth/facebook/callback'
                ],
                'microsoft' => [
                    'client_id' => $_ENV['MICROSOFT_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['MICROSOFT_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => '/auth/microsoft/callback'
                ]
            ],
            'user_status' => [
                'active' => self::STATUS_ACTIVE,
                'inactive' => self::STATUS_INACTIVE,
                'pending' => self::STATUS_PENDING,
                'suspended' => self::STATUS_SUSPENDED,
                'locked' => self::STATUS_LOCKED
            ],
            'role_hierarchy' => self::ROLE_HIERARCHY
        ];
    }
    
    /**
     * Check if password meets requirements
     */
    public static function validatePassword($password)
    {
        $config = self::getConfig()['password'];
        $errors = [];
        
        if (strlen($password) < $config['min_length']) {
            $errors[] = "Password must be at least {$config['min_length']} characters long.";
        }
        
        if ($config['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter.";
        }
        
        if ($config['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter.";
        }
        
        if ($config['require_numbers'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number.";
        }
        
        if ($config['require_symbols'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character.";
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Check if role has higher or equal level
     */
    public static function hasRoleLevel($userRole, $requiredRole)
    {
        $hierarchy = self::ROLE_HIERARCHY;
        
        $userLevel = $hierarchy[$userRole] ?? 0;
        $requiredLevel = $hierarchy[$requiredRole] ?? 0;
        
        return $userLevel >= $requiredLevel;
    }
    
    /**
     * Get role level
     */
    public static function getRoleLevel($role)
    {
        return self::ROLE_HIERARCHY[$role] ?? 0;
    }
    
    /**
     * Check if user status allows login
     */
    public static function canLogin($status)
    {
        return in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING]);
    }
    
    /**
     * Generate secure random string
     */
    public static function generateRandomString($length = 32)
    {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Hash password
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate JWT token
     */
    public static function generateJwtToken($payload, $expiry = null)
    {
        $expiry = $expiry ?? time() + self::JWT_ACCESS_TOKEN_TTL;
        
        $header = [
            'typ' => 'JWT',
            'alg' => self::JWT_ALGORITHM
        ];
        
        $payload['exp'] = $expiry;
        $payload['iat'] = time();
        
        $headerEncoded = base64url_encode(json_encode($header));
        $payloadEncoded = base64url_encode(json_encode($payload));
        
        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            self::getConfig()['jwt']['secret'],
            true
        );
        
        $signatureEncoded = base64url_encode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Verify JWT token
     */
    public static function verifyJwtToken($token)
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        
        $signature = base64url_decode($signatureEncoded);
        $expectedSignature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            self::getConfig()['jwt']['secret'],
            true
        );
        
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }
        
        $payload = json_decode(base64url_decode($payloadEncoded), true);
        
        if ($payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
}

/**
 * Base64 URL encode
 */
function base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL decode
 */
function base64url_decode($data)
{
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}