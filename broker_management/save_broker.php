<?php
session_start();
if (!isset($_SESSION['user_id']) || trim($_SESSION['role_name']) !== 'Profusion SuperAdmin') {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

$brokerage_name = $_POST['brokerage_name'];
$broker_fee = floatval($_POST['broker_fee']);
$brokerage_id = isset($_POST['brokerage_id']) ? intval($_POST['brokerage_id']) : 0;

if ($brokerage_id > 0) {
    // Update existing broker
    $stmt = $conn->prepare("UPDATE brokerages SET brokerage_name = ?, broker_fee = ? WHERE brokerage_id = ?");
    $stmt->bind_param("sdi", $brokerage_name, $broker_fee, $brokerage_id);
} else {
    // Insert new broker
    $stmt = $conn->prepare("INSERT INTO brokerages (brokerage_name, broker_fee) VALUES (?, ?)");
    $stmt->bind_param("sd", $brokerage_name, $broker_fee);
}

if ($stmt->execute()) {
    header("Location: manage_brokers.php");
    exit();
} else {
    die("Error: Could not save broker - " . $conn->error);
}

$stmt->close();
$conn->close();
?>