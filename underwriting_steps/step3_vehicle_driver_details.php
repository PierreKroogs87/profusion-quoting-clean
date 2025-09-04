<?php
require 'underwriting_common.php';

// Check if we're on the correct step (handled by underwriting_common.php)
// $current_step is already validated in underwriting_common.php

// Initialize variables for edit_mode
$edit_mode = isset($_GET['edit_mode']) && $_GET['edit_mode'] === 'true';
$vehicle_responses = [];
$additional_drivers_data = [];

// Fetch policy_id
$stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
$stmt->bind_param("i", $quote_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $policy_id = $result->fetch_assoc()['policy_id'];
} else {
    $policy_id = null; // Will create draft in POST if needed (normal mode only)
}
$stmt->close();

// Load existing vehicle responses if in edit_mode
if ($edit_mode && $policy_id) {
    foreach ($vehicles_data as $index => $data) {
        $vehicle_responses[$index] = [];
        $stmt = $conn->prepare("
            SELECT question_key, response
            FROM policy_underwriting_data
            WHERE policy_id = ? AND section = 'motor_section' AND question_key LIKE ?
        ");
        $like_pattern = "3.%_$index";
        $stmt->bind_param("is", $policy_id, $like_pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Adjust question keys to match new numbering
            $old_key = $row['question_key'];
            $new_key = $old_key;
            if (preg_match('/^3\.(\d+)_(.+)_' . $index . '$/', $old_key, $matches)) {
                $question_num = (int)$matches[1];
                $suffix = $matches[2];
                if ($question_num >= 5) {
                    $new_question_num = $question_num + 1;
                    $new_key = "3.{$new_question_num}_{$suffix}_$index";
                }
            } elseif (preg_match('/^3\.19_additional_driver_(.+)_' . $index . '_(\d+)$/', $old_key, $matches)) {
                $suffix = $matches[1];
                $driver_index = $matches[2];
                $new_key = "3.20_additional_driver_{$suffix}_{$index}_{$driver_index}";
            }
            $vehicle_responses[$index][$new_key] = $row['response'];
        }
        $stmt->close();

        // Load additional drivers
        $additional_drivers_data[$index] = [];
        $stmt = $conn->prepare("
            SELECT * FROM quote_additional_drivers 
            WHERE vehicle_id = ? AND quote_id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $vehicles_data[$index]['vehicle']['vehicle_id'], $quote_id);
        $stmt->execute();
        $add_driver_result = $stmt->get_result();
        while ($add_driver = $add_driver_result->fetch_assoc()) {
            $additional_drivers_data[$index][] = $add_driver;
        }
        $stmt->close();
    }
    error_log("Edit mode: Loaded vehicle responses for step 3: " . print_r($vehicle_responses, true));
    error_log("Edit mode: Loaded additional drivers for step 3: " . print_r($additional_drivers_data, true));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Debug: Log POST data
        error_log("Step 3 POST Data: " . print_r($_POST, true));

        // Determine navigation direction
        $direction = $_POST['direction'] ?? 'next';

        // Check if policy exists, else create a draft (only in normal mode)
        $stmt = $conn->prepare("SELECT policy_id FROM policies WHERE quote_id = ?");
        $stmt->bind_param("i", $quote_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $policy_id = $result->fetch_assoc()['policy_id'];
        } else if (!$edit_mode) {
            // Create draft in normal mode
            if (!isset($_SESSION['underwriting_product'][$quote_id]) || !isset($_SESSION['underwriting_product'][$quote_id]['premium_amount'])) {
                error_log("No product_type or premium_amount in session for quote_id=$quote_id");
                throw new Exception("Product type or premium amount not set. Please start over from the dashboard.");
            }
            $product_type = $_SESSION['underwriting_product'][$quote_id]['product_type'];
            $premium_amount = $_SESSION['underwriting_product'][$quote_id]['premium_amount'];
            $stmt = $conn->prepare("
                INSERT INTO policies (quote_id, user_id, brokerage_id, status, premium_type, premium_amount)
                VALUES (?, ?, ?, 'draft', ?, ?)
            ");
            $stmt->bind_param("iiisd", $quote_id, $_SESSION['user_id'], $quote_data['brokerage_id'], $product_type, $premium_amount);
            if (!$stmt->execute()) {
                throw new Exception("Failed to create draft policy: " . $stmt->error);
            }
            $policy_id = $conn->insert_id;
            error_log("Created draft policy with policy_id=$policy_id for quote_id=$quote_id");
        } else {
            throw new Exception("No policy found for editing in edit mode.");
        }
        $stmt->close();

        if ($direction === 'previous') {
            if (!$edit_mode) {
                $_SESSION['underwriting_step'][$quote_id] = 2;
            }
            $conn->commit();
            error_log("Step 3: Navigating to previous step (Step 2)");
            header("Location: step2_policy_holder_info.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
            exit();
        }

        // Process Step 3 responses
        $vehicles = $_POST['vehicles'] ?? [];
        if (count($vehicles) !== count($vehicles_data)) {
            throw new Exception("Mismatch in number of vehicles submitted");
        }

        // Validate and update per-vehicle responses
        foreach ($vehicles as $index => $vehicle_data) {
            if (!isset($vehicles_data[$index])) {
                throw new Exception("Invalid vehicle index $index");
            }
            $vehicle = $vehicles_data[$index]['vehicle'];
            $driver = $vehicles_data[$index]['driver'];

            // Validate vehicle responses
            if (empty($vehicle_data['vehicle_year']) || $vehicle_data['vehicle_year'] < 1900 || $vehicle_data['vehicle_year'] > 2025) {
                throw new Exception("Invalid or missing vehicle year for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['vehicle_make'])) {
                throw new Exception("Invalid or missing vehicle make for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['vehicle_model'])) {
                throw new Exception("Invalid or missing vehicle model for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['sum_insured']) || $vehicle_data['sum_insured'] <= 0) {
                throw new Exception("Invalid or missing sum insured for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['engine_number']) || !preg_match('/^[A-Za-z0-9]+$/', $vehicle_data['engine_number'])) {
                throw new Exception("Invalid or missing engine number for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['chassis_number']) || !preg_match('/^[A-Za-z0-9]+$/', $vehicle_data['chassis_number'])) {
                throw new Exception("Invalid or missing chassis number for vehicle " . ($index + 1));
            }
            if (!empty($vehicle_data['finance_house']) && !preg_match('/^[A-Za-z0-9\s,.@-]{0,100}$/', $vehicle_data['finance_house'])) {
                throw new Exception("Invalid finance house name for vehicle " . ($index + 1) . " (max 100 characters, alphanumeric and basic punctuation allowed)");
            }
            if (!in_array($vehicle_data['registered_in_client_name'] ?? '', ['yes', 'no'])) {
                throw new Exception("Invalid or missing registration status for vehicle " . ($index + 1));
            }
            if ($vehicle_data['registered_in_client_name'] === 'no' && empty($vehicle_data['registered_owner_name'])) {
                throw new Exception("Registered owner name required for vehicle " . ($index + 1));
            }
            if ($vehicle_data['coverage_type'] !== 'comprehensive') {
                throw new Exception("Invalid coverage type for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['vehicle_condition'] ?? '', ['new', 'used', 'demo'])) {
                throw new Exception("Invalid or missing vehicle condition for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['vehicle_use'] ?? '', ['private', 'business'])) {
                throw new Exception("Invalid or missing vehicle use for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['regular_driver'])) {
                throw new Exception("Invalid or missing regular driver name for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['driver_id_number']) || !preg_match('/^\d{13}$/', $vehicle_data['driver_id_number'])) {
                throw new Exception("Invalid or missing regular driver ID number for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['driver_dob']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vehicle_data['driver_dob'])) {
                throw new Exception("Invalid or missing regular driver date of birth for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['licence_type'] ?? '', ['B', 'EB', 'C1', 'EC', 'EC1'])) {
                throw new Exception("Invalid or missing licence type for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['year_of_issue']) || $vehicle_data['year_of_issue'] < 1924 || $vehicle_data['year_of_issue'] > 2025) {
                throw new Exception("Invalid or missing licence issue year for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['has_comprehensive_insurance'] ?? '', ['yes', 'no'])) {
                throw new Exception("Invalid or missing comprehensive insurance status for vehicle " . ($index + 1));
            }
            if ($vehicle_data['has_comprehensive_insurance'] === 'yes') {
                if (!isset($vehicle_data['insurance_duration']) || $vehicle_data['insurance_duration'] < 0) {
                    throw new Exception("Invalid or missing insurance duration for vehicle " . ($index + 1));
                }
                if (!in_array($vehicle_data['insurance_gaps'] ?? '', ['yes', 'no'])) {
                    throw new Exception("Invalid or missing insurance gaps response for vehicle " . ($index + 1));
                }
                if (!in_array($vehicle_data['recent_claims'] ?? '', ['yes', 'no'])) {
                    throw new Exception("Invalid or missing recent claims response for vehicle " . ($index + 1));
                }
            }
            if (!in_array($vehicle_data['existing_damage'] ?? '', ['yes', 'no'])) {
                throw new Exception("Invalid or missing existing damage response for vehicle " . ($index + 1));
            }
            if ($vehicle_data['existing_damage'] === 'yes' && empty($vehicle_data['damage_description'])) {
                throw new Exception("Damage description required for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['street'])) {
                throw new Exception("Invalid or missing street address for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['suburb_vehicle'])) {
                throw new Exception("Invalid or missing suburb for vehicle " . ($index + 1));
            }
            if (empty($vehicle_data['postal_code']) || !preg_match('/^\d{4}$/', $vehicle_data['postal_code'])) {
                throw new Exception("Invalid or missing postal code for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['security_device'] ?? '', ['none', 'reactive_tracking', 'proactive_tracking', 'alarm', 'immobiliser'])) {
                throw new Exception("Invalid or missing security device for vehicle " . ($index + 1));
            }
            if (!in_array($vehicle_data['car_hire'] ?? '', [
                'None',
                'Group B Manual Hatchback',
                'Group C Manual Sedan',
                'Group D Automatic Hatchback',
                'Group H 1 Ton LDV',
                'Group M Luxury Hatchback'
            ])) {
                throw new Exception("Invalid or missing car hire option for vehicle " . ($index + 1));
            }
            $recommended_security = getSecurityRequirements(
                $vehicle_data['vehicle_make'],
                $vehicle_data['vehicle_model'],
                floatval($vehicle_data['sum_insured'])
            );
            if (in_array($recommended_security, ['Reactive Tracking', 'Proactive Tracking']) && $vehicle_data['security_device'] === 'none') {
                throw new Exception("Security device required for vehicle " . ($index + 1) . " (Recommended: $recommended_security)");
            }
            if (!in_array($vehicle_data['add_additional_drivers'] ?? '', ['yes', 'no'])) {
                throw new Exception("Invalid or missing additional drivers response for vehicle " . ($index + 1));
            }

            // Validate additional drivers
            if ($vehicle_data['add_additional_drivers'] === 'yes') {
                if (!isset($vehicle_data['additional_drivers']) || !is_array($vehicle_data['additional_drivers'])) {
                    throw new Exception("No additional driver details provided for vehicle " . ($index + 1));
                }
                foreach ($vehicle_data['additional_drivers'] as $driver_index => $add_driver) {
                    if (empty($add_driver['name']) || !preg_match('/^[A-Za-z. ]+$/', $add_driver['name'])) {
                        throw new Exception("Invalid or missing additional driver name for vehicle " . ($index + 1) . ", driver " . ($driver_index + 1));
                    }
                    if (empty($add_driver['id_number']) || !preg_match('/^\d{13}$/', $add_driver['id_number'])) {
                        throw new Exception("Invalid or missing additional driver ID number for vehicle " . ($index + 1) . ", driver " . ($driver_index + 1));
                    }
                    if (!in_array($add_driver['licence_type'] ?? '', ['B', 'EB', 'C1', 'EC', 'EC1'])) {
                        throw new Exception("Invalid or missing additional driver licence type for vehicle " . ($index + 1) . ", driver " . ($driver_index + 1));
                    }
                }
            }

            // Update quote_vehicles
            $stmt = $conn->prepare("
                UPDATE quote_vehicles SET
                    vehicle_year = ?, vehicle_make = ?, vehicle_model = ?, vehicle_value = ?, vehicle_use = ?,
                    street = ?, suburb_vehicle = ?, postal_code = ?, car_hire = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            $stmt->bind_param(
                "issdsssisii",
                $vehicle_data['vehicle_year'],
                $vehicle_data['vehicle_make'],
                $vehicle_data['vehicle_model'],
                $vehicle_data['sum_insured'],
                $vehicle_data['vehicle_use'],
                $vehicle_data['street'],
                $vehicle_data['suburb_vehicle'],
                $vehicle_data['postal_code'],
                $vehicle_data['car_hire'],
                $vehicle['vehicle_id'],
                $quote_id
            );
            if (!$stmt->execute()) {
                throw new Exception("Failed to update vehicle details for vehicle " . ($index + 1) . ": " . $stmt->error);
            }
            $stmt->close();

            // Update quote_drivers
            $stmt = $conn->prepare("
                UPDATE quote_drivers SET
                    driver_initials = ?, driver_surname = ?, driver_id_number = ?, dob = ?,
                    licence_type = ?, year_of_issue = ?, licence_held = ?
                WHERE vehicle_id = ? AND quote_id = ?
            ");
            $driver_name_parts = explode(' ', $vehicle_data['regular_driver'], 2);
            $driver_initials = $driver_name_parts[0] ?? '';
            $driver_surname = $driver_name_parts[1] ?? '';
            $licence_held = 2025 - (int)$vehicle_data['year_of_issue'];
            $stmt->bind_param(
                "sssssiiii",
                $driver_initials,
                $driver_surname,
                $vehicle_data['driver_id_number'],
                $vehicle_data['driver_dob'],
                $vehicle_data['licence_type'],
                $vehicle_data['year_of_issue'],
                $licence_held,
                $vehicle['vehicle_id'],
                $quote_id
            );
            if (!$stmt->execute()) {
                throw new Exception("Failed to update driver details for vehicle " . ($index + 1) . ": " . $stmt->error);
            }
            $stmt->close();

            // Handle additional drivers
            if ($vehicle_data['add_additional_drivers'] === 'yes') {
                // Soft-delete existing additional drivers
                $stmt = $conn->prepare("UPDATE quote_additional_drivers SET deleted_at = NOW() WHERE vehicle_id = ? AND quote_id = ?");
                $stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to soft-delete existing additional drivers for vehicle " . ($index + 1) . ": " . $stmt->error);
                }
                $stmt->close();

                // Insert new additional drivers
                $stmt = $conn->prepare("
                    INSERT INTO quote_additional_drivers (quote_id, vehicle_id, driver_name, driver_id_number, licence_type)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($vehicle_data['additional_drivers'] as $add_driver) {
                    $stmt->bind_param(
                        "iisss",
                        $quote_id,
                        $vehicle['vehicle_id'],
                        $add_driver['name'],
                        $add_driver['id_number'],
                        $add_driver['licence_type']
                    );
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to insert additional driver for vehicle " . ($index + 1) . ": " . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                // Soft-delete all additional drivers if none are added
                $stmt = $conn->prepare("UPDATE quote_additional_drivers SET deleted_at = NOW() WHERE vehicle_id = ? AND quote_id = ?");
                $stmt->bind_param("ii", $vehicle['vehicle_id'], $quote_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to soft-delete additional drivers for vehicle " . ($index + 1) . ": " . $stmt->error);
                }
                $stmt->close();
            }

            // Save vehicle-specific responses
            $vehicle_responses = [
                "3.2_vehicle_year_$index" => $vehicle_data['vehicle_year'],
                "3.2_vehicle_make_$index" => $vehicle_data['vehicle_make'],
                "3.2_vehicle_model_$index" => $vehicle_data['vehicle_model'],
                "3.3_sum_insured_$index" => $vehicle_data['sum_insured'],
                "3.4_engine_number_$index" => $vehicle_data['engine_number'],
                "3.4_chassis_number_$index" => $vehicle_data['chassis_number'],
                "3.5_finance_house_$index" => $vehicle_data['finance_house'] ?? '',
                "3.6_registered_in_client_name_$index" => $vehicle_data['registered_in_client_name'],
                "3.6_registered_owner_name_$index" => $vehicle_data['registered_owner_name'] ?? '',
                "3.7_coverage_type_$index" => $vehicle_data['coverage_type'],
                "3.8_vehicle_condition_$index" => $vehicle_data['vehicle_condition'],
                "3.9_vehicle_use_$index" => $vehicle_data['vehicle_use'],
                "3.11_regular_driver_$index" => $vehicle_data['regular_driver'],
                "3.11_driver_id_number_$index" => $vehicle_data['driver_id_number'],
                "3.11_driver_dob_$index" => $vehicle_data['driver_dob'],
                "3.12_licence_type_$index" => $vehicle_data['licence_type'],
                "3.13_year_of_issue_$index" => $vehicle_data['year_of_issue'],
                "3.16_has_comprehensive_insurance_$index" => $vehicle_data['has_comprehensive_insurance'],
                "3.16_insurance_duration_$index" => $vehicle_data['insurance_duration'] ?? '',
                "3.16_insurance_gaps_$index" => $vehicle_data['insurance_gaps'] ?? '',
                "3.16_recent_claims_$index" => $vehicle_data['recent_claims'] ?? '',
                "3.20_add_additional_drivers_$index" => $vehicle_data['add_additional_drivers'],
                "3.27_existing_damage_$index" => $vehicle_data['existing_damage'],
                "3.27_damage_description_$index" => $vehicle_data['damage_description'] ?? '',
                "3.28_street_$index" => $vehicle_data['street'],
                "3.28_suburb_vehicle_$index" => $vehicle_data['suburb_vehicle'],
                "3.28_postal_code_$index" => $vehicle_data['postal_code'],
                "3.29_security_device_$index" => $vehicle_data['security_device'],
                "3.30_car_hire_$index" => $vehicle_data['car_hire']
            ];

            // Save additional driver responses
            if ($vehicle_data['add_additional_drivers'] === 'yes') {
                foreach ($vehicle_data['additional_drivers'] as $driver_index => $add_driver) {
                    $vehicle_responses["3.20_additional_driver_name_$index" . "_$driver_index"] = $add_driver['name'];
                    $vehicle_responses["3.20_additional_driver_id_number_$index" . "_$driver_index"] = $add_driver['id_number'];
                    $vehicle_responses["3.20_additional_driver_licence_type_$index" . "_$driver_index"] = $add_driver['licence_type'];
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO policy_underwriting_data (policy_id, section, question_key, response)
                VALUES (?, 'motor_section', ?, ?)
                ON DUPLICATE KEY UPDATE response = ?
            ");
            foreach ($vehicle_responses as $key => $value) {
                $stmt->bind_param("isss", $policy_id, $key, $value, $value);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to save response for $key: " . $stmt->error);
                }
            }
            $stmt->close();
        }

        // If in edit_mode, redirect to dashboard after save; else advance step
        if ($edit_mode) {
            $conn->commit();
            error_log("Step 3 Edit Mode: Changes saved, redirecting to dashboard");
            header("Location: ../dashboard.php");
            exit();
        } else {
            // Advance to next step in normal mode
            $_SESSION['underwriting_step'][$quote_id] = 4;
            $conn->commit();
            error_log("Step 3: Navigating to next step (Step 4)");
            header("Location: step4_excess_disclosures.php?quote_id=$quote_id");
            exit();
        }

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errors[] = $e->getMessage();
        error_log("Step 3 Transaction failed: " . $e->getMessage());
        $_SESSION['errors'] = $errors;
        ob_end_clean();
        header("Location: step3_vehicle_driver_details.php?quote_id=$quote_id" . ($edit_mode ? '&edit_mode=true' : ''));
        exit();
    }
}

// Start HTML
start_html($script_sections[3]['name']);
?>

<div class="container mt-4">
    <h2 class="mb-4">Underwrite Policy (Quote ID: <?php echo htmlspecialchars($quote_id ?? 'N/A'); ?>)</h2>
    <?php if (isset($_SESSION['errors'])) { ?>
        <div class="alert alert-danger">
            <?php foreach ($_SESSION['errors'] as $error) { ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php } ?>
            <?php unset($_SESSION['errors']); ?>
        </div>
    <?php } ?>
    <!-- Progress Bar -->
    <div class="progress mb-4">
        <div class="progress-bar" role="progressbar" style="width: <?php echo (100 / count($script_sections)) * $current_step; ?>%;" aria-valuenow="<?php echo $current_step; ?>" aria-valuemin="1" aria-valuemax="<?php echo count($script_sections); ?>">
            Step <?php echo $current_step; ?> of <?php echo count($script_sections); ?>
        </div>
    </div>
    <!-- Navigation Bar -->
    <div class="mb-4">
        <h5>Navigate to Step:</h5>
        <div class="btn-group" role="group" aria-label="Underwriting Steps">
            <?php foreach ($script_sections as $step => $section) { ?>
                <?php
                $is_current_step = $step === $current_step;
                $disabled = !$edit_mode && $is_current_step ? 'disabled' : '';
                $btn_class = $is_current_step ? 'btn btn-purple' : 'btn btn-outline-purple';
                ?>
                <a href="<?php echo htmlspecialchars($section['file'] . '?quote_id=' . $quote_id . ($edit_mode ? '&edit_mode=true' : '')); ?>" 
                   class="<?php echo $btn_class; ?>" 
                   <?php echo $disabled; ?>>
                    <?php echo htmlspecialchars($section['name']); ?> (Step <?php echo $step; ?>)
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="row">
        <div class="col-md-8">
            <form method="post" action="step3_vehicle_driver_details.php?quote_id=<?php echo htmlspecialchars($quote_id); ?><?php echo $edit_mode ? '&edit_mode=true' : ''; ?>" class="row g-3" id="underwritingForm">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header section-heading"><?php echo htmlspecialchars($script_sections[3]['name']); ?></div>
                        <div class="card-body">
                            <?php if (!empty($_SESSION['errors'])): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($_SESSION['errors'] as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                    <?php unset($_SESSION['errors']); ?>
                                </div>
                            <?php endif; ?>
                            <p><strong>3.1</strong> Let’s confirm your motor vehicle information. (Refer values over R500,000 to management. Maximum value R750,000)</p>
                            <?php foreach ($vehicles_data as $index => $data) {
                                $vehicle = $data['vehicle'];
                                $driver = $data['driver'];
                                $vehicle_description = htmlspecialchars(trim("{$vehicle['vehicle_year']} {$vehicle['vehicle_make']} {$vehicle['vehicle_model']}"));
                            ?>
                                <div class="card mb-3">
                                    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#vehicle_<?php echo $index; ?>" aria-expanded="true" aria-controls="vehicle_<?php echo $index; ?>" style="cursor: pointer;">
                                        Vehicle <?php echo $index + 1; ?>: <?php echo $vehicle_description; ?>
                                    </div>
                                    <div id="vehicle_<?php echo $index; ?>" class="collapse show">
                                        <div class="card-body">
                                            <p><strong>3.1-3.2</strong> Confirm vehicle: <?php echo $vehicle_description; ?>.</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][vehicle_year]" class="col-md-4 col-form-label">Vehicle Year:</label>
                                                <div class="col-md-8">
                                                    <input type="number" name="vehicles[<?php echo $index; ?>][vehicle_year]" id="vehicle_year_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.2_vehicle_year_' . $index] ?? $vehicle['vehicle_year'] ?? ''); ?>" class="form-control" min="1900" max="2025" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][vehicle_make]" class="col-md-4 col-form-label">Vehicle Make:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][vehicle_make]" id="vehicle_make_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.2_vehicle_make_' . $index] ?? $vehicle['vehicle_make'] ?? ''); ?>" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][vehicle_model]" class="col-md-4 col-form-label">Vehicle Model:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][vehicle_model]" id="vehicle_model_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.2_vehicle_model_' . $index] ?? $vehicle['vehicle_model'] ?? ''); ?>" class="form-control" required>
                                                </div>
                                            </div>
                                            <p><strong>3.3</strong> Sum Insured: R <?php echo number_format($vehicle['vehicle_value'], 2); ?>.</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][sum_insured]" class="col-md-4 col-form-label">Confirm Sum Insured:</label>
                                                <div class="col-md-8">
                                                    <input type="number" name="vehicles[<?php echo $index; ?>][sum_insured]" id="sum_insured_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.3_sum_insured_' . $index] ?? $vehicle['vehicle_value'] ?? ''); ?>" class="form-control" min="0" step="0.01" required>
                                                </div>
                                            </div>
                                            <p><strong>3.4</strong> Provide Engine and Chassis Numbers.</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][engine_number]" class="col-md-4 col-form-label">Engine Number:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][engine_number]" id="engine_number_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.4_engine_number_' . $index] ?? ''); ?>" class="form-control" pattern="[A-Za-z0-9]+" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][chassis_number]" class="col-md-4 col-form-label">Chassis Number:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][chassis_number]" id="chassis_number_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.4_chassis_number_' . $index] ?? ''); ?>" class="form-control" pattern="[A-Za-z0-9]+" required>
                                                </div>
                                            </div>
                                            <p><strong>3.6</strong> Is the vehicle financed? If yes, provide the finance institution's name.</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][finance_house]" class="col-md-4 col-form-label">Finance Institution (if applicable):</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][finance_house]" id="finance_house_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.6_finance_house_' . $index] ?? ''); ?>" class="form-control" pattern="[A-Za-z0-9\s,.@\-]*" maxlength="100">
                                                    <small class="form-text text-muted">Leave blank if the vehicle is not financed.</small>
                                                </div>
                                            </div>
                                            <p><strong>3.7</strong> Is the vehicle registered in your name?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Registered in Client’s Name:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][registered_in_client_name]" class="form-select registered_in_client_name" data-index="<?php echo $index; ?>" required>
                                                        <option value="">Choose...</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.7_registered_in_client_name_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.7_registered_in_client_name_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="registered_owner_details_<?php echo $index; ?>" style="display: <?php echo ($vehicle_responses[$index]['3.7_registered_in_client_name_' . $index] ?? '') === 'no' ? 'block' : 'none'; ?>;">
                                                <label for="vehicles[<?php echo $index; ?>][registered_owner_name]" class="col-md-4 col-form-label">Registered Owner Name:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][registered_owner_name]" id="registered_owner_name_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.7_registered_owner_name_' . $index] ?? ''); ?>" class="form-control" pattern="[A-Za-z. ]+">
                                                </div>
                                            </div>
                                            <p><strong>3.8</strong> Coverage Type: Comprehensive.</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Confirm Coverage Type:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][coverage_type]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="comprehensive" <?php echo ($vehicle_responses[$index]['3.8_coverage_type_' . $index] ?? '') === 'comprehensive' ? 'selected' : ''; ?>>Comprehensive</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p><strong>3.9</strong> Is the vehicle new, used, or demo?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Vehicle Condition:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][vehicle_condition]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="new" <?php echo ($vehicle_responses[$index]['3.9_vehicle_condition_' . $index] ?? '') === 'new' ? 'selected' : ''; ?>>New</option>
                                                        <option value="used" <?php echo ($vehicle_responses[$index]['3.9_vehicle_condition_' . $index] ?? '') === 'used' ? 'selected' : ''; ?>>Used</option>
                                                        <option value="demo" <?php echo ($vehicle_responses[$index]['3.9_vehicle_condition_' . $index] ?? '') === 'demo' ? 'selected' : ''; ?>>Demo</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p><strong>3.10</strong> For what purposes will the vehicle be used?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Vehicle Use:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][vehicle_use]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="private" <?php echo ($vehicle_responses[$index]['3.10_vehicle_use_' . $index] ?? '') === 'private' ? 'selected' : ''; ?>>Private</option>
                                                        <option value="business" <?php echo ($vehicle_responses[$index]['3.10_vehicle_use_' . $index] ?? '') === 'business' ? 'selected' : ''; ?>>Business</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p><strong>3.12</strong> Who will drive the vehicle most often?</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][regular_driver]" class="col-md-4 col-form-label">Regular Driver Name:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][regular_driver]" id="regular_driver_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.12_regular_driver_' . $index] ?? ($driver['driver_initials'] . ' ' . $driver['driver_surname'] ?? '')); ?>" class="form-control" required pattern="[A-Za-z. ]+">
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][driver_id_number]" class="col-md-4 col-form-label">Regular Driver ID Number:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][driver_id_number]" id="driver_id_number_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.12_driver_id_number_' . $index] ?? $driver['driver_id_number'] ?? ''); ?>" class="form-control" maxlength="13" pattern="\d{13}" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][driver_dob]" class="col-md-4 col-form-label">Regular Driver Date of Birth:</label>
                                                <div class="col-md-8">
                                                    <input type="date" name="vehicles[<?php echo $index; ?>][driver_dob]" id="driver_dob_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.12_driver_dob_' . $index] ?? $driver['dob'] ?? ''); ?>" class="form-control driver-dob" min="1905-01-01" max="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                            </div>
                                            <p><strong>3.13</strong> What type of licence does the driver have?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Licence Type:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][licence_type]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="B" <?php echo ($vehicle_responses[$index]['3.13_licence_type_' . $index] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                                        <option value="EB" <?php echo ($vehicle_responses[$index]['3.13_licence_type_' . $index] ?? '') === 'EB' ? 'selected' : ''; ?>>EB</option>
                                                        <option value="C1" <?php echo ($vehicle_responses[$index]['3.13_licence_type_' . $index] ?? '') === 'C1' ? 'selected' : ''; ?>>C1</option>
                                                        <option value="EC" <?php echo ($vehicle_responses[$index]['3.13_licence_type_' . $index] ?? '') === 'EC' ? 'selected' : ''; ?>>EC</option>
                                                        <option value="EC1" <?php echo ($vehicle_responses[$index]['3.13_licence_type_' . $index] ?? '') === 'EC1' ? 'selected' : ''; ?>>EC1</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p><strong>3.14</strong> When was the licence issued?</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][year_of_issue]" class="col-md-4 col-form-label">Year of Issue:</label>
                                                <div class="col-md-8">
                                                    <input type="number" name="vehicles[<?php echo $index; ?>][year_of_issue]" id="year_of_issue_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.14_year_of_issue_' . $index] ?? $driver['year_of_issue'] ?? ''); ?>" class="form-control driver-year-of-issue" min="1924" max="2025" required>
                                                </div>
                                            </div>
                                            <p><strong>3.17</strong> Does this vehicle have current comprehensive insurance?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Current Comprehensive Insurance:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][has_comprehensive_insurance]" class="form-select comprehensive-insurance" data-index="<?php echo $index; ?>" required>
                                                        <option value="">Choose...</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.17_has_comprehensive_insurance_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.17_has_comprehensive_insurance_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="ncb_details_<?php echo $index; ?>" style="display: <?php echo ($vehicle_responses[$index]['3.17_has_comprehensive_insurance_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <label for="vehicles[<?php echo $index; ?>][insurance_duration]" class="col-md-4 col-form-label">How long has the driver had comprehensive insurance? (Years):</label>
                                                <div class="col-md-8">
                                                    <input type="number" name="vehicles[<?php echo $index; ?>][insurance_duration]" id="insurance_duration_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.17_insurance_duration_' . $index] ?? ''); ?>" class="form-control" min="0">
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="ncb_gaps_<?php echo $index; ?>" style="display: <?php echo ($vehicle_responses[$index]['3.17_has_comprehensive_insurance_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <label class="col-md-4 col-form-label">Any gaps in cover (>39 days)?</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][insurance_gaps]" class="form-select">
                                                        <option value="">Choose...</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.17_insurance_gaps_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.17_insurance_gaps_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="ncb_claims_<?php echo $index; ?>" style="display: <?php echo ($vehicle_responses[$index]['3.17_has_comprehensive_insurance_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <label for="vehicles[<?php echo $index; ?>][recent_claims]" class="col-md-4 col-form-label">Any claims in the last 5 years?</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][recent_claims]" class="form-select">
                                                        <option value="">Choose...</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.17_recent_claims_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.17_recent_claims_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p><strong>3.21</strong> Are there any additional drivers you want to add?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Add Additional Drivers:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][add_additional_drivers]" class="form-select add-additional-drivers" data-index="<?php echo $index; ?>" required>
                                                        <option value="">Choose...</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.21_add_additional_drivers_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.21_add_additional_drivers_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div id="additional_drivers_<?php echo $index; ?>" class="additional-drivers-container" style="display: <?php echo ($vehicle_responses[$index]['3.21_add_additional_drivers_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <p><strong>Additional Driver Details</strong></p>
                                                <?php
                                                $additional_drivers = $additional_drivers_data[$index] ?? [];
                                                if (!empty($additional_drivers)) {
                                                    foreach ($additional_drivers as $driver_index => $add_driver) {
                                                ?>
                                                        <div class="additional-driver-section" data-driver-index="<?php echo $driver_index; ?>">
                                                            <div class="row mb-2">
                                                                <label for="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][name]" class="col-md-4 col-form-label">Driver Name:</label>
                                                                <div class="col-md-8">
                                                                    <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][name]" class="form-control" value="<?php echo htmlspecialchars($vehicle_responses[$index]["3.21_additional_driver_name_{$index}_{$driver_index}"] ?? $add_driver['driver_name'] ?? ''); ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="row mb-2">
                                                                <label for="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][id_number]" class="col-md-4 col-form-label">Driver ID Number:</label>
                                                                <div class="col-md-8">
                                                                    <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][id_number]" class="form-control" maxlength="13" pattern="\d{13}" value="<?php echo htmlspecialchars($vehicle_responses[$index]["3.21_additional_driver_id_number_{$index}_{$driver_index}"] ?? $add_driver['driver_id_number'] ?? ''); ?>" required>
                                                                </div>
                                                            </div>
                                                            <div class="row mb-2">
                                                                <label for="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][licence_type]" class="col-md-4 col-form-label">Licence Type:</label>
                                                                <div class="col-md-8">
                                                                    <select name="vehicles[<?php echo $index; ?>][additional_drivers][<?php echo $driver_index; ?>][licence_type]" class="form-select" required>
                                                                        <option value="">Choose...</option>
                                                                        <option value="B" <?php echo ($vehicle_responses[$index]["3.21_additional_driver_licence_type_{$index}_{$driver_index}"] ?? $add_driver['licence_type'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                                                        <option value="EB" <?php echo ($vehicle_responses[$index]["3.21_additional_driver_licence_type_{$index}_{$driver_index}"] ?? $add_driver['licence_type'] ?? '') === 'EB' ? 'selected' : ''; ?>>EB</option>
                                                                        <option value="C1" <?php echo ($vehicle_responses[$index]["3.21_additional_driver_licence_type_{$index}_{$driver_index}"] ?? $add_driver['licence_type'] ?? '') === 'C1' ? 'selected' : ''; ?>>C1</option>
                                                                        <option value="EC" <?php echo ($vehicle_responses[$index]["3.21_additional_driver_licence_type_{$index}_{$driver_index}"] ?? $add_driver['licence_type'] ?? '') === 'EC' ? 'selected' : ''; ?>>EC</option>
                                                                        <option value="EC1" <?php echo ($vehicle_responses[$index]["3.21_additional_driver_licence_type_{$index}_{$driver_index}"] ?? $add_driver['licence_type'] ?? '') === 'EC1' ? 'selected' : ''; ?>>EC1</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <?php if ($driver_index > 0) { ?>
                                                                <button type="button" class="btn btn-danger remove-driver mb-2" data-index="<?php echo $index; ?>">Remove Driver</button>
                                                            <?php } ?>
                                                            <hr>
                                                        </div>
                                                <?php
                                                    }
                                                } else {
                                                ?>
                                                    <div class="additional-driver-section" data-driver-index="0">
                                                        <div class="row mb-2">
                                                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][0][name]" class="col-md-4 col-form-label">Driver Name:</label>
                                                            <div class="col-md-8">
                                                                <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][0][name]" class="form-control" required>
                                                            </div>
                                                        </div>
                                                        <div class="row mb-2">
                                                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][0][id_number]" class="col-md-4 col-form-label">Driver ID Number:</label>
                                                            <div class="col-md-8">
                                                                <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][0][id_number]" class="form-control" maxlength="13" pattern="\d{13}" required>
                                                            </div>
                                                        </div>
                                                        <div class="row mb-2">
                                                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][0][licence_type]" class="col-md-4 col-form-label">Licence Type:</label>
                                                            <div class="col-md-8">
                                                                <select name="vehicles[<?php echo $index; ?>][additional_drivers][0][licence_type]" class="form-select" required>
                                                                    <option value="">Choose...</option>
                                                                    <option value="B">B</option>
                                                                    <option value="EB">EB</option>
                                                                    <option value="C1">C1</option>
                                                                    <option value="EC">EC</option>
                                                                    <option value="EC1">EC1</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <hr>
                                                    </div>
                                                <?php } ?>
                                                <button type="button" class="btn btn-purple mt-2 add-another-driver" data-index="<?php echo $index; ?>">Add Another Driver</button>
                                            </div>
                                            <p><strong>3.28</strong> Does the vehicle have any existing damage?</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Existing Damage:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][existing_damage]" class="form-select existing-damage" data-index="<?php echo $index; ?>" required>
                                                        <option value="">Choose...</option>
                                                        <option value="no" <?php echo ($vehicle_responses[$index]['3.28_existing_damage_' . $index] ?? '') === 'no' ? 'selected' : ''; ?>>No</option>
                                                        <option value="yes" <?php echo ($vehicle_responses[$index]['3.28_existing_damage_' . $index] ?? '') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="row mb-2" id="damage_details_<?php echo $index; ?>" style="display: <?php echo ($vehicle_responses[$index]['3.28_existing_damage_' . $index] ?? '') === 'yes' ? 'block' : 'none'; ?>;">
                                                <label for="vehicles[<?php echo $index; ?>][damage_description]" class="col-md-4 col-form-label">Damage Description:</label>
                                                <div class="col-md-8">
                                                    <textarea name="vehicles[<?php echo $index; ?>][damage_description]" id="damage_description_<?php echo $index; ?>" class="form-control"><?php echo htmlspecialchars($vehicle_responses[$index]['3.28_damage_description_' . $index] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                            <p><strong>3.29</strong> Where is the vehicle parked at night? (Risk address)</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][street]" class="col-md-4 col-form-label">Street:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][street]" id="street_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.29_street_' . $index] ?? $vehicle['street'] ?? ''); ?>" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][suburb_vehicle]" class="col-md-4 col-form-label">Suburb:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][suburb_vehicle]" id="suburb_vehicle_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.29_suburb_vehicle_' . $index] ?? $vehicle['suburb_vehicle'] ?? ''); ?>" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][postal_code]" class="col-md-4 col-form-label">Postal Code:</label>
                                                <div class="col-md-8">
                                                    <input type="text" name="vehicles[<?php echo $index; ?>][postal_code]" id="postal_code_<?php echo $index; ?>" value="<?php echo htmlspecialchars($vehicle_responses[$index]['3.29_postal_code_' . $index] ?? $vehicle['postal_code'] ?? ''); ?>" class="form-control" pattern="\d{4}" required>
                                                </div>
                                            </div>
                                            <p><strong>3.30</strong> Recommended security device.</p>
                                            <div class="row mb-2">
                                                <label class="col-md-4 col-form-label">Security Device:</label>
                                                <div class="col-md-8">
                                                    <?php
                                                    $security_requirement = getSecurityRequirements(
                                                        $vehicle_responses[$index]['3.2_vehicle_make_' . $index] ?? $vehicle['vehicle_make'] ?? '',
                                                        $vehicle_responses[$index]['3.2_vehicle_model_' . $index] ?? $vehicle['vehicle_model'] ?? '',
                                                        floatval($vehicle_responses[$index]['3.3_sum_insured_' . $index] ?? $vehicle['vehicle_value'] ?? 0)
                                                    );
                                                    ?>
                                                    <select name="vehicles[<?php echo $index; ?>][security_device]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="none" <?php echo ($vehicle_responses[$index]['3.30_security_device_' . $index] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                                        <option value="reactive_tracking" <?php echo ($vehicle_responses[$index]['3.30_security_device_' . $index] ?? '') === 'reactive_tracking' ? 'selected' : ''; ?>>Reactive Tracking</option>
                                                        <option value="proactive_tracking" <?php echo ($vehicle_responses[$index]['3.30_security_device_' . $index] ?? '') === 'proactive_tracking' ? 'selected' : ''; ?>>Proactive Tracking</option>
                                                        <option value="alarm" <?php echo ($vehicle_responses[$index]['3.30_security_device_' . $index] ?? '') === 'alarm' ? 'selected' : ''; ?>>Alarm</option>
                                                        <option value="immobiliser" <?php echo ($vehicle_responses[$index]['3.30_security_device_' . $index] ?? '') === 'immobiliser' ? 'selected' : ''; ?>>Immobiliser</option>
                                                    </select>
                                                    <small class="form-text text-muted">Recommended: <?php echo htmlspecialchars($security_requirement); ?></small>
                                                </div>
                                            </div>
                                            <p><strong>3.31</strong> Select a car hire option for this vehicle:</p>
                                            <div class="row mb-2">
                                                <label for="vehicles[<?php echo $index; ?>][car_hire]" class="col-md-4 col-form-label">Car Hire Option:</label>
                                                <div class="col-md-8">
                                                    <select name="vehicles[<?php echo $index; ?>][car_hire]" class="form-select" required>
                                                        <option value="">Choose...</option>
                                                        <option value="None" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'None' ? 'selected' : ''; ?>>None</option>
                                                        <option value="Group B Manual Hatchback" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'Group B Manual Hatchback' ? 'selected' : ''; ?>>Group B Manual Hatchback (R85.00)</option>
                                                        <option value="Group C Manual Sedan" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'Group C Manual Sedan' ? 'selected' : ''; ?>>Group C Manual Sedan (R95.00)</option>
                                                        <option value="Group D Automatic Hatchback" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'Group D Automatic Hatchback' ? 'selected' : ''; ?>>Group D Automatic Hatchback (R110.00)</option>
                                                        <option value="Group H 1 Ton LDV" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'Group H 1 Ton LDV' ? 'selected' : ''; ?>>Group H 1 Ton LDV (R130.00)</option>
                                                        <option value="Group M Luxury Hatchback" <?php echo ($vehicle_responses[$index]['3.31_car_hire_' . $index] ?? $vehicle['car_hire'] ?? '') === 'Group M Luxury Hatchback' ? 'selected' : ''; ?>>Group M Luxury Hatchback (R320.00)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" name="direction" value="previous" class="btn btn-secondary">Previous Step</button>
                    <button type="submit" name="direction" value="next" class="btn btn-purple"><?php echo $edit_mode ? 'Save Changes' : 'Next Step'; ?></button>
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
        <?php foreach ($vehicles_data as $index => $data) { ?>
            // Registered Owner Details toggle
            const registeredSelect<?php echo $index; ?> = document.querySelector('select[name="vehicles[<?php echo $index; ?>][registered_in_client_name]"]');
            const registeredDetails<?php echo $index; ?> = document.getElementById('registered_owner_details_<?php echo $index; ?>');
            if (registeredSelect<?php echo $index; ?>) {
                registeredSelect<?php echo $index; ?>.addEventListener('change', function() {
                    registeredDetails<?php echo $index; ?>.style.display = this.value === 'no' ? 'block' : 'none';
                    const ownerInput = document.getElementById('registered_owner_name_<?php echo $index; ?>');
                    ownerInput.required = this.value === 'no';
                });
                registeredDetails<?php echo $index; ?>.style.display = registeredSelect<?php echo $index; ?>.value === 'no' ? 'block' : 'none';
                const ownerInput = document.getElementById('registered_owner_name_<?php echo $index; ?>');
                ownerInput.required = registeredSelect<?php echo $index; ?>.value === 'no';
            }

            // Comprehensive Insurance toggle
            const comprehensiveSelect<?php echo $index; ?> = document.querySelector('select[name="vehicles[<?php echo $index; ?>][has_comprehensive_insurance]"]');
            const ncbDetails<?php echo $index; ?> = document.getElementById('ncb_details_<?php echo $index; ?>');
            const ncbGaps<?php echo $index; ?> = document.getElementById('ncb_gaps_<?php echo $index; ?>');
            const ncbClaims<?php echo $index; ?> = document.getElementById('ncb_claims_<?php echo $index; ?>');
            if (comprehensiveSelect<?php echo $index; ?>) {
                comprehensiveSelect<?php echo $index; ?>.addEventListener('change', function() {
                    const display = this.value === 'yes' ? 'block' : 'none';
                    ncbDetails<?php echo $index; ?>.style.display = display;
                    ncbGaps<?php echo $index; ?>.style.display = display;
                    ncbClaims<?php echo $index; ?>.style.display = display;
                    const durationInput = document.getElementById('insurance_duration_<?php echo $index; ?>');
                    durationInput.required = this.value === 'yes';
                });
                const display = comprehensiveSelect<?php echo $index; ?>.value === 'yes' ? 'block' : 'none';
                ncbDetails<?php echo $index; ?>.style.display = display;
                ncbGaps<?php echo $index; ?>.style.display = display;
                ncbClaims<?php echo $index; ?>.style.display = display;
                const durationInput = document.getElementById('insurance_duration_<?php echo $index; ?>');
                durationInput.required = comprehensiveSelect<?php echo $index; ?>.value === 'yes';
            }

            // Existing Damage toggle
            const damageSelect<?php echo $index; ?> = document.querySelector('select[name="vehicles[<?php echo $index; ?>][existing_damage]"]');
            const damageDetails<?php echo $index; ?> = document.getElementById('damage_details_<?php echo $index; ?>');
            if (damageSelect<?php echo $index; ?>) {
                damageSelect<?php echo $index; ?>.addEventListener('change', function() {
                    damageDetails<?php echo $index; ?>.style.display = this.value === 'yes' ? 'block' : 'none';
                    const descriptionInput = document.getElementById('damage_description_<?php echo $index; ?>');
                    descriptionInput.required = this.value === 'yes';
                });
                damageDetails<?php echo $index; ?>.style.display = damageSelect<?php echo $index; ?>.value === 'yes' ? 'block' : 'none';
                const descriptionInput = document.getElementById('damage_description_<?php echo $index; ?>');
                descriptionInput.required = damageSelect<?php echo $index; ?>.value === 'yes';
            }

            // Additional Drivers toggle
            const addDriversSelect<?php echo $index; ?> = document.querySelector('select[name="vehicles[<?php echo $index; ?>][add_additional_drivers]"]');
            const addDriversContainer<?php echo $index; ?> = document.getElementById('additional_drivers_<?php echo $index; ?>');
            if (addDriversSelect<?php echo $index; ?>) {
                addDriversSelect<?php echo $index; ?>.addEventListener('change', function() {
                    addDriversContainer<?php echo $index; ?>.style.display = this.value === 'yes' ? 'block' : 'none';
                    const inputs = addDriversContainer<?php echo $index; ?>.querySelectorAll('input, select');
                    inputs.forEach(input => {
                        input.required = this.value === 'yes';
                    });
                });
                addDriversContainer<?php echo $index; ?>.style.display = addDriversSelect<?php echo $index; ?>.value === 'yes' ? 'block' : 'none';
                const inputs = addDriversContainer<?php echo $index; ?>.querySelectorAll('input, select');
                inputs.forEach(input => {
                    input.required = addDriversSelect<?php echo $index; ?>.value === 'yes';
                });
            }

            // Add Another Driver
            const addDriverButton<?php echo $index; ?> = document.querySelector('.add-another-driver[data-index="<?php echo $index; ?>"]');
            if (addDriverButton<?php echo $index; ?>) {
                addDriverButton<?php echo $index; ?>.addEventListener('click', function() {
                    const container = document.getElementById('additional_drivers_<?php echo $index; ?>');
                    const sections = container.querySelectorAll('.additional-driver-section');
                    const newIndex = sections.length;
                    const newSection = document.createElement('div');
                    newSection.className = 'additional-driver-section';
                    newSection.dataset.driverIndex = newIndex;
                    newSection.innerHTML = `
                        <div class="row mb-2">
                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][name]" class="col-md-4 col-form-label">Driver Name:</label>
                            <div class="col-md-8">
                                <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][name]" class="form-control" required>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][id_number]" class="col-md-4 col-form-label">Driver ID Number:</label>
                            <div class="col-md-8">
                                <input type="text" name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][id_number]" class="form-control" maxlength="13" pattern="\\d{13}" required>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <label for="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][licence_type]" class="col-md-4 col-form-label">Licence Type:</label>
                            <div class="col-md-8">
                                <select name="vehicles[<?php echo $index; ?>][additional_drivers][${newIndex}][licence_type]" class="form-select" required>
                                    <option value="">Choose...</option>
                                    <option value="B">B</option>
                                    <option value="EB">EB</option>
                                    <option value="C1">C1</option>
                                    <option value="EC">EC</option>
                                    <option value="EC1">EC1</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger remove-driver mb-2" data-index="<?php echo $index; ?>">Remove Driver</button>
                        <hr>
                    `;
                    container.insertBefore(newSection, this);
                    // Add event listener to the new remove button
                    newSection.querySelector('.remove-driver').addEventListener('click', function() {
                        newSection.remove();
                    });
                });
            }

            // Remove Driver
            const removeDriverButtons<?php echo $index; ?> = document.querySelectorAll('.remove-driver[data-index="<?php echo $index; ?>"]');
            removeDriverButtons<?php echo $index; ?>.forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.remove();
                });
            });
        <?php } ?>

        // Client-side validation
        document.getElementById('underwritingForm').addEventListener('submit', function(event) {
            const requiredInputs = document.querySelectorAll('input[required], select[required]');
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