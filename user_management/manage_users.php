<?php
session_start();
if (!isset($_SESSION['user_id']) || strpos($_SESSION['role_name'], 'Profusion') !== 0) {
    header("Location: ../dashboard.php");
    exit();
}
require '../db_connect.php';

$user_query = "SELECT u.user_id, u.username, r.role_name, b.brokerage_name 
               FROM users u 
               LEFT JOIN roles r ON u.role_id = r.role_id 
               LEFT JOIN brokerages b ON u.brokerage_id = b.brokerage_id";
$user_result = mysqli_query($conn, $user_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Insurance App</title>
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
                                <a class="nav-link btn navbtn-purple" href="manage_users.php">Manage Users</a>
                            </li>
                             <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../broker_management/manage_brokers.php">Manage Brokers</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link btn navbtn-purple" href="../login_management/logout.php">Logout</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="mb-2">
                <a href="add_user.php" class="btn btn-green">Add New User</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Brokerage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (mysqli_num_rows($user_result) > 0) {
                            while ($user = mysqli_fetch_assoc($user_result)) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($user['user_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                                echo "<td>" . htmlspecialchars($user['role_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($user['brokerage_name'] ?? 'None') . "</td>";
                                echo "<td><a href='edit_user.php?user_id=" . urlencode($user['user_id']) . "' class='btn btn-purple btn-sm'>Edit</a></td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5'>No users found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
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