<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';

// --- Filtering and Searching Logic ---
$where_clauses = [];
$params = [];
$types = '';

// Search Query
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
if (!empty($search_query)) {
    $where_clauses[] = "(t.trip_code LIKE ? OR t.destination LIKE ? OR v.type LIKE ? OR v.model LIKE ?)";
    $search_term = "%" . $search_query . "%";
    array_push($params, $search_term, $search_term, $search_term, $search_term);
    $types .= 'ssss';
}

// Date Range Filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "DATE(t.pickup_time) BETWEEN ? AND ?";
    array_push($params, $start_date, $end_date);
    $types .= 'ss';
}

// Driver Filter
$driver_id = isset($_GET['driver_id']) && is_numeric($_GET['driver_id']) ? (int)$_GET['driver_id'] : '';
if (!empty($driver_id)) {
    $where_clauses[] = "t.driver_id = ?";
    $params[] = $driver_id;
    $types .= 'i';
}

// Status Filter
$status = isset($_GET['status']) ? $_GET['status'] : '';
if (!empty($status)) {
    $where_clauses[] = "t.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Construct the final query to include latest location for map functionality
$sql = "
    SELECT
        t.*,
        v.type AS vehicle_type,
        v.model AS vehicle_model,
        d.name AS driver_name,
        latest_log.latitude,
        latest_log.longitude
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    LEFT JOIN (
        SELECT l1.trip_id, l1.latitude, l1.longitude
        FROM tracking_log l1
        INNER JOIN (
            SELECT trip_id, MAX(log_time) AS max_log_time
            FROM tracking_log
            GROUP BY trip_id
        ) l2 ON l1.trip_id = l2.trip_id AND l1.log_time = l2.max_log_time
    ) AS latest_log ON t.id = latest_log.trip_id
";


if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY t.pickup_time DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$trip_history_result = $stmt->get_result();

// Fetch all results into an array for map and table
$trip_history_data = [];
while ($row = $trip_history_result->fetch_assoc()) {
    $trip_history_data[] = $row;
}
$trip_history_json = json_encode($trip_history_data);


// Fetch drivers for the filter dropdown
$drivers_result = $conn->query("SELECT id, name FROM drivers ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trip History Logs | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
  <!-- Leaflet.js for Maps -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2</div>
    <a href="landpage.php">Dashboard</a>
    <a href="FVM.php">Fleet & Vehicle Management (FVM)</a>
    <a href="VRDS.php">Vehicle Reservation & Dispatch System (VRDS)</a>
    <a href="DTPM.php" class="active">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Trip History Logs</h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>
    
    <div class="card">
        <h3>Filter and Search Trips</h3>
        <form action="trip_history.php" method="GET" class="filter-form">
            <div class="form-group">
                <label for="search">Search</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Trip code, destination, vehicle..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="form-group">
                <label for="driver_id">Driver</label>
                <select name="driver_id" id="driver_id" class="form-control">
                    <option value="">All Drivers</option>
                    <?php while($driver = $drivers_result->fetch_assoc()): ?>
                        <option value="<?php echo $driver['id']; ?>" <?php if($driver_id == $driver['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($driver['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="Scheduled" <?php if($status == 'Scheduled') echo 'selected'; ?>>Scheduled</option>
                    <option value="En Route" <?php if($status == 'En Route') echo 'selected'; ?>>En Route</option>
                    <option value="Completed" <?php if($status == 'Completed') echo 'selected'; ?>>Completed</option>
                    <option value="Delayed" <?php if($status == 'Delayed') echo 'selected'; ?>>Delayed</option>
                    <option value="Cancelled" <?php if($status == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                    <option value="Breakdown" <?php if($status == 'Breakdown') echo 'selected'; ?>>Breakdown</option>
                    <option value="Idle" <?php if($status == 'Idle') echo 'selected'; ?>>Idle</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="trip_history.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- Map Container -->
    <div class="card map-container-full" style="margin-top: 1.5rem;">
        <h3>Trip Locations Map</h3>
        <div id="tripHistoryMap" style="height: 450px; width: 100%; border-radius: var(--border-radius);"></div>
    </div>

    <div class="table-section-2">
      <h3>All Trips</h3>
      <table>
        <thead>
          <tr><th>Trip Code</th><th>Vehicle</th><th>Driver</th><th>Pickup Time</th><th>Destination</th><th>Status</th></tr>
        </thead>
        <tbody>
            <?php if (count($trip_history_data) > 0): ?>
                <?php foreach($trip_history_data as $row): ?>
                <tr class="clickable-row" 
                    data-tripid="<?php echo $row['id']; ?>"
                    <?php if(!empty($row['latitude'])): ?>
                        data-lat="<?php echo $row['latitude']; ?>"
                        data-lng="<?php echo $row['longitude']; ?>"
                        style="cursor: pointer;"
                    <?php endif; ?>>
                    <td><?php echo htmlspecialchars($row['trip_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['vehicle_type'] . ' ' . $row['vehicle_model']); ?></td>
                    <td><?php echo htmlspecialchars($row['driver_name']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['pickup_time']))); ?></td>
                    <td><?php echo htmlspecialchars($row['destination']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">No trips found matching your criteria.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <style>
    .filter-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-form .form-actions {
        grid-column: 1 / -1; /* Span across all columns */
        justify-content: flex-start;
    }
  </style>

  <script>
    document.getElementById('themeToggle').addEventListener('change', function() { document.body.classList.toggle('dark-mode', this.checked); });
    document.getElementById('hamburger').addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      if (window.innerWidth <= 992) { sidebar.classList.toggle('show'); } 
      else { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('expanded'); }
    });

    // --- Live Tracking Map Logic ---
    const tripHistoryData = <?php echo $trip_history_json; ?>;
    const map = L.map('tripHistoryMap').setView([12.8797, 121.7740], 6); // Centered on the Philippines
    const markers = {};
    const markerLayer = L.layerGroup().addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Function to populate map with markers
    function populateMap(data) {
        markerLayer.clearLayers(); // Clear existing markers
        const bounds = [];

        data.forEach(trip => {
            if (trip.latitude && trip.longitude) {
                const popupContent = `<b>Trip:</b> ${trip.trip_code}<br><b>Vehicle:</b> ${trip.vehicle_type} ${trip.vehicle_model}<br><b>Driver:</b> ${trip.driver_name}<br><b>Status:</b> ${trip.status}`;
                const marker = L.marker([trip.latitude, trip.longitude])
                    .bindPopup(popupContent);
                
                markerLayer.addLayer(marker);
                markers[trip.id] = marker; // Store marker by trip ID
                bounds.push([trip.latitude, trip.longitude]);
            }
        });

        // Fit map to show all markers if there are any
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [50, 50] });
        } else {
             map.setView([12.8797, 121.7740], 6); // Reset to default view if no results
        }
    }
    
    // Initial map population
    populateMap(tripHistoryData);


    // Add click listeners to table rows
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const lat = row.dataset.lat;
            const lng = row.dataset.lng;
            const tripId = row.dataset.tripid;
            
            // Pan to marker only if it has coordinates and exists on map
            if (lat && lng && markers[tripId]) {
                map.flyTo([lat, lng], 14); // Zoom in on the location
                markers[tripId].openPopup(); // Open the corresponding marker's popup
            }
        });
    });
  </script>
</body>
</html>

