<?php
require_once '../db_connect.php';

function validateQuoteData($post_data, $session_data) {
    $errors = [];
    $validated_data = [
        'quote_id' => null,
        'title' => null,
        'initials' => null,
        'surname' => null,
        'marital_status' => null,
        'client_id' => null,
        'suburb_client' => null,
        'brokerage_id' => null,
        'waive_broker_fee' => 0,
        'vehicles' => [],
        'global_discount_percentage' => 0,
        'is_authorized' => strpos($session_data['role_name'], 'Profusion') === 0
    ];

    // Validate quote_id (optional for new quotes)
    $quote_id = isset($post_data['quote_id']) && !empty($post_data['quote_id']) ? (int)$post_data['quote_id'] : null;
    if ($quote_id) {
        global $conn;
        if ($validated_data['is_authorized']) {
            $stmt = $conn->prepare("SELECT * FROM quotes WHERE quote_id = ?");
            $stmt->bind_param("i", $quote_id);
        } else {
            $stmt = $conn->prepare("SELECT * FROM quotes WHERE quote_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $quote_id, $session_data['user_id']);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $errors[] = "No quote found for Quote ID $quote_id or you lack permission to edit it.";
        } else {
            $validated_data['quote_id'] = $quote_id;
        }
        $stmt->close();
    }

    // Validate client fields
    $valid_titles = ['Mr.', 'Mrs.', 'Miss', 'Dr.', 'Prof'];
    $valid_marital_statuses = ['Single', 'Married', 'Divorced', 'Cohabiting', 'Widowed'];
    
    $title = trim($post_data['title'] ?? '');
    if (!in_array($title, $valid_titles)) {
        $errors[] = 'Invalid title.';
    } else {
        $validated_data['title'] = $title;
    }

    $initials = trim($post_data['initials'] ?? '');
    if (empty($initials)) {
        $errors[] = 'Initials are required.';
    } else {
        $validated_data['initials'] = $initials;
    }

    $surname = trim($post_data['surname'] ?? '');
    if (empty($surname)) {
        $errors[] = 'Surname is required.';
    } else {
        $validated_data['surname'] = $surname;
    }

    $marital_status = trim($post_data['marital_status'] ?? '');
    if (!in_array($marital_status, $valid_marital_statuses)) {
        $errors[] = 'Invalid marital status.';
    } else {
        $validated_data['marital_status'] = $marital_status;
    }

    $client_id = trim($post_data['client_id'] ?? '');
    if (!preg_match('/^\d{13}$/', $client_id)) {
        $errors[] = 'Invalid client ID.';
    } else {
        $validated_data['client_id'] = $client_id;
    }

    $suburb_client = trim($post_data['suburb_client'] ?? '');
    if (empty($suburb_client)) {
        $errors[] = 'Client suburb is required.';
    } else {
        $validated_data['suburb_client'] = $suburb_client;
    }

    $brokerage_id = isset($post_data['brokerage_id']) ? (int)$post_data['brokerage_id'] : null;
    if ($brokerage_id && $brokerage_id > 0) {
        global $conn;
        $stmt = $conn->prepare("SELECT brokerage_id FROM brokerages WHERE brokerage_id = ?");
        $stmt->bind_param("i", $brokerage_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $errors[] = 'Invalid brokerage ID.';
        } else {
            $validated_data['brokerage_id'] = $brokerage_id;
        }
        $stmt->close();
    } elseif ($validated_data['is_authorized'] && empty($brokerage_id)) {
        $errors[] = 'Brokerage ID is required for Profusion users.';
    }

    $validated_data['waive_broker_fee'] = isset($post_data['waive_broker_fee']) ? 1 : 0;

    // Validate vehicles
    $vehicles = $post_data['vehicles'] ?? [];
    if (empty($vehicles)) {
        $errors[] = 'At least one vehicle is required.';
    }

    foreach ($vehicles as $index => $vehicle) {
        $validated_vehicle = [
            'vehicle_id' => isset($vehicle['vehicle_id']) && !empty($vehicle['vehicle_id']) ? (int)$vehicle['vehicle_id'] : null,
            'year' => trim($vehicle['year'] ?? ''),
            'make' => trim($vehicle['make'] ?? ''),
            'model' => trim($vehicle['model'] ?? ''),
            'value' => floatval($vehicle['value'] ?? 0),
            'coverage_type' => trim($vehicle['coverage_type'] ?? ''),
            'use' => trim($vehicle['use'] ?? ''),
            'parking' => trim($vehicle['parking'] ?? ''),
            'car_hire' => trim($vehicle['car_hire'] ?? ''),
            'street' => trim($vehicle['street'] ?? ''),
            'suburb_vehicle' => trim($vehicle['suburb_vehicle'] ?? ''),
            'postal_code' => trim($vehicle['postal_code'] ?? ''),
            'driver' => []
        ];

        // Validate vehicle fields
        if (empty($validated_vehicle['year']) || !is_numeric($validated_vehicle['year'])) {
            $errors[] = "Vehicle year is required for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_vehicle['make'])) {
            $errors[] = "Vehicle make is required for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_vehicle['model'])) {
            $errors[] = "Vehicle model is required for vehicle " . ($index + 1) . ".";
        }
        if ($validated_vehicle['value'] <= 0) {
            $errors[] = "Vehicle value must be greater than 0 for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_vehicle['coverage_type'], ['Comprehensive', 'Third Party', 'Fire and Theft'])) {
            $errors[] = "Invalid coverage type for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_vehicle['use'], ['Private', 'Business'])) {
            $errors[] = "Invalid vehicle use for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_vehicle['parking'], ['Behind_Locked_Gates', 'LockedUp_Garage', 'in_street'])) {
            $errors[] = "Invalid parking type for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_vehicle['car_hire'], ['None', 'Group B Manual Hatchback', 'Group C Manual Sedan', 'Group D Automatic Hatchback', 'Group H 1 Ton LDV', 'Group M Luxury Hatchback'])) {
            $errors[] = "Invalid car hire option for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_vehicle['street'])) {
            $errors[] = "Street is required for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_vehicle['suburb_vehicle'])) {
            $errors[] = "Vehicle suburb is required for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_vehicle['postal_code']) || !is_numeric($validated_vehicle['postal_code'])) {
            $errors[] = "Postal code is required for vehicle " . ($index + 1) . ".";
        }

        // Validate driver fields
        $driver = $vehicle['driver'] ?? [];
        $validated_driver = [
            'title' => trim($driver['title'] ?? ''),
            'initials' => trim($driver['initials'] ?? ''),
            'surname' => trim($driver['surname'] ?? ''),
            'id_number' => trim($driver['id_number'] ?? ''),
            'dob' => trim($driver['dob'] ?? ''),
            'age' => intval($driver['age'] ?? 0),
            'licence_type' => trim($driver['licence_type'] ?? ''),
            'year_of_issue' => trim($driver['year_of_issue'] ?? ''),
            'licence_held' => intval($driver['licence_held'] ?? 0),
            'marital_status' => trim($driver['marital_status'] ?? ''),
            'ncb' => trim($driver['ncb'] ?? '')
        ];

        if (!in_array($validated_driver['title'], $valid_titles)) {
            $errors[] = "Invalid driver title for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_driver['initials'])) {
            $errors[] = "Driver initials are required for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_driver['surname'])) {
            $errors[] = "Driver surname is required for vehicle " . ($index + 1) . ".";
        }
        if (!preg_match('/^\d{13}$/', $validated_driver['id_number'])) {
            $errors[] = "Invalid driver ID number for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_driver['dob']) || !DateTime::createFromFormat('Y-m-d', $validated_driver['dob'])) {
            $errors[] = "Invalid driver DOB for vehicle " . ($index + 1) . ".";
        }
        if ($validated_driver['age'] < 18) {
            $errors[] = "Driver age must be at least 18 for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_driver['licence_type'], ['B', 'EB', 'C1', 'EC', 'EC1'])) {
            $errors[] = "Invalid licence type for vehicle " . ($index + 1) . ".";
        }
        if (empty($validated_driver['year_of_issue']) || !is_numeric($validated_driver['year_of_issue']) || $validated_driver['year_of_issue'] > date('Y')) {
            $errors[] = "Invalid year of issue for vehicle " . ($index + 1) . ".";
        }
        if ($validated_driver['licence_held'] < 0) {
            $errors[] = "Invalid licence held duration for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_driver['marital_status'], $valid_marital_statuses)) {
            $errors[] = "Invalid driver marital status for vehicle " . ($index + 1) . ".";
        }
        if (!in_array($validated_driver['ncb'], ['0', '1', '2', '3', '4', '5', '6', '7'])) {
            $errors[] = "Invalid NCB for vehicle " . ($index + 1) . ".";
        }

        $validated_vehicle['driver'] = $validated_driver;
        $validated_data['vehicles'][] = $validated_vehicle;
    }

    // Validate global discount percentage
    $global_discount_percentage = isset($post_data['global_discount_percentage']) ? floatval($post_data['global_discount_percentage']) : 0;
    if ($global_discount_percentage < 0 || $global_discount_percentage > 100) {
        $errors[] = 'Invalid global discount percentage.';
    } else {
        $validated_data['global_discount_percentage'] = $global_discount_percentage;
    }

    return [
        'errors' => $errors,
        'quote_id' => $validated_data['quote_id'],
        'title' => $validated_data['title'],
        'initials' => $validated_data['initials'],
        'surname' => $validated_data['surname'],
        'marital_status' => $validated_data['marital_status'],
        'client_id' => $validated_data['client_id'],
        'suburb_client' => $validated_data['suburb_client'],
        'brokerage_id' => $validated_data['brokerage_id'],
        'waive_broker_fee' => $validated_data['waive_broker_fee'],
        'vehicles' => $validated_data['vehicles'],
        'global_discount_percentage' => $validated_data['global_discount_percentage'],
        'is_authorized' => $validated_data['is_authorized']
    ];
}
?>