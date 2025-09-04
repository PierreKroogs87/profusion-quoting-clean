<?php
session_start();
if (!isset($_SESSION['user_id']) || trim($_SESSION['role_name']) !== 'Profusion SuperAdmin') {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

// Fetch brokers with broker_fee
$brokers_query = "SELECT brokerage_id, brokerage_name, broker_fee FROM brokerages ORDER BY brokerage_name";
$brokers_result = mysqli_query($conn, $brokers_query);
if (!$brokers_result) {
    die("Error fetching brokers: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Brokers - Insurance App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --purple: #6A0DAD;
            --green: #28A745;
            --white: #fff;
            --font-scale: 0.75;
        }
        body {
            font-size: calc(1rem * var(--font-scale));
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        main {
            flex: 1 0 auto;
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
        .navbar-nav .nav-link {
            margin: 0 calc(0.25rem * var(--font-scale));
        }
        .navbtn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: white;
            font-size: calc(1.2rem * var(--font-scale));
            padding: calc(0.5rem * var(--font-scale)) calc(0.5rem * var(--font-scale));
            text-decoration: none;
        }
        .navbtn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: white;
        }
        .btn-purple {
            background-color: var(--purple);
            border-color: var(--purple);
            color: white;
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
            text-decoration: none;
        }
        .btn-purple:hover {
            background-color: #4B0082;
            border-color: #4B0082;
            color: white;
        }
        .btn-green {
            background-color: var(--green);
            border-color: var(--green);
            color: white;
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        .btn-green:hover {
            background-color: #218838;
            border-color: #1E7E34;
            color: white;
        }
        .form-label, .form-control, .form-select {
            font-size: calc(1rem * var(--font-scale));
            padding: calc(0.375rem * var(--font-scale)) calc(0.75rem * var(--font-scale));
        }
        footer {
            background-color: var(--white);
            color: var(--purple);
            font-size: calc(1rem * var(--font-scale));
            padding: calc(1rem * var(--font-scale)) 0;
            text-align: center;
            width: 100%;
            flex-shrink: 0;
        }
        .table thead th {
            padding: calc(0.25rem * var(--font-scale));
            line-height: 1.2;
            white-space: nowrap;
            background-color: var(--purple);
            color: var(--white);
        }
        .table td {
            padding: calc(0.25rem * var(--font-scale));
            line-height: 1.2;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <main>
        <div class="container mt-3">
            <header>
                <a href="../dashboard.php">
                    <img src="../images/logo.png" alt="Profusion Insurance Logo">
                </a>
            </header>
            <nav class="navbar navbar-expand-lg navbar-dark">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../dashboard.php">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../user_management/manage_users.php">Manage Users</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="manage_brokers.php">Manage Brokers</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="container mt-4">
                <h2 class="mb-4">Manage Brokers</h2>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Brokerage ID</th>
                                <th>Brokerage Name</th>
                                <th>Broker Fee</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($broker = mysqli_fetch_assoc($brokers_result)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($broker['brokerage_id']); ?></td>
                                    <td><?php echo htmlspecialchars($broker['brokerage_name']); ?></td>
                                    <td>R<?php echo htmlspecialchars(number_format($broker['broker_fee'] ?? 0.00, 2)); ?></td>
                                    <td>
                                        <a href="edit_broker.php?brokerage_id=<?php echo urlencode($broker['brokerage_id']); ?>" class="btn btn-purple btn-sm">Edit</a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 mb-4">
                    <a href="edit_broker.php" class="btn btn-purple">Add New Broker</a>
                </div>
            </div>

            <footer class="text-center py-3 mt-4">
                <p>© 2025 Profusion Insurance</p>
            </footer>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>