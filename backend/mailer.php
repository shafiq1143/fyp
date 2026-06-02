<?php
declare(strict_types=1);

/**
 * CMS Mailer Utility
 * Standalone SMTP client for sending system notifications via Gmail.
 */

function mailer_log(string $message): void
{
    $logFile = __DIR__ . '/mailer_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function send_system_email(string $to, string $subject, string $body, ?string $fromAddress = null, ?string $fromNameOverride = null): bool
{
    global $config;
    $smtp = $config['smtp'] ?? null;

    if (!$smtp || $smtp['user'] === 'your-email@gmail.com') {
        $message = "Mailer Error: SMTP credentials not configured in config.php";
        error_log($message);
        mailer_log($message);
        return false;
    }

    try {
        $host = $smtp['host'];
        $port = $smtp['port'];
        $user = $smtp['user'];
        $pass = $smtp['pass'];
        $fromName = $fromNameOverride ?: ($smtp['from_name'] ?? 'College Management System');
        $fromAddress = $fromAddress ?: ($smtp['from_email'] ?? $user);

        // Simple SMTP implementation using PHP sockets
        $header = "From: \"$fromName\" <$fromAddress>\r\n";
        $header .= "Reply-To: \"$fromName\" <$fromAddress>\r\n";
        $header .= "To: $to\r\n";
        $header .= "Subject: $subject\r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-Type: text/html; charset=UTF-8\r\n";
        $header .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        // For local development on XAMPP, PHP's built-in mail() often needs configuration.
        // We will use a reliable SMTP connection flow.
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = stream_socket_client("tcp://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new Exception("Socket Error: $errstr ($errno)");

        $read = function($s) {
            $data = "";
            while ($line = fgets($s, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] == ' ') break;
            }
            return $data;
        };

        $write = function($s, $cmd) {
            fputs($s, $cmd . "\r\n");
        };

        $read($socket); // 220
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $write($socket, "EHLO " . $serverName);
        $read($socket);
        
        $write($socket, "STARTTLS");
        $read($socket); // 220
        
        // Apply crypto with the context
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("TLS encryption failed (SSL Error 0: check your local PHP OpenSSL config or Gmail connectivity)");
        }

        $write($socket, "EHLO " . $serverName);
        $read($socket);

        $write($socket, "AUTH LOGIN");
        $read($socket);

        $write($socket, base64_encode($user));
        $read($socket);

        $write($socket, base64_encode($pass));
        $res = $read($socket);
        if (!str_contains($res, "235")) throw new Exception("Authentication failed: " . $res);

        $write($socket, "MAIL FROM:<$user>");
        $read($socket);

        $write($socket, "RCPT TO:<$to>");
        $read($socket);

        $write($socket, "DATA");
        $read($socket);

        $write($socket, $header . "\r\n" . $body . "\r\n.");
        $read($socket);

        $write($socket, "QUIT");
        fclose($socket);

        return true;

    } catch (Exception $e) {
        $message = "Mailer Error: " . $e->getMessage();
        error_log($message);
        mailer_log($message);
        return false;
    }
}
