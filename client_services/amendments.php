<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login_management/login.php");
    exit();
}
require '../db_connect.php'; // Assuming this is your database connection file; adjust if named differently
// Define authorized roles (adapt from your existing code if needed)
$authorized_roles = ['Profusion SuperAdmin', 'Profusion Manager', 'Profusion Consultant', 'Broker Admin', 'Broker Manager', 'Broker Consultant'];
if (!in_array($_SESSION['role_name'], $authorized_roles)) {
    header("Location: ../dashboard.php"); // Adjust path if needed
    exit();
}
$search_results = []; // Initialize an empty array to hold results
$errors = []; // Initialize an empty array for errors
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_query = trim($_POST['search_query'] ?? '');
    $search_type = $_POST['search_type'] ?? '';
    if (empty($search_query) || empty($search_type)) {
        $errors[] = "Please enter a search term and select a type.";
    } else {
        // Prepare dynamic SQL based on search_type (querying policies and quotes for client/policy info)
        $sql = "
            SELECT p.policy_id AS policy_number, p.status, p.premium_type, p.premium_amount,
                   q.quote_id, q.client_id AS id, q.title, q.initials, q.surname,
                   (SELECT response FROM policy_underwriting_data WHERE policy_id = p.policy_id AND question_key = '2.5_cell_number' LIMIT 1) AS cell_number,
                   (SELECT response FROM policy_underwriting_data WHERE policy_id = p.policy_id AND question_key = '2.8_email' LIMIT 1) AS email_client
            FROM policies p
            LEFT JOIN quotes q ON p.quote_id = q.quote_id
            WHERE "; // Base query with subqueries for cell_number and email_client
        $param_type = 's'; // Default string type for binding
        $param_value = $search_query;
        switch ($search_type) {
            case 'policy_number':
                $sql .= "p.policy_id = ?";
                $param_type = 'i'; // Integer for policy_number (policy_id)
                $param_value = (int)$search_query; // Cast to int for exact match
                break;
            case 'id':
                $sql .= "q.client_id LIKE ?";
                $param_value = '%' . $search_query . '%'; // Partial match for client_id
                break;
            case 'client_name':
                $sql .= "(CONCAT(q.initials, ' ', q.surname) LIKE ? OR q.surname LIKE ? OR q.initials LIKE ?)";
                $param_type = 'sss'; // Three string params for full name search
                $param_value = ['%' . $search_query . '%', '%' . $search_query . '%', '%' . $search_query . '%']; // Array for multiple bindings
                break;
            case 'cell_number':
                $sql = "
                    SELECT p.policy_id AS policy_number, p.status, p.premium_type, p.premium_amount,
                           q.quote_id, q.client_id AS id, q.title, q.initials, q.surname,
                           (SELECT response FROM policy_underwriting_data WHERE policy_id = p.policy_id AND question_key = '2.8_email' LIMIT 1) AS email_client,
                           ud.response AS cell_number
                    FROM policies p
                    LEFT JOIN quotes q ON p.quote_id = q.quote_id
                    LEFT JOIN policy_underwriting_data ud ON p.policy_id = ud.policy_id AND ud.question_key = '2.5_cell_number'
                    WHERE ud.response LIKE ?";
                $param_value = '%' . $search_query . '%'; // Partial match for cell number
                break;
            default:
                $errors[] = "Invalid search type selected.";
        }
        if (empty($errors)) {
            $stmt = $conn->prepare($sql);
            if (is_array($param_value)) {
                $stmt->bind_param($param_type, ...$param_value); // Spread array for multiple params
            } else {
                $stmt->bind_param($param_type, $param_value);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $search_results[] = $row; // Store each matching row (client/policy info)
            }
            $stmt->close();
            if (empty($search_results)) {
                $errors[] = "No results found for your search.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Services - Amendments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Copy the entire <style> block from dashboard.php here to match the styling exactly */
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
                <a href="../home.php"><img src="../images/logo.png" alt="Profusion Insurance Logo"></a> <!-- Adjust path if needed -->
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../home.php">Home</a></li>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../dashboard.php">Dashboard</a></li>
                            <?php if (strpos($_SESSION['role_name'], 'Profusion') === 0) { ?>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../user_management/manage_users.php">Manage Users</a></li>
                                <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../broker_management/manage_brokers.php">Manage Brokers</a></li>
                            <?php } ?>
                            <li class="nav-item"><a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            <div class="container mt-4">
                <h2 class="mb-4">Amendments</h2>
                <form method="post" action="amendments.php" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" name="search_query" class="form-control" placeholder="Enter search term (e.g., policy number, client ID, name, or cell number)" required>
                        </div>
                        <div class="col-md-4">
                            <select name="search_type" class="form-select" required>
                                <option value="">Select search type</option>
                                <option value="policy_number">Policy Number</option>
                                <option value="id">Client ID</option>
                                <option value="client_name">Client Name</option>
                                <option value="cell_number">Cell Number</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-purple w-100">Search</button>
                        </div>
                    </div>
                </form>
               
                <?php if (!empty($search_results)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead class="table-dark">
                                <tr>
                                    <th>Policy Number</th>
                                    <th>Client ID</th>
                                    <th>Client Name</th>
                                    <th>Cell Number</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Premium Amount</th>
                                    <th>Edit</th>
                                    <!-- Add more <th> for other fields if needed, e.g., <th>Quote ID</th> -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($search_results as $result): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['policy_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($result['id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(trim(($result['title'] ?? '') . ' ' . ($result['initials'] ?? '') . ' ' . ($result['surname'] ?? ''))); ?></td>
                                        <td><?php echo htmlspecialchars($result['cell_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($result['email_client'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($result['status'] ?? 'N/A'); ?></td>
                                        <td>R<?php echo number_format($result['premium_amount'] ?? 0, 2); ?></td>
                                        <td><a href="amend_policy.php?policy_id=<?php echo htmlspecialchars($result['policy_number']); ?>" class="btn btn-purple btn-sm">Edit</a></td>
                                        <!-- Add more <td> for other fields if needed, e.g., <td><?php echo htmlspecialchars($result['quote_id'] ?? 'N/A'); ?></td> -->
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
           
        </div>
    </main>
    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>