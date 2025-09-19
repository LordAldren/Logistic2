<?php
// ... existing code ... */
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
  <link rel="stylesheet" href="style.css"> <!-- Link to main stylesheet for loader -->
   <style>
      .error-message {
          color: #ffb3b3; background-color: rgba(255, 0, 0, 0.2);
// ... existing code ... */
      .login-hint { font-size: 0.8em; margin-top: 1em; color: #a0a0a0; }
  </style>
</head>
<body>
  <div class="loader-wrapper hidden" id="loader"> <!-- Start hidden on login page -->
    <div class="loader-content">
      <img src="logo.png" alt="SLATE Logo">
      <div class="spinner"></div>
      <p>Logging In...</p>
    </div>
  </div>

  <div class="main-container">
    <div class="login-container">
      <div class="welcome-panel">
// ... existing code ... */
      </div>

      <div class="login-panel">
        <div class="login-box">
          <img src="logo.png" alt="SLATE Logo">
          <h2>SLATE Login</h2>
          <form id="login-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <input type="text" name="username" placeholder="Username" required value="">
            <input type="password" name="password" placeholder="Password" required value="">
            <button type="submit">Log In</button>
            <?php if(!empty($error_message)){ echo '<div class="error-message">' . $error_message . '</div>'; } ?>
            
          </form>
        </div>
      </div>
    </div>
  </div>

  <footer>
    &copy; <span id="currentYear"></span> SLATE Freight Management System. All rights reserved.
  </footer>

  <script>
    document.getElementById('currentYear').textContent = new Date().getFullYear();

    // Show loader when the form is submitted
    document.getElementById('login-form').addEventListener('submit', function() {
      document.getElementById('loader').classList.remove('hidden');
    });
  </script>
</body>
</html>
