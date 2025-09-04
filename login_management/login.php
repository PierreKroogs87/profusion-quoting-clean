<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Insurance App</title>
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
            position: fixed;
            bottom: 0;
        }
        .table { 
            font-size: calc(1rem * var(--font-scale));
        }
        .table th, .table td { 
            padding: calc(0.25rem * var(--font-scale));
            line-height: 1.2;
            white-space: nowrap;
        }
        h2 {
            font-size: 18px;
        }
        h3 {
            font-size: 16px;
        }
    </style>
</head>
<body>
<main>
    <header class="text-center py-3">
        <img src="../images/logo.png" alt="Profusion Insurance Logo" class="img-fluid" style="max-width: 500px; height: 100px;">
    </header>
    <div class="container">
        <div class="row justify-content-center mt-4">
            <div class="col-md-4">
                <h3 class="text-center">Welcome to Profusion Underwriting Managers</h3>
                <h3 class="text-center mb-4">To Access our client management platform, please Log In.</h3>
                <div class="card shadow">
                    <div class="card-body">
                        <form action="auth.php" method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username:</label>
                                <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password:</label>
                                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                            </div>
                            <button type="submit" class="btn btn-purple w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <footer class="text-center py-3 mt-4">
        <p>© 2025 Profusion Insurance</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</main>
</body>
</html>