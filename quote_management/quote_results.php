<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login_management/login.php");
    exit();
}
require '../db_connect.php';

// Get quote_id from URL and validate
$quote_id = $_GET['quote_id'] ?? null;
if (!$quote_id) {
    header("Location: ../dashboard.php");
    exit();
}

// Log request context for debugging
error_log("Quote Results Request: quote_id=$quote_id, URI={$_SERVER['REQUEST_URI']}, Referer=" . ($_SERVER['HTTP_REFERER'] ?? 'None'));

// Fetch quote and verify ownership
if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
    // Profusion roles have access to all quotes
    $stmt = $conn->prepare("
        SELECT q.*, b.brokerage_name 
        FROM quotes q 
        LEFT JOIN brokerages b ON q.brokerage_id = b.brokerage_id 
        WHERE q.quote_id = ?
    ");
    $stmt->bind_param("i", $quote_id);
} else {
    // Non-Profusion roles are restricted to their user_id or brokerage
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
    error_log("No quote found for quote_id=$quote_id for user_id={$_SESSION['user_id']} in quote_results.php");
    $error_message = "No quote found for Quote ID $quote_id. You may not have permission to view this quote.";
}
$quote_data = $quote_result->num_rows > 0 ? $quote_result->fetch_assoc() : null;
$stmt->close();

// Fetch vehicles and associated drivers
$vehicles = [];
$stmt = $conn->prepare("SELECT * FROM quote_vehicles WHERE quote_id = ? AND deleted_at IS NULL");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$vehicle_result = $stmt->get_result();
while ($vehicle = $vehicle_result->fetch_assoc()) {
    // Validate vehicle data
    if (empty($vehicle['vehicle_year']) || empty($vehicle['vehicle_make']) || empty($vehicle['vehicle_model']) || empty($vehicle['vehicle_value']) || floatval($vehicle['vehicle_value']) <= 0) {
        error_log("Invalid vehicle data for vehicle_id {$vehicle['vehicle_id']}: " . print_r($vehicle, true));
        continue; // Skip invalid vehicles
    }
    $driver_stmt = $conn->prepare("SELECT * FROM quote_drivers WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL");
    $driver_stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
    $driver_stmt->execute();
    $driver_result = $driver_stmt->get_result();
    $driver = $driver_result->num_rows > 0 ? $driver_result->fetch_assoc() : [];
    $driver_stmt->close();
    $vehicles[] = [
        'vehicle' => $vehicle,
        'driver' => $driver
    ];
}
$stmt->close();

// Debug: Log vehicles data
error_log('Fetched Vehicles: ' . print_r($vehicles, true));

