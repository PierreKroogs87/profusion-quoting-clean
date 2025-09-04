<?php
require 'underwriting_common.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id'])) {
    error_log("[DEBUG] Redirect to login.php: No user_id in session");
    ob_end_clean();
    header("Location: ../login.php");
    exit();
}
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant', 'Broker Manager'];
if (!in_array($_SESSION['role_name'], $authorized_roles)) {
    error_log("[DEBUG] Unauthorized access attempt by user_id {$_SESSION['user_id']} with role {$_SESSION['role_name']}");
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}

// Get policy_id from URL
$policy_id = $_GET['policy_id'] ?? null;
if (!$policy_id || !is_numeric($policy_id)) {
    error_log("[DEBUG] Invalid or missing policy_id in edit_vehicle_details.php");
    $_SESSION['errors'] = ["Invalid or missing policy ID."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
error_log("[DEBUG] Received policy_id: $policy_id");

// Fetch policy and quote data
if (strpos($_SESSION['role_name'], 'Profusion') === 0) {
    error_log("[DEBUG] Using query without restrictions for Profusion role");
    $stmt = $conn->prepare("
        SELECT p.*, q.quote_id FROM policies p
        JOIN quotes q ON p.quote_id = q.quote_id
        WHERE p.policy_id = ?
    ");
    $stmt->bind_param("i", $policy_id);
} else {
    error_log("[DEBUG] Using query with user_id filter for role: " . ($_SESSION['role_name'] ?? 'Not set'));
    $stmt = $conn->prepare("
        SELECT p.*, q.quote_id FROM policies p
        JOIN quotes q ON p.quote_id = q.quote_id
        WHERE p.policy_id = ? AND (p.user_id = ? OR ? IN (SELECT user_id FROM users WHERE brokerage_id = p.brokerage_id))
    ");
    $stmt->bind_param("iii", $policy_id, $_SESSION['user_id'], $_SESSION['user_id']);
}
$stmt->execute();
$policy_result = $stmt->get_result();
if ($policy_result->num_rows === 0) {
    error_log("[DEBUG] No policy found for policy_id=$policy_id for user_id={$_SESSION['user_id']}");
    $_SESSION['errors'] = ["No policy found for this policy ID."];
    ob_end_clean();
    header("Location: ../dashboard.php");
    exit();
}
$policy_data = $policy_result->fetch_assoc();
$quote_id = $policy_data['quote_id'];
error_log("[DEBUG] Fetched policy_data: " . print_r($policy_data, true));
error_log("[DEBUG] Fetched quote_id: $quote_id for policy_id=$policy_id");
error_log("[DEBUG] Fetched policy_data in edit_vehicle_details: " . print_r($policy_data, true));
$stmt->close();

// Fetch vehicle and driver data
$vehicles = [];
$vehicle_stmt = $conn->prepare("
    SELECT qv.*, qd.driver_initials, qd.driver_surname
    FROM quote_vehicles qv
    LEFT JOIN quote_drivers qd ON qv.vehicle_id = qd.vehicle_id AND qv.quote_id = qd.quote_id
    WHERE qv.quote_id = ? AND qv.deleted_at IS NULL
    ORDER BY qv.vehicle_id
");
$vehicle_stmt->bind_param("i", $quote_id);
$vehicle_stmt->execute();
$vehicle_result = $vehicle_stmt->get_result();
$index = 0;
while ($vehicle = $vehicle_result->fetch_assoc()) {
    $chassis_key = "3.4_chassis_number_$index";
    $engine_key = "3.4_engine_number_$index";
    $finance_key = "3.5_finance_house_$index";
    $underwriting_stmt = $conn->prepare("
        SELECT question_key, response
        FROM policy_underwriting_data
        WHERE policy_id = ? AND section = 'motor_section' AND question_key IN (?, ?, ?)
    ");
    $underwriting_stmt->bind_param("isss", $policy_id, $chassis_key, $engine_key, $finance_key);
    $underwriting_stmt->execute();
    $underwriting_result = $underwriting_stmt->get_result();
    $underwriting_data = [];
    while ($row = $underwriting_result->fetch_assoc()) {
        $underwriting_data[$row['question_key']] = $row['response'];
    }
    $underwriting_stmt->close();
    $vehicles[] = [
        'vehicle' => $vehicle,
        'driver' => [
            'driver_initials' => $vehicle['driver_initials'] ?? 'N/A',
            'driver_surname' => $vehicle['driver_surname'] ?? 'N/A'
        ],
        'chassis_number' => $underwriting_data[$chassis_key] ?? '',
        'engine_number' => $underwriting_data[$engine_key] ?? '',
        'finance_house' => $underwriting_data[$finance_key] ?? ''
    ];
    $index++;
}
$vehicle_stmt->close();
if (empty($vehicles)) {
    error_log("[DEBUG] No valid vehicles found for quote_id=$quote_id");
    $_SESSION['errors'] = ["No valid vehicles found for this policy."];
    ob_end_clean();
    header("Location: ../dashboard.php"); // Changed to dashboard, as quote_id may be empty
    exit();
}
error_log("[DEBUG] Fetched vehicles for policy_id=$policy_id: " . print_r($vehicles, true));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Edit Vehicle Details POST Data: " . print_r($_POST, true));

        $vehicles_post = $_POST['vehicles'] ?? [];
        if (count($vehicles_post) !== count($vehicles)) {
            throw new Exception("Mismatch in number of vehicles submitted");
        }

        // Validate and update vehicle details
        foreach ($vehicles_post as $index => $vehicle_data) {
            if (!isset($vehicles[$index])) {
                throw new Exception("Invalid vehicle index $index");
            }

            // Validate inputs
            if (empty($vehicle_data['chassis_number']) || !preg_match('/^[A-Za-z0-9]+$/', $vehicle_data['chassis_number'])) {
                throw new Exception("Invalid or missing chassis number for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['engine_number']) || !preg_match('/^[A-Za-z0-9]+$/', $vehicle_data['engine_number'])) {
                throw new Exception("Invalid or missing engine number for vehicle " . ($index + 1));
            }
            if (!empty($vehicle_data['finance_house']) && !preg_match('/^[A-Za-z0-9\s,.@-]{0,100}$/', $vehicle_data['finance_house'])) {
                throw new Exception("Invalid finance house name for vehicle " . ($index + 1) . " (max 100 characters, alphanumeric and basic punctuation allowed)");
            }

            // Save to policy_underwriting_data
            $responses = [
                "3.4_chassis_number_$index" => $vehicle_data['chassis_number'],
                "3.4_engine_number_$index" => $vehicle_data['engine_number'],
                "3.5_finance_house_$index" => $vehicle_data['finance_house'] ?? ''
            ];

            $stmt = $conn->prepare("
                INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
                VALUES (?, 'motor_section', ?, ?)
                ON DUPLICATE KEY UPDATE response = ?
            ");
            foreach ($responses as $key => $value) {
                $stmt->bind_param("isss", $policy_id, $key, $value, $value);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to save response for $key: " . $stmt->error);
                }
            }
            $stmt->close();
        }

        $conn->commit();
        error_log("Edit Vehicle Details: Successfully updated vehicle details for policy_id=$policy_id");
        $_SESSION['success'] = ["Vehicle details updated successfully."];
        header("Location: ../dashboard.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Edit Vehicle Details Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        header("Location: edit_vehicle_details.php?policy_id=$policy_id");
        exit();
    }
}

// Start HTML
start_html("Edit Vehicle Details");
?>

<form method="post" action="edit_vehicle_details.php?policy_id=<?php echo htmlspecialchars($policy_id); ?>" class="row g-3" id="editVehicleForm">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-header section-heading">Edit Vehicle Details (Policy ID: <?php echo htmlspecialchars($policy_id); ?>)</div>
            <div class="card-body">
                <?php if (!empty($_SESSION['errors'])): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($_SESSION['errors'] as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                        <?php unset($_SESSION['errors']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php foreach ($_SESSION['success'] as $success): ?>
                            <p><?php echo htmlspecialchars($success); ?></p>
                        <?php endforeach; ?>
                        <?php unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($vehicles as $index => $data) {
                    $vehicle = $data['vehicle'];
                    $vehicle_description = htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
                    ?>
                    <div class="card mb-3">
                        <div class="card-header">Vehicle <?php echo $index + 1; ?>: <?php echo $vehicle_description; ?></div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <label for="vehicles[<?php echo $index; ?>][chassis_number]" class="col-md-4 col-form-label">Chassis Number (VIN):</label>
                                <div class="col-md-8">
                                    <input type="text" name="vehicles[<?php echo $index; ?>][chassis_number]" id="chassis_number_<?php echo $index; ?>" value="<?php echo htmlspecialchars($data['chassis_number']); ?>" class="form-control" pattern="[A-Za-z0-9]+" required>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="vehicles[<?php echo $index; ?>][engine_number]" class="col-md-4 col-form-label">Engine Number:</label>
                                <div class="col-md-8">
                                    <input type="text" name="vehicles[<?php echo $index; ?>][engine_number]" id="engine_number_<?php echo $index; ?>" value="<?php echo htmlspecialchars($data['engine_number']); ?>" class="form-control" pattern="[A-Za-z0-9]+" required>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <label for="vehicles[<?php echo $index; ?>][finance_house]" class="col-md-4 col-form-label">Finance Institution (if applicable):</label>
                                <div class="col-md-8">
                                    <input type="text" name="vehicles[<?php echo $index; ?>][finance_house]" id="finance_house_<?php echo $index; ?>" value="<?php echo htmlspecialchars($data['finance_house']); ?>" class="form-control" pattern="[A-Za-z0-9\s,.@-]*" maxlength="100">
                                    <small class="form-text text-muted">Leave blank if the vehicle is not financed.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-purple">Save Changes</button>
        <a href="../dashboard.php" class="btn btn-link ms-3">Back to Dashboard</a>
    </div>
</form>

</div>
</div>
</div>
</main>

<footer class="text-center py-3 mt-4">
    <p>© 2025 Profusion Insurance</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Validate form on submission
        document.getElementById('editVehicleForm').addEventListener('submit', function(event) {
            const requiredInputs = document.querySelectorAll('input[required]');
            let valid = true;
            requiredInputs.forEach(input => {
                if (!input.value) {
                    valid = false;
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            if (!valid) {
                event.preventDefault();
                alert('Please fill out all required fields.');
            }
        });
    });
</script>
</body>
</html>
<?php
$conn->close();
?>