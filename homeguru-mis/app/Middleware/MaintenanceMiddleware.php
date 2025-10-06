<?php

namespace App\Middleware;

use App\Config\App;

class MaintenanceMiddleware
{
    private $maintenanceMode = false;
    private $allowedIps = [];
    private $allowedUsers = [];
    private $excludedRoutes = [];
    private $maintenanceMessage = 'The application is currently under maintenance. Please try again later.';
    private $retryAfter = 3600; // 1 hour
    private $maintenancePage = null;
    
    public function __construct()
    {
        $this->maintenanceMode = $this->getMaintenanceMode();
        $this->loadConfiguration();
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        // Check if maintenance mode is enabled
        if (!$this->maintenanceMode) {
            return $next($request);
        }
        
        // Check if route is excluded from maintenance mode
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }
        
        // Check if IP is allowed during maintenance
        if ($this->isAllowedIp($request)) {
            return $next($request);
        }
        
        // Check if user is allowed during maintenance
        if ($this->isAllowedUser($request)) {
            return $next($request);
        }
        
        // Return maintenance response
        return $this->handleMaintenanceMode($request);
    }
    
    /**
     * Get maintenance mode status
     */
    private function getMaintenanceMode()
    {
        // Check environment variable
        if (isset($_ENV['MAINTENANCE_MODE']) && $_ENV['MAINTENANCE_MODE'] === 'true') {
            return true;
        }
        
        // Check application config
        if (App::isMaintenanceMode()) {
            return true;
        }
        
        // Check maintenance file
        $maintenanceFile = storage_path('maintenance.json');
        if (file_exists($maintenanceFile)) {
            $data = json_decode(file_get_contents($maintenanceFile), true);
            return $data['enabled'] ?? false;
        }
        
        return false;
    }
    
    /**
     * Load configuration
     */
    private function loadConfiguration()
    {
        $maintenanceFile = storage_path('maintenance.json');
        
        if (file_exists($maintenanceFile)) {
            $data = json_decode(file_get_contents($maintenanceFile), true);
            
            $this->allowedIps = $data['allowed_ips'] ?? [];
            $this->allowedUsers = $data['allowed_users'] ?? [];
            $this->excludedRoutes = $data['excluded_routes'] ?? [];
            $this->maintenanceMessage = $data['message'] ?? $this->maintenanceMessage;
            $this->retryAfter = $data['retry_after'] ?? $this->retryAfter;
            $this->maintenancePage = $data['maintenance_page'] ?? null;
        }
    }
    
    /**
     * Check if route is excluded from maintenance mode
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
     * Check if IP is allowed during maintenance
     */
    private function isAllowedIp($request)
    {
        $ip = $this->getClientIp($request);
        
        foreach ($this->allowedIps as $allowedIp) {
            if ($this->ipMatches($ip, $allowedIp)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user is allowed during maintenance
     */
    private function isAllowedUser($request)
    {
        $user = $request->user ?? null;
        
        if (!$user) {
            return false;
        }
        
        $userId = $user->id ?? null;
        $userEmail = $user->email ?? null;
        
        if ($userId && in_array($userId, $this->allowedUsers)) {
            return true;
        }
        
        if ($userEmail && in_array($userEmail, $this->allowedUsers)) {
            return true;
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
     * Handle maintenance mode
     */
    private function handleMaintenanceMode($request)
    {
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $this->maintenanceMessage,
                'error_code' => 'MAINTENANCE_MODE',
                'retry_after' => $this->retryAfter
            ], 503);
        }
        
        if ($this->maintenancePage && file_exists($this->maintenancePage)) {
            return $this->renderMaintenancePage();
        }
        
        return $this->renderDefaultMaintenancePage();
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
        header('Retry-After: ' . $this->retryAfter);
        echo json_encode($data);
        exit;
    }
    
    /**
     * Render maintenance page
     */
    private function renderMaintenancePage()
    {
        http_response_code(503);
        header('Retry-After: ' . $this->retryAfter);
        
        include $this->maintenancePage;
        exit;
    }
    
    /**
     * Render default maintenance page
     */
    private function renderDefaultMaintenancePage()
    {
        http_response_code(503);
        header('Retry-After: ' . $this->retryAfter);
        header('Content-Type: text/html; charset=utf-8');
        
        $html = $this->getDefaultMaintenanceHtml();
        echo $html;
        exit;
    }
    
    /**
     * Get default maintenance HTML
     */
    private function getDefaultMaintenanceHtml()
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }
        .container {
            text-align: center;
            background: white;
            padding: 3rem;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 500px;
            margin: 2rem;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #667eea;
        }
        h1 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 2rem;
        }
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .retry-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            border-left: 4px solid #667eea;
            font-size: 0.9rem;
            color: #666;
        }
        .footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔧</div>
        <h1>Maintenance Mode</h1>
        <p>' . htmlspecialchars($this->maintenanceMessage) . '</p>
        <div class="retry-info">
            <strong>Retry After:</strong> ' . $this->formatRetryAfter() . '
        </div>
        <div class="footer">
            <p>We apologize for any inconvenience caused.</p>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Format retry after time
     */
    private function formatRetryAfter()
    {
        if ($this->retryAfter < 60) {
            return $this->retryAfter . ' seconds';
        } elseif ($this->retryAfter < 3600) {
            $minutes = floor($this->retryAfter / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        } else {
            $hours = floor($this->retryAfter / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
    }
    
    /**
     * Enable maintenance mode
     */
    public function enable($message = null, $retryAfter = null, $allowedIps = [], $allowedUsers = [], $excludedRoutes = [])
    {
        $data = [
            'enabled' => true,
            'message' => $message ?? $this->maintenanceMessage,
            'retry_after' => $retryAfter ?? $this->retryAfter,
            'allowed_ips' => $allowedIps,
            'allowed_users' => $allowedUsers,
            'excluded_routes' => $excludedRoutes,
            'enabled_at' => date('Y-m-d H:i:s'),
            'enabled_by' => $_SESSION['user_id'] ?? null
        ];
        
        $maintenanceFile = storage_path('maintenance.json');
        $maintenanceDir = dirname($maintenanceFile);
        
        if (!is_dir($maintenanceDir)) {
            mkdir($maintenanceDir, 0755, true);
        }
        
        file_put_contents($maintenanceFile, json_encode($data, JSON_PRETTY_PRINT));
        
        $this->maintenanceMode = true;
        $this->loadConfiguration();
        
        return true;
    }
    
    /**
     * Disable maintenance mode
     */
    public function disable()
    {
        $maintenanceFile = storage_path('maintenance.json');
        
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
        
        $this->maintenanceMode = false;
        
        return true;
    }
    
    /**
     * Check if maintenance mode is enabled
     */
    public function isEnabled()
    {
        return $this->maintenanceMode;
    }
    
    /**
     * Get maintenance configuration
     */
    public function getConfiguration()
    {
        return [
            'enabled' => $this->maintenanceMode,
            'message' => $this->maintenanceMessage,
            'retry_after' => $this->retryAfter,
            'allowed_ips' => $this->allowedIps,
            'allowed_users' => $this->allowedUsers,
            'excluded_routes' => $this->excludedRoutes,
            'maintenance_page' => $this->maintenancePage
        ];
    }
    
    /**
     * Add allowed IP
     */
    public function addAllowedIp($ip)
    {
        if (!in_array($ip, $this->allowedIps)) {
            $this->allowedIps[] = $ip;
        }
        return $this;
    }
    
    /**
     * Remove allowed IP
     */
    public function removeAllowedIp($ip)
    {
        $key = array_search($ip, $this->allowedIps);
        if ($key !== false) {
            unset($this->allowedIps[$key]);
        }
        return $this;
    }
    
    /**
     * Add allowed user
     */
    public function addAllowedUser($user)
    {
        if (!in_array($user, $this->allowedUsers)) {
            $this->allowedUsers[] = $user;
        }
        return $this;
    }
    
    /**
     * Remove allowed user
     */
    public function removeAllowedUser($user)
    {
        $key = array_search($user, $this->allowedUsers);
        if ($key !== false) {
            unset($this->allowedUsers[$key]);
        }
        return $this;
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
     * Set maintenance message
     */
    public function setMessage($message)
    {
        $this->maintenanceMessage = $message;
        return $this;
    }
    
    /**
     * Set retry after time
     */
    public function setRetryAfter($retryAfter)
    {
        $this->retryAfter = $retryAfter;
        return $this;
    }
    
    /**
     * Set maintenance page
     */
    public function setMaintenancePage($page)
    {
        $this->maintenancePage = $page;
        return $this;
    }
    
    /**
     * Get maintenance status (static method)
     */
    public static function isEnabled()
    {
        $middleware = new self();
        return $middleware->isEnabled();
    }
    
    /**
     * Enable maintenance mode (static method)
     */
    public static function enable($message = null, $retryAfter = null, $allowedIps = [], $allowedUsers = [], $excludedRoutes = [])
    {
        $middleware = new self();
        return $middleware->enable($message, $retryAfter, $allowedIps, $allowedUsers, $excludedRoutes);
    }
    
    /**
     * Disable maintenance mode (static method)
     */
    public static function disable()
    {
        $middleware = new self();
        return $middleware->disable();
    }
    
    /**
     * Get maintenance configuration (static method)
     */
    public static function getConfiguration()
    {
        $middleware = new self();
        return $middleware->getConfiguration();
    }
}