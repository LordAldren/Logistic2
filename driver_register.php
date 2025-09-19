<?php
require_once 'db_connect.php';
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $name = trim($_POST['name']);
    $license_number = trim($_POST['license_number']);

    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($name) || empty($license_number)) {
        $message = "<div class='message-banner error'>All fields are required.</div>";
    } elseif (strlen($password) < 6) {
        $message = "<div class='message-banner error'>Password must be at least 6 characters long.</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='message-banner error'>Invalid email format.</div>";
    } else {
        $conn->begin_transaction();
        try {
            // Check if username or email already exists in users table
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                throw new Exception("Username or email is already taken.");
            }
            $stmt->close();
            
            // Check if license number already exists in drivers table
            $stmt = $conn->prepare("SELECT id FROM drivers WHERE license_number = ?");
            $stmt->bind_param("s", $license_number);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                throw new Exception("License number is already registered.");
            }
            $stmt->close();

            // Insert into users table
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'driver';
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
            $stmt->execute();
            $new_user_id = $stmt->insert_id;
            $stmt->close();

            // Insert into drivers table with 'Pending' status
            $status = 'Pending';
            $stmt = $conn->prepare("INSERT INTO drivers (user_id, name, license_number, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $new_user_id, $name, $license_number, $status);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            $message = "<div class='message-banner success'>Registration successful! Please wait for an admin to approve your account.</div>";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='message-banner error'>Error: " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Registration - SLATE System</title>
    <link rel="stylesheet" href="login-style.css">
</head>
<body class="login-page-body">
  <div class="main-container">
    <div class="login-container" style="max-width: 35rem;">
      <div class="login-panel" style="width: 100%;">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>Driver Registration</h2>
          <p style="margin-bottom: 1rem; color: #ccc;">Create your driver account.</p>
          
          <?php echo $message; ?>

          <form action="driver_register.php" method="post">
            <input type="text" name="name" placeholder="Full Name" required>
            <input type="text" name="license_number" placeholder="License Number" required>
            <input type="text" name="username" placeholder="Create a Username" required>
            <input type="email" name="email" placeholder="Your Email Address" required>
            <input type="password" name="password" placeholder="Create a Password" required>
            <button type="submit">Register</button>
          </form>
          <div style="margin-top: 1.5rem;">
            <a href="login.php" style="color: #00c6ff; text-decoration: none;">&larr; Back to Login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

