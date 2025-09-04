<?php
// Test if shell_exec works
$output = shell_exec('echo "Test successful"');
echo $output ? $output : "Shell exec is blocked";
?>