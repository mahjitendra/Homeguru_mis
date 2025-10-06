<?php

namespace App\Middleware;

use App\Libraries\Database\Connection;
use App\Helpers\SecurityHelper;

class LogMiddleware
{
    private $logLevel = 'info';
    private $logChannels = ['file', 'database'];
    private $excludedRoutes = [];
    private $excludedMethods = [];
    private $excludedIps = [];
    private $excludedUserAgents = [];
    private $sensitiveFields = ['password', 'token', 'secret', 'key', 'ssn', 'credit_card'];
    private $maxLogSize = 10485760; // 10MB
    private $logRotation = true;
    
    public function __construct($logLevel = 'info')
    {
        $this->logLevel = $logLevel;
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        // Check if request should be logged
        if (!$this->shouldLog($request)) {
            return $next($request);
        }
        
        // Log request
        $this->logRequest($request);
        
        // Process request
        $response = $next($request);
        
        // Log response
        $this->logResponse($request, $response, $startTime, $startMemory);
        
        return $response;
    }
    
    /**
     * Check if request should be logged
     */
    private function shouldLog($request)
    {
        // Check if route is excluded
        if ($this->isExcludedRoute($request)) {
            return false;
        }
        
        // Check if method is excluded
        if ($this->isExcludedMethod($request)) {
            return false;
        }
        
        // Check if IP is excluded
        if ($this->isExcludedIp($request)) {
            return false;
        }
        
        // Check if user agent is excluded
        if ($this->isExcludedUserAgent($request)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if route is excluded
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
     * Check if method is excluded
     */
    private function isExcludedMethod($request)
    {
        $method = strtoupper($request->getMethod());
        return in_array($method, $this->excludedMethods);
    }
    
    /**
     * Check if IP is excluded
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
     * Check if user agent is excluded
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
     * Log request
     */
    private function logRequest($request)
    {
        $logData = [
            'type' => 'request',
            'method' => $request->getMethod(),
            'url' => $request->getFullUrl(),
            'path' => $request->getPath(),
            'query' => $request->getQuery(),
            'headers' => $this->sanitizeHeaders($request->getHeaders()),
            'body' => $this->sanitizeBody($request->getBody()),
            'ip' => $this->getClientIp($request),
            'user_agent' => $request->getUserAgent(),
            'user_id' => $request->user->id ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $this->logLevel
        ];
        
        $this->writeLog($logData);
    }
    
    /**
     * Log response
     */
    private function logResponse($request, $response, $startTime, $startMemory)
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $logData = [
            'type' => 'response',
            'method' => $request->getMethod(),
            'url' => $request->getFullUrl(),
            'path' => $request->getPath(),
            'status_code' => $response->getStatusCode(),
            'response_time' => round(($endTime - $startTime) * 1000, 2), // milliseconds
            'memory_usage' => $endMemory - $startMemory,
            'memory_peak' => memory_get_peak_usage(),
            'ip' => $this->getClientIp($request),
            'user_agent' => $request->getUserAgent(),
            'user_id' => $request->user->id ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $this->logLevel
        ];
        
        $this->writeLog($logData);
    }
    
    /**
     * Sanitize headers
     */
    private function sanitizeHeaders($headers)
    {
        $sanitized = [];
        
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Skip sensitive headers
            if (in_array($lowerKey, ['authorization', 'cookie', 'x-api-key', 'x-auth-token'])) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize body
     */
    private function sanitizeBody($body)
    {
        if (empty($body)) {
            return $body;
        }
        
        // Try to decode JSON
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->sanitizeArray($decoded);
        }
        
        // Try to parse form data
        if (strpos($body, '&') !== false) {
            parse_str($body, $parsed);
            return $this->sanitizeArray($parsed);
        }
        
        // Return as is if can't parse
        return $body;
    }
    
    /**
     * Sanitize array data
     */
    private function sanitizeArray($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Check if field is sensitive
            $isSensitive = false;
            foreach ($this->sensitiveFields as $sensitiveField) {
                if (strpos($lowerKey, $sensitiveField) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Write log
     */
    private function writeLog($logData)
    {
        foreach ($this->logChannels as $channel) {
            switch ($channel) {
                case 'file':
                    $this->writeToFile($logData);
                    break;
                case 'database':
                    $this->writeToDatabase($logData);
                    break;
                case 'syslog':
                    $this->writeToSyslog($logData);
                    break;
            }
        }
    }
    
    /**
     * Write to file
     */
    private function writeToFile($logData)
    {
        $logFile = storage_path('logs/access.log');
        $logDir = dirname($logFile);
        
        // Create log directory if it doesn't exist
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Check if log rotation is needed
        if ($this->logRotation && file_exists($logFile) && filesize($logFile) > $this->maxLogSize) {
            $this->rotateLogFile($logFile);
        }
        
        $logLine = json_encode($logData) . "\n";
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Write to database
     */
    private function writeToDatabase($logData)
    {
        try {
            $connection = Connection::getInstance();
            
            $sql = "INSERT INTO access_logs (
                type, method, url, path, query, headers, body, ip, user_agent, 
                user_id, status_code, response_time, memory_usage, memory_peak, 
                timestamp, level
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $logData['type'],
                $logData['method'],
                $logData['url'],
                $logData['path'],
                json_encode($logData['query'] ?? []),
                json_encode($logData['headers'] ?? []),
                json_encode($logData['body'] ?? ''),
                $logData['ip'],
                $logData['user_agent'],
                $logData['user_id'],
                $logData['status_code'] ?? null,
                $logData['response_time'] ?? null,
                $logData['memory_usage'] ?? null,
                $logData['memory_peak'] ?? null,
                $logData['timestamp'],
                $logData['level']
            ];
            
            $connection->execute($sql, $params);
        } catch (\Exception $e) {
            // Log error to file if database logging fails
            error_log("Failed to write to database log: " . $e->getMessage());
        }
    }
    
    /**
     * Write to syslog
     */
    private function writeToSyslog($logData)
    {
        $message = json_encode($logData);
        $priority = $this->getSyslogPriority($logData['level']);
        
        syslog($priority, $message);
    }
    
    /**
     * Get syslog priority
     */
    private function getSyslogPriority($level)
    {
        switch (strtolower($level)) {
            case 'emergency':
                return LOG_EMERG;
            case 'alert':
                return LOG_ALERT;
            case 'critical':
                return LOG_CRIT;
            case 'error':
                return LOG_ERR;
            case 'warning':
                return LOG_WARNING;
            case 'notice':
                return LOG_NOTICE;
            case 'info':
                return LOG_INFO;
            case 'debug':
                return LOG_DEBUG;
            default:
                return LOG_INFO;
        }
    }
    
    /**
     * Rotate log file
     */
    private function rotateLogFile($logFile)
    {
        $timestamp = date('Y-m-d-H-i-s');
        $rotatedFile = $logFile . '.' . $timestamp;
        
        rename($logFile, $rotatedFile);
        
        // Compress old log file
        if (function_exists('gzopen')) {
            $gzFile = $rotatedFile . '.gz';
            $fp = gzopen($gzFile, 'w9');
            gzwrite($fp, file_get_contents($rotatedFile));
            gzclose($fp);
            unlink($rotatedFile);
        }
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
     * Add excluded method
     */
    public function addExcludedMethod($method)
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->excludedMethods)) {
            $this->excludedMethods[] = $method;
        }
        return $this;
    }
    
    /**
     * Remove excluded method
     */
    public function removeExcludedMethod($method)
    {
        $method = strtoupper($method);
        $key = array_search($method, $this->excludedMethods);
        if ($key !== false) {
            unset($this->excludedMethods[$key]);
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
     * Add sensitive field
     */
    public function addSensitiveField($field)
    {
        if (!in_array($field, $this->sensitiveFields)) {
            $this->sensitiveFields[] = $field;
        }
        return $this;
    }
    
    /**
     * Remove sensitive field
     */
    public function removeSensitiveField($field)
    {
        $key = array_search($field, $this->sensitiveFields);
        if ($key !== false) {
            unset($this->sensitiveFields[$key]);
        }
        return $this;
    }
    
    /**
     * Set log level
     */
    public function setLogLevel($level)
    {
        $this->logLevel = $level;
        return $this;
    }
    
    /**
     * Set log channels
     */
    public function setLogChannels($channels)
    {
        $this->logChannels = $channels;
        return $this;
    }
    
    /**
     * Set max log size
     */
    public function setMaxLogSize($size)
    {
        $this->maxLogSize = $size;
        return $this;
    }
    
    /**
     * Set log rotation
     */
    public function setLogRotation($rotation)
    {
        $this->logRotation = $rotation;
        return $this;
    }
    
    /**
     * Get log level
     */
    public function getLogLevel()
    {
        return $this->logLevel;
    }
    
    /**
     * Get log channels
     */
    public function getLogChannels()
    {
        return $this->logChannels;
    }
    
    /**
     * Get excluded routes
     */
    public function getExcludedRoutes()
    {
        return $this->excludedRoutes;
    }
    
    /**
     * Get excluded methods
     */
    public function getExcludedMethods()
    {
        return $this->excludedMethods;
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
     * Get sensitive fields
     */
    public function getSensitiveFields()
    {
        return $this->sensitiveFields;
    }
    
    /**
     * Create middleware for specific log level
     */
    public static function forLevel($level)
    {
        return new self($level);
    }
    
    /**
     * Create middleware for debug level
     */
    public static function forDebug()
    {
        return new self('debug');
    }
    
    /**
     * Create middleware for info level
     */
    public static function forInfo()
    {
        return new self('info');
    }
    
    /**
     * Create middleware for warning level
     */
    public static function forWarning()
    {
        return new self('warning');
    }
    
    /**
     * Create middleware for error level
     */
    public static function forError()
    {
        return new self('error');
    }
    
    /**
     * Get log statistics
     */
    public function getStatistics()
    {
        $logFile = storage_path('logs/access.log');
        
        if (!file_exists($logFile)) {
            return [
                'total_requests' => 0,
                'file_size' => 0,
                'last_modified' => null
            ];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $totalRequests = count($lines);
        $fileSize = filesize($logFile);
        $lastModified = date('Y-m-d H:i:s', filemtime($logFile));
        
        return [
            'total_requests' => $totalRequests,
            'file_size' => $fileSize,
            'last_modified' => $lastModified
        ];
    }
    
    /**
     * Clear old logs
     */
    public function clearOldLogs($days = 30)
    {
        $logDir = storage_path('logs');
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        
        $files = glob($logDir . '/*.log*');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
}