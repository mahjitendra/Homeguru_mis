<?php

namespace App\Middleware;

use App\Models\User\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Exceptions\AuthorizationException;

class PermissionMiddleware
{
    private $requiredPermissions = [];
    private $excludedPermissions = [];
    private $requireAll = false; // If true, user must have ALL permissions, if false, user needs ANY permission
    private $checkRole = true; // Whether to check role-based permissions
    
    public function __construct($permissions = [], $requireAll = false, $checkRole = true)
    {
        $this->requiredPermissions = is_array($permissions) ? $permissions : [$permissions];
        $this->requireAll = $requireAll;
        $this->checkRole = $checkRole;
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
        
        if (!$this->hasRequiredPermission($user)) {
            return $this->handleUnauthorized($request, $user);
        }
        
        if ($this->hasExcludedPermission($user)) {
            return $this->handleExcludedPermission($request, $user);
        }
        
        return $next($request);
    }
    
    /**
     * Check if user has required permission(s)
     */
    private function hasRequiredPermission($user)
    {
        if (empty($this->requiredPermissions)) {
            return true;
        }
        
        $userPermissions = $this->getUserPermissions($user);
        
        if ($this->requireAll) {
            // User must have ALL required permissions
            return $this->hasAllPermissions($userPermissions, $this->requiredPermissions);
        } else {
            // User needs ANY of the required permissions
            return $this->hasAnyPermission($userPermissions, $this->requiredPermissions);
        }
    }
    
    /**
     * Check if user has excluded permission
     */
    private function hasExcludedPermission($user)
    {
        if (empty($this->excludedPermissions)) {
            return false;
        }
        
        $userPermissions = $this->getUserPermissions($user);
        return $this->hasAnyPermission($userPermissions, $this->excludedPermissions);
    }
    
