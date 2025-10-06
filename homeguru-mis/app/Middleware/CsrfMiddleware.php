<?php

namespace App\Middleware;

use App\Helpers\SecurityHelper;

class CsrfMiddleware
{
    private $excludedRoutes = [
        '/api/',
        '/webhook/',
        '/callback/'
    ];
    
    private $excludedMethods = ['GET', 'HEAD', 'OPTIONS'];
    
    private $tokenName = '_token';
    private $headerName = 'X-CSRF-TOKEN';
    private $cookieName = 'XSRF-TOKEN';
    
    public function __construct()
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        // Check if route is excluded from CSRF protection
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }
        
        // Check if method is excluded from CSRF protection
        if ($this->isExcludedMethod($request)) {
            return $next($request);
        }
        
        // Generate CSRF token if not exists
        if (!$this->hasToken()) {
            $this->generateToken();
        }
        
        // Validate CSRF token
        if (!$this->validateToken($request)) {
            return $this->handleInvalidToken($request);
        }
        
        // Regenerate token for security
        $this->regenerateToken();
        
        return $next($request);
    }
    
    /**
     * Check if route is excluded from CSRF protection
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
     * Check if method is excluded from CSRF protection
     */
    private function isExcludedMethod($request)
    {
        $method = strtoupper($request->getMethod());
        return in_array($method, $this->excludedMethods);
    }
    
    /**
     * Check if CSRF token exists
     */
    private function hasToken()
    {
        return isset($_SESSION[$this->tokenName]) && !empty($_SESSION[$this->tokenName]);
    }
    
    /**
     * Generate CSRF token
     */
    private function generateToken()
    {
        $token = SecurityHelper::generateRandomString(40);
        $_SESSION[$this->tokenName] = $token;
        return $token;
    }
    
    /**
     * Regenerate CSRF token
     */
    private function regenerateToken()
    {
        $this->generateToken();
    }
    
    /**
     * Validate CSRF token
     */
    private function validateToken($request)
    {
        $token = $this->getTokenFromRequest($request);
        $sessionToken = $_SESSION[$this->tokenName] ?? null;
        
        if (!$token || !$sessionToken) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Get CSRF token from request
     */
    private function getTokenFromRequest($request)
    {
        // Check header first
        $token = $request->getHeader($this->headerName);
        if ($token) {
            return $token;
        }
        
        // Check cookie
        $token = $request->getCookie($this->cookieName);
        if ($token) {
            return $token;
        }
        
        // Check form data
        $token = $request->getInput($this->tokenName);
        if ($token) {
            return $token;
        }
        
        return null;
    }
    
    /**
     * Handle invalid CSRF token
     */
    private function handleInvalidToken($request)
    {
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'CSRF token mismatch',
                'error_code' => 'CSRF_TOKEN_MISMATCH'
            ], 419);
        }
        
        // For web requests, redirect back with error
        $this->redirectBackWithError('CSRF token mismatch');
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
     * Redirect back with error
     */
    private function redirectBackWithError($message)
    {
        $_SESSION['csrf_error'] = $message;
        
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        header("Location: {$referer}");
        exit;
    }
    
    /**
     * Get current CSRF token
     */
    public function getToken()
    {
        if (!$this->hasToken()) {
            $this->generateToken();
        }
        
        return $_SESSION[$this->tokenName];
    }
    
    /**
     * Get CSRF token HTML input
     */
    public function getTokenInput()
    {
        $token = $this->getToken();
        return '<input type="hidden" name="' . $this->tokenName . '" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Get CSRF token meta tag
     */
    public function getTokenMeta()
    {
        $token = $this->getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Get CSRF token for JavaScript
     */
    public function getTokenForJs()
    {
        $token = $this->getToken();
        return 'window.csrfToken = "' . addslashes($token) . '";';
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
     * Set token name
     */
    public function setTokenName($name)
    {
        $this->tokenName = $name;
        return $this;
    }
    
    /**
     * Set header name
     */
    public function setHeaderName($name)
    {
        $this->headerName = $name;
        return $this;
    }
    
    /**
     * Set cookie name
     */
    public function setCookieName($name)
    {
        $this->cookieName = $name;
        return $this;
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
     * Get token name
     */
    public function getTokenName()
    {
        return $this->tokenName;
    }
    
    /**
     * Get header name
     */
    public function getHeaderName()
    {
        return $this->headerName;
    }
    
    /**
     * Get cookie name
     */
    public function getCookieName()
    {
        return $this->cookieName;
    }
    
    /**
     * Clear CSRF error from session
     */
    public function clearError()
    {
        unset($_SESSION['csrf_error']);
    }
    
    /**
     * Get CSRF error from session
     */
    public function getError()
    {
        return $_SESSION['csrf_error'] ?? null;
    }
    
    /**
     * Check if CSRF error exists
     */
    public function hasError()
    {
        return isset($_SESSION['csrf_error']);
    }
    
    /**
     * Get CSRF token (static method)
     */
    public static function token()
    {
        $middleware = new self();
        return $middleware->getToken();
    }
    
    /**
     * Get CSRF token input (static method)
     */
    public static function tokenInput()
    {
        $middleware = new self();
        return $middleware->getTokenInput();
    }
    
    /**
     * Get CSRF token meta (static method)
     */
    public static function tokenMeta()
    {
        $middleware = new self();
        return $middleware->getTokenMeta();
    }
    
    /**
     * Get CSRF token for JavaScript (static method)
     */
    public static function tokenForJs()
    {
        $middleware = new self();
        return $middleware->getTokenForJs();
    }
    
    /**
     * Validate CSRF token (static method)
     */
    public static function validate($request)
    {
        $middleware = new self();
        return $middleware->validateToken($request);
    }
    
    /**
     * Generate CSRF token (static method)
     */
    public static function generate()
    {
        $middleware = new self();
        return $middleware->generateToken();
    }
    
    /**
     * Clear CSRF error (static method)
     */
    public static function clearError()
    {
        $middleware = new self();
        $middleware->clearError();
    }
    
    /**
     * Get CSRF error (static method)
     */
    public static function getError()
    {
        $middleware = new self();
        return $middleware->getError();
    }
    
    /**
     * Check if CSRF error exists (static method)
     */
    public static function hasError()
    {
        $middleware = new self();
        return $middleware->hasError();
    }
    
    /**
     * Get CSRF token for AJAX requests
     */
    public function getTokenForAjax()
    {
        $token = $this->getToken();
        return [
            'token' => $token,
            'header' => $this->headerName,
            'cookie' => $this->cookieName
        ];
    }
    
    /**
     * Set CSRF token for AJAX requests
     */
    public function setTokenForAjax()
    {
        $token = $this->getToken();
        
        // Set cookie for AJAX requests
        setcookie($this->cookieName, $token, 0, '/', '', false, true);
        
        // Set header for AJAX requests
        header("X-CSRF-TOKEN: {$token}");
    }
    
    /**
     * Verify CSRF token for AJAX requests
     */
    public function verifyTokenForAjax($request)
    {
        $token = $this->getTokenFromRequest($request);
        $sessionToken = $_SESSION[$this->tokenName] ?? null;
        
        if (!$token || !$sessionToken) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Get CSRF token expiration time
     */
    public function getTokenExpiration()
    {
        return $_SESSION['csrf_token_expires'] ?? null;
    }
    
    /**
     * Set CSRF token expiration
     */
    public function setTokenExpiration($expiration)
    {
        $_SESSION['csrf_token_expires'] = $expiration;
    }
    
    /**
     * Check if CSRF token is expired
     */
    public function isTokenExpired()
    {
        $expiration = $this->getTokenExpiration();
        
        if (!$expiration) {
            return false;
        }
        
        return time() > $expiration;
    }
    
    /**
     * Refresh CSRF token if expired
     */
    public function refreshTokenIfExpired()
    {
        if ($this->isTokenExpired()) {
            $this->generateToken();
            $this->setTokenExpiration(time() + 3600); // 1 hour
        }
    }
}