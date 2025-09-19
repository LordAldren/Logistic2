<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';
$message = '';

// Handle Driver Approval/Rejection
if ($_SESSION['role'] === 'admin' && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_driver_status'])) {
    $driver_id_to_update = $_POST['driver_id_to_update'];
    $new_status = $_POST['new_status']; // 'Active' or 'Rejected'

    if ($new_status === 'Active') {
        $stmt = $conn->prepare("UPDATE drivers SET status = 'Active' WHERE id = ?");
        $stmt->bind_param("i", $driver_id_to_update);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Driver approved and is now active.</div>";
        } else {
            $message = "<div class='message-banner error'>Error approving driver.</div>";
        }
        $stmt->close();
    } elseif ($new_status === 'Rejected') {
        // First get the user_id associated with the driver
        $user_id_query = $conn->prepare("SELECT user_id FROM drivers WHERE id = ?");
        $user_id_query->bind_param("i", $driver_id_to_update);
        $user_id_query->execute();
        $user_id_result = $user_id_query->get_result();
        if($user_id_row = $user_id_result->fetch_assoc()){
            $user_id_to_delete = $user_id_row['user_id'];
            
            // Because of ON DELETE CASCADE, deleting the user will also delete the driver record.
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id_to_delete);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Driver registration rejected and record deleted.</div>";
            } else {
                $message = "<div class='message-banner error'>Error rejecting driver.</div>";
            }
            $stmt->close();
        }
        $user_id_query->close();
    }
}


// Handle Add/Edit Driver
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_driver'])) {
    $id = $_POST['driver_id']; $name = $_POST['name']; $license_number = $_POST['license_number']; $status = $_POST['status']; $rating = $_POST['rating']; $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : NULL;
    if (empty($id)) { $sql = "INSERT INTO drivers (name, license_number, status, rating, user_id) VALUES (?, ?, ?, ?, ?)"; $stmt = $conn->prepare($sql); $stmt->bind_param("sssdi", $name, $license_number, $status, $rating, $user_id);
    } else { $sql = "UPDATE drivers SET name=?, license_number=?, status=?, rating=?, user_id=? WHERE id=?"; $stmt = $conn->prepare($sql); $stmt->bind_param("sssdii", $name, $license_number, $status, $rating, $user_id, $id); }
    if ($stmt->execute()) { $message = "<div class='message-banner success'>Driver saved successfully!</div>"; } else { $message = "<div class='message-banner error'>Error saving driver. The license number might already exist.</div>"; }
    $stmt->close();
}
if (isset($_GET['delete_driver'])) {
    $id = $_GET['delete_driver']; $sql = "DELETE FROM drivers WHERE id = ?"; $stmt = $conn->prepare($sql); $stmt->bind_param("i", $id);
    if ($stmt->execute()) { $message = "<div class='message-banner success'>Driver deleted successfully!</div>"; } else { $message = "<div class='message-banner error'>Error: Cannot delete a driver assigned to a trip.</div>"; }
    $stmt->close();
}

// Fetch Pending Drivers
$pending_drivers = $conn->query("SELECT d.id, d.name, d.license_number, u.email FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.status = 'Pending' ORDER BY d.created_at ASC");

