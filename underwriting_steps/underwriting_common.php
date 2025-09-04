<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    error_log("[DEBUG] Redirect to login.php: No user_id in session");
    ob_end_clean();
    header("Location: ../login.php");
    exit();
}

require '../db_connect.php';
require '../quote_commit_management/quote_calculator.php';

// Define authorized roles for underwriting
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant', 'Broker Admin', 'Broker Manager', 'Broker Consultant'];
if (!in_array($_SESSION['role_name'], $authorized_roles)) {
    error_log("[DEBUG] Unauthorized access attempt by user_id {$_SESSION['user_id']} with role {$_SESSION['role_name']}");
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}

// Get script name to determine parameter requirements
$script_name = basename($_SERVER['PHP_SELF']);

// Initialize variables
$quote_id = null;
$policy_id = null;

// Handle parameters based on script
if (in_array($script_name, ['generate_confirmation.php', 'generate_insurance_confirmation.php', 'edit_vehicle_details.php'])) {
    // For policy_id-based scripts, require policy_id
    $policy_id = $_GET['policy_id'] ?? null;
    if (!$policy_id || !is_numeric($policy_id)) {
        error_log("[DEBUG] Invalid or missing policy_id for script $script_name");
        $_SESSION['errors'] = ["Invalid or missing policy ID."];
        ob_end_clean();
        header("Location: ../dashboard.php");
        exit();
    }
    error_log("[DEBUG] Received policy_id: $policy_id for script $script_name");
} else {
    // For other scripts, require quote_id
    $quote_id = $_GET['quote_id'] ?? null;
    if (!$quote_id || !is_numeric($quote_id)) {
        error_log("[DEBUG] Invalid or missing quote_id for script $script_name");
        $_SESSION['errors'] = ["Invalid or missing quote ID."];
        ob_end_clean();
        header("Location: ../dashboard.php");
        exit();
    }
    error_log("[DEBUG] Received quote_id: $quote_id for script $script_name");
}

// Handle product_type from URL or session, with support for edit_mode
$valid_product_types = ['premium6', 'premium5', 'premium4', 'premium_flat'];
$product_type = $_GET['product_type'] ?? null;
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';

