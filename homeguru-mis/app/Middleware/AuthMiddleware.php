<?php

namespace App\Middleware;

use App\Config\Auth;
use App\Models\User\User;
use App\Services\Auth\AuthService;
use App\Exceptions\AuthenticationException;

class AuthMiddleware
{
    private $authService;
    private $excludedRoutes = [
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/forgot-password',
        '/api/auth/reset-password'
    ];
    
    public function __construct()
    {
        $this->authService = new AuthService();
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        // Check if route is excluded from authentication
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }
        
        // Check if user is authenticated
        if (!$this->isAuthenticated()) {
            return $this->handleUnauthenticated($request);
        }
        
        // Check if user session is valid
        if (!$this->isValidSession()) {
            return $this->handleInvalidSession($request);
        }
        
        // Check if user account is active
        if (!$this->isActiveUser()) {
            return $this->handleInactiveUser($request);
        }
        
        // Update last activity
        $this->updateLastActivity();
        
        // Add user to request
        $request->user = $this->getCurrentUser();
        
        return $next($request);
    }
    
    /**
     * Check if user is authenticated
     */
    private function isAuthenticated()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Check if user session is valid
     */
    private function isValidSession()
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        // Check session lifetime
        if (isset($_SESSION['last_activity'])) {
            $sessionLifetime = Auth::SESSION_LIFETIME;
            if (time() - $_SESSION['last_activity'] > $sessionLifetime) {
                return false;
            }
        }
        
        // Verify session token
        $userId = $_SESSION['user_id'];
        $sessionToken = $_SESSION['session_token'];
        
        return $this->authService->verifySessionToken($userId, $sessionToken);
    }
    
    /**
     * Check if user account is active
     */
    private function isActiveUser()
    {
        $user = $this->getCurrentUser();
        
        if (!$user) {
            return false;
        }
        
        return $user->status === Auth::STATUS_ACTIVE;
    }
    
    /**
     * Get current user
     */
    private function getCurrentUser()
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        $userId = $_SESSION['user_id'];
        
        // Check cache first
        $cacheKey = "user_{$userId}";
        $user = $this->getFromCache($cacheKey);
        
        if (!$user) {
            $user = User::find($userId);
            if ($user) {
                $this->putInCache($cacheKey, $user, 300); // Cache for 5 minutes
            }
        }
        
        return $user;
    }
    
    /**
     * Update last activity
     */
    private function updateLastActivity()
    {
        $_SESSION['last_activity'] = time();
        
        // Update in database
        $userId = $_SESSION['user_id'];
        $this->authService->updateLastActivity($userId);
    }
    
    /**
     * Check if route is excluded from authentication
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
     * Handle unauthenticated request
     */
    private function handleUnauthenticated($request)
    {
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Authentication required',
                'error_code' => 'UNAUTHENTICATED'
            ], 401);
        }
        
        // Store intended URL for redirect after login
        $_SESSION['intended_url'] = $request->getFullUrl();
        
        return $this->redirect('/login');
    }
    
    /**
     * Handle invalid session
     */
    private function handleInvalidSession($request)
    {
        // Clear session
        $this->clearSession();
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Session expired',
                'error_code' => 'SESSION_EXPIRED'
            ], 401);
        }
        
        return $this->redirect('/login?expired=1');
    }
    
    /**
     * Handle inactive user
     */
    private function handleInactiveUser($request)
    {
        // Clear session
        $this->clearSession();
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Account is inactive',
                'error_code' => 'ACCOUNT_INACTIVE'
            ], 403);
        }
        
        return $this->redirect('/login?inactive=1');
    }
    
    /**
     * Check if request is API request
     */
    private function isApiRequest($request)
    {
        return strpos($request->getPath(), '/api/') === 0;
    }
    
    /**
     * Clear user session
     */
    private function clearSession()
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['session_token']);
        unset($_SESSION['last_activity']);
        unset($_SESSION['intended_url']);
    }
    
    /**
     * Get from cache
     */
    private function getFromCache($key)
    {
        // Implement your cache logic here
        return null;
    }
    
    /**
     * Put in cache
     */
    private function putInCache($key, $value, $ttl = 300)
    {
        // Implement your cache logic here
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
     * Redirect response
     */
    private function redirect($url)
    {
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Add excluded route
     */
    public function addExcludedRoute($route)
    {
        $this->excludedRoutes[] = $route;
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
     * Get excluded routes
     */
    public function getExcludedRoutes()
    {
        return $this->excludedRoutes;
    }
    
    /**
     * Check if user is authenticated (static method)
     */
    public static function check()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Get current user ID (static method)
     */
    public static function userId()
    {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user (static method)
     */
    public static function user()
    {
        $userId = self::userId();
        return $userId ? User::find($userId) : null;
    }
    
    /**
     * Require authentication (static method)
     */
    public static function requireAuth()
    {
        if (!self::check()) {
            throw new AuthenticationException('Authentication required');
        }
    }
    
    /**
     * Login user
     */
    public function login($userId, $remember = false)
    {
        // Generate session token
        $sessionToken = $this->authService->generateSessionToken($userId);
        
        // Set session variables
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['last_activity'] = time();
        
        // Set remember me cookie if requested
        if ($remember) {
            $rememberToken = $this->authService->generateRememberToken($userId);
            setcookie('remember_token', $rememberToken, time() + Auth::REMEMBER_ME_LIFETIME, '/', '', true, true);
        }
        
        // Update last login
        $this->authService->updateLastLogin($userId);
        
        return true;
    }
    
    /**
     * Logout user
     */
    public function logout()
    {
        $userId = $_SESSION['user_id'] ?? null;
        
        if ($userId) {
            // Invalidate session token
            $this->authService->invalidateSessionToken($userId, $_SESSION['session_token']);
        }
        
        // Clear session
        $this->clearSession();
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
        
        return true;
    }
    
    /**
     * Check remember me token
     */
    public function checkRememberMe()
    {
        if (isset($_COOKIE['remember_token'])) {
            $rememberToken = $_COOKIE['remember_token'];
            $userId = $this->authService->validateRememberToken($rememberToken);
            
            if ($userId) {
                $this->login($userId, true);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Regenerate session ID
     */
    public function regenerateSessionId()
    {
        session_regenerate_id(true);
    }
    
    /**
     * Get session data
     */
    public function getSessionData()
    {
        return [
            'user_id' => $_SESSION['user_id'] ?? null,
            'session_token' => $_SESSION['session_token'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null,
            'intended_url' => $_SESSION['intended_url'] ?? null
        ];
    }
    
    /**
     * Set session data
     */
    public function setSessionData($key, $value)
    {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get session data by key
     */
    public function getSessionDataByKey($key)
    {
        return $_SESSION[$key] ?? null;
    }
    
    /**
     * Clear session data by key
     */
    public function clearSessionDataByKey($key)
    {
        unset($_SESSION[$key]);
    }
}