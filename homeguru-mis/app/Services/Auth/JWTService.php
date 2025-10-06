<?php

namespace App\Services\Auth;

use App\Config\Auth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use App\Exceptions\AuthenticationException;

class JWTService
{
    private $secret;
    private $algorithm;
    private $accessTokenTtl;
    private $refreshTokenTtl;
    private $blacklist;
    
    public function __construct()
    {
        $config = Auth::getConfig();
        $this->secret = $config['jwt']['secret'];
        $this->algorithm = $config['jwt']['algorithm'];
        $this->accessTokenTtl = $config['jwt']['access_token_ttl'];
        $this->refreshTokenTtl = $config['jwt']['refresh_token_ttl'];
        $this->blacklist = [];
    }
    
    /**
     * Generate access token
     */
    public function generateAccessToken($userId, $payload = [])
    {
        $now = time();
        
        $tokenPayload = array_merge([
            'iss' => 'homeguru-mis', // Issuer
            'aud' => 'homeguru-mis', // Audience
            'iat' => $now, // Issued at
            'exp' => $now + $this->accessTokenTtl, // Expiration
            'sub' => $userId, // Subject (user ID)
            'type' => 'access',
            'jti' => $this->generateJti() // JWT ID
        ], $payload);
        
        return JWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }
    
    /**
     * Generate refresh token
     */
    public function generateRefreshToken($userId, $payload = [])
    {
        $now = time();
        
        $tokenPayload = array_merge([
            'iss' => 'homeguru-mis',
            'aud' => 'homeguru-mis',
            'iat' => $now,
            'exp' => $now + $this->refreshTokenTtl,
            'sub' => $userId,
            'type' => 'refresh',
            'jti' => $this->generateJti()
        ], $payload);
        
        return JWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }
    
