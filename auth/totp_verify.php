<?php
session_start();
// This page requires a user to have passed the first step of login
if (!isset($_SESSION['verification_user_id'])) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../PHPMailer/PHPMailer.php';
require_once '../PHPMailer/SMTP.php';
require_once '../PHPMailer/Exception.php';
require_once '../config/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Get user info for display
$user_id = $_SESSION['verification_user_id'];
$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$email_sent = false;
$email_error = '';
$masked_email = '';

// Constants for TOTP
$TIME_PERIOD = 300; // 5 minutes in seconds

// Generate a unique code for this session
// We use the user ID and current time window to generate a consistent code
function generateVerificationCode($user_id, $time_period = 300) {
    $time_slot = floor(time() / $time_period);
    // Use a combination of user_id, time_slot, and a secret for uniqueness
    $seed = ($user_id * 1000003 + $time_slot) * 9301;
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $seed = ($seed * 9301 + 49297) % 233280;
        $code .= floor(($seed / 233280) * 10);
    }
    return $code;
}

// Check if we need to generate and send a new code
$current_time_slot = floor(time() / $TIME_PERIOD);
$last_sent_slot = isset($_SESSION['totp_code_slot']) ? $_SESSION['totp_code_slot'] : 0;
$verification_code = generateVerificationCode($user_id, $TIME_PERIOD);

