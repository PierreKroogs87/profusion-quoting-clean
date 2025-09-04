<?php
session_start();
header('Content-Type: application/json');

// Log session details
error_log("Session data: " . print_r($_SESSION, true));

if (!isset($_SESSION['client_id']) || $_SESSION['user_type'] !== 'client') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    error_log("Unauthorized access: client_id=" . ($_SESSION['client_id'] ?? 'none') . ", user_type=" . ($_SESSION['user_type'] ?? 'none'));
    exit();
}

require '../db_connect.php';

$client_id = $_SESSION['client_id'];

// Log client_id
error_log("Fetching data for client_id: $client_id");

// Fetch client details
$stmt = $conn->prepare("SELECT client_id, name, email FROM clients WHERE client_id = ?");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();
$stmt->close();

if (!$client) {
    http_response_code(404);
    echo json_encode(['error' => 'Client not found']);
    error_log("Client not found: client_id=$client_id");
    exit();
}
error_log("Client details: " . print_r($client, true));

// Fetch personal details
$personal_details = [
    'cell_number' => 'N/A',
    'sms_consent' => 'N/A',
    'physical_address' => '',
    'postal_address' => ''
];
$stmt = $conn->prepare("SELECT question_key, response 
                       FROM policy_underwriting_data pud 
                       JOIN policies p ON pud.policy_id = p.policy_id 
                       JOIN quotes q ON p.quote_id = q.quote_id 
                       WHERE q.client_id = ? AND pud.section = 'policy_holder_information'");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
error_log("Personal details rows for client_id $client_id: " . print_r($rows, true));

// Process address fields
foreach ($rows as $row) {
    error_log("Processing row: question_key={$row['question_key']}, response={$row['response']}");
    if ($row['question_key'] === '2.5_cell_number') {
        $personal_details['cell_number'] = $row['response'] ?: 'N/A';
    } elseif ($row['question_key'] === '2.7_sms_consent') {
        $personal_details['sms_consent'] = $row['response'] ?: 'N/A';
    } elseif ($row['question_key'] === '2.10_physical_address') {
        $personal_details['physical_address'] = $row['response'];
    } elseif ($row['question_key'] === '2.10_physical_suburb') {
        $personal_details['physical_address'] .= ($personal_details['physical_address'] ? ', ' : '') . $row['response'];
    } elseif ($row['question_key'] === '2.10_physical_postal_code') {
        $personal_details['physical_address'] .= ($personal_details['physical_address'] ? ' ' : '') . $row['response'];
    } elseif ($row['question_key'] === '2.12_postal_address') {
        $personal_details['postal_address'] = $row['response'];
    } elseif ($row['question_key'] === '2.12_postal_suburb') {
        $personal_details['postal_address'] .= ($personal_details['postal_address'] ? ', ' : '') . $row['response'];
    } elseif ($row['question_key'] === '2.12_postal_postal_code') {
        $personal_details['postal_address'] .= ($personal_details['postal_address'] ? ' ' : '') . $row['response'];
    }
}
$personal_details['physical_address'] = $personal_details['physical_address'] ?: 'N/A';
$personal_details['postal_address'] = $personal_details['postal_address'] ?: 'N/A';
$stmt->close();
error_log("Processed personal details: " . print_r($personal_details, true));

// Fetch insured vehicles
$vehicles = [];
$stmt = $conn->prepare("SELECT DISTINCT p.policy_id, q.quote_id, qv.vehicle_year, qv.vehicle_make, qv.vehicle_model, qv.coverage_type, qv.vehicle_value, 
                              p.premium_amount, p.debit_date, p.policy_start_date,
                              pud1.response AS security_device,
                              pud2.response AS inspection_status
                       FROM quote_vehicles qv 
                       JOIN quotes q ON qv.quote_id = q.quote_id 
                       JOIN policies p ON q.quote_id = p.quote_id 
                       LEFT JOIN policy_underwriting_data pud1 ON p.policy_id = pud1.policy_id 
                           AND pud1.question_key = CONCAT('3.28_security_device_', 
                               (SELECT COUNT(*) FROM quote_vehicles qv2 
                                WHERE qv2.quote_id = q.quote_id AND qv2.vehicle_id < qv.vehicle_id))
                       LEFT JOIN policy_underwriting_data pud2 ON p.policy_id = pud2.policy_id 
                           AND pud2.question_key = '5.4_vehicle_inspection'
                       WHERE q.client_id = ? AND qv.deleted_at IS NULL");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$vehicles = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
error_log("Vehicles fetched for client_id $client_id: " . print_r($vehicles, true));

// Fetch debit order details
$debit_order = [
    'debit_date' => 'N/A',
    'debit_premium' => 'N/A'
];
$stmt = $conn->prepare("SELECT debit_date, premium_amount 
                       FROM policies p 
                       JOIN quotes q ON p.quote_id = q.quote_id 
                       WHERE q.client_id = ? 
                       ORDER BY p.created_at DESC LIMIT 1");
$stmt->bind_param("s", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
error_log("Debit order rows for client_id $client_id: " . print_r($rows, true));
if ($row = $rows[0] ?? null) {
    $debit_order['debit_date'] = $row['debit_date'] ?: 'N/A';
    $debit_order['debit_premium'] = isset($row['premium_amount']) && $row['premium_amount'] !== null ? 'R ' . number_format($row['premium_amount'], 2) : 'N/A';
}
$stmt->close();
error_log("Processed debit order: " . print_r($debit_order, true));

echo json_encode([
    'success' => true,
    'client' => $client,
    'personal_details' => $personal_details,
    'vehicles' => $vehicles,
    'debit_order' => $debit_order
]);

$conn->close();
?>