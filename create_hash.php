<?php
// Gamitin ang file na ito para gumawa ng bagong password hash.
// I-access ito sa iyong browser (e.g., http://localhost/your_project/CODE FINAL/create_hash.php)
// Kopyahin ang "Generated Hash" at i-paste sa 'password' column ng 'admin' user sa iyong database.



$passwordToHash = 'supotako';
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);
$passwordToHash = 'password123';
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);
$passwordToHash = 'poginaman';
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

echo "<h1>Password Hash Generator</h1>";
echo "<p><strong>Password:</strong> " . htmlspecialchars($passwordToHash) . "</p>";
echo "<p><strong>Generated Hash (Kopyahin ito):</strong></p>";
echo "<textarea rows='3' cols='80' readonly>" . htmlspecialchars($hashedPassword) . "</textarea>";

?>