    /**
     * Get user permissions
     */
    private function getUserPermissions($user)
    {
        $permissions = [];
        
        // Get direct permissions
        $directPermissions = $this->getDirectPermissions($user);
        $permissions = array_merge($permissions, $directPermissions);
        
        // Get role-based permissions
        if ($this->checkRole) {
            $rolePermissions = $this->getRolePermissions($user);
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        // Remove duplicates
        return array_unique($permissions);
    }
    
    /**
     * Get direct user permissions
     */
    private function getDirectPermissions($user)
    {
        if (method_exists($user, 'getPermissions')) {
            return $user->getPermissions();
        }
        
        // Fallback to database query
        $sql = "SELECT p.name FROM permissions p 
                INNER JOIN user_permissions up ON p.id = up.permission_id 
                WHERE up.user_id = ? AND up.status = 'active'";
        
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute([$user->id]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Get role-based permissions
     */
    private function getRolePermissions($user)
    {
        if (method_exists($user, 'getRolePermissions')) {
            return $user->getRolePermissions();
        }
        
        // Fallback to database query
        $sql = "SELECT DISTINCT p.name FROM permissions p 
                INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                INNER JOIN user_roles ur ON rp.role_id = ur.role_id 
                WHERE ur.user_id = ? AND ur.status = 'active' AND rp.status = 'active'";
        
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute([$user->id]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Check if user has all required permissions
     */
    private function hasAllPermissions($userPermissions, $requiredPermissions)
    {
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if user has any of the required permissions
     */
    private function hasAnyPermission($userPermissions, $requiredPermissions)
    {
        foreach ($requiredPermissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if permission is wildcard (e.g., 'user.*' matches 'user.create', 'user.edit', etc.)
     */
    private function matchesWildcard($userPermission, $requiredPermission)
    {
        if (strpos($requiredPermission, '*') === false) {
            return $userPermission === $requiredPermission;
        }
        
        $pattern = str_replace('*', '.*', $requiredPermission);
        return preg_match('/^' . $pattern . '$/', $userPermission);
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
        $userPermissions = $this->getUserPermissions($user);
        $requiredPermissions = $this->requiredPermissions;
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Insufficient permissions',
                'error_code' => 'INSUFFICIENT_PERMISSIONS',
                'user_permissions' => $userPermissions,
                'required_permissions' => $requiredPermissions
            ], 403);
        }
        
        return $this->redirect('/unauthorized');
    }
    
    /**
     * Handle excluded permission
     */
    private function handleExcludedPermission($request, $user)
    {
        $userPermissions = $this->getUserPermissions($user);
        $excludedPermissions = $this->excludedPermissions;
        
        if ($this->isApiRequest($request)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Access denied for your permissions',
                'error_code' => 'PERMISSION_EXCLUDED',
                'user_permissions' => $userPermissions,
                'excluded_permissions' => $excludedPermissions
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
     * Get database connection
     */
    private function getConnection()
    {
        return \App\Libraries\Database\Connection::getInstance();
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
     * Add required permission
     */
    public function addRequiredPermission($permission)
    {
        if (!in_array($permission, $this->requiredPermissions)) {
            $this->requiredPermissions[] = $permission;
        }
        return $this;
    }
    
    /**
     * Remove required permission
     */
    public function removeRequiredPermission($permission)
    {
        $key = array_search($permission, $this->requiredPermissions);
        if ($key !== false) {
            unset($this->requiredPermissions[$key]);
        }
        return $this;
    }
    
    /**
     * Add excluded permission
     */
    public function addExcludedPermission($permission)
    {
        if (!in_array($permission, $this->excludedPermissions)) {
            $this->excludedPermissions[] = $permission;
        }
        return $this;
    }
    
    /**
     * Remove excluded permission
     */
    public function removeExcludedPermission($permission)
    {
        $key = array_search($permission, $this->excludedPermissions);
        if ($key !== false) {
            unset($this->excludedPermissions[$key]);
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
     * Set check role flag
     */
    public function setCheckRole($checkRole)
    {
        $this->checkRole = $checkRole;
        return $this;
    }
    
    /**
     * Get required permissions
     */
    public function getRequiredPermissions()
    {
        return $this->requiredPermissions;
    }
    
    /**
     * Get excluded permissions
     */
    public function getExcludedPermissions()
    {
        return $this->excludedPermissions;
    }
    
    /**
     * Check if require all
     */
    public function isRequireAll()
    {
        return $this->requireAll;
    }
    
    /**
     * Check if check role
     */
    public function isCheckRole()
    {
        return $this->checkRole;
    }
    
    /**
     * Create middleware for specific permission
     */
    public static function forPermission($permission)
    {
        return new self($permission, false, true);
    }
    
    /**
     * Create middleware for multiple permissions (any)
     */
    public static function forAnyPermission($permissions)
    {
        return new self($permissions, false, true);
    }
    
    /**
     * Create middleware for multiple permissions (all)
     */
    public static function forAllPermissions($permissions)
    {
        return new self($permissions, true, true);
    }
    
    /**
     * Create middleware for direct permissions only
     */
    public static function forDirectPermission($permission)
    {
        return new self($permission, false, false);
    }
    
    /**
     * Create middleware for role permissions only
     */
    public static function forRolePermission($permission)
    {
        $middleware = new self($permission, false, true);
        return $middleware;
    }
    
    /**
     * Create middleware excluding specific permission
     */
    public static function excludingPermission($permission)
    {
        $middleware = new self([], false, true);
        $middleware->addExcludedPermission($permission);
        return $middleware;
    }
    
    /**
     * Create middleware excluding multiple permissions
     */
    public static function excludingPermissions($permissions)
    {
        $middleware = new self([], false, true);
        foreach ($permissions as $permission) {
            $middleware->addExcludedPermission($permission);
        }
        return $middleware;
    }
    
    /**
     * Check if user has permission (static method)
     */
    public static function hasPermission($user, $permission)
    {
        $userPermissions = self::getUserPermissions($user);
        return in_array($permission, $userPermissions);
    }
    
    /**
     * Check if user has any of the permissions (static method)
     */
    public static function hasAnyPermission($user, $permissions)
    {
        $userPermissions = self::getUserPermissions($user);
        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all permissions (static method)
     */
    public static function hasAllPermissions($user, $permissions)
    {
        $userPermissions = self::getUserPermissions($user);
        foreach ($permissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get user permissions (static method)
     */
    public static function getUserPermissions($user)
    {
        $permissions = [];
        
        // Get direct permissions
        $directPermissions = self::getDirectPermissions($user);
        $permissions = array_merge($permissions, $directPermissions);
        
        // Get role-based permissions
        $rolePermissions = self::getRolePermissions($user);
        $permissions = array_merge($permissions, $rolePermissions);
        
        // Remove duplicates
        return array_unique($permissions);
    }
    
    /**
     * Get direct user permissions (static method)
     */
    public static function getDirectPermissions($user)
    {
        if (method_exists($user, 'getPermissions')) {
            return $user->getPermissions();
        }
        
        // Fallback to database query
        $connection = \App\Libraries\Database\Connection::getInstance();
        $sql = "SELECT p.name FROM permissions p 
                INNER JOIN user_permissions up ON p.id = up.permission_id 
                WHERE up.user_id = ? AND up.status = 'active'";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute([$user->id]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Get role-based permissions (static method)
     */
    public static function getRolePermissions($user)
    {
        if (method_exists($user, 'getRolePermissions')) {
            return $user->getRolePermissions();
        }
        
        // Fallback to database query
        $connection = \App\Libraries\Database\Connection::getInstance();
        $sql = "SELECT DISTINCT p.name FROM permissions p 
                INNER JOIN role_permissions rp ON p.id = rp.permission_id 
                INNER JOIN user_roles ur ON rp.role_id = ur.role_id 
                WHERE ur.user_id = ? AND ur.status = 'active' AND rp.status = 'active'";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute([$user->id]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Check if user can perform action (static method)
     */
    public static function can($user, $permission)
    {
        return self::hasPermission($user, $permission);
    }
    
    /**
     * Check if user cannot perform action (static method)
     */
    public static function cannot($user, $permission)
    {
        return !self::hasPermission($user, $permission);
    }
    
    /**
     * Authorize user for permission (static method)
     */
    public static function authorize($user, $permission)
    {
        if (!self::hasPermission($user, $permission)) {
            throw new AuthorizationException("User does not have permission: {$permission}");
        }
    }
    
    /**
     * Get all permissions (static method)
     */
    public static function getAllPermissions()
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        $sql = "SELECT * FROM permissions ORDER BY name";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get permissions by module (static method)
     */
    public static function getPermissionsByModule($module)
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        $sql = "SELECT * FROM permissions WHERE module = ? ORDER BY name";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute([$module]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get permission modules (static method)
     */
    public static function getPermissionModules()
    {
        $connection = \App\Libraries\Database\Connection::getInstance();
        $sql = "SELECT DISTINCT module FROM permissions WHERE module IS NOT NULL ORDER BY module";
        
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}