<?php
require 'vendor/autoload.php'; // Composer packages
require '../db_connect.php'; // Your DB connection (adjust path if needed)

use Ramsey\Uuid\Uuid; // For unique tokens
use Twilio\Rest\Client; // For Twilio
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__); // Loads .env from the same directory
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['policy_id'])) {
    $policy_id = intval($_POST['policy_id']);
    $token = Uuid::uuid4()->toString(); // Generate unique token

    // Store token in DB
    $stmt = $conn->prepare("UPDATE policies SET mandate_token = ? WHERE policy_id = ?");
    $stmt->bind_param("si", $token, $policy_id);
    $stmt->execute();
    $stmt->close();

    $link = 'https://quoting.profusionum.co.za/underwriting_steps/mandate_approval.php?token=' . $token; // Your domain

    // Fetch client's cell number from quotes table
    $stmt = $conn->prepare("SELECT q.cell_number FROM quotes q JOIN policies p ON q.quote_id = p.quote_id WHERE p.policy_id = ?");
    $stmt->bind_param("i", $policy_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cell_number = $result->num_rows > 0 ? $result->fetch_assoc()['cell_number'] : '';
    $stmt->close();

    if (empty($cell_number)) {
        echo 'Error: No cell number found';
        exit();
    }

    // Send via Twilio WhatsApp
    $twilio = new Client($_ENV['TWILIO_SID'], $_ENV['TWILIO_AUTH_TOKEN']);
    $from = 'whatsapp:' . $_ENV['TWILIO_PHONE']; // Twilio WhatsApp-enabled number
    $to = 'whatsapp:+27' . substr($cell_number, 1); // Ensure SA format (e.g., +27815987654)
    $message = "Your debit order mandate link: " . $link . " Approve now while on the call.";

    $twilio->messages->create($to, ['from' => $from, 'body' => $message]);

    echo 'Link sent via WhatsApp: ' . $link;
} else {
    echo 'Error: Invalid request';
}
?>