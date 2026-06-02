<?php
/**
 * Environment config — adjust for your XAMPP MySQL credentials.
 * Optional: MYSQL_UNIX_SOCKET env overrides auto fallback (e.g. Linux socket path).
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'cms_college',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    /** React dev server; production: your domain */
    'cors_origin' => 'http://localhost:5173',
    'session_name' => 'CMSSESSID',
    'upload_dir' => __DIR__ . '/uploads',
    'upload_public_path' => '/CMS/backend/uploads',
    'debug' => true,
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'user' => 'saqlain.kic@gmail.com', // Update with your Gmail
        'pass' => 'ajgsazeiqyzuoqsh',   // Update with your Gmail App Password
        'from_name' => 'College Management System',
    ],
];
