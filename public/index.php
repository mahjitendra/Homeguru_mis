<?php

// Define the root directory
define('ROOT_PATH', dirname(__DIR__));

// Autoload dependencies
require_once ROOT_PATH . '/vendor/autoload.php';

// Load environment variables
// (Assuming a .env file loader would be used, e.g., Dotenv)
// For now, we can define constants or include a config file.

// Include the main application configuration
require_once ROOT_PATH . '/app/Config/App.php';

// Basic error reporting for development
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Load the routes
require_once ROOT_PATH . '/app/Routes/web.php';

// A simple router logic (to be replaced with a proper library later)
$request_uri = strtok($_SERVER['REQUEST_URI'], '?');
$route = trim($request_uri, '/');

// Default route
if ($route === '') {
    $route = 'home';
}

// Check if the route exists in our simple router
if (isset($routes[$route])) {
    $callback = $routes[$route];
    if (is_callable($callback)) {
        $callback();
    } else {
        // Handle controller@method syntax
        list($controller, $method) = explode('@', $callback);
        $controllerClass = "App\\Controllers\\{$controller}";

        if (class_exists($controllerClass)) {
            $controllerInstance = new $controllerClass();
            if (method_exists($controllerInstance, $method)) {
                $controllerInstance->$method();
            } else {
                http_response_code(404);
                echo "Method {$method} not found.";
            }
        } else {
            http_response_code(404);
            echo "Controller {$controller} not found.";
        }
    }
} else {
    http_response_code(404);
    // You would typically load a 404 view here
    echo "<h1>404 - Page Not Found</h1>";
}