if ($edit_mode) {
    // For edit mode, fetch existing premium_type from policies table
    $stmt = $conn->prepare("SELECT premium_type, premium_amount FROM policies WHERE quote_id = ? AND status = 'active'");
    $stmt->bind_param("i", $quote_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $policy_data = $result->fetch_assoc();
        $product_type = $policy_data['premium_type'];
        $premium_amount = $policy_data['premium_amount'];
        $_SESSION['underwriting_product'][$quote_id] = [
            'product_type' => $product_type,
            'premium_amount' => $premium_amount
        ];
        error_log("[DEBUG] Edit mode: Loaded existing product_type=$product_type and premium_amount=$premium_amount for quote_id=$quote_id");
    } else {
        error_log("[DEBUG] Edit mode: No active policy found for quote_id=$quote_id");
        $_SESSION['errors'] = ["No active policy found for editing."];
        ob_end_clean();
        header("Location: ../dashboard.php");
        exit();
    }
    $stmt->close();
} elseif ($product_type && in_array($product_type, $valid_product_types)) {
    $_SESSION['underwriting_product'][$quote_id] = [
        'product_type' => $product_type,
        'premium_amount' => null // Will be set after fetching quote data
    ];
} elseif (!isset($_SESSION['underwriting_product'][$quote_id]) && !in_array($script_name, ['generate_confirmation.php', 'generate_insurance_confirmation.php', 'edit_vehicle_details.php'])) {
    error_log("[DEBUG] No valid product_type provided for quote_id=$quote_id in script $script_name");
    $_SESSION['errors'] = ["No valid product type provided."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}

// Fetch quote data (skip for policy_id-based scripts)
if (!in_array($script_name, ['generate_confirmation.php', 'generate_insurance_confirmation.php', 'edit_vehicle_details.php'])) {
    if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
        error_log("[DEBUG] Using query without restrictions for Profusion role");
        $stmt = $conn->prepare("
            SELECT q.*, b.brokerage_name 
            FROM quotes q 
            LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id 
            WHERE q.quote_id = ?
        ");
        $stmt->bind_param("i", $quote_id);
    } else {
        error_log("[DEBUG] Using query with user_id filter for role: " . ($_SESSION['role_name'] ?? 'Not set'));
        $stmt = $conn->prepare("
            SELECT q.*, b.brokerage_name 
            FROM quotes q 
            LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id 
            WHERE q.quote_id = ? AND (q.user_id = ? OR ? IN (SELECT user_id FROM users WHERE brokerage_id = q.brokerage_id))
        ");
        $stmt->bind_param("iii", $quote_id, $_SESSION['user_id'], $_SESSION['user_id']);
    }
    $stmt->execute();
    $quote_result = $stmt->get_result();
    if ($quote_result->num_rows === 0) {
        error_log("[DEBUG] No quote found for quote_id=$quote_id for user_id={$_SESSION['user_id']}");
        $_SESSION['errors'] = ["No quote found for this quote ID."];
        ob_end_clean();
        header("Location: ../dashboard.php");
        exit();
    }
    $quote_data = $quote_result->fetch_assoc();
    $stmt->close();

    // Set premium amount based on product_type
    if (isset($_SESSION['underwriting_product'][$quote_id])) {
        $product_type = $_SESSION['underwriting_product'][$quote_id]['product_type'];
        if (isset($quote_data[$product_type]) && is_numeric($quote_data[$product_type]) && $quote_data[$product_type] > 0) {
            $_SESSION['underwriting_product'][$quote_id]['premium_amount'] = $quote_data[$product_type];
        } else {
            error_log("[DEBUG] Invalid or zero premium for product_type=$product_type for quote_id=$quote_id");
            $_SESSION['errors'] = ["Invalid or zero premium for the selected product type."];
            ob_end_clean();
            header("Location: ../dashboard.php");
            exit();
        }
    }
}

// Fetch vehicle and driver data (skip for policy_id-based scripts if not needed, but keep for consistency)
if (!in_array($script_name, ['generate_confirmation.php', 'generate_insurance_confirmation.php'])) {
    $vehicles_data = [];
    $stmt = $conn->prepare("
        SELECT * FROM quote_vehicles 
        WHERE quote_id = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("i", $quote_id);
    $stmt->execute();
    $vehicle_result = $stmt->get_result();
    while ($vehicle = $vehicle_result->fetch_assoc()) {
        $driver_stmt = $conn->prepare("
            SELECT * FROM quote_drivers 
            WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL
        ");
        $driver_stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
        $driver_stmt->execute();
        $driver_result = $driver_stmt->get_result();
        $driver = $driver_result->num_rows > 0 ? $driver_result->fetch_assoc() : [];
        $driver_stmt->close();
        $vehicles_data[] = [
            'vehicle' => $vehicle,
            'driver' => $driver
        ];
    }
    $stmt->close();

    if (empty($vehicles_data)) {
        error_log("[DEBUG] No valid vehicles found for quote_id $quote_id");
        $_SESSION['errors'] = ["No valid vehicles found for this quote."];
        ob_end_clean();
        header("Location: quote_management/edit_quote.php?quote_id=$quote_id");
        exit();
    }

    // Initialize current step (using session to persist progress)
       if (!isset($_SESSION['underwriting_step'][$quote_id]) || $script_name === 'step1_disclosures.php') {
        $_SESSION['underwriting_step'][$quote_id] = 1;
        }
        $current_step = $_SESSION['underwriting_step'][$quote_id];
   

    // Define script sections and corresponding files
    $script_sections = [
        1 => ['name' => 'Disclosures', 'file' => 'step1_disclosures.php'],
        2 => ['name' => 'Policy Holder Info', 'file' => 'step2_policy_holder_info.php'],
        3 => ['name' => 'Vehicle and Driver Details', 'file' => 'step3_vehicle_driver_details.php'],
        4 => ['name' => 'Excess Disclosures', 'file' => 'step4_excess_disclosures.php'],
        5 => ['name' => 'Bank Details Mandate', 'file' => 'step5_banking_details_mandate.php'],
        6 => ['name' => 'Declarations', 'file' => 'step6_declarations.php'],
        7 => ['name' => 'Finalization', 'file' => 'step7_finalization.php']
    ];

    // Skip step validation in edit_mode
    $edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
    if (!$edit_mode) {
        // Validate current step against script only if not in edit_mode
        $expected_file = $script_sections[$current_step]['file'];
        if ($script_name !== $expected_file) {
            error_log("[DEBUG] Step mismatch: Current step $current_step, expected file $expected_file, but accessed $script_name");
            $_SESSION['errors'] = ["You cannot access this step directly. Please follow the underwriting process in order."];
            ob_end_clean();
            header("Location: $expected_file?quote_id=$quote_id");
            exit();
        }
    }
}

// Function to start HTML structure
function start_html($step_title) {
    global $quote_id, $current_step, $script_sections;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Underwrite Policy - <?php echo htmlspecialchars($step_title); ?> - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --white: #fff;
            --font-scale: 1;
            --base-font: 14px;
            --base-padding: calc(0.375rem * var(--font-scale));
        }
        body {
            font-size: var(--base-font);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        main {
            flex: 1 0 auto;
        }
        footer {
            background-color: var(--white);
            color: var(--purple);
            font-size: var(--base-font);
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }
        header {
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: left;
        }
        header img {
            height: calc(110px * var(--font-scale));
        }
        .navbar {
            background-color: var(--white);
            padding: calc(0.5rem * var(--font-scale)) 1rem;
            justify-content: flex-start;
        }
        .navbtn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: var(--white);
            font-size: 14px;
            padding: calc(0.5rem * var(--font-scale));
            text-decoration: none;
        }
        .navbtn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: var(--white);
        }
        .btn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: var(--white);
            font-size: var(--base-font);
            padding: var(--base-padding) calc(0.75rem * var(--font-scale));
        }
        .btn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: var(--white);
        }
        .btn-outline-purple {
            border-color: var(--purple);
            color: var(--purple);
            background-color: var(--white);
            font-size: var(--base-font);
            padding: var(--base-padding) calc(0.75rem * var(--font-scale));
        }
        .btn-outline-purple:hover {
            background-color: var(--purple);
            color: var(--white);
            border-color: var(--purple);
        }
        .form-label,
        .form-control,
        .form-select {
            font-size: var(--base-font);
            padding: var(--base-padding) calc(0.75rem * var(--font-scale));
        }
        .section-heading {
            font-size: 16px;
            font-weight: bold;
            color: var(--purple);
            margin-bottom: 10px;
        }
        .progress-bar {
            background-color: var(--purple);
        }
        .additional-driver-section {
            position: relative;
            padding: 10px;
            border: 1px solid #ccc;
            margin-bottom: 10px;
        }
        .remove-driver {
            position: absolute;
            top: 5px;
            right: 5px;
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="../dashboard.php"><img src="../images/logo.png" alt="Profusion Insurance Logo"></a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../dashboard.php">Dashboard</a></li>
                            <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="user_management/manage_users.php">Manage Users</a></li>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="broker_management/manage_brokers.php">Manage Brokers</a></li>
                            <?php } ?>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="login_management/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
<?php
}
?>