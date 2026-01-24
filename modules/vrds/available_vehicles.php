<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../../auth/login.php");
    exit;
}

// RBAC check - Admin at Staff lang ang pwedeng pumasok dito
if (!in_array($_SESSION['role'], ['admin', 'staff'])) {
    if ($_SESSION['role'] === 'driver') {
        header("location: ../mfc/mobile_app.php");
    } else {
        header("location: ../../landpage.php");
    }
    exit;
}

require_once '../../config/db_connect.php';
$message = '';
$current_user_id = $_SESSION['id'];

// --- API INTEGRATION ---
$API_BASE_URL = 'http://192.168.1.31/logistics1/api/assets.php';

function fetchFromAPI($action, $params = []) {
    global $API_BASE_URL;
    
    $params['action'] = $action;
    $url = $API_BASE_URL . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error, 'data' => null];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP Error: $httpCode", 'data' => null];
    }
    
    $data = json_decode($response, true);
    return $data ?: ['success' => false, 'error' => 'Invalid JSON response', 'data' => null];
}

// --- DATA FETCHING FROM API ---
$api_result = fetchFromAPI('cargos_vehicles');
$available_vehicles = [];

if ($api_result['success']) {
    // Extract vehicles and cargo vans from the grouped assets
    $assets = $api_result['data']['assets'] ?? [];
    $all_vehicles = [];
    
    // Merge Cargo Van and Vehicle types
    if (isset($assets['Cargo Van'])) {
        $all_vehicles = array_merge($all_vehicles, $assets['Cargo Van']);
    }
    if (isset($assets['Vehicle'])) {
        $all_vehicles = array_merge($all_vehicles, $assets['Vehicle']);
    }
    if (isset($assets['Delivery Truck'])) {
        $all_vehicles = array_merge($all_vehicles, $assets['Delivery Truck']);
    }
    
    // Filter only available (Operational) vehicles
    foreach ($all_vehicles as $vehicle) {
        if ($vehicle['status'] === 'Operational') {
            $available_vehicles[] = $vehicle;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Vehicles | VRDS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>

<body>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="content" id="mainContent">
        <div class="header">
            <div class="hamburger" id="hamburger">☰</div>
            <div>
                <h1>Available Vehicles for Reservation</h1>
            </div>
            <div class="theme-toggle-container">
                <span class="theme-label">Dark Mode</span>
                <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
            </div>
        </div>

        <?php echo $message; ?>

        <div class="card">
            <h3>Vehicle Fleet</h3>
            <p>This is a view-only list of all available vehicles. To create a new reservation, please go to the <a
                    href="reservation_booking.php">Reservation Booking</a> page.</p>
            <div class="vehicle-gallery" style="margin-top: 1.5rem;">
                <?php if (!empty($available_vehicles)): ?>
                    <?php foreach ($available_vehicles as $vehicle):
                        // Get image from API or use placeholder
                        if (!empty($vehicle['image_path'])) {
                            $image = 'http://192.168.1.31/logistics1/' . $vehicle['image_path'];
                        } else {
                            $image = 'https://placehold.co/400x300/e2e8f0/64748b?text=' . urlencode($vehicle['asset_type']);
                        }
                        ?>
                        <div class="vehicle-card">
                            <img src="<?php echo htmlspecialchars($image); ?>"
                                alt="<?php echo htmlspecialchars($vehicle['asset_type']); ?>" 
                                class="vehicle-image"
                                onerror="this.src='https://placehold.co/400x300/e2e8f0/64748b?text=No+Image'">
                            <div class="vehicle-details">
                                <div>
                                    <div class="vehicle-title">
                                        <?php echo htmlspecialchars($vehicle['asset_type'] . ' - ' . $vehicle['asset_name']); ?>
                                    </div>
                                    <div class="vehicle-info">
                                        <strong>Classification:</strong> <?php echo htmlspecialchars($vehicle['asset_classification'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="vehicle-info">
                                        <strong>Usage:</strong> <?php echo htmlspecialchars($vehicle['usage_frequency'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="vehicle-info">
                                        <strong>Status:</strong> <span class="status-badge status-operational"><?php echo htmlspecialchars($vehicle['status']); ?></span>
                                    </div>
                                </div>
                                <a href="../fvm/vehicle_list.php?query=<?php echo urlencode($vehicle['asset_name']); ?>"
                                    class="btn btn-info" style="margin-top: 1rem; width: 100%;">View in FVM</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No vehicles are currently available from the API.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../../assets/js/dark_mode_handler.js" defer></script>
</body>

</html>