<?php
session_start();
require_once '../config/db_connect.php';

// Set header to return JSON
header('Content-Type: application/json');

// Get the posted data
$data = json_decode(file_get_contents("php://input"));

// Check if user is authenticated for the second step
if (!isset($_SESSION['verification_user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

$user_id = $_SESSION['verification_user_id'];
$submitted_code = isset($data->code) ? trim($data->code) : null;

if (empty($submitted_code) || strlen($submitted_code) !== 6 || !ctype_digit($submitted_code)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid code format.']);
    exit;
}

// Check if we have a code stored in session
if (!isset($_SESSION['totp_code']) || !isset($_SESSION['totp_code_time'])) {
    echo json_encode(['status' => 'error', 'message' => 'No verification code found. Please request a new code.']);
    exit;
}

$stored_code = $_SESSION['totp_code'];
$code_time = $_SESSION['totp_code_time'];
$time_period = 300; // 5 minutes

// Check if the code has expired
$elapsed = time() - $code_time;
if ($elapsed > $time_period) {
    // Clear expired code
    unset($_SESSION['totp_code']);
    unset($_SESSION['totp_code_time']);
    unset($_SESSION['totp_code_slot']);
    echo json_encode(['status' => 'error', 'message' => 'Verification code has expired. Please request a new code.']);
    exit;
}

// Verify the code
if ($submitted_code === $stored_code) {
    // Fetch user details
    $sql = "SELECT id, username, role FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        // Clear all verification session data
        unset($_SESSION['verification_user_id']);
        unset($_SESSION['verification_role']);
        unset($_SESSION['totp_code']);
        unset($_SESSION['totp_code_time']);
        unset($_SESSION['totp_code_slot']);
        
        session_regenerate_id(true);
        
        // Set full login session
        $_SESSION["loggedin"] = true;
        $_SESSION["id"] = $user['id'];
        $_SESSION["user_id"] = $user['id'];
        $_SESSION["username"] = $user['username'];
        $_SESSION["role"] = $user['role'];
        
        echo json_encode(['status' => 'success', 'role' => $user['role']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
    }
    
    $stmt->close();
} else {
    // Log failed attempt (optional)
    echo json_encode(['status' => 'error', 'message' => 'Invalid verification code. Please try again.']);
}

$conn->close();
?>
