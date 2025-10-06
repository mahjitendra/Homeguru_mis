<?php

namespace App\Middleware;

use App\Config\Auth;
use App\Models\User\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\JWTService;
use App\Exceptions\AuthenticationException;

class ApiAuthMiddleware
{
    private $authService;
    private $jwtService;
    private $excludedRoutes = [
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/forgot-password',
        '/api/auth/reset-password',
        '/api/auth/refresh',
        '/api/health',
        '/api/status'
    ];
    
    public function __construct()
    {
        $this->authService = new AuthService();
        $this->jwtService = new JWTService();
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
        
        // Get authorization header
        $authHeader = $request->getHeader('Authorization');
        
        if (!$authHeader) {
            return $this->handleUnauthenticated($request);
        }
        
        // Parse authorization header
        $authData = $this->parseAuthHeader($authHeader);
        
        if (!$authData) {
            return $this->handleUnauthenticated($request);
        }
        
        // Authenticate based on type
        $user = $this->authenticate($authData, $request);
        
        if (!$user) {
            return $this->handleUnauthenticated($request);
        }
        
        // Check if user account is active
        if (!$this->isActiveUser($user)) {
            return $this->handleInactiveUser($request);
        }
        
        // Add user to request
        $request->user = $user;
        
        // Update last activity
        $this->updateLastActivity($user);
        
        return $next($request);
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
     * Parse authorization header
     */
    private function parseAuthHeader($authHeader)
    {
        $parts = explode(' ', $authHeader, 2);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        $type = strtolower($parts[0]);
        $token = $parts[1];
        
        return [
            'type' => $type,
            'token' => $token
        ];
    }
    
    /**
     * Authenticate user
     */
    private function authenticate($authData, $request)
    {
        switch ($authData['type']) {
            case 'bearer':
                return $this->authenticateBearer($authData['token']);
            case 'basic':
                return $this->authenticateBasic($authData['token']);
            case 'apikey':
                return $this->authenticateApiKey($authData['token']);
            default:
                return null;
        }
    }
    
    /**
     * Authenticate Bearer token (JWT)
     */
    private function authenticateBearer($token)
    {
        try {
            $payload = $this->jwtService->verify($token);
            
            if (!$payload) {
                return null;
            }
            
            $userId = $payload['user_id'] ?? null;
            
            if (!$userId) {
                return null;
            }
            
            $user = User::find($userId);
            
            if (!$user) {
                return null;
            }
            
            // Check if token is blacklisted
            if ($this->jwtService->isBlacklisted($token)) {
                return null;
            }
            
            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Authenticate Basic authentication
     */
    private function authenticateBasic($token)
    {
        $credentials = base64_decode($token);
        
        if (!$credentials) {
            return null;
        }
        
        $parts = explode(':', $credentials, 2);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        $email = $parts[0];
        $password = $parts[1];
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return null;
        }
        
        if (!password_verify($password, $user->password)) {
            return null;
        }
        
        return $user;
    }
    
    /**
     * Authenticate API key
     */
    private function authenticateApiKey($token)
    {
        $user = User::where('api_key', $token)->first();
        
        if (!$user) {
            return null;
        }
        
        // Check if API key is active
        if ($user->api_key_status !== 'active') {
            return null;
        }
        
        // Check if API key is expired
        if ($user->api_key_expires_at && strtotime($user->api_key_expires_at) < time()) {
            return null;
        }
        
        return $user;
    }
    
    /**
     * Check if user account is active
     */
    private function isActiveUser($user)
    {
        return $user->status === Auth::STATUS_ACTIVE;
    }
    
    /**
     * Update last activity
     */
    private function updateLastActivity($user)
    {
        $this->authService->updateLastActivity($user->id);
    }
    
    /**
     * Handle unauthenticated request
     */
    private function handleUnauthenticated($request)
    {
        return $this->jsonResponse([
            'success' => false,
            'message' => 'Authentication required',
            'error_code' => 'UNAUTHENTICATED'
        ], 401);
    }
    
    /**
     * Handle inactive user
     */
    private function handleInactiveUser($request)
    {
        return $this->jsonResponse([
            'success' => false,
            'message' => 'Account is inactive',
            'error_code' => 'ACCOUNT_INACTIVE'
        ], 403);
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
        $request = new \stdClass();
        $request->user = null;
        
        $middleware = new self();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (!$authHeader) {
            return false;
        }
        
        $authData = $middleware->parseAuthHeader($authHeader);
        
        if (!$authData) {
            return false;
        }
        
        $user = $middleware->authenticate($authData, $request);
        
        return $user !== null;
    }
    
    /**
     * Get current user (static method)
     */
    public static function user()
    {
        $request = new \stdClass();
        $request->user = null;
        
        $middleware = new self();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (!$authHeader) {
            return null;
        }
        
        $authData = $middleware->parseAuthHeader($authHeader);
        
        if (!$authData) {
            return null;
        }
        
        return $middleware->authenticate($authData, $request);
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
     * Generate API key for user
     */
    public function generateApiKey($userId, $expiresAt = null)
    {
        $apiKey = $this->generateRandomApiKey();
        
        $user = User::find($userId);
        
        if (!$user) {
            return false;
        }
        
        $user->api_key = $apiKey;
        $user->api_key_status = 'active';
        $user->api_key_expires_at = $expiresAt;
        $user->save();
        
        return $apiKey;
    }
    
    /**
     * Generate random API key
     */
    private function generateRandomApiKey()
    {
        return 'hg_' . bin2hex(random_bytes(32));
    }
    
    /**
     * Revoke API key
     */
    public function revokeApiKey($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return false;
        }
        
        $user->api_key = null;
        $user->api_key_status = 'revoked';
        $user->api_key_expires_at = null;
        $user->save();
        
        return true;
    }
    
    /**
     * Validate API key
     */
    public function validateApiKey($apiKey)
    {
        $user = User::where('api_key', $apiKey)->first();
        
        if (!$user) {
            return false;
        }
        
        if ($user->api_key_status !== 'active') {
            return false;
        }
        
        if ($user->api_key_expires_at && strtotime($user->api_key_expires_at) < time()) {
            return false;
        }
        
        return $user;
    }
    
    /**
     * Get API key info
     */
    public function getApiKeyInfo($apiKey)
    {
        $user = User::where('api_key', $apiKey)->first();
        
        if (!$user) {
            return null;
        }
        
        return [
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'status' => $user->api_key_status,
            'expires_at' => $user->api_key_expires_at,
            'created_at' => $user->created_at,
            'last_used_at' => $user->last_activity_at
        ];
    }
    
    /**
     * Get all API keys for user
     */
    public function getUserApiKeys($userId)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return [];
        }
        
        return [
            'api_key' => $user->api_key,
            'status' => $user->api_key_status,
            'expires_at' => $user->api_key_expires_at,
            'created_at' => $user->created_at,
            'last_used_at' => $user->last_activity_at
        ];
    }
    
