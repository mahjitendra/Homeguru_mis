<?php

namespace App\Middleware;

class CorsMiddleware
{
    private $allowedOrigins = ['*'];
    private $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
    private $allowedHeaders = ['*'];
    private $exposedHeaders = [];
    private $maxAge = 86400; // 24 hours
    private $allowCredentials = false;
    private $excludedRoutes = [];
    
    public function __construct($config = [])
    {
        $this->loadConfiguration($config);
    }
    
    /**
     * Handle the middleware
     */
    public function handle($request, $next)
    {
        // Handle preflight requests
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }
        
        // Process the request
        $response = $next($request);
        
        // Add CORS headers to response
        $this->addCorsHeaders($request, $response);
        
        return $response;
    }
    
    /**
     * Load configuration
     */
    private function loadConfiguration($config)
    {
        $this->allowedOrigins = $config['allowed_origins'] ?? $this->allowedOrigins;
        $this->allowedMethods = $config['allowed_methods'] ?? $this->allowedMethods;
        $this->allowedHeaders = $config['allowed_headers'] ?? $this->allowedHeaders;
        $this->exposedHeaders = $config['exposed_headers'] ?? $this->exposedHeaders;
        $this->maxAge = $config['max_age'] ?? $this->maxAge;
        $this->allowCredentials = $config['allow_credentials'] ?? $this->allowCredentials;
        $this->excludedRoutes = $config['excluded_routes'] ?? $this->excludedRoutes;
    }
    
    /**
     * Handle preflight request
     */
    private function handlePreflightRequest($request)
    {
        $origin = $request->getHeader('Origin');
        
        // Check if origin is allowed
        if (!$this->isOriginAllowed($origin)) {
            return $this->corsError('Origin not allowed');
        }
        
        // Check if method is allowed
        $requestMethod = $request->getHeader('Access-Control-Request-Method');
        if (!$this->isMethodAllowed($requestMethod)) {
            return $this->corsError('Method not allowed');
        }
        
        // Check if headers are allowed
        $requestHeaders = $request->getHeader('Access-Control-Request-Headers');
        if (!$this->areHeadersAllowed($requestHeaders)) {
            return $this->corsError('Headers not allowed');
        }
        
        // Return preflight response
        return $this->preflightResponse($origin, $requestMethod, $requestHeaders);
    }
    
    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed($origin)
    {
        if (empty($origin)) {
            return false;
        }
        
        // Allow all origins
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }
        
        // Check exact match
        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }
        
        // Check wildcard patterns
        foreach ($this->allowedOrigins as $allowedOrigin) {
            if ($this->matchesPattern($origin, $allowedOrigin)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if method is allowed
     */
    private function isMethodAllowed($method)
    {
        if (empty($method)) {
            return false;
        }
        
        // Allow all methods
        if (in_array('*', $this->allowedMethods)) {
            return true;
        }
        
        return in_array(strtoupper($method), $this->allowedMethods);
    }
    
    /**
     * Check if headers are allowed
     */
    private function areHeadersAllowed($headers)
    {
        if (empty($headers)) {
            return true;
        }
        
        // Allow all headers
        if (in_array('*', $this->allowedHeaders)) {
            return true;
        }
        
        $headerList = array_map('trim', explode(',', $headers));
        
        foreach ($headerList as $header) {
            if (!$this->isHeaderAllowed($header)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if single header is allowed
     */
    private function isHeaderAllowed($header)
    {
        // Allow all headers
        if (in_array('*', $this->allowedHeaders)) {
            return true;
        }
        
        // Check exact match
        if (in_array($header, $this->allowedHeaders)) {
            return true;
        }
        
        // Check wildcard patterns
        foreach ($this->allowedHeaders as $allowedHeader) {
            if ($this->matchesPattern($header, $allowedHeader)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if string matches pattern
     */
    private function matchesPattern($string, $pattern)
    {
        // Convert wildcard pattern to regex
        $regex = str_replace(['*', '?'], ['.*', '.'], $pattern);
        $regex = '/^' . $regex . '$/i';
        
        return preg_match($regex, $string);
    }
    
    /**
     * Check if route is excluded from CORS
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
     * Add CORS headers to response
     */
    private function addCorsHeaders($request, $response)
    {
        // Check if route is excluded
        if ($this->isExcludedRoute($request)) {
            return;
        }
        
        $origin = $request->getHeader('Origin');
        
        // Set Access-Control-Allow-Origin
        if ($this->isOriginAllowed($origin)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } elseif (in_array('*', $this->allowedOrigins)) {
            header('Access-Control-Allow-Origin: *');
        }
        
        // Set Access-Control-Allow-Methods
        $methods = implode(', ', $this->allowedMethods);
        header("Access-Control-Allow-Methods: {$methods}");
        
        // Set Access-Control-Allow-Headers
        $headers = implode(', ', $this->allowedHeaders);
        header("Access-Control-Allow-Headers: {$headers}");
        
        // Set Access-Control-Expose-Headers
        if (!empty($this->exposedHeaders)) {
            $exposedHeaders = implode(', ', $this->exposedHeaders);
            header("Access-Control-Expose-Headers: {$exposedHeaders}");
        }
        
        // Set Access-Control-Max-Age
        header("Access-Control-Max-Age: {$this->maxAge}");
        
        // Set Access-Control-Allow-Credentials
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }
    
    /**
     * Handle preflight response
     */
    private function preflightResponse($origin, $method, $headers)
    {
        http_response_code(200);
        
        // Set Access-Control-Allow-Origin
        if ($this->isOriginAllowed($origin)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } elseif (in_array('*', $this->allowedOrigins)) {
            header('Access-Control-Allow-Origin: *');
        }
        
        // Set Access-Control-Allow-Methods
        $methods = implode(', ', $this->allowedMethods);
        header("Access-Control-Allow-Methods: {$methods}");
        
        // Set Access-Control-Allow-Headers
        $allowedHeaders = implode(', ', $this->allowedHeaders);
        header("Access-Control-Allow-Headers: {$allowedHeaders}");
        
        // Set Access-Control-Max-Age
        header("Access-Control-Max-Age: {$this->maxAge}");
        
        // Set Access-Control-Allow-Credentials
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
        
        // Set Vary header
        header('Vary: Origin');
        
        return '';
    }
    
    /**
     * Handle CORS error
     */
    private function corsError($message)
    {
        http_response_code(403);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'message' => $message,
            'error_code' => 'CORS_ERROR'
        ];
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * Add allowed origin
     */
    public function addAllowedOrigin($origin)
    {
        if (!in_array($origin, $this->allowedOrigins)) {
            $this->allowedOrigins[] = $origin;
        }
        return $this;
    }
    
    /**
     * Remove allowed origin
     */
    public function removeAllowedOrigin($origin)
    {
        $key = array_search($origin, $this->allowedOrigins);
        if ($key !== false) {
            unset($this->allowedOrigins[$key]);
        }
        return $this;
    }
    
    /**
     * Add allowed method
     */
    public function addAllowedMethod($method)
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods)) {
            $this->allowedMethods[] = $method;
        }
        return $this;
    }
    
    /**
     * Remove allowed method
     */
    public function removeAllowedMethod($method)
    {
        $method = strtoupper($method);
        $key = array_search($method, $this->allowedMethods);
        if ($key !== false) {
            unset($this->allowedMethods[$key]);
        }
        return $this;
    }
    
    /**
     * Add allowed header
     */
    public function addAllowedHeader($header)
    {
        if (!in_array($header, $this->allowedHeaders)) {
            $this->allowedHeaders[] = $header;
        }
        return $this;
    }
    
    /**
     * Remove allowed header
     */
    public function removeAllowedHeader($header)
    {
        $key = array_search($header, $this->allowedHeaders);
        if ($key !== false) {
            unset($this->allowedHeaders[$key]);
        }
        return $this;
    }
    
    /**
     * Add exposed header
     */
    public function addExposedHeader($header)
    {
        if (!in_array($header, $this->exposedHeaders)) {
            $this->exposedHeaders[] = $header;
        }
        return $this;
    }
    
    /**
     * Remove exposed header
     */
    public function removeExposedHeader($header)
    {
        $key = array_search($header, $this->exposedHeaders);
        if ($key !== false) {
            unset($this->exposedHeaders[$key]);
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
     * Set max age
     */
    public function setMaxAge($maxAge)
    {
        $this->maxAge = $maxAge;
        return $this;
    }
    
    /**
     * Set allow credentials
     */
    public function setAllowCredentials($allowCredentials)
    {
        $this->allowCredentials = $allowCredentials;
        return $this;
    }
    
    /**
     * Get allowed origins
     */
    public function getAllowedOrigins()
    {
        return $this->allowedOrigins;
    }
    
    /**
     * Get allowed methods
     */
    public function getAllowedMethods()
    {
        return $this->allowedMethods;
    }
    
    /**
     * Get allowed headers
     */
    public function getAllowedHeaders()
    {
        return $this->allowedHeaders;
    }
    
    /**
     * Get exposed headers
     */
    public function getExposedHeaders()
    {
        return $this->exposedHeaders;
    }
    
    /**
     * Get excluded routes
     */
    public function getExcludedRoutes()
    {
        return $this->excludedRoutes;
    }
    
    /**
     * Get max age
     */
    public function getMaxAge()
    {
        return $this->maxAge;
    }
    
    /**
     * Get allow credentials
     */
    public function getAllowCredentials()
    {
        return $this->allowCredentials;
    }
    
    /**
     * Create middleware for specific origins
     */
    public static function forOrigins($origins)
    {
        return new self(['allowed_origins' => $origins]);
    }
    
    /**
     * Create middleware for all origins
     */
    public static function forAllOrigins()
    {
        return new self(['allowed_origins' => ['*']]);
    }
    
    /**
     * Create middleware for specific methods
     */
    public static function forMethods($methods)
    {
        return new self(['allowed_methods' => $methods]);
    }
    
    /**
     * Create middleware for specific headers
     */
    public static function forHeaders($headers)
    {
        return new self(['allowed_headers' => $headers]);
    }
    
    /**
     * Create middleware for API
     */
    public static function forApi()
    {
        return new self([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
            'allowed_headers' => ['*'],
            'max_age' => 3600
        ]);
    }
    
    /**
     * Create middleware for development
     */
    public static function forDevelopment()
    {
        return new self([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['*'],
            'allowed_headers' => ['*'],
            'allow_credentials' => true,
            'max_age' => 0
        ]);
    }
    
    /**
     * Create middleware for production
     */
    public static function forProduction($origins = [])
    {
        return new self([
            'allowed_origins' => $origins,
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'max_age' => 86400
        ]);
    }
    
    /**
     * Get CORS configuration
     */
    public function getConfiguration()
    {
        return [
            'allowed_origins' => $this->allowedOrigins,
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders,
            'exposed_headers' => $this->exposedHeaders,
            'max_age' => $this->maxAge,
            'allow_credentials' => $this->allowCredentials,
            'excluded_routes' => $this->excludedRoutes
        ];
    }
    
    /**
     * Validate CORS configuration
     */
    public function validateConfiguration()
    {
        $errors = [];
        
        // Validate origins
        if (empty($this->allowedOrigins)) {
            $errors[] = 'At least one origin must be allowed';
        }
        
        // Validate methods
        if (empty($this->allowedMethods)) {
            $errors[] = 'At least one method must be allowed';
        }
        
        // Validate headers
        if (empty($this->allowedHeaders)) {
            $errors[] = 'At least one header must be allowed';
        }
        
        // Validate max age
        if ($this->maxAge < 0) {
            $errors[] = 'Max age must be non-negative';
        }
        
        return $errors;
    }
    
    /**
     * Get CORS headers for manual setting
     */
    public function getCorsHeaders($origin = null)
    {
        $headers = [];
        
        if ($this->isOriginAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        } elseif (in_array('*', $this->allowedOrigins)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }
        
        $headers['Access-Control-Allow-Methods'] = implode(', ', $this->allowedMethods);
        $headers['Access-Control-Allow-Headers'] = implode(', ', $this->allowedHeaders);
        
        if (!empty($this->exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $this->exposedHeaders);
        }
        
        $headers['Access-Control-Max-Age'] = $this->maxAge;
        
        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        
        return $headers;
    }
}