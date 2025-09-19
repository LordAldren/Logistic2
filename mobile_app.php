<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'driver') {
    header('Location: login.php');
    exit;
}
require_once 'db_connect.php';

$user_id = $_SESSION['id'];
$driver_info = null;
$trip_info = null;

// Fetch driver details, including their status
$driver_stmt = $conn->prepare("SELECT d.*, v.type as vehicle_type, v.model as vehicle_model FROM drivers d LEFT JOIN vehicles v ON d.id = v.assigned_driver_id WHERE d.user_id = ?");
$driver_stmt->bind_param("i", $user_id);
$driver_stmt->execute();
$driver_result = $driver_stmt->get_result();
if ($driver_result->num_rows > 0) {
    $driver_info = $driver_result->fetch_assoc();
    $driver_id = $driver_info['id'];

    // If driver is active, fetch current trip details
    if ($driver_info['status'] === 'Active') {
        $trip_stmt = $conn->prepare("SELECT t.id, t.destination, t.status, t.client_name FROM trips t WHERE t.driver_id = ? AND t.status = 'En Route' LIMIT 1");
        $trip_stmt->bind_param("i", $driver_id);
        $trip_stmt->execute();
        $trip_result = $trip_stmt->get_result();
        $trip_info = $trip_result->fetch_assoc();
        $trip_stmt->close();
    }
}
$driver_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, maximum-scale=1.0">
    <title>Driver App - Logistics II</title>
    <link rel="stylesheet" href="style.css">
    <!-- Firebase SDKs -->
    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js"></script>
</head>
<body class="mobile-app-body">
    <div class="app-wrapper">
        <div class="app-header">SLATE Driver App</div>
        <div class="app-content">
            <?php if ($driver_info && $driver_info['status'] === 'Active'): ?>
                <!-- Active Driver View -->
                <div class="card">
                    <h4>Driver & Vehicle</h4>
                    <p><strong>Driver:</strong> <?php echo htmlspecialchars($driver_info['name']); ?></p>
                    <p><strong>Vehicle:</strong> <?php echo htmlspecialchars($driver_info['vehicle_type'] . ' ' . $driver_info['vehicle_model']); ?></p>
                </div>

                <?php if ($trip_info): ?>
                    <div class="card">
                        <h4>Current Trip</h4>
                        <p><strong>Assigned Client:</strong> <?php echo htmlspecialchars($trip_info['client_name'] ?? 'N/A'); ?></p>
                        <p><strong>Destination:</strong> <?php echo htmlspecialchars($trip_info['destination']); ?></p>
                        <p><strong>Status:</strong> <span class="status-badge status-en-route">En Route</span></p>
                    </div>

                    <div class="speedometer-container">
                        <div class="speedometer">
                            <div id="speedValue" class="speed-value">0</div>
                            <div class="speed-unit">km/h</div>
                        </div>
                        <div id="locationDisplay" class="location-display">Initializing GPS...</div>
                    </div>
                <?php else: ?>
                    <div class="card" style="text-align:center;">
                        <h4>Standby</h4>
                        <p>You have no active trip. Please wait for dispatch instructions.</p>
                    </div>
                <?php endif; ?>
            
            <?php elseif($driver_info && $driver_info['status'] === 'Pending'): ?>
                 <!-- Pending Driver View -->
                <div class="app-status-page">
                    <div class="status-icon-container pending">
                         <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-clock-history" viewBox="0 0 16 16">
                          <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022l-.074.997zM5.56 2.423a7.001 7.001 0 0 0-2.09 1.13l.47.887a6 6 0 0 1 1.74-1.02l-.12-.999zM2.423 5.56a7.001 7.001 0 0 0-1.13 2.09l.887.47a6 6 0 0 1 1.02-1.74l-.999-.12zm-1.13 4.45a7.001 7.001 0 0 0 1.13 2.09l.999-.12a6 6 0 0 1-1.02-1.74l-.887.47zM5.56 13.577a7.001 7.001 0 0 0 2.09 1.13l.12-.999a6 6 0 0 1-1.74-1.02l-.47.887zM11.537 12.6l.47-.887a6 6 0 0 1-1.74 1.02l.12.999a7.001 7.001 0 0 0 2.09-1.13zM13.577 8.44a7.001 7.001 0 0 0-1.13-2.09l-.999.12a6 6 0 0 1 1.02 1.74l.887-.47z"/>
                          <path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/>
                          <path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/>
                        </svg>
                    </div>
                    <h3 class="status-title">Account Pending Approval</h3>
                    <p class="status-message">Your registration is being reviewed by an administrator. Please check back later.</p>
                    <a href="logout.php" class="btn btn-secondary" style="margin-top: 1.5rem;">Logout</a>
                </div>
            <?php else: ?>
                <!-- Inactive/Suspended or No Profile View -->
                <div class="app-status-page">
                    <div class="status-icon-container access-denied">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-x-circle-fill" viewBox="0 0 16 16">
                          <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/>
                        </svg>
                    </div>
                    <h3 class="status-title">Access Denied</h3>
                    <p class="status-message">Your account is currently not active or found. Please contact an administrator.</p>
                    <a href="logout.php" class="btn btn-secondary" style="margin-top: 1.5rem;">Logout</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="bottom-nav">
             <a href="logout.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width: 24px; height: 24px;"><path d="M16 17v-3H9v-4h7V7l5 5-5 5M14 2a2 2 0 012 2v2h-2V4H5v16h9v-2h2v2a2 2 0 01-2 2H5a2 2 0 01-2-2V4a2 2 0 012-2h9z"/></svg>
                Logout
            </a>
        </div>
    </div>

