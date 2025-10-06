<?php

namespace App\Middleware;

use App\Config\Auth;
use App\Models\User\User;
use App\Exceptions\AuthorizationException;

class RoleMiddleware
{
    private $requiredRoles = [];
    private $excludedRoles = [];
    private $requireAll = false; // If true, user must have ALL roles, if false, user needs ANY role
    
    public function __construct($roles = [], $requireAll = false)
    {
        $this->requiredRoles = is_array($roles) ? $roles : [$roles];
        $this->requireAll = $requireAll;
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        $user = $request->user ?? AuthMiddleware::user();
        
        if (!$user) {
            return $this->handleUnauthenticated($request);
        }
        
        if (!$this->hasRequiredRole($user)) {
            return $this->handleUnauthorized($request, $user);
        }
        
        if ($this->hasExcludedRole($user)) {
            return $this->handleExcludedRole($request, $user);
        }
        
        return $next($request);
    }
    
    /**
     * Check if user has required role(s)
     */
    private function hasRequiredRole($user)
    {
        if (empty($this->requiredRoles)) {
            return true;
        }
        
        $userRoles = $this->getUserRoles($user);
        
        if ($this->requireAll) {
            // User must have ALL required roles
            return $this->hasAllRoles($userRoles, $this->requiredRoles);
        } else {
            // User needs ANY of the required roles
            return $this->hasAnyRole($userRoles, $this->requiredRoles);
        }
    }
    
    /**
     * Check if user has excluded role
     */
    private function hasExcludedRole($user)
    {
        if (empty($this->excludedRoles)) {
            return false;
        }
        
        $userRoles = $this->getUserRoles($user);
        return $this->hasAnyRole($userRoles, $this->excludedRoles);
    }
    
    /**
     * Get user roles
     */
    private function getUserRoles($user)
    {
        if (method_exists($user, 'getRoles')) {
            return $user->getRoles();
        }
        
        // Fallback to single role
        return [$user->role ?? 'guest'];
    }
    
    /**
     * Check if user has all required roles
     */
    private function hasAllRoles($userRoles, $requiredRoles)
    {
        foreach ($requiredRoles as $role) {
            if (!in_array($role, $userRoles)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if user has any of the required roles
     */
    private function hasAnyRole($userRoles, $requiredRoles)
    {
        foreach ($requiredRoles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has higher or equal role level
     */
    private function hasRoleLevel($user, $requiredRole)
    {
        $userRoles = $this->getUserRoles($user);
        $userRole = $userRoles[0] ?? 'guest';
        
        return Auth::hasRoleLevel($userRole, $requiredRole);
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
        
        return $this->redirect('/login');
    }
    
    /**
     * Handle unauthorized request
     */
    private function handleUnauthorized($request, $user)
    {
        $userRoles = $this->getUserRoles($user);
        $requiredRoles = $this->requiredRoles;
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Insufficient permissions',
                'error_code' => 'INSUFFICIENT_PERMISSIONS',
                'user_roles' => $userRoles,
                'required_roles' => $requiredRoles
            ], 403);
        }
        
        return $this->redirect('/unauthorized');
    }
    
    /**
     * Handle excluded role
     */
    private function handleExcludedRole($request, $user)
    {
        $userRoles = $this->getUserRoles($user);
        $excludedRoles = $this->excludedRoles;
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Access denied for your role',
                'error_code' => 'ROLE_EXCLUDED',
                'user_roles' => $userRoles,
                'excluded_roles' => $excludedRoles
            ], 403);
        }
        
