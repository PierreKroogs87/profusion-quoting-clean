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
    header("Location: ../dashboard.php"); // Or wherever unauthorized users should go
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Services - Insurance App</title>
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
                <a href="home.php"><img src="../images/logo.png" alt="Profusion Insurance Logo"></a> <!-- Adjust path if needed -->
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
                <h2 class="mb-4">Client Services</h2>
                <div class="d-flex flex-column align-items-start">
                    <a href="amendments.php" class="btn btn-primary mb-3">Amendments</a>
                    <a href="add_ons.php" class="btn btn-success mb-3">Add Items to existing Policies</a>
                    <a href="client_letters.php" class="btn btn-secondary mb-3">Client Letters</a>
                </div>
            </div>
            
        </div>
    </main>

    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>