// Only send email if we haven't sent one for this time slot yet
if ($last_sent_slot !== $current_time_slot && $user && !empty($user['email'])) {
    // Store the code in session for verification
    $_SESSION['totp_code'] = $verification_code;
    $_SESSION['totp_code_slot'] = $current_time_slot;
    $_SESSION['totp_code_time'] = time();
    
    // Send email with the code
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($user['email']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your SLATE Security Code';
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; background-color: #0a1628; margin: 0; padding: 40px 20px; }
                .container { max-width: 500px; margin: 0 auto; background: linear-gradient(135deg, #101a29 0%, #0d1520 100%); border-radius: 16px; padding: 40px; border: 1px solid rgba(0, 225, 255, 0.2); box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
                .logo { text-align: center; margin-bottom: 30px; }
                .logo img { width: 80px; }
                h1 { color: #00e1ff; text-align: center; margin: 0 0 10px; font-size: 24px; letter-spacing: 2px; }
                .subtitle { color: #94a3b8; text-align: center; margin-bottom: 30px; font-size: 14px; }
                .code-box { background: rgba(0, 225, 255, 0.1); border: 2px solid rgba(0, 225, 255, 0.3); border-radius: 12px; padding: 25px; text-align: center; margin: 25px 0; }
                .code { font-size: 42px; font-weight: 700; letter-spacing: 12px; color: #00e1ff; font-family: monospace; text-shadow: 0 0 20px rgba(0, 225, 255, 0.5); }
                .expires { color: #f6c23e; font-size: 14px; margin-top: 15px; }
                .footer { text-align: center; color: #64748b; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); }
                .warning { color: #e74a3b; font-size: 12px; margin-top: 20px; text-align: center; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>SECURITY VERIFICATION</h1>
                <p class="subtitle">Your login verification code for SLATE System</p>
                <div class="code-box">
                    <div class="code">' . $verification_code . '</div>
                    <div class="expires">⏱ This code expires in 5 minutes</div>
                </div>
                <p class="warning">⚠️ If you did not request this code, please ignore this email.</p>
                <div class="footer">
                    © ' . date('Y') . ' SLATE Freight Management System<br>
                    This is an automated message, please do not reply.
                </div>
            </div>
        </body>
        </html>';
        $mail->AltBody = 'Your SLATE verification code is: ' . $verification_code . '. This code expires in 5 minutes.';

        $mail->send();
        $email_sent = true;
    } catch (Exception $e) {
        $email_error = 'Could not send verification email. Please try again.';
        error_log("TOTP Email Error: " . $mail->ErrorInfo);
    }
    
    // Mask email for display
    if (!empty($user['email'])) {
        $email_parts = explode('@', $user['email']);
        $name = $email_parts[0];
        $domain = $email_parts[1];
        $masked_name = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 4, 2)) . substr($name, -2);
        $masked_email = $masked_name . '@' . $domain;
    }
} else if ($user && !empty($user['email'])) {
    // Code was already sent for this time slot
    $email_sent = true;
    $email_parts = explode('@', $user['email']);
    $name = $email_parts[0];
    $domain = $email_parts[1];
    $masked_name = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 4, 2)) . substr($name, -2);
    $masked_email = $masked_name . '@' . $domain;
}

// Calculate remaining time for the current code
$code_sent_time = isset($_SESSION['totp_code_time']) ? $_SESSION['totp_code_time'] : time();
$elapsed = time() - $code_sent_time;
$remaining_seconds = max(0, $TIME_PERIOD - $elapsed);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Security Verification - SLATE System</title>
    <link rel="stylesheet" href="../assets/css/login-style.css?v=2.1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Override to center everything */
        body.login-page-body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .main-container {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            min-height: auto;
            margin: 0 auto;
        }

        .totp-container {
            text-align: center;
        }

        .totp-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 25px;
            background: linear-gradient(135deg, rgba(0, 225, 255, 0.15), rgba(0, 114, 255, 0.15));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(0, 225, 255, 0.3);
            box-shadow: 0 0 40px rgba(0, 225, 255, 0.2), inset 0 0 30px rgba(0, 225, 255, 0.05);
            animation: iconPulse 2s ease-in-out infinite;
        }

        .totp-icon i {
            font-size: 2.2rem;
            color: var(--primary-cyan);
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(0, 225, 255, 0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 50px rgba(0, 225, 255, 0.4); }
        }

        .totp-title {
            font-size: 1.8rem;
            color: var(--primary-cyan);
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 12px;
            text-shadow: 0 0 15px rgba(0, 225, 255, 0.4);
        }

        .totp-subtitle {
            color: var(--text-muted);
            margin-bottom: 20px;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .email-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(0, 225, 255, 0.08);
            border: 1px solid rgba(0, 225, 255, 0.2);
            padding: 10px 20px;
            border-radius: 25px;
            margin-bottom: 25px;
            font-family: 'Roboto Mono', monospace;
            font-size: 0.85rem;
            color: var(--primary-cyan);
        }

        .email-badge i {
            font-size: 1rem;
        }

        /* Timer Container - Centered and Prominent */
        .timer-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 16px;
            border: 1px solid rgba(0, 225, 255, 0.1);
        }

        .countdown-ring {
            position: relative;
            width: 100px;
            height: 100px;
            margin-bottom: 15px;
        }

        .countdown-ring svg {
            transform: rotate(-90deg);
            width: 100px;
            height: 100px;
        }

        .countdown-ring circle {
            fill: none;
            stroke-width: 6;
        }

        .countdown-ring .bg {
            stroke: rgba(0, 225, 255, 0.1);
        }

        .countdown-ring .progress {
            stroke: var(--primary-cyan);
            stroke-linecap: round;
            transition: stroke-dashoffset 1s linear;
            filter: drop-shadow(0 0 8px rgba(0, 225, 255, 0.6));
        }

        .countdown-ring .progress.warning {
            stroke: #f6c23e;
            filter: drop-shadow(0 0 8px rgba(246, 194, 62, 0.6));
        }

        .countdown-ring .progress.critical {
            stroke: #e74a3b;
            filter: drop-shadow(0 0 8px rgba(231, 74, 59, 0.6));
        }

        .countdown-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Roboto Mono', monospace;
            color: var(--primary-cyan);
        }

        .countdown-text.warning {
            color: #f6c23e;
        }

        .countdown-text.critical {
            color: #e74a3b;
            animation: blink 0.5s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .timer-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Code Input Container */
        .code-input-container {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .code-digit {
            width: 50px;
            height: 60px;
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(0, 225, 255, 0.2);
            border-radius: 10px;
            text-align: center;
            font-size: 1.8rem;
            font-family: 'Roboto Mono', monospace;
            font-weight: 600;
            color: #fff;
            transition: all 0.3s ease;
            outline: none;
        }

        .code-digit:focus {
            border-color: var(--primary-cyan);
            background: rgba(0, 225, 255, 0.1);
            box-shadow: 0 0 25px rgba(0, 225, 255, 0.3);
            transform: translateY(-3px);
        }

        .code-digit.filled {
            border-color: rgba(0, 225, 255, 0.5);
            background: rgba(0, 225, 255, 0.05);
        }

        .code-digit.error {
            border-color: #ff3838;
            animation: shake 0.5s ease-in-out;
        }

        .code-digit.success {
            border-color: #1cc88a;
            background: rgba(28, 200, 138, 0.15);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        /* Status Messages */
        .status-message {
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .status-message.show {
            opacity: 1;
            transform: translateY(0);
        }

        .status-message.error {
            background: rgba(231, 74, 59, 0.15);
            border: 1px solid rgba(231, 74, 59, 0.3);
            color: #e74a3b;
        }

        .status-message.success {
            background: rgba(28, 200, 138, 0.15);
            border: 1px solid rgba(28, 200, 138, 0.3);
            color: #1cc88a;
        }

        .status-message.info {
            background: rgba(0, 225, 255, 0.1);
            border: 1px solid rgba(0, 225, 255, 0.3);
            color: var(--primary-cyan);
        }

        /* Email Sent Alert */
        .email-alert {
            background: rgba(28, 200, 138, 0.1);
            border: 1px solid rgba(28, 200, 138, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .email-alert i {
            font-size: 1.5rem;
            color: #1cc88a;
        }

        .email-alert-text {
            text-align: left;
        }

        .email-alert-text strong {
            color: #1cc88a;
            display: block;
            margin-bottom: 3px;
        }

        .email-alert-text span {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Verify Button */
        .verify-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--primary-cyan), var(--primary-blue));
            color: #fff;
            border: none;
            padding: 16px 30px;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .verify-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .verify-btn:hover::before {
            left: 100%;
        }

        .verify-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 35px rgba(0, 225, 255, 0.35);
        }

        .verify-btn:disabled {
            background: rgba(100, 100, 100, 0.3);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .verify-btn .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        .verify-btn.loading .spinner {
            display: block;
        }

        .verify-btn.loading .btn-text {
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Resend Link */
        .resend-container {
            margin-top: 20px;
        }

        .resend-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .resend-link:hover {
            color: var(--primary-cyan);
        }

        .resend-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            margin-top: 25px;
        }

        .back-link:hover {
            color: var(--primary-cyan);
        }

        /* Error Alert */
        .error-alert {
            background: rgba(231, 74, 59, 0.15);
            border: 1px solid rgba(231, 74, 59, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 25px;
            color: #e74a3b;
            text-align: center;
        }
    </style>
</head>
<body class="login-page-body">
    <div class="main-container">
        <div class="login-container">
            <div class="login-panel" style="width: 100%;">
                <div class="totp-container">
                    <div class="totp-icon">
                        <i class="fas fa-envelope-circle-check"></i>
                    </div>
                    
                    <h1 class="totp-title">Email Verification</h1>
                    <p class="totp-subtitle">We've sent a verification code to your email</p>

                    <?php if ($email_sent): ?>
                    <div class="email-alert">
                        <i class="fas fa-check-circle"></i>
                        <div class="email-alert-text">
                            <strong>Code Sent!</strong>
                            <span><?php echo htmlspecialchars($masked_email); ?></span>
                        </div>
                    </div>
                    <?php elseif (!empty($email_error)): ?>
                    <div class="error-alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($email_error); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Timer -->
                    <div class="timer-container">
                        <div class="countdown-ring">
                            <svg>
                                <circle class="bg" cx="50" cy="50" r="42"></circle>
                                <circle class="progress" cx="50" cy="50" r="42" 
                                    stroke-dasharray="263.9"
                                    stroke-dashoffset="0"
                                    id="progressCircle"></circle>
                            </svg>
                            <span class="countdown-text" id="countdownText">5:00</span>
                        </div>
                        <span class="timer-label">Code expires in</span>
                    </div>

                    <!-- Code Input -->
                    <div class="code-input-container" id="codeInputContainer">
                        <input type="text" class="code-digit" maxlength="1" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="code-digit" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="code-digit" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="code-digit" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="code-digit" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="code-digit" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    </div>

                    <!-- Status Message -->
                    <div class="status-message" id="statusMessage">
                        <i class="fas fa-info-circle"></i>
                        <span id="statusText"></span>
                    </div>

                    <!-- Verify Button -->
                    <button class="verify-btn" id="verifyBtn">
                        <span class="btn-text"><i class="fas fa-check-circle"></i> Verify Code</span>
                        <span class="spinner"></span>
                    </button>

                    <div class="resend-container">
                        <a href="totp_verify.php" class="resend-link" id="resendLink">
                            <i class="fas fa-redo"></i> Resend Code
                        </a>
                    </div>

                    <a href="logout.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Cancel and go back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.code-digit');
            const verifyBtn = document.getElementById('verifyBtn');
            const statusMessage = document.getElementById('statusMessage');
            const statusText = document.getElementById('statusText');
            const progressCircle = document.getElementById('progressCircle');
            const countdownText = document.getElementById('countdownText');
            const resendLink = document.getElementById('resendLink');
            
            const CIRCUMFERENCE = 2 * Math.PI * 42; // ~263.9
            const TIME_PERIOD = 300; // 5 minutes in seconds
            
            // Get remaining time from server
            let remainingSeconds = <?php echo $remaining_seconds; ?>;
            let isVerifying = false;

            // Format time as M:SS
            function formatTime(seconds) {
                const mins = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            }

            // Update timer
            function updateTimer() {
                if (remainingSeconds <= 0) {
                    countdownText.textContent = '0:00';
                    countdownText.classList.add('critical');
                    progressCircle.classList.add('critical');
                    progressCircle.style.strokeDashoffset = CIRCUMFERENCE;
                    showStatus('Code expired. Please request a new code.', 'error');
                    resendLink.classList.remove('disabled');
                    return;
                }

                remainingSeconds--;
                
                // Update countdown text
                countdownText.textContent = formatTime(remainingSeconds);
                
                // Update progress ring
                const elapsed = TIME_PERIOD - remainingSeconds;
                const progress = (elapsed / TIME_PERIOD) * CIRCUMFERENCE;
                progressCircle.style.strokeDashoffset = progress;
                
                // Color changes based on time remaining
                if (remainingSeconds <= 30) {
                    progressCircle.classList.remove('warning');
                    progressCircle.classList.add('critical');
                    countdownText.classList.remove('warning');
                    countdownText.classList.add('critical');
                } else if (remainingSeconds <= 60) {
                    progressCircle.classList.remove('critical');
                    progressCircle.classList.add('warning');
                    countdownText.classList.remove('critical');
                    countdownText.classList.add('warning');
                } else {
                    progressCircle.classList.remove('warning', 'critical');
                    countdownText.classList.remove('warning', 'critical');
                }
            }

            // Initialize
            progressCircle.style.strokeDasharray = CIRCUMFERENCE;
            const initialElapsed = TIME_PERIOD - remainingSeconds;
            progressCircle.style.strokeDashoffset = (initialElapsed / TIME_PERIOD) * CIRCUMFERENCE;
            countdownText.textContent = formatTime(remainingSeconds);
            
            // Start timer
            setInterval(updateTimer, 1000);

            // Input handling
            inputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    const value = e.target.value.replace(/[^0-9]/g, '');
                    e.target.value = value;
                    
                    if (value) {
                        input.classList.add('filled');
                        if (index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        }
                    } else {
                        input.classList.remove('filled');
                    }
                    
                    clearStatus();
                    checkAutoSubmit();
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !e.target.value && index > 0) {
                        inputs[index - 1].focus();
                        inputs[index - 1].value = '';
                        inputs[index - 1].classList.remove('filled');
                    }
                    
                    if (e.key === 'Enter') {
                        verifyCode();
                    }
                });

                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                    const digits = pastedData.replace(/[^0-9]/g, '').slice(0, 6);
                    
                    digits.split('').forEach((digit, i) => {
                        if (inputs[i]) {
                            inputs[i].value = digit;
                            inputs[i].classList.add('filled');
                        }
                    });
                    
                    if (digits.length > 0) {
                        inputs[Math.min(digits.length, inputs.length - 1)].focus();
                    }
                    
                    checkAutoSubmit();
                });

                input.addEventListener('focus', () => {
                    input.select();
                });
            });

            // Focus first input
            inputs[0].focus();

            // Check if all digits filled for auto-submit
            function checkAutoSubmit() {
                const code = getCode();
                if (code.length === 6) {
                    setTimeout(() => verifyCode(), 300);
                }
            }

            // Get entered code
            function getCode() {
                return Array.from(inputs).map(input => input.value).join('');
            }

            // Show status message
            function showStatus(message, type) {
                statusText.textContent = message;
                statusMessage.className = 'status-message show ' + type;
                
                const icon = statusMessage.querySelector('i');
                if (type === 'error') {
                    icon.className = 'fas fa-exclamation-circle';
                } else if (type === 'success') {
                    icon.className = 'fas fa-check-circle';
                } else {
                    icon.className = 'fas fa-info-circle';
                }
            }

            // Clear status
            function clearStatus() {
                statusMessage.classList.remove('show');
                inputs.forEach(input => {
                    input.classList.remove('error', 'success');
                });
            }

            // Verify code
            async function verifyCode() {
                if (isVerifying) return;
                
                const code = getCode();
                
                if (code.length !== 6) {
                    showStatus('Please enter all 6 digits', 'error');
                    inputs.forEach(input => input.classList.add('error'));
                    return;
                }

                if (remainingSeconds <= 0) {
                    showStatus('Code expired. Please request a new code.', 'error');
                    return;
                }

                isVerifying = true;
                verifyBtn.classList.add('loading');
                verifyBtn.disabled = true;

                try {
                    const response = await fetch('verify_totp.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: code })
                    });

                    const data = await response.json();

                    if (data.status === 'success') {
                        showStatus('Verification successful! Redirecting...', 'success');
                        inputs.forEach(input => input.classList.add('success'));
                        
                        setTimeout(() => {
                            window.location.href = data.role === 'driver' 
                                ? '../modules/mfc/mobile_app.php' 
                                : '../landpage.php';
                        }, 1000);
                    } else {
                        showStatus(data.message || 'Invalid code. Please try again.', 'error');
                        inputs.forEach(input => {
                            input.classList.add('error');
                            input.value = '';
                            input.classList.remove('filled');
                        });
                        inputs[0].focus();
                        isVerifying = false;
                        verifyBtn.classList.remove('loading');
                        verifyBtn.disabled = false;
                    }
                } catch (error) {
                    console.error('Verification error:', error);
                    showStatus('An error occurred. Please try again.', 'error');
                    isVerifying = false;
                    verifyBtn.classList.remove('loading');
                    verifyBtn.disabled = false;
                }
            }

            // Button click
            verifyBtn.addEventListener('click', verifyCode);
        });
    </script>
</body>
</html>
