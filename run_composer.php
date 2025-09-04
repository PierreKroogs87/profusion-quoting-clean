<?php
// Security: Only allow from your IP (replace with your public IP from google 'what is my IP')
if ($_SERVER['REMOTE_ADDR'] !== '169.0.87.112') {
    die('Access denied');
}

// Get command from URL (e.g., ?cmd=install twilio/sdk)
$cmd = $_GET['cmd'] ?? '';
echo '<pre>Debug: CMD received = ' . htmlspecialchars($cmd) . '</pre>';
echo '<pre>Debug: Full Shell Command = export COMPOSER_HOME=/home/quoting/composer && php composer.phar ' . htmlspecialchars($cmd) . ' 2>&1</pre>';
if (empty($cmd)) {
    die('No command provided. Add ?cmd=your-command-here');
}

// Run Composer with the command
$parts = explode(' ', $cmd);
$escaped = [];
foreach ($parts as $part) {
    $escaped[] = escapeshellarg($part);
}
$full_cmd = 'php composer.phar ' . implode(' ', $escaped);
echo '<pre>Debug: Full Shell Command = export COMPOSER_HOME=/home/quoting/composer && ' . htmlspecialchars($full_cmd) . ' 2>&1</pre>';
$output = shell_exec('export COMPOSER_HOME=/home/quoting/composer && ' . $full_cmd . ' 2>&1');