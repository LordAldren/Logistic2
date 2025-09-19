<?php
session_start();
require_once 'db_connect.php';

// If user is not in the middle of OTP verification, redirect to login
if (!isset($_SESSION['otp_user_id'])) {
    header("location: login.php");
    exit;
}

$error_message = '';
$user_id = $_SESSION['otp_user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_otp = trim($_POST['otp_code']);

    if (empty($submitted_otp) || !is_numeric($submitted_otp)) {
        $error_message = "Please enter a valid 6-digit OTP.";
    } else {
        $stmt = $conn->prepare("SELECT role, username, otp_code, otp_expires_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user) {
            $now = new DateTime();
            $otp_expires = new DateTime($user['otp_expires_at']);

            if ($user['otp_code'] == null || $now > $otp_expires) {
                $error_message = "OTP has expired. Please try logging in again.";
            } elseif ($user['otp_code'] == $submitted_otp) {
                // Correct OTP: Finalize login
                
                $conn->query("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = $user_id");

                unset($_SESSION['otp_user_id']);

                session_regenerate_id(true);
                $_SESSION["loggedin"] = true;
                $_SESSION["id"] = $user_id;
                $_SESSION["username"] = $user['username'];
                $_SESSION["role"] = $user['role'];

                if ($user['role'] == 'driver') {
                    header("location: mobile_app.php");
                } else {
                    header("location: landpage.php");
                }
                exit;

            } else {
                $error_message = "Invalid OTP. Please try again.";
            }
        } else {
            $error_message = "An unexpected error occurred. Please try logging in again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verify OTP - SLATE System</title>
  <link rel="stylesheet" href="login-style.css">
  <style>
      .error-message { color: #ffb3b3; background-color: rgba(255, 0, 0, 0.2); border: 1px solid #ff4d4d; padding: 0.75rem; border-radius: 0.375rem; margin-top: 1rem; text-align: center; }
  </style>
</head>
<body class="login-page-body">
  <div class="main-container">
    <div class="login-container" style="max-width: 35rem;">
      <div class="login-panel" style="width: 100%;">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>Two-Factor Authentication</h2>
          <p style="margin-bottom: 1rem; color: #ccc;">A One-Time Password (OTP) has been sent to your registered email. Please check your inbox.</p>
          
          <?php if(!empty($error_message)){ echo '<div class="error-message">' . $error_message . '</div>'; } ?>

          <form action="verify_otp.php" method="post">
            <input type="text" name="otp_code" placeholder="Enter 6-Digit OTP" required autofocus>
            <button type="submit">Verify & Login</button>
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

