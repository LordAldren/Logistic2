<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';
$message = '';
$current_user_id = $_SESSION['id'];

// --- CSV REPORT GENERATION ---
if (isset($_GET['download_csv'])) {
    // Sanitize and prepare filters
    $where_clauses = [];
    $params = [];
    $types = '';

    $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
    if (!empty($search_query)) {
        $where_clauses[] = "(r.reservation_code LIKE ? OR r.client_name LIKE ?)";
        $search_term = "%{$search_query}%";
        array_push($params, $search_term, $search_term);
        $types .= 'ss';
    }
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    if (!empty($start_date)) {
        $where_clauses[] = "r.reservation_date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
    if (!empty($end_date)) {
        $where_clauses[] = "r.reservation_date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    if (!empty($status_filter)) {
        $where_clauses[] = "r.status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }

    $report_sql = "SELECT r.*, v.type as vehicle_type, v.model as vehicle_model, u.username as reserved_by FROM reservations r LEFT JOIN vehicles v ON r.vehicle_id = v.id LEFT JOIN users u ON r.reserved_by_user_id = u.id";
    if (!empty($where_clauses)) {
        $report_sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $report_sql .= " ORDER BY r.reservation_date DESC";
    
    $stmt = $conn->prepare($report_sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reservations_report_'.date('Y-m-d').'.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Reservation Code', 'Client', 'Reserved By', 'Vehicle', 'Date', 'Purpose', 'Status']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['reservation_code'],
            $row['client_name'],
            $row['reserved_by'],
            ($row['vehicle_type'] ?? 'N/A') . ' ' . ($row['vehicle_model'] ?? ''),
            $row['reservation_date'],
            $row['purpose'],
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

// --- FORM & ACTION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle Add/Edit Reservation
    if (isset($_POST['save_reservation'])) {
        $id = $_POST['reservation_id'];
        $client_name = $_POST['client_name'];
        $vehicle_id = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : NULL;
        $reservation_date = $_POST['reservation_date'];
        $purpose = $_POST['purpose'];
        
        if (empty($id)) { // Add new reservation
            $status = 'Pending'; // New reservations are always Pending
            $reservation_code = 'R' . date('YmdHis');
            $sql = "INSERT INTO reservations (reservation_code, client_name, reserved_by_user_id, vehicle_id, reservation_date, purpose, status) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiisss", $reservation_code, $client_name, $current_user_id, $vehicle_id, $reservation_date, $purpose, $status);
        } else { // Update existing reservation
            $sql = "UPDATE reservations SET client_name=?, vehicle_id=?, reservation_date=?, purpose=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sissi", $client_name, $vehicle_id, $reservation_date, $purpose, $id);
        }
        if ($stmt->execute()) { $message = "<div class='message-banner success'>Reservation saved successfully!</div>"; } else { $message = "<div class='message-banner error'>Error saving reservation: " . $conn->error . "</div>"; }
        $stmt->close();
    }
    // Handle Accept/Reject Reservation
    elseif (isset($_POST['update_status'])) {
        $id = $_POST['reservation_id'];
        $new_status = $_POST['new_status'];
        if ($new_status === 'Confirmed' || $new_status === 'Rejected') {
            $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $id);
            if ($stmt->execute()) { $message = "<div class='message-banner success'>Reservation status updated to $new_status.</div>"; } else { $message = "<div class='message-banner error'>Error updating status.</div>"; }
            $stmt->close();
        }
    }
    // Handle Trip Logic (Add/Edit, Start, End)
    elseif (isset($_POST['save_trip'])) {
        $trip_id = $_POST['trip_id']; $vehicle_id = $_POST['vehicle_id']; $driver_id = $_POST['driver_id']; $destination = $_POST['destination']; $pickup_time = $_POST['pickup_time']; $status = $_POST['status'];
        if (empty($trip_id)) {
            $trip_code = 'T' . date('YmdHis'); $sql = "INSERT INTO trips (trip_code, vehicle_id, driver_id, destination, pickup_time, status) VALUES (?, ?, ?, ?, ?, ?)"; $stmt = $conn->prepare($sql); $stmt->bind_param("siisss", $trip_code, $vehicle_id, $driver_id, $destination, $pickup_time, $status);
            if ($stmt->execute()) { $message = "<div class='message-banner success'>New trip scheduled successfully!</div>"; } else { $message = "<div class='message-banner error'>Error: " . $conn->error . "</div>"; }
        } else {
            $sql = "UPDATE trips SET vehicle_id=?, driver_id=?, destination=?, pickup_time=?, status=? WHERE id=?"; $stmt = $conn->prepare($sql); $stmt->bind_param("iisssi", $vehicle_id, $driver_id, $destination, $pickup_time, $status, $trip_id);
            if ($stmt->execute()) { $message = "<div class='message-banner success'>Trip updated successfully!</div>"; } else { $message = "<div class='message-banner error'>Error: " . $conn->error . "</div>"; }
        }
        $stmt->close();
    } elseif (isset($_POST['start_trip_log'])) {
        $trip_id = $_POST['log_trip_id']; $location_name = $_POST['location_name']; $status_message = $_POST['status_message']; $latitude = null; $longitude = null;
        
        $apiUrl = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($location_name) . "&countrycodes=PH&limit=1";
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, $apiUrl); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        curl_setopt($ch, CURLOPT_USERAGENT, 'SLATE Logistics/1.0'); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $geo_response_json = curl_exec($ch); 
        $curl_error = curl_error($ch); 
        curl_close($ch);
        
        $geocoding_successful = false;
        if ($curl_error) { $message = "<div class='message-banner error'>cURL Error: " . htmlspecialchars($curl_error) . "</div>";
        } elseif ($geo_response_json) { $geo_response = json_decode($geo_response_json, true); if (isset($geo_response[0]['lat'])) { $latitude = $geo_response[0]['lat']; $longitude = $geo_response[0]['lon']; $geocoding_successful = true; } }
        
        if ($geocoding_successful) {
            $conn->begin_transaction();
            try {
                $stmt1 = $conn->prepare("UPDATE trips SET status = 'En Route' WHERE id = ?"); $stmt1->bind_param("i", $trip_id); $stmt1->execute(); $stmt1->close();
                $stmt2 = $conn->prepare("INSERT INTO tracking_log (trip_id, latitude, longitude, status_message) VALUES (?, ?, ?, ?)"); $stmt2->bind_param("idds", $trip_id, $latitude, $longitude, $status_message); $stmt2->execute(); $stmt2->close();
                $conn->commit();
                $message = "<div class='message-banner success'>Trip started from '$location_name' and is now live on all maps.</div>";
                
                // --- SIMULAN ANG FIREBASE PUSH ---
                $trip_details_stmt = $conn->prepare("SELECT v.type, v.model, d.name as driver_name FROM trips t JOIN vehicles v ON t.vehicle_id = v.id JOIN drivers d ON t.driver_id = d.id WHERE t.id = ?");
                $trip_details_stmt->bind_param("i", $trip_id);
                $trip_details_stmt->execute();
                $trip_details_result = $trip_details_stmt->get_result();
                if ($trip_details = $trip_details_result->fetch_assoc()) {
                    $firebase_data = [
                        'lat' => (float)$latitude,
                        'lng' => (float)$longitude,
                        'speed' => 0,
                        'timestamp' => date('c'),
                        'vehicle_info' => $trip_details['type'] . ' ' . $trip_details['model'],
                        'driver_name' => $trip_details['driver_name']
                    ];
                    $firebase_url = "https://slate49-cde60-default-rtdb.firebaseio.com/live_tracking/" . $trip_id . ".json";
                    $json_data = json_encode($firebase_data);
                    $ch_firebase = curl_init();
                    curl_setopt($ch_firebase, CURLOPT_URL, $firebase_url);
                    curl_setopt($ch_firebase, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch_firebase, CURLOPT_CUSTOMREQUEST, "PUT");
                    curl_setopt($ch_firebase, CURLOPT_POSTFIELDS, $json_data);
                    curl_setopt($ch_firebase, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                    curl_setopt($ch_firebase, CURLOPT_SSL_VERIFYPEER, false);
                    curl_exec($ch_firebase);
                    if (curl_errno($ch_firebase)) {
                        $message .= " <br><span style='color:orange; font-size:0.9em;'>Warning: Could not push initial state to Firebase: " . curl_error($ch_firebase) . "</span>";
                    }
                    curl_close($ch_firebase);
                }
                $trip_details_stmt->close();
                // --- WAKAS NG FIREBASE PUSH ---

            } catch (mysqli_sql_exception $exception) { $conn->rollback(); $message = "<div class='message-banner error'>Error: " . $exception->getMessage() . "</div>"; }
        } else { if (empty($message)) { $message = "<div class='message-banner error'>Could not find coordinates for: '" . htmlspecialchars($location_name) . "'.</div>"; } }
    } elseif (isset($_POST['end_trip'])) {
        $trip_id = $_POST['trip_id_to_end'];
        $stmt = $conn->prepare("UPDATE trips SET status = 'Completed' WHERE id = ?");
        $stmt->bind_param("i", $trip_id);
        if ($stmt->execute()) { $message = "<div class='message-banner success'>Trip #$trip_id marked as Completed. Vehicle removed from live map.</div>"; } else { $message = "<div class='message-banner error'>Error updating trip status in database.</div>"; }
    }
}
// Handle Delete Trip
if (isset($_GET['delete_trip'])) {
    $id = $_GET['delete_trip']; $stmt = $conn->prepare("DELETE FROM trips WHERE id = ?"); $stmt->bind_param("i", $id);
    if ($stmt->execute()) { $message = "<div class='message-banner success'>Trip deleted successfully.</div>"; } else { $message = "<div class='message-banner error'>Error deleting trip.</div>"; }
    $stmt->close();
}

// --- DATA FETCHING ---
$available_vehicles_query = $conn->query("SELECT id, type, model, load_capacity_kg, image_url FROM vehicles WHERE status IN ('Active', 'Idle') ORDER BY type, model");
$where_clauses = []; $params = []; $types = '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search_query)) { $where_clauses[] = "(r.reservation_code LIKE ? OR r.client_name LIKE ?)"; $search_term = "%{$search_query}%"; array_push($params, $search_term, $search_term); $types .= 'ss'; }
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
if (!empty($start_date)) { $where_clauses[] = "r.reservation_date >= ?"; $params[] = $start_date; $types .= 's'; }
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
if (!empty($end_date)) { $where_clauses[] = "r.reservation_date <= ?"; $params[] = $end_date; $types .= 's'; }
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
if (!empty($status_filter)) { $where_clauses[] = "r.status = ?"; $params[] = $status_filter; $types .= 's'; }

$reservations_sql = "SELECT r.*, v.type as vehicle_type, v.model as vehicle_model, u.username as reserved_by FROM reservations r LEFT JOIN vehicles v ON r.vehicle_id = v.id LEFT JOIN users u ON r.reserved_by_user_id = u.id";
if (!empty($where_clauses)) { $reservations_sql .= " WHERE " . implode(" AND ", $where_clauses); }
$reservations_sql .= " ORDER BY r.reservation_date DESC";
$stmt = $conn->prepare($reservations_sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$reservations = $stmt->get_result();
$dispatch_query = $conn->query("SELECT v.type, v.model, v.status, t.id as trip_id, t.current_location, d.name as driver_name FROM vehicles v LEFT JOIN trips t ON v.id = t.vehicle_id AND t.status = 'En Route' LEFT JOIN drivers d ON t.driver_id = d.id ORDER BY v.id");
$trips_query = "SELECT t.*, v.type AS vehicle_type, v.model AS vehicle_model, d.name AS driver_name, latest_log.latitude, latest_log.longitude FROM trips t JOIN vehicles v ON t.vehicle_id = v.id JOIN drivers d ON t.driver_id = d.id LEFT JOIN (SELECT l1.trip_id, l1.latitude, l1.longitude FROM tracking_log l1 INNER JOIN (SELECT trip_id, MAX(log_time) AS max_log_time FROM tracking_log GROUP BY trip_id) l2 ON l1.trip_id = l2.trip_id AND l1.log_time = l2.max_log_time) AS latest_log ON t.id = latest_log.trip_id WHERE t.status IN ('Scheduled', 'En Route') ORDER BY t.pickup_time DESC";
$trips = $conn->query($trips_query);
$available_vehicles_for_form = $conn->query("SELECT id, type, model FROM vehicles WHERE status IN ('Active', 'Idle')");
$available_drivers = $conn->query("SELECT id, name FROM drivers WHERE status = 'Active'");
$tracking_data_query = $conn->query("SELECT t.id as trip_id, v.type, v.model, d.name as driver_name, tl.latitude, tl.longitude FROM tracking_log tl JOIN trips t ON tl.trip_id = t.id AND t.status = 'En Route' JOIN vehicles v ON t.vehicle_id = v.id JOIN drivers d ON t.driver_id = d.id INNER JOIN ( SELECT trip_id, MAX(log_time) AS max_log_time FROM tracking_log GROUP BY trip_id) latest_log ON tl.trip_id = latest_log.trip_id AND tl.log_time = latest_log.max_log_time");
$locations = [];
if ($tracking_data_query) { while($row = $tracking_data_query->fetch_assoc()) { $locations[] = $row; } }
$locations_json = json_encode($locations);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vehicle Reservation & Dispatch System | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet.marker.slideto@0.2.0/Leaflet.Marker.SlideTo.js"></script>
  <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
  <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js"></script>
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2</div>
    <a href="landpage.php">Dashboard</a>
    <a href="FVM.php">Fleet & Vehicle Management (FVM)</a>
    <a href="VRDS.php" class="active">Vehicle Reservation & Dispatch System (VRDS)</a>
    <a href="DTPM.php">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
        <div class="hamburger" id="hamburger">☰</div>
        <div><h1>Vehicle Reservation & Dispatch System</h1></div>
        <div class="theme-toggle-container">
            <span class="theme-label">Dark Mode</span>
            <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
        </div>
    </div>
    
    <?php echo $message; ?>

    <div class="table-section">
        <h3>Available Vehicles</h3>
        <div class="vehicle-gallery">
            <?php if ($available_vehicles_query->num_rows > 0): mysqli_data_seek($available_vehicles_query, 0); ?>
                <?php while($row = $available_vehicles_query->fetch_assoc()): ?>
                <div class="vehicle-card">
                    <img src="<?php echo htmlspecialchars(!empty($row['image_url']) ? $row['image_url'] : 'https://placehold.co/400x300/e2e8f0/e2e8f0'); ?>" alt="<?php echo htmlspecialchars($row['type']); ?>" class="vehicle-image">
                    <div class="vehicle-details">
                        <div>
                            <div class="vehicle-title"><?php echo htmlspecialchars($row['type'] . ' - ' . $row['model']); ?></div>
                            <div class="vehicle-info">Capacity: <?php echo htmlspecialchars($row['load_capacity_kg']); ?> kg</div>
                        </div>
                        <button class="btn btn-primary galleryReserveBtn" data-vehicle-id="<?php echo $row['id']; ?>">Reserve</button>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No vehicles are currently available for reservation.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-section-2">
      <h3>Reservation Booking</h3>
      <div class="card" style="margin-bottom: 1.5rem; padding: 1.5rem;">
        <form action="VRDS.php" method="GET" class="filter-form">
            <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" placeholder="Code or Client" value="<?php echo htmlspecialchars($search_query); ?>"></div>
            <div class="form-group"><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>"></div>
            <div class="form-group"><label>End Date</label><input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>"></div>
            <div class="form-group"><label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <option value="Pending" <?php if($status_filter == 'Pending') echo 'selected'; ?>>Pending</option>
                    <option value="Confirmed" <?php if($status_filter == 'Confirmed') echo 'selected'; ?>>Confirmed</option>
                    <option value="Rejected" <?php if($status_filter == 'Rejected') echo 'selected'; ?>>Rejected</option>
                    <option value="Cancelled" <?php if($status_filter == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                </select>
            </div>
            <div class="form-actions" style="grid-column: 1 / -1;"><button type="submit" class="btn btn-primary">Filter</button><a href="VRDS.php" class="btn btn-secondary">Reset</a></div>
        </form>
      </div>
      <div style="margin-bottom: 1rem; display: flex; gap: 0.5rem;">
        <button id="createReservationBtn" class="btn btn-primary">Create Reservation</button>
        <a href="VRDS.php?download_csv=true&<?php echo http_build_query($_GET); ?>" class="btn btn-success">Download Report (CSV)</a>
      </div>
      <table>
        <thead><tr><th>Code</th><th>Client</th><th>Reserved By</th><th>Vehicle Details</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($reservations->num_rows > 0): mysqli_data_seek($reservations, 0); while($row = $reservations->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['reservation_code']); ?></td><td><?php echo htmlspecialchars($row['client_name']); ?></td><td><?php echo htmlspecialchars($row['reserved_by'] ?? 'N/A'); ?></td><td><?php echo htmlspecialchars(($row['vehicle_type'] ?? 'Not Assigned') . ' ' . ($row['vehicle_model'] ?? '')); ?></td><td><?php echo htmlspecialchars($row['reservation_date']); ?></td><td><span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                <td>
                    <button class="btn btn-info btn-sm viewReservationBtn" data-details='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'>View</button>
                    <?php if ($row['status'] == 'Pending'): ?>
                    <form action="VRDS.php" method="POST" style="display: inline;">
                        <input type="hidden" name="reservation_id" value="<?php echo $row['id']; ?>">
                        <input type="hidden" name="new_status" value="Confirmed">
                        <button type="submit" name="update_status" class="btn btn-success btn-sm">Accept</button>
                    </form>
                    <form action="VRDS.php" method="POST" style="display: inline;">
                        <input type="hidden" name="reservation_id" value="<?php echo $row['id']; ?>">
                        <input type="hidden" name="new_status" value="Rejected">
                        <button type="submit" name="update_status" class="btn btn-danger btn-sm">Reject</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; else: ?><tr><td colspan="7">No reservations found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-section-2">
      <h3>Dispatch Control</h3>
       <button id="viewMapBtn" class="btn btn-info" style="margin-bottom: 1rem;">View Live Map</button>
      <table>
        <thead><tr><th>Who Assign</th><th>Vehicle</th><th>Current Location</th><th>Status</th></tr></thead>
        <tbody id="dispatch-table-body">
            <?php if ($dispatch_query && $dispatch_query->num_rows > 0): while($row = $dispatch_query->fetch_assoc()): ?>
            <tr <?php if ($row['status'] == 'En Route' && !empty($row['trip_id'])): ?>
                    class="clickable-row" 
                    data-tripid="<?php echo $row['trip_id']; ?>"
                <?php endif; ?>>
                <td><?php echo htmlspecialchars($row['driver_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                <td><?php echo htmlspecialchars($row['current_location'] ?? 'Depot'); ?></td>
                <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
            </tr>
            <?php endwhile; else: ?><tr><td colspan="4">No dispatch data available.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-section-2">
      <h3>Scheduled Trips</h3>
       <div style="margin-bottom: 1rem;"><button id="createTripBtn" class="btn btn-success">Create New Trip</button></div>
      <table>
        <thead><tr><th>Trip Code</th><th>Vehicle</th><th>Driver</th><th>Pickup Time</th><th>Destination</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($trips && $trips->num_rows > 0): mysqli_data_seek($trips, 0); while($row = $trips->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['trip_code']); ?></td>
                <td><?php echo htmlspecialchars($row['vehicle_type'] . ' ' . $row['vehicle_model']); ?></td>
                <td><?php echo htmlspecialchars($row['driver_name']); ?></td>
                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['pickup_time']))); ?></td>
                <td><?php echo htmlspecialchars($row['destination']); ?></td>
                <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                <td class="action-buttons">
                    <button class="btn btn-warning btn-sm editTripBtn" data-id="<?php echo $row['id']; ?>" data-vehicle_id="<?php echo $row['vehicle_id']; ?>" data-driver_id="<?php echo $row['driver_id']; ?>" data-destination="<?php echo htmlspecialchars($row['destination']); ?>" data-pickup_time="<?php echo substr(str_replace(' ', 'T', $row['pickup_time']), 0, 16); ?>" data-status="<?php echo $row['status']; ?>">Edit</button>
                    <?php if ($row['status'] == 'Scheduled'): ?><button class="btn btn-success btn-sm startTripBtn" data-trip_id="<?php echo $row['id']; ?>">Start Trip</button><?php endif; ?>
                    <?php if ($row['status'] == 'En Route'): ?><button class="btn btn-danger btn-sm endTripBtn" data-trip_id="<?php echo $row['id']; ?>">End Trip</button><?php endif; ?>
                    <?php if ($row['status'] == 'En Route' && !empty($row['latitude'])): ?><button class="btn btn-info btn-sm viewTripOnMapBtn" data-trip_id="<?php echo $row['id']; ?>">View on Map</button><?php endif; ?>
                    <a href="VRDS.php?delete_trip=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                </td>
            </tr>
            <?php endwhile; else: ?><tr><td colspan="7">No active or scheduled trips found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- MODALS -->
  <div id="reservationModal" class="modal"><div class="modal-content" style="max-width: 800px;"><span class="close-button">&times;</span><h2 id="reservationModalTitle">Create Reservation</h2><div id="reservationModalBody"></div></div></div>
  <div id="viewReservationModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2>Reservation Details</h2><div id="viewReservationBody"></div></div></div>
  <div id="tripFormModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2 id="tripFormTitle">Schedule New Trip</h2><form action="VRDS.php" method="POST"><input type="hidden" name="trip_id" id="trip_id"><div class="form-group"><label>Vehicle</label><select name="vehicle_id" class="form-control" required><option value="">-- Choose --</option><?php mysqli_data_seek($available_vehicles_for_form, 0); while($v = $available_vehicles_for_form->fetch_assoc()) { echo "<option value='{$v['id']}'>" . htmlspecialchars($v['type'] . ' - ' . $v['model']) . "</option>"; } ?></select></div><div class="form-group"><label>Driver</label><select name="driver_id" class="form-control" required><option value="">-- Choose --</option><?php mysqli_data_seek($available_drivers, 0); while($d = $available_drivers->fetch_assoc()) { echo "<option value='{$d['id']}'>" . htmlspecialchars($d['name']) . "</option>"; } ?></select></div><div class="form-group"><label>Destination</label><input type="text" name="destination" class="form-control" required></div><div class="form-group"><label>Pickup Time</label><input type="datetime-local" name="pickup_time" class="form-control" required></div><div class="form-group"><label>Status</label><select name="status" id="trip_status_select" class="form-control" required><option value="Scheduled">Scheduled</option><option value="En Route">En Route</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div><div class="form-actions"><button type="button" class="btn btn-secondary cancelBtn">Cancel</button><button type="submit" name="save_trip" class="btn btn-primary">Save Trip</button></div></form></div></div>
  <div id="startTripModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2>Start Trip & Log Initial Location</h2><form action="VRDS.php" method="POST"><input type="hidden" name="log_trip_id" id="log_trip_id"><div class="form-group"><label>Starting Location</label><input type="text" name="location_name" id="location_name" class="form-control" placeholder="e.g., SM North EDSA, Quezon City" required></div><div class="form-group"><label>Status Message</label><input type="text" name="status_message" id="status_message" class="form-control" value="Trip Started" required></div><div class="form-actions"><button type="button" class="btn btn-secondary cancelBtn">Cancel</button><button type="submit" name="start_trip_log" class="btn btn-success">Confirm & Start</button></div></form></div></div>
  <div id="mapModal" class="modal"><div class="modal-content" style="max-width: 900px;"><span class="close-button">&times;</span><h2>Live Dispatch Map</h2><div id="dispatchMap" style="height: 500px; width: 100%; border-radius: 0.35rem;"></div></div></div>
  <form id="endTripForm" action="VRDS.php" method="POST" style="display: none;"><input type="hidden" name="trip_id_to_end" id="trip_id_to_end"><input type="hidden" name="end_trip" value="1"></form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Standard page scripts ---
    document.getElementById('themeToggle').addEventListener('change', function() { document.body.classList.toggle('dark-mode', this.checked); });
    document.getElementById('hamburger').addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar'); const mainContent = document.getElementById('mainContent');
      if (window.innerWidth <= 992) { sidebar.classList.toggle('show'); } else { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('expanded'); }
    });
    document.querySelectorAll('.modal').forEach(modal => {
        const closeBtn = modal.querySelector('.close-button'); const cancelBtn = modal.querySelector('.cancelBtn');
        if(closeBtn) { closeBtn.onclick = () => modal.style.display = 'none'; }
        if(cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == modal) { modal.style.display = 'none'; } });
    });

    // --- Reservation Modal & Route Optimizer Logic ---
    const reservationModal = document.getElementById("reservationModal");
    const reservationModalTitle = document.getElementById("reservationModalTitle");
    const reservationModalBody = document.getElementById("reservationModalBody");

    function showReservationModal(title, data = {}) {
        reservationModalTitle.innerHTML = title;
        const formHtml = 
            `<form action='VRDS.php' method='POST'>
                <input type='hidden' name='reservation_id' value="${data.id || ''}">
                <div class='form-group'><label>Client</label><input type='text' name='client_name' class='form-control' value="${data.client_name || ''}" required></div>
                <div class='form-group'><label>Vehicle</label><select name='vehicle_id' class='form-control'><option value="">-- Optional: Assign Later --</option><?php mysqli_data_seek($available_vehicles_for_form, 0); while($v = $available_vehicles_for_form->fetch_assoc()) { echo "<option value='{$v['id']}'>" . htmlspecialchars($v['type'] . ' - ' . $v['model']) . "</option>"; } ?></select></div>
                <div class='form-group'><label>Date</label><input type='date' name='reservation_date' class='form-control' value="${data.reservation_date || ''}" required></div>
                
                <hr style="margin: 1.5rem 0; border-color: rgba(0,0,0,0.1);">
                <h4 style="margin-bottom: 1rem;">Route Planner (Waze-like)</h4>
                <div class="form-group"><label>Start Location</label><input type="text" id="startLocation" class="form-control" placeholder="e.g., FCM, Quezon City"></div>
                <div class="form-group"><label>Destination</label><input type="text" id="endLocation" class="form-control" placeholder="e.g., SM North Edsa, Quezon City"></div>
                <button type="button" id="findRouteBtn" class="btn btn-info">Find Best Route</button>
                <div id="route-output" style="display:none; margin-top:1rem;">
                    <div id="routeMapPreview"></div>
                    <div id="route-details" class="route-results-grid"></div>
                </div>
                <hr style="margin: 1.5rem 0; border-color: rgba(0,0,0,0.1);">
                
                <div class='form-group'><label>Purpose / Route Details</label><textarea name='purpose' id='purpose-textarea' class='form-control' rows="4">${data.purpose || ''}</textarea></div>
                <div class='form-actions'><button type='button' class='btn btn-secondary cancelBtn'>Cancel</button><button type='submit' name='save_reservation' class='btn btn-primary'>Save Reservation</button></div>
            </form>`;
        reservationModalBody.innerHTML = formHtml;
        if(data.vehicle_id) { reservationModal.querySelector('select[name="vehicle_id"]').value = data.vehicle_id; }
        const localCancelBtn = reservationModalBody.querySelector('.cancelBtn');
        if(localCancelBtn) { localCancelBtn.onclick = () => reservationModal.style.display = 'none'; }
        
        attachRoutePlannerEvents();
        reservationModal.style.display = "block";
    }

    function attachRoutePlannerEvents() {
        const findRouteBtn = document.getElementById('findRouteBtn');
        if(!findRouteBtn) return;

        let routeMapPreview;
        let routeLayer;

        findRouteBtn.addEventListener('click', async () => {
            const start = document.getElementById('startLocation').value;
            const end = document.getElementById('endLocation').value;
            const routeOutput = document.getElementById('route-output');
            const routeDetails = document.getElementById('route-details');
            const purposeTextarea = document.getElementById('purpose-textarea');

            if (!start || !end) {
                alert('Please enter both Start and End locations.');
                return;
            }

            findRouteBtn.textContent = 'Calculating...';
            findRouteBtn.disabled = true;
            routeDetails.innerHTML = 'Calculating...';
            routeOutput.style.display = 'block';

            try {
                if (!routeMapPreview) {
                    routeMapPreview = L.map('routeMapPreview').setView([14.6, 121], 10);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(routeMapPreview);
                }

                // Use the proxy for geocoding
                const startCoordsUrl = `route_proxy.php?service=geocode&q=${encodeURIComponent(start)}`;
                const endCoordsUrl = `route_proxy.php?service=geocode&q=${encodeURIComponent(end)}`;
                
                const [startResponse, endResponse] = await Promise.all([fetch(startCoordsUrl), fetch(endCoordsUrl)]);
                
                if (!startResponse.ok || !endResponse.ok) { throw new Error(`Geocoding failed.`); }

                const startData = await startResponse.json();
                const endData = await endResponse.json();

                if (startData.length === 0 || endData.length === 0) throw new Error('Could not find one or both locations.');

                const startCoords = { lat: startData[0].lat, lon: startData[0].lon };
                const endCoords = { lat: endData[0].lat, lon: endData[0].lon };

                // Use the proxy for routing (no alternatives)
                const directionsUrl = `route_proxy.php?service=route&coords=${startCoords.lon},${startCoords.lat};${endCoords.lon},${endCoords.lat}`;
                const directionsResponse = await fetch(directionsUrl);
                
                if (!directionsResponse.ok) { throw new Error(`Routing failed.`); }

                const directionsData = await directionsResponse.json();

                if (directionsData.code !== 'Ok' || directionsData.routes.length === 0) throw new Error('No route found.');

                const route = directionsData.routes[0];
                const distanceKm = (route.distance / 1000).toFixed(2);
                const durationSeconds = route.duration;
                const hours = Math.floor(durationSeconds / 3600);
                const minutes = Math.floor((durationSeconds % 3600) / 60);
                const durationFormatted = `${hours}h ${minutes}m`;

                routeDetails.innerHTML = `
                    <strong>Distance:</strong><span>${distanceKm} km</span>
                    <strong>Est. Time:</strong><span>${durationFormatted}</span>
                `;
                purposeTextarea.value = `Route from ${start} to ${end}.\nDistance: ${distanceKm} km\nEst. Time: ${durationFormatted}`;
                
                if (routeLayer) routeMapPreview.removeLayer(routeLayer);
                routeLayer = L.geoJSON(route.geometry, { style: { color: '#3887be', weight: 6, opacity: 0.85 } }).addTo(routeMapPreview);
                
                setTimeout(() => {
                    routeMapPreview.invalidateSize();
                    routeMapPreview.fitBounds(routeLayer.getBounds(), { padding: [20, 20] });
                }, 10);

            } catch (error) {
                console.error("Route Planner Error:", error);
                let userMessage = `Failed to fetch route data. Please check your internet connection.`;
                if (error.message.includes('CORS')) {
                    userMessage = `A CORS security policy is blocking the request.`;
                } else if (error.message.includes('NetworkError')) {
                    userMessage = `A network error occurred. Please check internet and firewall settings.`;
                }
                routeDetails.innerHTML = `<span style="color:var(--danger-color);"><b>Error:</b> ${error.message} Please check the proxy file and your internet connection.</span>`;
            } finally {
                findRouteBtn.textContent = 'Find Best Route';
                findRouteBtn.disabled = false;
            }
        });
    }
    
    document.getElementById("createReservationBtn").addEventListener("click", () => showReservationModal("Create New Reservation"));
    document.querySelectorAll('.galleryReserveBtn').forEach(button => {
        button.addEventListener('click', () => {
            const vehicleId = button.dataset.vehicleId;
            showReservationModal("Create Reservation", { vehicle_id: vehicleId });
        });
    });
    
    const viewModal = document.getElementById("viewReservationModal");
    const viewBody = document.getElementById("viewReservationBody");
    document.querySelectorAll('.viewReservationBtn').forEach(button => {
        button.addEventListener('click', () => {
            const details = JSON.parse(button.dataset.details);
            viewBody.innerHTML = `
                <p><strong>Code:</strong> ${details.reservation_code}</p><p><strong>Client:</strong> ${details.client_name}</p>
                <p><strong>Reserved By:</strong> ${details.reserved_by || 'N/A'}</p><p><strong>Date:</strong> ${details.reservation_date}</p>
                <p><strong>Vehicle:</strong> ${details.vehicle_type || 'Not Assigned'} ${details.vehicle_model || ''}</p>
                <p><strong>Purpose / Route:</strong><br>${(details.purpose || 'Not specified').replace(/\n/g, '<br>')}</p>
                <p><strong>Status:</strong> <span class="status-badge status-${details.status.toLowerCase()}">${details.status}</span></p>
            `;
            viewModal.style.display = 'block';
        });
    });

    // --- Other Modals & Live Map Logic ---
    const tripFormModal = document.getElementById("tripFormModal"); 
    const tripFormTitle = document.getElementById("tripFormTitle");
    document.getElementById("createTripBtn").addEventListener("click", () => { 
        const tripForm = tripFormModal.querySelector('form'); tripForm.reset(); tripForm.querySelector('#trip_id').value = ''; 
        tripFormTitle.textContent = "Schedule New Trip"; tripFormModal.style.display = "block"; 
    });
    document.querySelectorAll('.editTripBtn').forEach(button => { 
        button.addEventListener('click', () => { 
            const tripForm = tripFormModal.querySelector('form'); tripForm.reset(); 
            const tripId = button.dataset.id;
            tripForm.querySelector('#trip_id').value = tripId;
            tripForm.querySelector('select[name="vehicle_id"]').value = button.dataset.vehicle_id; 
            tripForm.querySelector('select[name="driver_id"]').value = button.dataset.driver_id; 
            tripForm.querySelector('input[name="destination"]').value = button.dataset.destination; 
            tripForm.querySelector('input[name="pickup_time"]').value = button.dataset.pickup_time; 
            const statusSelect = tripForm.querySelector('#trip_status_select');
            statusSelect.innerHTML = '';
            const statuses = ['Scheduled', 'En Route', 'Completed', 'Cancelled'];
            statuses.forEach(s => { statusSelect.innerHTML += `<option value="${s}">${s}</option>`; });
            statusSelect.value = button.dataset.status; 
            tripFormTitle.textContent = "Edit Trip Details"; 
            tripFormModal.style.display = "block"; 
        }); 
    });
    const startTripModal = document.getElementById("startTripModal");
    document.querySelectorAll('.startTripBtn').forEach(button => { 
        button.addEventListener('click', () => { 
            startTripModal.querySelector('#log_trip_id').value = button.dataset.trip_id; 
            startTripModal.style.display = "block"; 
        }); 
    });
    const mapModal = document.getElementById("mapModal");
    let map; let markers = {}; let firebaseInitialized = false;
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
    function getVehicleIcon(vehicleInfo) {
        let svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#858796"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11C5.84 5 5.28 5.42 5.08 6.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>`;
        if (vehicleInfo.toLowerCase().includes('truck')) { svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#4e73df"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm13.5-1.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM18 10h1.5v3H18v-3zM3 6h12v7H3V6z"/></svg>`; }
        else if (vehicleInfo.toLowerCase().includes('van')) { svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1cc88a"><path d="M20 8H4V6h16v2zm-2.17-3.24L15.21 2.14A1 1 0 0014.4 2H5a2 2 0 00-2 2v13c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7c0-.98-.71-1.8-1.65-1.97l-2.52-.27zM6.5 18c-.83 0-1.5-.67-1.5-1.5S5.67 15 6.5 15s1.5.67 1.5 1.5S7.33 18 6.5 18zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5zM4 13h16V9H4v4z"/></svg>`; }
        return L.divIcon({ html: svgIcon, className: 'vehicle-icon', iconSize: [40, 40], iconAnchor: [20, 40] });
    }
    function initializeFirebaseListener() {
        if (firebaseInitialized) return;
        try {
            if (!firebase.apps.length) firebase.initializeApp(firebaseConfig); else firebase.app();
            const database = firebase.database(); const trackingRef = database.ref('live_tracking');
            trackingRef.on('child_added', (s) => handleDataChange(s.key, s.val()));
            trackingRef.on('child_changed', (s) => handleDataChange(s.key, s.val()));
            trackingRef.on('child_removed', (s) => { if (map && markers[s.key]) { map.removeLayer(markers[s.key]); delete markers[s.key]; } });
            firebaseInitialized = true;
        } catch(e) { console.error("Firebase init error:", e); }
    }
    function handleDataChange(tripId, data) {
        if (!map || !data.vehicle_info) return;
        const newLatLng = [data.lat, data.lng];
        if (!markers[tripId]) {
            const vehicleIcon = getVehicleIcon(data.vehicle_info);
            const popupContent = `<b>Vehicle:</b> ${data.vehicle_info}<br><b>Driver:</b> ${data.driver_name}`;
            markers[tripId] = L.marker(newLatLng, {icon: vehicleIcon}).addTo(map).bindPopup(popupContent);
            markers[tripId].options.duration = 2000;
        } else {
            markers[tripId].setIcon(getVehicleIcon(data.vehicle_info));
            markers[tripId].slideTo(newLatLng, { duration: 2000 });
        }
    }
    document.getElementById("viewMapBtn").addEventListener("click", function(){
        mapModal.style.display = "block";
        if (!map) {
            map = L.map('dispatchMap').setView([12.8797, 121.7740], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
            const initialLocations = <?php echo $locations_json; ?>;
            initialLocations.forEach(loc => handleDataChange(loc.trip_id, {lat: loc.latitude, lng: loc.longitude, vehicle_info: `${loc.type} ${loc.model}`, driver_name: loc.driver_name}));
            initializeFirebaseListener();
        }
        setTimeout(() => map.invalidateSize(), 10);
    });

    document.querySelectorAll('#dispatch-table-body .clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const tripId = row.dataset.tripid;
            if (tripId) {
                document.getElementById("viewMapBtn").click();
                setTimeout(() => {
                    if (markers[tripId]) {
                        map.flyTo(markers[tripId].getLatLng(), 14);
                        markers[tripId].openPopup();
                    }
                }, 500);
            }
        });
    });

    document.querySelectorAll('.viewTripOnMapBtn').forEach(button => {
        button.addEventListener('click', () => {
            const tripId = button.dataset.trip_id;
            document.getElementById("viewMapBtn").click();
            setTimeout(() => { if (markers[tripId]) { map.flyTo(markers[tripId].getLatLng(), 14); markers[tripId].openPopup(); } }, 500);
        });
    });
    document.querySelectorAll('.endTripBtn').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault(); const tripId = button.dataset.trip_id;
            if (confirm(`Are you sure you want to end Trip #${tripId}?`)) {
                if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
                firebase.database().ref('live_tracking/' + tripId).remove();
                document.getElementById('trip_id_to_end').value = tripId;
                document.getElementById('endTripForm').submit();
            }
        });
    });
});
</script>
</body>
</html>
