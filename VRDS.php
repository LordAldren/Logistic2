<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';
$message = '';

// Handle Add/Edit Reservation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_reservation'])) {
    $id = $_POST['reservation_id'];
    $client_name = $_POST['client_name'];
    $vehicle_id = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : NULL;
    $reservation_date = $_POST['reservation_date'];
    $status = $_POST['status'];

    if (empty($id)) { // Add
        $reservation_code = 'R' . date('YmdHis');
        $sql = "INSERT INTO reservations (reservation_code, client_name, vehicle_id, reservation_date, status) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiss", $reservation_code, $client_name, $vehicle_id, $reservation_date, $status);
    } else { // Update
        $sql = "UPDATE reservations SET client_name=?, vehicle_id=?, reservation_date=?, status=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sissi", $client_name, $vehicle_id, $reservation_date, $status, $id);
    }

    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Reservation saved successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error saving reservation: " . $conn->error . "</div>";
    }
    $stmt->close();
}

// Handle Delete Reservation
if(isset($_GET['delete_reservation'])) {
    $id = $_GET['delete_reservation'];
    $stmt = $conn->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()){
        $message = "<div class='message-banner success'>Reservation deleted successfully.</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting reservation.</div>";
    }
    $stmt->close();
}

// Handle Add/Edit Trip
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_trip'])) {
    $trip_id = $_POST['trip_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $driver_id = $_POST['driver_id'];
    $destination = $_POST['destination'];
    $pickup_time = $_POST['pickup_time'];
    $status = $_POST['status'];

    if (empty($trip_id)) { // Add new trip
        $trip_code = 'T' . date('YmdHis');
        $sql = "INSERT INTO trips (trip_code, vehicle_id, driver_id, destination, pickup_time, status) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("siisss", $trip_code, $vehicle_id, $driver_id, $destination, $pickup_time, $status);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>New trip scheduled successfully!</div>";
        } else {
            $message = "<div class='message-banner error'>Error scheduling trip: " . $conn->error . "</div>";
        }
    } else { // Update existing trip
        $sql = "UPDATE trips SET vehicle_id=?, driver_id=?, destination=?, pickup_time=?, status=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisssi", $vehicle_id, $driver_id, $destination, $pickup_time, $status, $trip_id);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Trip updated successfully!</div>";
        } else {
            $message = "<div class='message-banner error'>Error updating trip: " . $conn->error . "</div>";
        }
    }
    $stmt->close();
}

// Handle Delete Trip
if (isset($_GET['delete_trip'])) {
    $id = $_GET['delete_trip'];
    $stmt = $conn->prepare("DELETE FROM trips WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Trip deleted successfully.</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting trip. It might be linked to other records.</div>";
    }
    $stmt->close();
}


