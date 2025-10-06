<?php

namespace App\Middleware;

use App\Libraries\Cache\Cache;
use App\Helpers\SecurityHelper;

class ThrottleMiddleware
{
    private $maxAttempts = 60; // Maximum attempts per minute
    private $decayMinutes = 1; // Decay time in minutes
    private $keyPrefix = 'throttle:';
    private $excludedRoutes = [];
    private $excludedIps = [];
    private $excludedUserAgents = [];
    
    public function __construct($maxAttempts = 60, $decayMinutes = 1)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        // Check if route is excluded from throttling
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }
        
        // Check if IP is excluded from throttling
        if ($this->isExcludedIp($request)) {
            return $next($request);
        }
        
        // Check if user agent is excluded from throttling
        if ($this->isExcludedUserAgent($request)) {
            return $next($request);
        }
        
        // Get throttle key
        $key = $this->getThrottleKey($request);
        
        // Check if rate limit is exceeded
        if ($this->tooManyAttempts($key)) {
            return $this->handleTooManyAttempts($request, $key);
        }
        
        // Increment attempts
        $this->incrementAttempts($key);
        
        // Add throttle headers to response
        $response = $next($request);
        $this->addThrottleHeaders($response, $key);
        
        return $response;
    }
    
    /**
     * Check if route is excluded from throttling
     */
    private function isExcludedRoute($request)
    {
        $path = $request->getPath();
        
        foreach ($this->excludedRoutes as $excludedRoute) {
            if (strpos($path, $excludedRoute) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if IP is excluded from throttling
     */
    private function isExcludedIp($request)
    {
        $ip = $this->getClientIp($request);
        
        foreach ($this->excludedIps as $excludedIp) {
            if ($this->ipMatches($ip, $excludedIp)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user agent is excluded from throttling
     */
    private function isExcludedUserAgent($request)
    {
        $userAgent = $request->getUserAgent();
        
        foreach ($this->excludedUserAgents as $excludedUserAgent) {
            if (strpos($userAgent, $excludedUserAgent) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get throttle key
     */
    private function getThrottleKey($request)
    {
        $ip = $this->getClientIp($request);
        $path = $request->getPath();
        $method = $request->getMethod();
        
        return $this->keyPrefix . md5($ip . '|' . $path . '|' . $method);
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp($request)
    {
        // Check for shared internet
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        
        // Check for IP passed from proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        // Check for IP from remote address
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        return 'unknown';
    }
    
    /**
     * Check if IP matches pattern
     */
    private function ipMatches($ip, $pattern)
    {
        // Check for exact match
        if ($ip === $pattern) {
            return true;
        }
        
        // Check for CIDR notation
        if (strpos($pattern, '/') !== false) {
            return $this->ipInCidr($ip, $pattern);
        }
        
        // Check for wildcard pattern
        if (strpos($pattern, '*') !== false) {
            $pattern = str_replace('*', '.*', $pattern);
            return preg_match('/^' . $pattern . '$/', $ip);
        }
        
        return false;
    }
    
    /**
     * Check if IP is in CIDR range
     */
    private function ipInCidr($ip, $cidr)
    {
        list($subnet, $mask) = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4InCidr($ip, $subnet, $mask);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6InCidr($ip, $subnet, $mask);
        }
        
        return false;
    }
    
    /**
     * Check if IPv4 is in CIDR range
     */
    private function ipv4InCidr($ip, $subnet, $mask)
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    
    /**
     * Check if IPv6 is in CIDR range
     */
    private function ipv6InCidr($ip, $subnet, $mask)
    {
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        
        $bytes = intval($mask / 8);
        $bits = $mask % 8;
        
        if ($bytes > 0) {
            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }
        }
        
        if ($bits > 0) {
            $ipByte = ord($ipBin[$bytes]);
            $subnetByte = ord($subnetBin[$bytes]);
            $maskByte = 0xFF << (8 - $bits);
            
            if (($ipByte & $maskByte) !== ($subnetByte & $maskByte)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if too many attempts
     */
    private function tooManyAttempts($key)
    {
        $attempts = $this->getAttempts($key);
        return $attempts >= $this->maxAttempts;
    }
    
    /**
     * Get attempts count
     */
    private function getAttempts($key)
    {
        $cache = Cache::getInstance();
        $data = $cache->get($key);
        
        if (!$data) {
            return 0;
        }
        
        $data = json_decode($data, true);
        return $data['attempts'] ?? 0;
    }
    
    /**
     * Increment attempts
     */
    private function incrementAttempts($key)
    {
        $cache = Cache::getInstance();
        $data = $cache->get($key);
        
        if (!$data) {
            $data = [
                'attempts' => 1,
                'first_attempt' => time()
            ];
        } else {
            $data = json_decode($data, true);
            $data['attempts']++;
        }
        
        $ttl = $this->decayMinutes * 60;
        $cache->put($key, json_encode($data), $ttl);
    }
    
    /**
     * Handle too many attempts
     */
    private function handleTooManyAttempts($request, $key)
    {
        $retryAfter = $this->getRetryAfter($key);
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Too many attempts. Please try again later.',
                'error_code' => 'TOO_MANY_ATTEMPTS',
                'retry_after' => $retryAfter
            ], 429);
        }
        
        return $this->redirectWithError('Too many attempts. Please try again later.', $retryAfter);
    }
    
    /**
     * Get retry after time
     */
    private function getRetryAfter($key)
    {
        $cache = Cache::getInstance();
        $data = $cache->get($key);
        
        if (!$data) {
            return $this->decayMinutes * 60;
        }
        
        $data = json_decode($data, true);
        $firstAttempt = $data['first_attempt'] ?? time();
        $elapsed = time() - $firstAttempt;
        $remaining = ($this->decayMinutes * 60) - $elapsed;
        
        return max(0, $remaining);
    }
    
    /**
     * Check if request is API request
     */
    private function isApiRequest($request)
    {
        return strpos($request->getPath(), '/api/') === 0;
    }
    
    /**
     * JSON response
     */
    private function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Redirect with error
     */
    private function redirectWithError($message, $retryAfter = 0)
    {
        $_SESSION['throttle_error'] = $message;
        $_SESSION['throttle_retry_after'] = $retryAfter;
        
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        header("Location: {$referer}");
        exit;
    }
    
    /**
     * Add throttle headers to response
     */
    private function addThrottleHeaders($response, $key)
    {
        $attempts = $this->getAttempts($key);
        $remaining = max(0, $this->maxAttempts - $attempts);
        $retryAfter = $this->getRetryAfter($key);
        
        header("X-RateLimit-Limit: {$this->maxAttempts}");
        header("X-RateLimit-Remaining: {$remaining}");
        header("X-RateLimit-Reset: " . (time() + $retryAfter));
        
        if ($remaining <= 0) {
            header("Retry-After: {$retryAfter}");
        }
    }
    
    /**
     * Clear attempts for key
     */
    public function clearAttempts($key)
    {
        $cache = Cache::getInstance();
        $cache->forget($key);
    }
    
    /**
     * Clear attempts for IP
     */
    public function clearAttemptsForIp($ip)
    {
        $pattern = $this->keyPrefix . md5($ip . '|*');
        $cache = Cache::getInstance();
        $cache->forgetPattern($pattern);
    }
    
    /**
     * Clear all attempts
     */
    public function clearAllAttempts()
    {
        $pattern = $this->keyPrefix . '*';
        $cache = Cache::getInstance();
        $cache->forgetPattern($pattern);
    }
    
    /**
     * Add excluded route
     */
    public function addExcludedRoute($route)
    {
        if (!in_array($route, $this->excludedRoutes)) {
            $this->excludedRoutes[] = $route;
        }
        return $this;
    }
    
    /**
     * Remove excluded route
     */
    public function removeExcludedRoute($route)
    {
        $key = array_search($route, $this->excludedRoutes);
        if ($key !== false) {
            unset($this->excludedRoutes[$key]);
        }
        return $this;
    }
    
    /**
     * Add excluded IP
     */
    public function addExcludedIp($ip)
    {
        if (!in_array($ip, $this->excludedIps)) {
            $this->excludedIps[] = $ip;
        }
        return $this;
    }
    
    /**
     * Remove excluded IP
     */
    public function removeExcludedIp($ip)
    {
        $key = array_search($ip, $this->excludedIps);
        if ($key !== false) {
            unset($this->excludedIps[$key]);
        }
        return $this;
    }
    
    /**
     * Add excluded user agent
     */
    public function addExcludedUserAgent($userAgent)
    {
        if (!in_array($userAgent, $this->excludedUserAgents)) {
            $this->excludedUserAgents[] = $userAgent;
        }
        return $this;
    }
    
    /**
     * Remove excluded user agent
     */
    public function removeExcludedUserAgent($userAgent)
    {
        $key = array_search($userAgent, $this->excludedUserAgents);
        if ($key !== false) {
            unset($this->excludedUserAgents[$key]);
        }
        return $this;
    }
    
    /**
     * Set max attempts
     */
    public function setMaxAttempts($maxAttempts)
    {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }
    
    /**
     * Set decay minutes
     */
    public function setDecayMinutes($decayMinutes)
    {
        $this->decayMinutes = $decayMinutes;
        return $this;
    }
    
    /**
     * Set key prefix
     */
    public function setKeyPrefix($prefix)
    {
        $this->keyPrefix = $prefix;
        return $this;
    }
    
    /**
     * Get max attempts
     */
    public function getMaxAttempts()
    {
        return $this->maxAttempts;
    }
    
    /**
     * Get decay minutes
     */
    public function getDecayMinutes()
    {
        return $this->decayMinutes;
    }
    
    /**
     * Get key prefix
     */
    public function getKeyPrefix()
    {
        return $this->keyPrefix;
    }
    
    /**
     * Get excluded routes
     */
    public function getExcludedRoutes()
    {
        return $this->excludedRoutes;
    }
    
    /**
     * Get excluded IPs
     */
    public function getExcludedIps()
    {
        return $this->excludedIps;
    }
    
    /**
     * Get excluded user agents
     */
    public function getExcludedUserAgents()
    {
        return $this->excludedUserAgents;
    }
    
    /**
     * Create middleware for specific attempts and decay
     */
    public static function for($maxAttempts, $decayMinutes = 1)
    {
        return new self($maxAttempts, $decayMinutes);
    }
    
    /**
     * Create middleware for login attempts
     */
    public static function forLogin()
    {
        return new self(5, 15); // 5 attempts per 15 minutes
    }
    
    /**
     * Create middleware for API requests
     */
    public static function forApi()
    {
        return new self(100, 1); // 100 attempts per minute
    }
    
    /**
     * Create middleware for general requests
     */
    public static function forGeneral()
    {
        return new self(60, 1); // 60 attempts per minute
    }
    
    /**
     * Clear throttle error from session
     */
    public function clearError()
    {
        unset($_SESSION['throttle_error']);
        unset($_SESSION['throttle_retry_after']);
    }
    
    /**
     * Get throttle error from session
     */
    public function getError()
    {
        return $_SESSION['throttle_error'] ?? null;
    }
    
    /**
     * Get throttle retry after from session
     */
    public function getRetryAfterFromSession()
    {
        return $_SESSION['throttle_retry_after'] ?? 0;
    }
    
    /**
     * Check if throttle error exists
     */
    public function hasError()
    {
        return isset($_SESSION['throttle_error']);
    }
    
    /**
     * Get throttle statistics
     */
    public function getStatistics()
    {
        $pattern = $this->keyPrefix . '*';
        $cache = Cache::getInstance();
        $keys = $cache->getKeys($pattern);
        
        $totalAttempts = 0;
        $activeKeys = 0;
        
        foreach ($keys as $key) {
            $data = $cache->get($key);
            if ($data) {
                $data = json_decode($data, true);
                $totalAttempts += $data['attempts'] ?? 0;
                $activeKeys++;
            }
        }
        
        return [
            'total_attempts' => $totalAttempts,
            'active_keys' => $activeKeys,
            'max_attempts' => $this->maxAttempts,
            'decay_minutes' => $this->decayMinutes
        ];
    }
}