// Fetch Active Driver Data
$drivers_query = "SELECT d.*, v.type as vehicle_type, v.model as vehicle_model, v.tag_code FROM drivers d LEFT JOIN vehicles v ON d.id = v.assigned_driver_id WHERE d.status != 'Pending' ORDER BY d.name ASC";
$drivers = $conn->query($drivers_query);
$users = $conn->query("SELECT id, username FROM users WHERE role = 'driver'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver & Trip Performance Monitoring | LOGISTICS II</title>
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
    <a href="VRDS.php">Vehicle Reservation & Dispatch System (VRDS)</a>
    <a href="DTPM.php" class="active">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
  
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Driver & Trip Performance Monitoring System</h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>
    
    <?php echo $message; ?>

    <?php if ($_SESSION['role'] === 'admin' && $pending_drivers->num_rows > 0): ?>
    <div class="table-section">
      <h3>Pending Driver Registrations</h3>
      <table>
        <thead><tr><th>Name</th><th>License No.</th><th>Email</th><th>Actions</th></tr></thead>
        <tbody>
            <?php while($row = $pending_drivers->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['license_number']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td>
                    <form action="DTPM.php" method="POST" style="display: inline-block;">
                        <input type="hidden" name="driver_id_to_update" value="<?php echo $row['id']; ?>">
                        <input type="hidden" name="new_status" value="Active">
                        <button type="submit" name="update_driver_status" class="btn btn-success btn-sm">Approve</button>
                    </form>
                    <form action="DTPM.php" method="POST" style="display: inline-block;">
                        <input type="hidden" name="driver_id_to_update" value="<?php echo $row['id']; ?>">
                        <input type="hidden" name="new_status" value="Rejected">
                        <button type="submit" name="update_driver_status" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to reject and delete this registration?');">Reject</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="table-section" style="margin-top: 2rem;">
      <h3>Live Tracking Module</h3>
       <div style="margin-bottom: 1rem;">
          <a href="trip_history.php" class="btn btn-info">Trip History Logs</a>
       </div>
      <div id="liveTrackingMap" style="height: 400px; width: 100%; border-radius: var(--border-radius); margin-bottom: 1.5rem;"></div>
      <table>
        <thead>
          <tr><th>Vehicle</th><th>Driver</th><th>Location (Lat, Lng)</th><th>Speed</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody id="tracking-table-body">
            <!-- Rows are populated by JavaScript -->
             <tr id="no-tracking-placeholder"><td colspan="6">No live tracking data available. Waiting for live feed...</td></tr>
        </tbody>
      </table>
    </div>      

    <div class="table-section-2">
      <h3>Driver Profiles</h3>
      <button id="addDriverBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Add Driver</button>
      <table>
        <thead>
          <tr><th>ID</th><th>Name</th><th>Assigned Vehicle</th><th>Status</th><th>Rating</th><th>Actions</th></tr>   
        </thead>
        <tbody>
            <?php if($drivers->num_rows > 0): mysqli_data_seek($drivers, 0); ?>
               <?php while($row = $drivers->fetch_assoc()): ?>
              <tr>
                  <td><?php echo $row['id']; ?></td>
                  <td><?php echo htmlspecialchars($row['name']); ?></td>
                  <td>
                    <?php if (!empty($row['vehicle_type'])): ?>
                        <a href="FVM.php?query=<?php echo urlencode($row['tag_code']); ?>" title="Click to view in FVM Module">
                            <?php echo htmlspecialchars($row['vehicle_type'] . ' ' . $row['model']); ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #888;">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                  <td><?php echo htmlspecialchars($row['rating']); ?> ★</td>
                  <td class="action-buttons">
                    <button class="btn btn-warning btn-sm editDriverBtn" data-id="<?php echo $row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name']); ?>" data-license_number="<?php echo htmlspecialchars($row['license_number']); ?>" data-status="<?php echo $row['status']; ?>" data-rating="<?php echo $row['rating']; ?>" data-user_id="<?php echo $row['user_id']; ?>">Edit</button>
                    <a href="DTPM.php?delete_driver=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                    <a href="trip_history.php?driver_id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm">Trips</a>
                    <?php if (!empty($row['user_id'])): ?><a href="MA.php#messaging" class="btn btn-success btn-sm">Message</a><?php endif; ?>
                  </td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">No active drivers found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <div id="driverModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2 id="modalTitle">Add Driver</h2>
        <form action="DTPM.php" method="POST">
            <input type="hidden" id="driver_id" name="driver_id">
            <div class="form-group"><label>Full Name</label><input type="text" name="name" id="name" class="form-control" required></div>
            <div class="form-group"><label>License Number</label><input type="text" name="license_number" id="license_number" class="form-control" required></div>
            <div class="form-group"><label>Rating (1.0 - 5.0)</label><input type="number" step="0.1" min="1" max="5" name="rating" id="rating" class="form-control" required></div>
            <div class="form-group"><label>Status</label><select name="status" id="status" class="form-control" required><option value="Active">Active</option><option value="Suspended">Suspended</option><option value="Inactive">Inactive</option></select></div>
            <div class="form-group"><label>Link to User Account</label><select name="user_id" id="user_id" class="form-control"><option value="">-- None --</option><?php mysqli_data_seek($users, 0); while($user = $users->fetch_assoc()): ?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option><?php endwhile; ?></select></div>
            <div class="form-actions"><button type="button" class="btn btn-secondary cancelBtn">Cancel</button><button type="submit" name="save_driver" class="btn btn-primary">Save Driver</button></div>
        </form>
    </div>
  </div>
  
<script>
    // --- Standard page scripts ---
    document.getElementById('themeToggle').addEventListener('change', function() { document.body.classList.toggle('dark-mode', this.checked); });
    document.getElementById('hamburger').addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      if (window.innerWidth <= 992) { sidebar.classList.toggle('show'); } 
      else { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('expanded'); }
    });
    document.querySelectorAll('.modal').forEach(modal => {
        const closeBtn = modal.querySelector('.close-button'); const cancelBtn = modal.querySelector('.cancelBtn');
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        if(cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == modal) { modal.style.display = 'none'; } });
    });
    const driverModal = document.getElementById("driverModal");
    document.getElementById("addDriverBtn").addEventListener("click", () => {
        driverModal.querySelector('form').reset(); driverModal.querySelector('#driver_id').value = '';
        driverModal.querySelector("#modalTitle").textContent = 'Add New Driver';
        driverModal.style.display = 'block';
    });
    document.querySelectorAll(".editDriverBtn").forEach(btn => {
        btn.addEventListener("click", () => {
            driverModal.querySelector('form').reset();
            driverModal.querySelector("#modalTitle").textContent = 'Edit Driver';
            driverModal.querySelector('#driver_id').value = btn.dataset.id;
            driverModal.querySelector('#name').value = btn.dataset.name;
            driverModal.querySelector('#license_number').value = btn.dataset.license_number;
            driverModal.querySelector('#status').value = btn.dataset.status;
            driverModal.querySelector('#rating').value = btn.dataset.rating;
            driverModal.querySelector('#user_id').value = btn.dataset.user_id;
            driverModal.style.display = 'block';
        });
    });

    // --- Live Tracking Map Logic ---
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
    
    const map = L.map('liveTrackingMap').setView([12.8797, 121.7740], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>' }).addTo(map);
    
    const markers = {};
    const trackingTableBody = document.getElementById('tracking-table-body');
    const noTrackingPlaceholder = document.getElementById('no-tracking-placeholder');

    function getVehicleIcon(vehicleInfo) {
        let svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#858796"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11C5.84 5 5.28 5.42 5.08 6.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>`;
        if (vehicleInfo.toLowerCase().includes('truck')) { svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#4e73df"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm13.5-1.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM18 10h1.5v3H18v-3zM3 6h12v7H3V6z"/></svg>`; }
        else if (vehicleInfo.toLowerCase().includes('van')) { svgIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1cc88a"><path d="M20 8H4V6h16v2zm-2.17-3.24L15.21 2.14A1 1 0 0014.4 2H5a2 2 0 00-2 2v13c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7c0-.98-.71-1.8-1.65-1.97l-2.52-.27zM6.5 18c-.83 0-1.5-.67-1.5-1.5S5.67 15 6.5 15s1.5.67 1.5 1.5S7.33 18 6.5 18zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5zM4 13h16V9H4v4z"/></svg>`; }
        return L.divIcon({ html: svgIcon, className: 'vehicle-icon', iconSize: [40, 40], iconAnchor: [20, 40] });
    }

    function createOrUpdateMarkerAndRow(tripId, data) {
        if (!data.vehicle_info || !data.driver_name) return; 
        noTrackingPlaceholder.style.display = 'none';
        
        const vehicle = data.vehicle_info;
        const driver = data.driver_name;
        const newLatLng = [data.lat, data.lng];
        let tripRow = document.getElementById(`trip-row-${tripId}`);
        
        if (!markers[tripId]) {
            const popupContent = `<b>Vehicle:</b> ${vehicle}<br><b>Driver:</b> ${driver}`;
            markers[tripId] = L.marker(newLatLng, { icon: getVehicleIcon(vehicle) }).addTo(map).bindPopup(popupContent);
            markers[tripId].options.duration = 2000;
            if (!tripRow) {
                const newRow = document.createElement('tr');
                newRow.id = `trip-row-${tripId}`; newRow.className = 'clickable-row'; newRow.dataset.tripid = tripId;
                newRow.innerHTML = `<td>${vehicle}</td><td>${driver}</td><td class="location-cell">${data.lat.toFixed(6)}, ${data.lng.toFixed(6)}</td><td class="speed-cell">${Math.round(data.speed)} km/h</td><td><span class="status-badge status-en-route">En Route</span></td><td><a href="VRDS.php" class="btn btn-info btn-sm">View Dispatch</a></td>`;
                newRow.addEventListener('click', () => { map.flyTo(newLatLng, 15); markers[tripId].openPopup(); });
                trackingTableBody.appendChild(newRow);
            }
        } else {
            markers[tripId].slideTo(newLatLng, { duration: 2000 });
            markers[tripId].setIcon(getVehicleIcon(vehicle));
            if (tripRow) {
                tripRow.querySelector('.location-cell').textContent = `${data.lat.toFixed(6)}, ${data.lng.toFixed(6)}`;
                tripRow.querySelector('.speed-cell').textContent = `${Math.round(data.speed)} km/h`;
                tripRow.style.backgroundColor = 'var(--info-color)';
                setTimeout(() => { tripRow.style.backgroundColor = ''; }, 1500);
            }
        }
    }

    function removeMarkerAndRow(tripId) {
        if (markers[tripId]) { map.removeLayer(markers[tripId]); delete markers[tripId]; }
        const tripRow = document.getElementById(`trip-row-${tripId}`);
        if (tripRow) { tripRow.remove(); }
        if (Object.keys(markers).length === 0) { noTrackingPlaceholder.style.display = ''; }
    }
    
    // Listen for all live tracking data from Firebase
    const trackingRef = database.ref('live_tracking');
    trackingRef.on('child_added', (snapshot) => createOrUpdateMarkerAndRow(snapshot.key, snapshot.val()));
    trackingRef.on('child_changed', (snapshot) => createOrUpdateMarkerAndRow(snapshot.key, snapshot.val()));
    trackingRef.on('child_removed', (snapshot) => removeMarkerAndRow(snapshot.key));
</script>
</body>
</html>