// Check if vehicles array is empty
if (empty($vehicles)) {
    error_log("No valid vehicles found for quote_id $quote_id");
    $error_message = "No valid vehicles found for this quote. Please edit the quote to add valid vehicle details.";
} else {
    // Fetch breakdown from session
    $breakdown = isset($_SESSION['quote_breakdown']) ? $_SESSION['quote_breakdown'] : [];
    error_log('Post-Calculation Breakdown: ' . print_r($breakdown, true));

    // Prepare display data
    $vehicle_descriptions = [];
    $premium_options = [];
    if (empty($breakdown) || empty($breakdown['policy_fees'])) {
        error_log("Breakdown array is empty or missing policy_fees for quote_id $quote_id");
        $error_message = "No valid premium data found for this quote. Please edit the quote to recalculate premiums.";
    } else {
        // Extract total premiums from quote_data
        $total_premiums = [
            'premium6' => floatval($quote_data['premium6']),
            'premium5' => floatval($quote_data['premium5']),
            'premium4' => floatval($quote_data['premium4']),
            'premium_flat' => floatval($quote_data['premium_flat'])
        ];
        $original_premiums = [
            'premium6' => floatval($quote_data['original_premium6']),
            'premium5' => floatval($quote_data['original_premium5']),
            'premium4' => floatval($quote_data['original_premium4']),
            'premium_flat' => floatval($quote_data['original_premium_flat'])
        ];

        // Prepare premium options for display
        $labels = [
            'premium6' => ['text' => 'Premium 6', 'logo' => '../images/Nucleus6.png', 'excess' => '6% of sum insured min R5000.00'],
            'premium5' => ['text' => 'Premium 5', 'logo' => '../images/Nucleus5.png', 'excess' => '5% of sum insured min R5000.00'],
            'premium4' => ['text' => 'Premium 4', 'logo' => '../images/Nucleus4.png', 'excess' => '4% of sum insured min R5000.00'],
            'premium_flat' => ['text' => 'Premium Flat', 'logo' => '../images/NucleusCore.png', 'excess' => 'Flat R3500.00']
        ];

        foreach (['premium6', 'premium5', 'premium4', 'premium_flat'] as $key) {
            if ($total_premiums[$key] > 0) {
                $breakdown_details = [];
                $risk_premium_key = 'motor_risk_' . $key; // e.g., motor_risk_premium6
                $total_risk_premium_key = 'total_risk_' . $key; // e.g., total_risk_premium6
                // Add per-vehicle risk premiums and security requirements
                foreach ($vehicles as $index => $vehicle_data) {
                    $vehicle = $vehicle_data['vehicle'];
                    $vehicle_description = trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}");
                    $vehicle_breakdown = $breakdown[$index] ?? [];
                    if (!empty($vehicle_breakdown)) {
                        $breakdown_details[] = [
                            'type' => 'vehicle_risk_premium',
                            'label' => "Risk Premium ($vehicle_description)",
                            'value' => number_format($vehicle_breakdown[$risk_premium_key], 2)
                        ];
                        $breakdown_details[] = [
                            'type' => 'vehicle_security',
                            'label' => "Security Requirement",
                            'value' => $vehicle_breakdown['security'] ?? 'None',
                            'no_currency' => true // Flag to skip R prefix
                        ];
                    }
                }
                // Add total risk premium
                $breakdown_details[] = [
                    'type' => 'total_risk_premium',
                    'label' => 'Total Risk Premium',
                    'value' => number_format($breakdown[$total_risk_premium_key], 2)
                ];
                // Add policy-wide fees
                $policy_fees = $breakdown['policy_fees'];
                foreach (['broker_fee', 'convenience_drive', 'car_hire', 'sasria_motor', 'personal_liability', 'legal_assist', 'claims_assist'] as $fee_key) {
                    if (isset($policy_fees[$fee_key]) && $policy_fees[$fee_key] > 0) {
                        $breakdown_details[] = [
                            'type' => 'fee',
                            'label' => $fee_labels[$fee_key] ?? ucwords(str_replace('_', ' ', $fee_key)),
                            'value' => number_format($policy_fees[$fee_key], 2)
                        ];
                    }
                }
                // Add total fees
                $breakdown_details[] = [
                    'type' => 'total_fees',
                    'label' => 'Total Fees',
                    'value' => number_format($breakdown['total_policy_fees'], 2)
                ];

                $premium_options[$key] = [
                    'label' => $labels[$key]['text'],
                    'logo' => $labels[$key]['logo'],
                    'gross' => number_format($total_premiums[$key], 2),
                    'original' => number_format($original_premiums[$key], 2),
                    'excess' => $labels[$key]['excess'],
                    'breakdown' => $breakdown_details
                ];
            }
        }
    }
}

// Fee labels for breakdown display
$fee_labels = [
    'broker_fee' => 'Broker Fee',
    'convenience_drive' => 'Convenience Drive',
    'car_hire' => 'Car Hire',
    'claims_assist' => 'Claims Assist',
    'legal_assist' => 'Legal Assist',
    'sasria_motor' => 'Sasria Motor',
    'personal_liability' => 'Personal Liability'
];