<?php if ($driver_info && $driver_info['status'] === 'Active' && $trip_info): ?>
<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCB0_OYZXX3K-AxKeHnVlYMv2wZ_81FeYM",
        authDomain: "slate49-cde60.firebaseapp.com",
        databaseURL: "https://slate49-cde60-default-rtdb.firebaseio.com",
        projectId: "slate49-cde60",
        storageBucket: "slate49-cde60.firebasestorage.app",
        messagingSenderId: "809390854040",
        appId: "1:809390854040:web:f7f77333bb0ac7ab73e5ed",
        measurementId: "G-FNW2WP3351"
    };
    try {
        if (!firebase.apps.length) { firebase.initializeApp(firebaseConfig); } else { firebase.app(); }
    } catch(e) { console.error("Firebase init failed. Check config.", e); }
    const database = firebase.database();

    const speedValueElement = document.getElementById('speedValue');
    const locationDisplayElement = document.getElementById('locationDisplay');
    const tripId = <?php echo json_encode($trip_info['id']); ?>;
    const vehicleInfo = <?php echo json_encode($driver_info['vehicle_type'] . ' ' . $driver_info['vehicle_model']); ?>;
    const driverName = <?php echo json_encode($driver_info['name']); ?>;

    function updateFirebase(position) {
        const speedMs = position.coords.speed;
        const speedKmh = speedMs ? Math.round(speedMs * 3.6) : 0;
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;

        // Update UI elements
        speedValueElement.textContent = speedKmh;
        if (locationDisplayElement) {
            locationDisplayElement.textContent = `Lat: ${lat.toFixed(4)}, Lng: ${lng.toFixed(4)}`;
        }

        const data = {
            lat: lat,
            lng: lng,
            speed: speedKmh, // Send speed in km/h
            timestamp: new Date().toISOString(),
            vehicle_info: vehicleInfo,
            driver_name: driverName
        };

        database.ref('live_tracking/' + tripId).set(data)
            .catch(error => console.error('Firebase update failed:', error));
    }

    function handleLocationError(error) {
        let message = 'Location tracking error.';
        switch(error.code) {
            case error.PERMISSION_DENIED:
                message = "Kailangan ng pahintulot para sa lokasyon.";
                break;
            case error.POSITION_UNAVAILABLE:
                message = "Hindi makuha ang impormasyon ng lokasyon.";
                break;
            case error.TIMEOUT:
                message = "Nag-timeout ang request para sa lokasyon.";
                break;
        }
        if (locationDisplayElement) {
            locationDisplayElement.textContent = message;
        }
    }

    if (navigator.geolocation && tripId) {
        navigator.geolocation.watchPosition(
            updateFirebase, 
            handleLocationError, 
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } else {
        if (locationDisplayElement) {
            locationDisplayElement.textContent = 'Hindi suportado ang Geolocation o walang aktibong biyahe.';
        }
    }
</script>
<?php endif; ?>
</body>
</html>