        return $this->redirect('/access-denied');
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
     * Redirect response
     */
    private function redirect($url)
    {
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Add required role
     */
    public function addRequiredRole($role)
    {
        if (!in_array($role, $this->requiredRoles)) {
            $this->requiredRoles[] = $role;
        }
        return $this;
    }
    
    /**
     * Remove required role
     */
    public function removeRequiredRole($role)
    {
        $key = array_search($role, $this->requiredRoles);
        if ($key !== false) {
            unset($this->requiredRoles[$key]);
        }
        return $this;
    }
    
    /**
     * Add excluded role
     */
    public function addExcludedRole($role)
    {
        if (!in_array($role, $this->excludedRoles)) {
            $this->excludedRoles[] = $role;
        }
        return $this;
    }
    
    /**
     * Remove excluded role
     */
    public function removeExcludedRole($role)
    {
        $key = array_search($role, $this->excludedRoles);
        if ($key !== false) {
            unset($this->excludedRoles[$key]);
        }
        return $this;
    }
    
    /**
     * Set require all flag
     */
    public function setRequireAll($requireAll)
    {
        $this->requireAll = $requireAll;
        return $this;
    }
    
    /**
     * Get required roles
     */
    public function getRequiredRoles()
    {
        return $this->requiredRoles;
    }
    
    /**
     * Get excluded roles
     */
    public function getExcludedRoles()
    {
        return $this->excludedRoles;
    }
    
    /**
     * Check if require all
     */
    public function isRequireAll()
    {
        return $this->requireAll;
    }
    
    /**
     * Create middleware for specific role
     */
    public static function forRole($role)
    {
        return new self($role, false);
    }
    
    /**
     * Create middleware for multiple roles (any)
     */
    public static function forAnyRole($roles)
    {
        return new self($roles, false);
    }
    
    /**
     * Create middleware for multiple roles (all)
     */
    public static function forAllRoles($roles)
    {
        return new self($roles, true);
    }
    
    /**
     * Create middleware for role level or higher
     */
    public static function forRoleLevel($role)
    {
        return new self([], false);
    }
    
    /**
     * Create middleware excluding specific role
     */
    public static function excludingRole($role)
    {
        $middleware = new self([], false);
        $middleware->addExcludedRole($role);
        return $middleware;
    }
    
    /**
     * Create middleware excluding multiple roles
     */
    public static function excludingRoles($roles)
    {
        $middleware = new self([], false);
        foreach ($roles as $role) {
            $middleware->addExcludedRole($role);
        }
        return $middleware;
    }
    
    /**
     * Check if user has role (static method)
     */
    public static function hasRole($user, $role)
    {
        $userRoles = method_exists($user, 'getRoles') ? $user->getRoles() : [$user->role ?? 'guest'];
        return in_array($role, $userRoles);
    }
    
    /**
     * Check if user has any of the roles (static method)
     */
    public static function hasAnyRole($user, $roles)
    {
        $userRoles = method_exists($user, 'getRoles') ? $user->getRoles() : [$user->role ?? 'guest'];
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all roles (static method)
     */
    public static function hasAllRoles($user, $roles)
    {
        $userRoles = method_exists($user, 'getRoles') ? $user->getRoles() : [$user->role ?? 'guest'];
        foreach ($roles as $role) {
            if (!in_array($role, $userRoles)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if user has role level or higher (static method)
     */
    public static function hasRoleLevel($user, $requiredRole)
    {
        $userRoles = method_exists($user, 'getRoles') ? $user->getRoles() : [$user->role ?? 'guest'];
        $userRole = $userRoles[0] ?? 'guest';
        
        return Auth::hasRoleLevel($userRole, $requiredRole);
    }
    
    /**
     * Get user role level (static method)
     */
    public static function getUserRoleLevel($user)
    {
        $userRoles = method_exists($user, 'getRoles') ? $user->getRoles() : [$user->role ?? 'guest'];
        $userRole = $userRoles[0] ?? 'guest';
        
        return Auth::getRoleLevel($userRole);
    }
    
    /**
     * Check if user is admin or higher (static method)
     */
    public static function isAdminOrHigher($user)
    {
        return self::hasRoleLevel($user, 'admin');
    }
    
    /**
     * Check if user is teacher or higher (static method)
     */
    public static function isTeacherOrHigher($user)
    {
        return self::hasRoleLevel($user, 'teacher');
    }
    
    /**
     * Check if user is student or higher (static method)
     */
    public static function isStudentOrHigher($user)
    {
        return self::hasRoleLevel($user, 'student');
    }
    
    /**
     * Get role hierarchy (static method)
     */
    public static function getRoleHierarchy()
    {
        return Auth::ROLE_HIERARCHY;
    }
    
    /**
     * Get all roles (static method)
     */
    public static function getAllRoles()
    {
        return array_keys(Auth::ROLE_HIERARCHY);
    }
    
    /**
     * Get roles by level (static method)
     */
    public static function getRolesByLevel($level)
    {
        $hierarchy = Auth::ROLE_HIERARCHY;
        $roles = [];
        
        foreach ($hierarchy as $role => $roleLevel) {
            if ($roleLevel >= $level) {
                $roles[] = $role;
            }
        }
        
        return $roles;
    }
    
    /**
     * Get roles below level (static method)
     */
    public static function getRolesBelowLevel($level)
    {
        $hierarchy = Auth::ROLE_HIERARCHY;
        $roles = [];
        
        foreach ($hierarchy as $role => $roleLevel) {
            if ($roleLevel < $level) {
                $roles[] = $role;
            }
        }
        
        return $roles;
    }
}