// Check if user is authorized to view original premiums
$is_authorized = in_array($_SESSION['role_name'], ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Results - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --white: #fff;
        }
        body { 
            font-size: 16px; 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
        }
        main { 
            flex: 1; 
        }
        footer { 
            background-color: var(--white); 
            color: var(--purple); 
            padding: 1rem 0; 
            text-align: center; 
        }
        header { 
            padding: 1rem 0; 
        }
        header img { 
            height: 110px; 
        }
        .navbar { 
            background-color: var(--white); 
            padding: 0.5rem 1rem; 
        }
        .navbtn-purple {
            background-color: var(--purple); 
            color: var(--white); 
            padding: 0.5rem; 
            margin: 0 0.25rem;
            text-decoration: none; 
            font-size: 14px;
        }
        .navbtn-purple:hover { 
            background-color: #4B0082; 
            color: var(--white); 
        }
        .btn-purple {
            background-color: var(--purple); 
            color: var(--white); 
            border-color: var(--purple);
        }
        .btn-purple:hover { 
            background-color: #4B0082; 
            border-color: #4B0082; 
        }
        .premium-card { 
            border: 1px solid var(--purple); 
            background-color: #f9f9f9; 
            margin-bottom: 15px; 
        }
        .premium-card .card-header { 
            background-color: var(--purple); 
            color: var(--white); 
            font-size: 18px; 
            text-align: center; 
        }
        .product-logo { 
            width: 150px; 
            height: auto; 
        }
        .button-column { 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            align-items: flex-end; 
        }
        .collapse-table th { 
            width: 40%; 
            background-color: #f1f1f1; 
            font-weight: bold; 
        }
        .collapse-table .total-row { 
            font-weight: bold; 
            background-color: #d3d3d3; 
        }
        .collapse-table .total-row th { 
            font-size: 1.1em; 
        }
        .summary-heading {
            background-color: var(--purple);
            color: var(--white);
            font-size: 1.2em;
            padding: 0.5rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .premium-table .gross-row th,
        .premium-table .gross-row td {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="../dashboard.php"><img src="../images/logo.png" alt="Profusion Insurance Logo"></a>
            </header>
            <nav class="navbar navbar-expand-lg">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav">
                        <li class="nav-item"><a class="nav-link navbtn-purple" href="../dashboard.php">Dashboard</a></li>
                        <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                            <li class="nav-item"><a class="nav-link navbtn-purple" href="../user_management/manage_users.php">Manage Users</a></li>
                            <li class="nav-item"><a class="nav-link navbtn-purple" href="../broker_management/manage_brokers.php">Manage Brokers</a></li>
                        <?php } ?>
                        <li class="nav-item"><a class="nav-link navbtn-purple" href="../login_management/logout.php">Logout</a></li>
                    </ul>
                </div>
            </nav>

            <div class="container mt-4">
                <h2 class="mb-4">Quote Results (ID: <?php echo htmlspecialchars($quote_id); ?>)</h2>
                <?php if (isset($error_message)) { ?>
                    <div class="alert alert-warning">
                        <?php echo htmlspecialchars($error_message); ?>
                        <a href="edit_quote.php?quote_id=<?php echo htmlspecialchars($quote_id); ?>" class="btn btn-purple">Edit Quote</a>
                    </div>
                <?php } elseif (empty($premium_options)) { ?>
                    <p>No quote results available. <a href="edit_quote.php?quote_id=<?php echo htmlspecialchars($quote_id); ?>" class="btn btn-purple">Edit Quote</a></p>
                <?php } else { ?>
                    <div class="row">
                        <div class="col-12">
                            <?php foreach ($premium_options as $key => $option) { ?>
                                <div class="card premium-card">
                                    <div class="card-header"><?php echo htmlspecialchars($option['label']); ?></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <table class="table table-bordered premium-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th>Excess</th>
                                                            <?php if ($is_authorized) { ?>
                                                                <th>Original Premium</th>
                                                                <th>Discounted Premium</th>
                                                            <?php } else { ?>
                                                                <th>Premium</th>
                                                            <?php } ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr class="gross-row">
                                                            <td><img src="<?php echo htmlspecialchars($option['logo']); ?>" alt="<?php echo htmlspecialchars($option['label']); ?>" class="product-logo"></td>
                                                            <td><?php echo htmlspecialchars($option['excess']); ?></td>
                                                            <?php if ($is_authorized) { ?>
                                                                <td>R <?php echo htmlspecialchars($option['original']); ?></td>
                                                                <td>R <?php echo htmlspecialchars($option['gross']); ?></td>
                                                            <?php } else { ?>
                                                                <td>R <?php echo htmlspecialchars($option['gross']); ?></td>
                                                            <?php } ?>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="button-column">
                                                    <button class="btn btn-purple" data-bs-toggle="collapse" data-bs-target="#details-<?php echo $key; ?>" aria-expanded="false" aria-controls="details-<?php echo $key; ?>">View Details</button>
                                                    <a href="edit_quote.php?quote_id=<?php echo htmlspecialchars($quote_id); ?>" class="btn btn-purple">Edit Quote</a>
                                                    <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#downloadModal-<?php echo $key; ?>">Download Quote</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="collapse mt-3" id="details-<?php echo $key; ?>">
                                            <div class="summary-heading">Summary of Premium</div>
                                            <table class="table table-bordered collapse-table">
                                                <tbody>
                                                    <?php foreach ($option['breakdown'] as $item) { ?>
                                                        <tr <?php echo $item['type'] === 'total_risk_premium' || $item['type'] === 'total_fees' ? 'class="total-row"' : ''; ?>>
                                                            <th><?php echo htmlspecialchars($item['label']); ?></th>
                                                            <td><?php echo $item['type'] === 'vehicle_security' ? htmlspecialchars($item['value']) : 'R ' . htmlspecialchars($item['value']); ?></td>
                                                        </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Download Modal -->
                                    <div class="modal fade" id="downloadModal-<?php echo $key; ?>" tabindex="-1" aria-labelledby="downloadModalLabel-<?php echo $key; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="downloadModalLabel-<?php echo $key; ?>">Download Quote</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form action="../download_quote.php" method="get">
                                                        <input type="hidden" name="quote_id" value="<?php echo htmlspecialchars($quote_id); ?>">
                                                        <input type="hidden" name="excess_option" value="<?php echo $key; ?>">
                                                        <input type="hidden" name="breakdown" value="<?php echo htmlspecialchars(json_encode($breakdown)); ?>">
                                                        <p>Download <?php echo htmlspecialchars($option['label']); ?> with <?php echo htmlspecialchars($option['excess']); ?> excess.</p>
                                                        <button type="submit" class="btn btn-purple">Download</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <a href="../dashboard.php" class="btn btn-link mt-3">Back to Dashboard</a>
                <?php } ?>
            </div>
        </div>
    </main>

    <footer>
        <p>© 2025 Profusion Insurance</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
mysqli_close($conn);
?>