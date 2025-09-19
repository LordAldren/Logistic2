<?php
// generate_hash.php
// Gamitin ang file na ito para gumawa ng bagong password hash.

// 1. Ilagay ang bagong password na gusto mo sa loob ng mga single quotes.
$passwordToHash = 'reneldriver';

// 2. I-save ang file na ito.
// 3. Buksan ito sa iyong browser (e.g., http://localhost/slate/CODE FINAL 3/generate_hash.php)
// 4. Kopyahin ang "Generated Hash" na lalabas.
// 5. Pumunta sa phpMyAdmin, hanapin ang user na gusto mong palitan ng password, at i-paste ang kinopya mong hash sa 'password' column.

// --- WALA NANG DAPAT BAGUHIN SA IBABA NITO ---

if (empty($passwordToHash) || $passwordToHash === 'ang-bago-mong-password') {
    echo "<h1>Password Hash Generator</h1>";
    echo "<p style='color:red;'><b>ACTION NEEDED:</b> Please open the `generate_hash.php` file and change the value of the <b>\$passwordToHash</b> variable on line 7 to your desired new password.</p>";
} else {
    $hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);
    
    echo "<h1>Password Hash Generator</h1>";
    echo "<p><strong>Password to Hash:</strong> " . htmlspecialchars($passwordToHash) . "</p>";
    echo "<p><strong>Generated Hash (Copy this value):</strong></p>";
    echo "<textarea rows='4' cols='80' readonly style='font-size: 1rem; padding: 10px;'>" . htmlspecialchars($hashedPassword) . "</textarea>";
    echo "<p style='margin-top: 1rem;'>Now, go to your database, find the user, and paste this hash into their 'password' field.</p>";
}

?>
