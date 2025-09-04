<?php
session_start();
require '../db_connect.php';

// Determine response type based on Accept header
$isPWA = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

if ($isPWA) {
    header('Content-Type: application/json');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isPWA) {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    } else {
        header("Location: ../login_management/login.php");
    }
    exit();
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    if ($isPWA) {
        http_response_code(400);
        echo json_encode(['error' => 'Email/ID Number and password are required']);
    } else {
        echo "Missing username or password. <a href='../login_management/login.php'>Try again</a>";
    }
    exit();
}

// Try authenticating as a staff user
$stmt = $conn->prepare("SELECT u.user_id, u.password, u.brokerage_id, r.role_name 
                       FROM users u 
                       LEFT JOIN roles r ON u.role_id = r.role_id 
                       WHERE u.username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role_name'] = $user['role_name'] ?? 'Broker';
    $_SESSION['brokerage_id'] = $user['brokerage_id'];
    $_SESSION['user_type'] = 'staff';
    if ($isPWA) {
        echo json_encode([
            'success' => true,
            'user_id' => $user['user_id'],
            'role_name' => $user['role_name'] ?? 'Broker',
            'brokerage_id' => $user['brokerage_id'],
            'user_type' => 'staff'
        ]);
    } else {
        header("Location: ../broker_summary.php");
        exit();
    }
} else {
    // Try authenticating as a client using email or client_id
    $stmt = $conn->prepare("SELECT client_id, password, name FROM clients WHERE email = ? OR client_id = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    $stmt->close();

    if ($client && password_verify($password, $client['password'])) {
        $_SESSION['client_id'] = $client['client_id'];
        $_SESSION['client_name'] = $client['name'];
        $_SESSION['user_type'] = 'client';
        if ($isPWA) {
            echo json_encode([
                'success' => true,
                'client_id' => $client['client_id'],
                'client_name' => $client['name'],
                'user_type' => 'client'
            ]);
        } else {
            header("Location: ../insurance-pwa/dashboard.php"); // Adjust if you have a client-specific PHP page
            exit();
        }
    } else {
        if ($isPWA) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email/ID number or password']);
        } else {
            echo "Invalid login. <a href='../login_management/login.php'>Try again</a>";
        }
    }
}

$conn->close();
?>