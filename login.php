<?php
session_start();

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: landpage.php");
    exit;
}

require_once 'db_connect.php';
require_once 'mailer.php'; 

$username = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
    } else {
        $sql = "SELECT id, username, email, password, role, failed_login_attempts, lockout_until FROM users WHERE username = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $username);
            
            if ($stmt->execute()) {
                $stmt->store_result();
                
                if ($stmt->num_rows == 1) {                    
                    $stmt->bind_result($id, $db_username, $email, $hashed_password, $role, $failed_attempts, $lockout_until);
                    if ($stmt->fetch()) {
                        
                        if ($lockout_until !== null) {
                            $now = new DateTime();
                            $lockout_time = new DateTime($lockout_until);
                            if ($now < $lockout_time) {
                                $error_message = "Account is locked. Please try again later.";
                            }
                        }

                        if (empty($error_message)) {
                            if (password_verify($password, $hashed_password)) {
                                // Password correct, now send OTP
                                
                                // Reset failed attempts
                                $reset_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?");
                                $reset_stmt->bind_param("i", $id);
                                $reset_stmt->execute();
                                $reset_stmt->close();

                                // Generate and save OTP
                                $otp_code = rand(100000, 999999);
                                $otp_expires = (new DateTime())->add(new DateInterval("PT5M"))->format('Y-m-d H:i:s');
                                
                                $otp_stmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                                $otp_stmt->bind_param("ssi", $otp_code, $otp_expires, $id);
                                $otp_stmt->execute();
                                $otp_stmt->close();
                                
                                $subject = "Your OTP for SLATE Logistics Login";
                                $body = "<h3>Login Verification</h3>
                                         <p>Your One-Time Password (OTP) is: <strong>$otp_code</strong></p>
                                         <p>This code will expire in 5 minutes.</p>
                                         <p>If you did not request this, please ignore this email.</p>";
                                
                                if (sendEmail($email, $subject, $body)) {
                                    $_SESSION['otp_user_id'] = $id;
                                    header("location: verify_otp.php");
                                    exit;
                                } else {
                                    $error_message = "Failed to send OTP email. Please try again later.";
                                }

                            } else {
                                // Incorrect password, handle attempts
                                $failed_attempts++;
                                $max_attempts = 5;
                                if ($failed_attempts >= $max_attempts) {
                                    $lockout_until_time = (new DateTime())->add(new DateInterval("PT15M"))->format('Y-m-d H:i:s');
                                    $lock_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = ?, lockout_until = ? WHERE id = ?");
                                    $lock_stmt->bind_param("isi", $failed_attempts, $lockout_until_time, $id);
                                    $lock_stmt->execute();
                                    $lock_stmt->close();
                                    $error_message = "Account locked for 15 minutes.";
                                } else {
                                    $update_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?");
                                    $update_stmt->bind_param("ii", $failed_attempts, $id);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                    $error_message = "Invalid username or password.";
                                }
                            }
                        }
                    }
                } else {
                    $error_message = "Invalid username or password.";
                }
            } else {
                $error_message = "Oops! Something went wrong.";
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login - SLATE System</title>
  <link rel="stylesheet" href="login-style.css">
   <style>
      .error-message { color: #ffb3b3; background-color: rgba(255, 0, 0, 0.2); border: 1px solid #ff4d4d; padding: 0.75rem; border-radius: 0.375rem; margin-top: 1rem; text-align: center; }
      .forgot-password { text-align: right; margin-top: -0.75rem; margin-bottom: 1.25rem; }
      .forgot-password a { font-size: 0.9em; color: #00c6ff; text-decoration: none; }
      .register-link { text-align: center; margin-top: 1.5rem; }
      .register-link a { color: #00c6ff; text-decoration: none; font-weight: 600; }
  </style>
</head>
<body class="login-page-body">
  <div class="main-container">
    <div class="login-container">
      <div class="welcome-panel">
        <h1>FREIGHT MANAGEMENT SYSTEM</h1>
      </div>
      <div class="login-panel">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>SLATE Login</h2>
          <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <input type="text" name="username" placeholder="Username" required value="">
            <input type="password" name="password" placeholder="Password" required value="">
            <div class="forgot-password">
              <a href="forgot_password.php">Forgot Password?</a>
            </div>
            <button type="submit">Log In</button>
            <?php if(!empty($error_message)){ echo '<div class="error-message">' . $error_message . '</div>'; } ?>
          </form>
          <div class="register-link">
            Want to be a driver? <a href="driver_register.php">Register here</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