// Fetch Data
$reservations = $conn->query("SELECT r.*, v.type as vehicle_type, v.model as vehicle_model FROM reservations r LEFT JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.reservation_date DESC");
$dispatch = $conn->query("SELECT v.type, v.model, v.status, t.current_location, d.name as driver_name 
                          FROM vehicles v 
                          LEFT JOIN trips t ON v.id = t.vehicle_id AND t.status IN ('En Route', 'In Progress')
                          LEFT JOIN drivers d ON v.assigned_driver_id = d.id
                          ORDER BY v.id");

// Modified query for Trips to include latest location for map functionality
$trips_query = "
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
    ORDER BY t.pickup_time DESC
";
$trips = $conn->query($trips_query);

$available_vehicles = $conn->query("SELECT id, type, model FROM vehicles WHERE status IN ('Active', 'Idle')");
$available_drivers = $conn->query("SELECT id, name FROM drivers WHERE status = 'Active'");

// Fetch live tracking data for the map, including trip_id for marker reference
$tracking_data_query = $conn->query("
    SELECT t.id as trip_id, v.type, v.model, d.name as driver_name, tl.latitude, tl.longitude
    FROM tracking_log tl
    JOIN trips t ON tl.trip_id = t.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.status = 'En Route'
");
$locations = [];
if ($tracking_data_query) {
    while($row = $tracking_data_query->fetch_assoc()) {
        $locations[] = $row;
    }
}
$locations_json = json_encode($locations);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vehicle Reservation & Dispatch System | LOGISTICS II</title>
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
      <h3>Reservation</h3>
      <button id="createReservationBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Create Reservation</button>
      <table>
        <thead>
          <tr>
            <th>Reservation Code</th><th>Client</th><th>Vehicle</th><th>Date</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
            <?php if ($reservations->num_rows > 0): ?>
                <?php while($row = $reservations->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['reservation_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                    <td><?php echo htmlspecialchars(($row['vehicle_type'] ?? 'N/A') . ' ' . ($row['vehicle_model'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($row['reservation_date']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>" style="color: black;"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td>
                        <button class="btn btn-warning btn-sm editReservationBtn" 
                            data-id="<?php echo $row['id']; ?>"
                            data-client_name="<?php echo htmlspecialchars($row['client_name']); ?>"
                            data-vehicle_id="<?php echo $row['vehicle_id']; ?>"
                            data-reservation_date="<?php echo $row['reservation_date']; ?>"
                            data-status="<?php echo $row['status']; ?>">Edit</button>
                        <a href="VRDS.php?delete_reservation=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">No reservations found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-section-2">
      <h3>Dispatch Control</h3>
       <button id="viewMapBtn" class="btn btn-info" style="margin-bottom: 1rem;">View Live Map</button>
      <table>
        <thead>
          <tr><th>Vehicle</th><th>Status</th><th>Current Location</th><th>Driver</th></tr>   
        </thead>
        <tbody>
            <?php if ($dispatch->num_rows > 0): ?>
                <?php while($row = $dispatch->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>" style="color: black;"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td><?php echo htmlspecialchars($row['current_location'] ?? 'Depot'); ?></td>
                    <td><?php echo htmlspecialchars($row['driver_name'] ?? 'N/A'); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4">No dispatch data available.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-section-2">
      <h3>Trip Scheduling</h3>
       <div style="margin-bottom: 1rem;">
           <button id="createTripBtn" class="btn btn-success">Create Trip</button>
       </div>
      <table>
        <thead>
          <tr><th>Trip Code</th><th>Vehicle</th><th>Driver</th><th>PickUp Time</th><th>Destination</th><th>Status</th><th>Actions</th></tr>   
        </thead>
        <tbody>
            <?php if ($trips->num_rows > 0): ?>
               <?php mysqli_data_seek($trips, 0); while($row = $trips->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['trip_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['vehicle_type'] . ' ' . $row['vehicle_model']); ?></td>
                    <td><?php echo htmlspecialchars($row['driver_name']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['pickup_time']))); ?></td>
                    <td><?php echo htmlspecialchars($row['destination']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>" style="color: black;"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td>
                        <button class="btn btn-warning btn-sm editTripBtn"
                            data-id="<?php echo $row['id']; ?>"
                            data-vehicle_id="<?php echo $row['vehicle_id']; ?>"
                            data-driver_id="<?php echo $row['driver_id']; ?>"
                            data-destination="<?php echo htmlspecialchars($row['destination']); ?>"
                            data-pickup_time="<?php echo substr(str_replace(' ', 'T', $row['pickup_time']), 0, 16); ?>"
                            data-status="<?php echo $row['status']; ?>">Edit</button>
                        <a href="VRDS.php?delete_trip=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this trip?');">Delete</a>
                        <?php if ($row['status'] == 'En Route' && !empty($row['latitude'])): ?>
                        <button class="btn btn-info btn-sm viewTripOnMapBtn" 
                                data-trip_id="<?php echo $row['id']; ?>"
                                data-lat="<?php echo $row['latitude']; ?>"
                                data-lng="<?php echo $row['longitude']; ?>">
                            View on Map
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7">No scheduled trips found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>  
  </div>
  
  <!-- Reservation Modal -->
  <div id="actionModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2 id="modalTitle"></h2>
        <div id="modalBody"></div>
    </div>
  </div>

  <!-- Trip Form Modal (for Create and Edit) -->
  <div id="tripFormModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2 id="tripFormTitle">Schedule New Trip</h2>
        <form action="VRDS.php" method="POST">
            <input type="hidden" name="trip_id" id="trip_id">
            <div class="form-group">
                <label for="vehicle_id">Select Vehicle</label>
                <select name="vehicle_id" class="form-control" required>
                    <option value="">-- Choose a Vehicle --</option>
                    <?php mysqli_data_seek($available_vehicles, 0); while($v = $available_vehicles->fetch_assoc()) { echo "<option value='{$v['id']}'>" . htmlspecialchars($v['type'] . ' - ' . $v['model']) . "</option>"; } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="driver_id">Select Driver</label>
                <select name="driver_id" class="form-control" required>
                    <option value="">-- Choose a Driver --</option>
                    <?php mysqli_data_seek($available_drivers, 0); while($d = $available_drivers->fetch_assoc()) { echo "<option value='{$d['id']}'>" . htmlspecialchars($d['name']) . "</option>"; } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="destination">Destination</label>
                <input type="text" name="destination" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="pickup_time">Pickup Date & Time</label>
                <input type="datetime-local" name="pickup_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" class="form-control" required>
                    <option value="Scheduled">Scheduled</option>
                    <option value="En Route">En Route</option>
                    <option value="Idle">Idle</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary cancelBtn">Cancel</button>
                <button type="submit" name="save_trip" class="btn btn-primary">Save Trip</button>
            </div>
        </form>
    </div>
  </div>
  
  <!-- Map Modal -->
  <div id="mapModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <span class="close-button">&times;</span>
        <h2>Live Dispatch Map</h2>
        <div id="dispatchMap" style="height: 500px; width: 100%; border-radius: 0.35rem;"></div>
    </div>
  </div>
     
  <script>
    document.getElementById('themeToggle').addEventListener('change', function() { document.body.classList.toggle('dark-mode', this.checked); });
    document.getElementById('hamburger').addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      if (window.innerWidth <= 992) { sidebar.classList.toggle('show'); } 
      else { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('expanded'); }
    });

    // --- Generic Modal Closer ---
    document.querySelectorAll('.modal').forEach(modal => {
        const closeBtn = modal.querySelector('.close-button');
        const cancelBtn = modal.querySelector('.cancelBtn');
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        if(cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == modal) { modal.style.display = 'none'; } });
    });

    // --- Reservation Modal Logic ---
    const reservationModal = document.getElementById("actionModal");
    function showReservationModal(title, content) {
        reservationModal.querySelector("#modalTitle").innerHTML = title;
        reservationModal.querySelector("#modalBody").innerHTML = content;
        reservationModal.style.display = "block";
    }
    
    const reservationFormHtml = `
        <form action='VRDS.php' method='POST'>
            <input type='hidden' name='reservation_id' id='reservation_id'>
            <div class='form-group'><label for='client_name'>Client Name</label><input type='text' id='client_name' name='client_name' class='form-control' required></div>
            <div class='form-group'><label for='vehicle_id'>Assign Vehicle</label><select name='vehicle_id' id='vehicle_id' class='form-control'><option value="">-- Select a Vehicle --</option><?php mysqli_data_seek($available_vehicles, 0); while($v = $available_vehicles->fetch_assoc()) { echo "<option value='{$v['id']}'>" . htmlspecialchars($v['type'] . ' - ' . $v['model']) . "</option>"; } ?></select></div>
            <div class='form-group'><label for='reservation_date'>Reservation Date</label><input type='date' id='reservation_date' name='reservation_date' class='form-control' required></div>
            <div class='form-group'><label for='status'>Status</label><select name='status' id='status' class='form-control' required><option value='Pending'>Pending</option><option value='Confirmed'>Confirmed</option><option value='Cancelled'>Cancelled</option></select></div>
            <div class='form-actions'><button type='button' class='btn btn-secondary' onclick='reservationModal.style.display="none"'>Cancel</button><button type='submit' name='save_reservation' class='btn btn-primary'>Save Reservation</button></div>
        </form>`;

    document.getElementById("createReservationBtn").addEventListener("click", function(){
        showReservationModal("Create New Reservation", reservationFormHtml);
    });

    document.querySelectorAll('.editReservationBtn').forEach(button => {
        button.addEventListener('click', () => {
            showReservationModal('Edit Reservation', reservationFormHtml);
            setTimeout(() => {
                document.getElementById('reservation_id').value = button.dataset.id;
                document.getElementById('client_name').value = button.dataset.client_name;
                document.getElementById('vehicle_id').value = button.dataset.vehicle_id;
                document.getElementById('reservation_date').value = button.dataset.reservation_date;
                document.getElementById('status').value = button.dataset.status;
            }, 50);
        });
    });

    // --- Trip Form Modal (for both Create and Edit) ---
    const tripFormModal = document.getElementById("tripFormModal");
    const tripFormTitle = document.getElementById("tripFormTitle");

    document.getElementById("createTripBtn").addEventListener("click", () => {
        tripFormModal.querySelector('form').reset();
        tripFormModal.querySelector('#trip_id').value = '';
        tripFormTitle.textContent = "Schedule New Trip";
        tripFormModal.style.display = "block";
    });

    document.querySelectorAll('.editTripBtn').forEach(button => {
        button.addEventListener('click', () => {
            tripFormModal.querySelector('form').reset();
            tripFormModal.querySelector('#trip_id').value = button.dataset.id;
            tripFormModal.querySelector('select[name="vehicle_id"]').value = button.dataset.vehicle_id;
            tripFormModal.querySelector('select[name="driver_id"]').value = button.dataset.driver_id;
            tripFormModal.querySelector('input[name="destination"]').value = button.dataset.destination;
            tripFormModal.querySelector('input[name="pickup_time"]').value = button.dataset.pickup_time;
            tripFormModal.querySelector('select[name="status"]').value = button.dataset.status;
            
            tripFormTitle.textContent = "Edit Trip Details";
            tripFormModal.style.display = "block";
        });
    });

    // --- Leaflet Map Modal Logic ---
    const mapModal = document.getElementById("mapModal");
    let map; // variable to hold the map instance
    let markers = {}; // Object to hold marker references

    document.getElementById("viewMapBtn").addEventListener("click", function(){
        mapModal.style.display = "block";
        
        if (!map) {
            map = L.map('dispatchMap').setView([12.8797, 121.7740], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            const locations = <?php echo $locations_json; ?>;
            locations.forEach(loc => {
                const popup = `<h3>${loc.type} ${loc.model}</h3><p>Driver: ${loc.driver_name}</p>`;
                const marker = L.marker([loc.latitude, loc.longitude]).addTo(map).bindPopup(popup);
                markers[loc.trip_id] = marker; // Store marker by trip_id
            });
        }
        // This timeout ensures the map container is visible before Leaflet tries to calculate its size
        setTimeout(() => map.invalidateSize(), 10);
    });

    // New logic for viewing a specific trip on the map from the trip list
    document.querySelectorAll('.viewTripOnMapBtn').forEach(button => {
        button.addEventListener('click', () => {
            const tripId = button.dataset.trip_id;

            // Open the map modal (this will also initialize the map if it's the first time)
            document.getElementById("viewMapBtn").click();

            // Wait for the modal and map to render, then fly to the marker
            setTimeout(() => {
                if (markers[tripId]) {
                    const marker = markers[tripId];
                    map.flyTo(marker.getLatLng(), 14); // Zoom in closer
                    marker.openPopup();
                } else {
                    // Fallback if the map wasn't initialized with this marker for some reason
                    const lat = button.dataset.lat;
                    const lng = button.dataset.lng;
                    if(lat && lng) {
                        map.flyTo([lat, lng], 14);
                    }
                }
            }, 500); // A small delay to ensure the map is ready
        });
    });
  </script>
</body>
</html>

