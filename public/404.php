<?php
/**
 * public/404.php
 * User-friendly 404 error page.
 * Renders outside the normal header/footer layout so it works even
 * when the requesting page is completely invalid.
 * 
 * Apache serves this when ErrorDocument 404 /public/404.php is set.
 * The PHP built-in server also picks it up when document root is public/.
 */
http_response_code(404);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page Not Found · Apex Sports Club</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Inter', sans-serif;
    background: #0f172a;
    color: white;
    padding: 40px 20px;
}
.container {
    max-width: 600px;
    text-align: center;
}
.error-code {
    font-size: 8rem;
    font-weight: 800;
    line-height: 1;
    background: linear-gradient(135deg, #1d5c8f, #2a6ba8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 16px;
    letter-spacing: -4px;
}
.icon-badge {
    width: 80px;
    height: 80px;
    border-radius: 24px;
    background: rgba(20, 73, 122, 0.15);
    border: 1px solid rgba(20, 73, 122, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 32px;
    font-size: 32px;
    color: #4a8cb9;
}
h1 {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 12px;
    letter-spacing: -0.5px;
}
p {
    color: rgba(255,255,255,0.6);
    line-height: 1.7;
    margin-bottom: 32px;
    font-size: 1rem;
}
.actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    border-radius: 14px;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}
.btn-primary {
    background: #14497a;
    color: white;
}
.btn-primary:hover {
    background: #0e3a5f;
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(20, 73, 122, 0.3);
}
.btn-outline {
    border: 1px solid rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.8);
}
.btn-outline:hover {
    background: rgba(255,255,255,0.05);
    border-color: rgba(255,255,255,0.25);
}
</style>
</head>
<body>
<div class="container">
    <div class="icon-badge"><i class="fas fa-compass"></i></div>
    <div class="error-code">404</div>
    <h1>Lost your way?</h1>
    <p>The page you're looking for doesn't exist or has been moved. Let's get you back on track.</p>
    <div class="actions">
        <a href="index.php" class="btn btn-primary"><i class="fas fa-home"></i> Home</a>
        <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-user"></i> My Dashboard</a>
        <a href="javascript:history.back()" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Go Back</a>
    </div>
</div>
</body>
</html>