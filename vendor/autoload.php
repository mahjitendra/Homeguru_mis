<?php
// This is a placeholder for a real Composer autoloader.
// In a real project, you would run `composer install` to generate this file.

spl_autoload_register(function ($class) {
    // A very basic autoloader that looks for files in the 'app' directory.
    // It converts namespaces to directory paths.
    // e.g., App\Controllers\HomeController -> app/Controllers/HomeController.php

    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../app/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // no, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators in the relative class name, append
    // with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // if the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});