<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'HomeGuru'; ?></title>

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?php echo ASSET_URL; ?>/css/app.css">

    <!-- Google Fonts (Optional, for better typography) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>

    <header class="main-header">
        <nav class="navbar">
            <div class="logo">
                <a href="/home">HomeGuru</a>
            </div>
            <ul class="nav-links">
                <li><a href="/home">Home</a></li>
                <li><a href="#">About</a></li>
                <li><a href="#">Modules</a></li>
                <li><a href="#">Contact</a></li>
                <li><a href="/login" class="login-button">Login</a></li>
            </ul>
        </nav>
    </header>

    <div id="main-content">
        <?php
        // This is where the specific view content will be injected
        if (isset($contentView) && file_exists($contentView)) {
            require_once $contentView;
        } else {
            echo "<p>Content view not found.</p>";
        }
        ?>
    </div>

    <footer class="main-footer">
        <p>&copy; <?php echo date('Y'); ?> HomeGuru. All Rights Reserved.</p>
    </footer>

    <!-- Main JS File (for later use) -->
    <!-- <script src="<?php echo ASSET_URL; ?>/js/app.js"></script> -->
</body>
</html>