<?php
require_once 'db_connect.php';
require_once 'mailer.php';

$message = '';
$message_type = ''; // 'success' or 'error'

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $user_id = $user['id'];
            $token = bin2hex(random_bytes(50));
            $expires = new DateTime('NOW');
            $expires->add(new DateInterval('PT1H')); // 1 hour expiry
            $expires_str = $expires->format('Y-m-d H:i:s');
            
            $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $token, $expires_str);
            $stmt->execute();
            
            // Construct the reset link
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            // Send the email
            $subject = "Password Reset Request for SLATE Logistics";
            $body = "<h3>Password Reset Request</h3>
                     <p>You are receiving this email because a password reset was requested for your account.</p>
                     <p>Please click the link below to reset your password. This link is valid for 1 hour.</p>
                     <p><a href='$reset_link'>$reset_link</a></p>
                     <p>If you did not request a password reset, you can safely ignore this email.</p>";

            if (sendEmail($email, $subject, $body)) {
                $message = "A password reset link has been sent to your email address.";
                $message_type = 'success';
            } else {
                $message = "Failed to send reset email. Please try again later.";
                $message_type = 'error';
            }
        } else {
            // To prevent user enumeration, show a generic success message even if email doesn't exist.
            $message = "If an account with that email exists, a password reset link has been sent.";
            $message_type = 'success';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SLATE System</title>
    <link rel="stylesheet" href="login-style.css">
    <style>
        .message { padding: 1rem; border-radius: 0.375rem; margin-top: 1rem; text-align: center; }
        .success { color: #d1e7dd; background-color: rgba(15, 128, 62, 0.2); border: 1px solid #0f803e; }
        .error { color: #ffb3b3; background-color: rgba(255, 0, 0, 0.2); border: 1px solid #ff4d4d; }
    </style>
</head>
<body class="login-page-body">
  <div class="main-container">
    <div class="login-container" style="max-width: 35rem;">
      <div class="login-panel" style="width: 100%;">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>Reset Password</h2>
          <p style="margin-bottom: 1rem; color: #ccc;">Enter your email address and we will send you a link to reset your password.</p>
          
          <?php if(!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
          <?php endif; ?>

          <form action="forgot_password.php" method="post">
            <input type="email" name="email" placeholder="Enter your email address" required autofocus>
            <button type="submit">Send Reset Link</button>
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

