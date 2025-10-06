<?php

// Application Debugging
// When true, detailed error messages will be shown.
define('APP_DEBUG', true);

// Application URL
// This URL is used by the console to properly generate URLs when using the CLI.
define('APP_URL', 'http://localhost');

// Application Name
define('APP_NAME', 'HomeGuru');

// Asset URL
// The base URL for your assets (CSS, JS, images).
define('ASSET_URL', APP_URL . '/assets');

// Timezone
date_default_timezone_set('UTC');