    /**
     * Refresh API key
     */
    public function refreshApiKey($userId, $expiresAt = null)
    {
        $this->revokeApiKey($userId);
        return $this->generateApiKey($userId, $expiresAt);
    }
    
    /**
     * Get authentication methods
     */
    public function getAuthMethods()
    {
        return [
            'bearer' => 'JWT Bearer Token',
            'basic' => 'Basic Authentication',
            'apikey' => 'API Key'
        ];
    }
    
    /**
     * Get authentication requirements
     */
    public function getAuthRequirements()
    {
        return [
            'bearer' => [
                'header' => 'Authorization: Bearer <token>',
                'description' => 'JWT token in Authorization header'
            ],
            'basic' => [
                'header' => 'Authorization: Basic <base64_encoded_credentials>',
                'description' => 'Base64 encoded email:password'
            ],
            'apikey' => [
                'header' => 'Authorization: ApiKey <key>',
                'description' => 'API key in Authorization header'
            ]
        ];
    }
    
    /**
     * Get rate limits for authenticated user
     */
    public function getRateLimits($user)
    {
        $role = $user->role ?? 'user';
        
        $limits = [
            'superadmin' => ['requests' => 10000, 'period' => 3600],
            'admin' => ['requests' => 5000, 'period' => 3600],
            'teacher' => ['requests' => 1000, 'period' => 3600],
            'student' => ['requests' => 500, 'period' => 3600],
            'parent' => ['requests' => 300, 'period' => 3600],
            'user' => ['requests' => 100, 'period' => 3600]
        ];
        
        return $limits[$role] ?? $limits['user'];
    }
    
    /**
     * Check rate limit for user
     */
    public function checkRateLimit($user, $endpoint = null)
    {
        $limits = $this->getRateLimits($user);
        $key = "rate_limit:{$user->id}:" . ($endpoint ?? 'global');
        
        // Implementation would depend on your cache system
        // This is a simplified version
        return true;
    }
    
    /**
     * Get user permissions for API
     */
    public function getUserPermissions($user)
    {
        if (method_exists($user, 'getPermissions')) {
            return $user->getPermissions();
        }
        
        // Fallback to role-based permissions
        $role = $user->role ?? 'user';
        
        $permissions = [
            'superadmin' => ['*'],
            'admin' => ['read', 'write', 'delete'],
            'teacher' => ['read', 'write'],
            'student' => ['read'],
            'parent' => ['read'],
            'user' => ['read']
        ];
        
        return $permissions[$role] ?? $permissions['user'];
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission($user, $permission)
    {
        $permissions = $this->getUserPermissions($user);
        
        if (in_array('*', $permissions)) {
            return true;
        }
        
        return in_array($permission, $permissions);
    }
    
    /**
     * Get user roles for API
     */
    public function getUserRoles($user)
    {
        if (method_exists($user, 'getRoles')) {
            return $user->getRoles();
        }
        
        return [$user->role ?? 'user'];
    }
    
    /**
     * Check if user has role
     */
    public function hasRole($user, $role)
    {
        $roles = $this->getUserRoles($user);
        return in_array($role, $roles);
    }
    
    /**
     * Get user info for API
     */
    public function getUserInfo($user)
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'roles' => $this->getUserRoles($user),
            'permissions' => $this->getUserPermissions($user),
            'status' => $user->status,
            'created_at' => $user->created_at,
            'last_activity_at' => $user->last_activity_at
        ];
    }
}