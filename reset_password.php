<?php
require_once 'db_connect.php';
$token = isset($_GET['token']) ? $_GET['token'] : null;
$message = '';
$show_form = false;
$user_id = null;

if (!$token) {
    $message = "<div class='message-banner error'>Invalid reset link. No token provided.</div>";
} else {
    $stmt = $conn->prepare("SELECT user_id, expires_at FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($reset_data = $result->fetch_assoc()) {
        $user_id = $reset_data['user_id'];
        $expires = new DateTime($reset_data['expires_at']);
        $now = new DateTime('NOW');

        if ($now > $expires) {
            $message = "<div class='message-banner error'>This password reset link has expired.</div>";
        } else {
            $show_form = true;
        }
    } else {
        $message = "<div class='message-banner error'>Invalid password reset link.</div>";
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    $post_token = $_POST['token'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $post_user_id = $_POST['user_id'];

    if ($new_password !== $confirm_password) {
        $message = "<div class='message-banner error'>Passwords do not match.</div>";
        $show_form = true; // Show form again
    } elseif (strlen($new_password) < 6) {
        $message = "<div class='message-banner error'>Password must be at least 6 characters long.</div>";
        $show_form = true; // Show form again
    } else {
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            // Update user's password
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $post_user_id);
            $update_stmt->execute();

            // Delete the token
            $delete_stmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
            $delete_stmt->bind_param("s", $post_token);
            $delete_stmt->execute();

            $conn->commit();
            $message = "<div class='message-banner success'>Your password has been reset successfully!</div>";
            $show_form = false;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $message = "<div class='message-banner error'>An error occurred. Please try again.</div>";
            $show_form = true;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password - SLATE System</title>
  <link rel="stylesheet" href="login-style.css">
   <style>
      .message-banner { padding: 1rem; margin-bottom: 1.5rem; border-radius: 0.35rem; color: white; }
      .message-banner.success { background-color: #1cc88a; }
      .message-banner.error { background-color: #e74a3b; }
  </style>
</head>
<body class="login-page-body">
  <div class="main-container">
    <div class="login-container" style="max-width: 35rem;">
      <div class="login-panel" style="width: 100%;">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>Set New Password</h2>
          
          <?php echo $message; ?>

          <?php if ($show_form): ?>
          <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
            <input type="password" name="new_password" placeholder="Enter new password" required>
            <input type="password" name="confirm_password" placeholder="Confirm new password" required>
            <button type="submit" name="reset_password">Reset Password</button>
          </form>
          <?php endif; ?>

          <div style="margin-top: 1.5rem;">
            <a href="login.php" style="color: #00c6ff; text-decoration: none;">&larr; Back to Login</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
