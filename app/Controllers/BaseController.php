<?php

namespace App\Controllers;

class BaseController
{
    /**
     * Renders a view file within the master layout.
     *
     * @param string $viewName The view file to render (e.g., 'home.index')
     * @param array $data Data to extract into the view
     */
    protected function view(string $viewName, array $data = [])
    {
        $viewFile = ROOT_PATH . '/app/Views/' . str_replace('.', '/', $viewName) . '.php';

        if (file_exists($viewFile)) {
            // Extract data so it's available as variables in both the layout and the view
            extract($data);

            // Make the view file path available to the master layout
            // This is the variable the master layout will use to include the specific page content.
            $contentView = $viewFile;

            // Include the master layout, which will in turn include the content view
            require_once ROOT_PATH . '/app/Views/layouts/master.php';
        } else {
            // A simple error message if the view is not found
            // In a real app, this would throw an exception or show a proper error page.
            echo "Error: View '{$viewName}' not found.";
        }
    }
}