    /**
     * Generate token pair
     */
    public function generateTokenPair($userId, $payload = [])
    {
        $accessToken = $this->generateAccessToken($userId, $payload);
        $refreshToken = $this->generateRefreshToken($userId, $payload);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl
        ];
    }
    
    /**
     * Verify token
     */
    public function verify($token)
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            
            // Check if token is blacklisted
            if ($this->isBlacklisted($token)) {
                throw new AuthenticationException('Token has been revoked');
            }
            
            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw new AuthenticationException('Token has expired');
        } catch (SignatureInvalidException $e) {
            throw new AuthenticationException('Invalid token signature');
        } catch (\Exception $e) {
            throw new AuthenticationException('Invalid token');
        }
    }
    
    /**
     * Refresh access token
     */
    public function refreshAccessToken($refreshToken)
    {
        $payload = $this->verify($refreshToken);
        
        if ($payload['type'] !== 'refresh') {
            throw new AuthenticationException('Invalid token type');
        }
        
        // Blacklist the old refresh token
        $this->blacklistToken($refreshToken);
        
        // Generate new token pair
        return $this->generateTokenPair($payload['sub'], [
            'roles' => $payload['roles'] ?? [],
            'permissions' => $payload['permissions'] ?? []
        ]);
    }
    
    /**
     * Blacklist token
     */
    public function blacklistToken($token)
    {
        $payload = $this->verify($token);
        $jti = $payload['jti'];
        $exp = $payload['exp'];
        
        $this->blacklist[$jti] = $exp;
        
        // Store in database for persistence
        $this->storeBlacklistedToken($jti, $exp);
    }
    
    /**
     * Check if token is blacklisted
     */
    public function isBlacklisted($token)
    {
        try {
            $payload = $this->verify($token);
            $jti = $payload['jti'];
            
            // Check memory blacklist
            if (isset($this->blacklist[$jti])) {
                return true;
            }
            
            // Check database blacklist
            return $this->isTokenBlacklistedInDatabase($jti);
        } catch (\Exception $e) {
            return true; // Consider invalid tokens as blacklisted
        }
    }
    
    /**
     * Get token payload without verification
     */
    public function getPayload($token)
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid token format');
        }
        
        $payload = json_decode(base64_decode($parts[1]), true);
        
        if (!$payload) {
            throw new AuthenticationException('Invalid token payload');
        }
        
        return $payload;
    }
    
    /**
     * Get token header
     */
    public function getHeader($token)
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new AuthenticationException('Invalid token format');
        }
        
        $header = json_decode(base64_decode($parts[0]), true);
        
        if (!$header) {
            throw new AuthenticationException('Invalid token header');
        }
        
        return $header;
    }
    
    /**
     * Check if token is expired
     */
    public function isExpired($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['exp'] < time();
        } catch (\Exception $e) {
            return true;
        }
    }
    
    /**
     * Get token expiration time
     */
    public function getExpiration($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['exp'];
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get time until expiration
     */
    public function getTimeUntilExpiration($token)
    {
        $exp = $this->getExpiration($token);
        
        if (!$exp) {
            return 0;
        }
        
        return max(0, $exp - time());
    }
    
    /**
     * Generate JTI (JWT ID)
     */
    private function generateJti()
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Store blacklisted token in database
     */
    private function storeBlacklistedToken($jti, $exp)
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        
        $sql = "INSERT INTO blacklisted_tokens (jti, expires_at, created_at) VALUES (?, ?, ?)";
        
        try {
            $connection->execute($sql, [
                $jti,
                date('Y-m-d H:i:s', $exp),
                date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail
            error_log("Failed to store blacklisted token: " . $e->getMessage());
        }
    }
    
    /**
     * Check if token is blacklisted in database
     */
    private function isTokenBlacklistedInDatabase($jti)
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        
        $sql = "SELECT COUNT(*) as count FROM blacklisted_tokens WHERE jti = ? AND expires_at > ?";
        
        try {
            $result = $connection->fetchOne($sql, [
                $jti,
                date('Y-m-d H:i:s')
            ]);
            
            return $result['count'] > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Clean up expired blacklisted tokens
     */
    public function cleanupExpiredTokens()
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        
        $sql = "DELETE FROM blacklisted_tokens WHERE expires_at < ?";
        
        try {
            $connection->execute($sql, [date('Y-m-d H:i:s')]);
        } catch (\Exception $e) {
            error_log("Failed to cleanup expired tokens: " . $e->getMessage());
        }
    }
    
    /**
     * Get token statistics
     */
    public function getTokenStatistics()
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        
        $sql = "SELECT COUNT(*) as count FROM blacklisted_tokens WHERE expires_at > ?";
        
        try {
            $result = $connection->fetchOne($sql, [date('Y-m-d H:i:s')]);
            return [
                'blacklisted_tokens' => $result['count'],
                'memory_blacklist' => count($this->blacklist)
            ];
        } catch (\Exception $e) {
            return [
                'blacklisted_tokens' => 0,
                'memory_blacklist' => count($this->blacklist)
            ];
        }
    }
    
    /**
     * Validate token format
     */
    public function validateTokenFormat($token)
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        // Check if all parts are base64 encoded
        foreach ($parts as $part) {
            if (!base64_decode($part, true)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Extract user ID from token
     */
    public function getUserId($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['sub'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Extract roles from token
     */
    public function getRoles($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['roles'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Extract permissions from token
     */
    public function getPermissions($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['permissions'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Create token with custom claims
     */
    public function createTokenWithClaims($userId, $claims = [])
    {
        $payload = [
            'iss' => 'homeguru-mis',
            'aud' => 'homeguru-mis',
            'iat' => time(),
            'exp' => time() + $this->accessTokenTtl,
            'sub' => $userId,
            'type' => 'access',
            'jti' => $this->generateJti()
        ];
        
        // Merge custom claims
        $payload = array_merge($payload, $claims);
        
        return JWT::encode($payload, $this->secret, $this->algorithm);
    }
    
    /**
     * Verify token with specific claims
     */
    public function verifyWithClaims($token, $requiredClaims = [])
    {
        $payload = $this->verify($token);
        
        foreach ($requiredClaims as $claim => $value) {
            if (!isset($payload[$claim]) || $payload[$claim] !== $value) {
                throw new AuthenticationException("Token missing required claim: {$claim}");
            }
        }
        
        return $payload;
    }
    
    /**
     * Get token type
     */
    public function getTokenType($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['type'] ?? 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
    
    /**
     * Check if token is access token
     */
    public function isAccessToken($token)
    {
        return $this->getTokenType($token) === 'access';
    }
    
    /**
     * Check if token is refresh token
     */
    public function isRefreshToken($token)
    {
        return $this->getTokenType($token) === 'refresh';
    }
    
    /**
     * Get token issuer
     */
    public function getIssuer($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['iss'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get token audience
     */
    public function getAudience($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['aud'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get token issued at time
     */
    public function getIssuedAt($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['iat'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get token JTI
     */
    public function getJti($token)
    {
        try {
            $payload = $this->getPayload($token);
            return $payload['jti'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}