<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'db_connect.php';
$message = '';
$admin_id = 1; // Assuming admin user ID is 1

// --- Handle Driver App Actions (Assuming logged-in driver has user_id) ---
$user_id = $_SESSION['id']; 
$driver_id_result = $conn->query("SELECT id FROM drivers WHERE user_id = $user_id");
$driver_id = $driver_id_result->num_rows > 0 ? $driver_id_result->fetch_assoc()['id'] : null;

// Handle Send Message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $message_text = $_POST['message_text'];
    $is_admin_sending = $_SESSION['role'] === 'admin';
    
    $sender_id = $user_id;
    $receiver_id = $is_admin_sending ? $_POST['receiver_id'] : $admin_id; 
    
    if (!empty($message_text) && !empty($receiver_id)) {
        $sql = "INSERT INTO messages (sender_id, receiver_id, message_text) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $sender_id, $receiver_id, $message_text);
        $stmt->execute();
        $stmt->close();
        $message = "<div class='message-banner success'>Message sent!</div>";
    }
}

// Handle SOS Alert
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_sos'])) {
    $description = $_POST['description'];
    $trip_id_res = $conn->query("SELECT id FROM trips WHERE driver_id = $driver_id AND status = 'En Route' LIMIT 1");
    $trip_id = $trip_id_res->num_rows > 0 ? $trip_id_res->fetch_assoc()['id'] : null;

    if ($driver_id && $trip_id) {
        $sql = "INSERT INTO alerts (trip_id, driver_id, alert_type, description, status) VALUES (?, ?, 'SOS', ?, 'Pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $trip_id, $driver_id, $description);
        $stmt->execute();
        $stmt->close();
        $message = "<div class='message-banner success'>SOS Alert sent to admin!</div>";
    } else {
        $message = "<div class='message-banner error'>Could not send SOS. You must be an active driver on a trip.</div>";
    }
}


// --- Fetch Data for Driver App Dashboard (if user is a driver) ---
$driver_info = null;
$trip_summary = null;
if ($_SESSION['role'] === 'driver' && $driver_id) {
    $driver_info_sql = "SELECT d.name, v.type as vehicle_type, v.model as vehicle_model, d.status FROM drivers d LEFT JOIN vehicles v ON d.id = v.assigned_driver_id WHERE d.id = ?";
    $stmt = $conn->prepare($driver_info_sql);
    $stmt->bind_param("i", $driver_id);
    $stmt->execute();
    $driver_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $trip_summary_sql = "SELECT t.trip_code, t.destination, t.pickup_time, t.eta, t.status FROM trips t WHERE t.driver_id = ? AND t.status IN ('En Route', 'Scheduled') ORDER BY t.pickup_time DESC LIMIT 1";
    $stmt = $conn->prepare($trip_summary_sql);
    $stmt->bind_param("i", $driver_id);
    $stmt->execute();
    $trip_summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Fetch Data for Admin Fleet Overview ---
$active_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'En Route'")->fetch_assoc()['count'];
$idle_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'Idle'")->fetch_assoc()['count'];
$pending_alerts = $conn->query("SELECT COUNT(*) as count FROM alerts WHERE status = 'Pending'")->fetch_assoc()['count'];
$live_trips_result = $conn->query("SELECT v.type as vehicle_type, v.model, d.name as driver_name, t.status, t.current_location FROM trips t JOIN vehicles v ON t.vehicle_id = v.id JOIN drivers d ON t.driver_id = d.id WHERE t.status IN ('En Route', 'Breakdown', 'Idle') ORDER BY t.pickup_time DESC LIMIT 3");

// --- Fetch Data for Messaging & Alerts ---
$messages_result = $conn->query("SELECT u_sender.username as sender, message_text, sent_at FROM messages JOIN users u_sender ON messages.sender_id = u_sender.id WHERE (sender_id = $admin_id AND receiver_id = $user_id) OR (sender_id = $user_id AND receiver_id = $admin_id) ORDER BY sent_at ASC");
$sos_alerts_result = $conn->query("SELECT a.created_at, d.name as driver_name, a.description, a.status FROM alerts a JOIN drivers d ON a.driver_id = d.id WHERE a.alert_type = 'SOS' ORDER BY a.created_at DESC LIMIT 5");
$active_drivers = $conn->query("SELECT d.id, d.name, u.id as user_id FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.status = 'Active'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobile Fleet Command App | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2 </div>
    <a href="landpage.php">Dashboard</a>
    <a href="FVM.php">Fleet & Vehicle Management (FVM)</a>
    <a href="VRDS.php">Vehicle Reservation & Dispatch System (VRDS)</a>
    <a href="DTPM.php">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
    <a href="MA.php" class="active">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Mobile Fleet Command App</h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>
    
    <a href="mobile_app.php" class="btn btn-primary" style="margin-bottom: 1.5rem; text-align: center;">Open Mobile App Simulation</a>

    <?php echo $message; ?>

    <div class="grid-container">
      <div class="card">
        <h3>Driver App Dashboard (Desktop View)</h3>
        <?php if ($_SESSION['role'] === 'driver'): ?>
            <div class="card" style="margin-top: 1rem;">
              <?php if ($driver_info): ?>
              <p><strong>Driver:</strong> <?php echo htmlspecialchars($driver_info['name']); ?> | <strong>Vehicle:</strong> <?php echo htmlspecialchars($driver_info['vehicle_type'] . ' ' . $driver_info['vehicle_model']); ?> | <strong>Status:</strong> <span class="status-badge status-<?php echo strtolower($driver_info['status']); ?>"><?php echo htmlspecialchars($driver_info['status']); ?></span></p>
              <?php else: ?><p>No driver info linked to this user account.</p><?php endif; ?>
              <div style="margin-top: 1rem;">
                <button id="checkInBtn" class="btn btn-primary">Check-In</button>
                <button id="checkOutBtn" class="btn btn-secondary">Check-Out</button>
              </div>
            </div>
            <div class="card" style="margin-top: 1rem;">
                <h4>Today's Trip Summary</h4>
                <?php if ($trip_summary): ?>
                <p><strong>Trip ID:</strong> <?php echo htmlspecialchars($trip_summary['trip_code']); ?> | <strong>Destination:</strong> <?php echo htmlspecialchars($trip_summary['destination']); ?></p>
                <p><strong>Pickup:</strong> <?php echo htmlspecialchars(date('h:i A', strtotime($trip_summary['pickup_time']))); ?> | <strong>ETA:</strong> <?php echo htmlspecialchars($trip_summary['eta'] ? date('h:i A', strtotime($trip_summary['eta'])) : 'N/A'); ?></p>
                <p><strong>Current Status:</strong> <span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $trip_summary['status'])); ?>"><?php echo htmlspecialchars($trip_summary['status']); ?></span></p>
                <?php else: ?><p>No active trip assigned.</p><?php endif; ?>
            </div>
        <?php else: ?>
            <p style="margin-top: 1rem;">This view is for users with the 'driver' role.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>Admin Fleet Overview App</h3>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="card" style="margin-top: 1rem;">
                <h4>Summary Metrics</h4>
                <p><strong>Active Vehicles:</strong> <?php echo $active_vehicles; ?> | <strong>Idle Vehicles:</strong> <?php echo $idle_vehicles; ?> | <strong>Alerts:</strong> <span style="color: var(--danger-color); font-weight: bold;"><?php echo $pending_alerts; ?> Pending</span></p>
            </div>
            <div class="table-section" style="padding: 0; box-shadow: none; margin-top: 1rem;">
                <h4>Live Trip Status List</h4>
                <table>
                    <thead><tr><th>Vehicle</th><th>Driver</th><th>Status</th><th>Location</th></tr></thead>
                    <tbody>
                        <?php if($live_trips_result->num_rows > 0): mysqli_data_seek($live_trips_result, 0); ?>
                        <?php while($trip = $live_trips_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($trip['vehicle_type'] . ' ' . $trip['model']); ?></td>
                            <td><?php echo htmlspecialchars($trip['driver_name']); ?></td>
                            <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $trip['status'])); ?>"><?php echo htmlspecialchars($trip['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($trip['current_location'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php else: ?>
                        <tr><td colspan="4">No live trips.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 1rem;">
                <button id="assignTripBtn" class="btn btn-primary">Assign Trip</button>
                <button id="viewRouteBtn" class="btn btn-secondary">View Route</button>
            </div>
        <?php else: ?>
            <p style="margin-top: 1rem;">This view is for users with the 'admin' role.</p>
        <?php endif; ?>
      </div>

      <div class="card" id="messaging">
        <h3>Messaging & Alert System</h3>
        <div class="chat-box" style="margin-top: 1rem;">
            <?php if($messages_result->num_rows > 0): mysqli_data_seek($messages_result, 0); ?>
            <?php while($msg = $messages_result->fetch_assoc()): ?>
                <div class="message"><strong><?php echo htmlspecialchars(ucfirst($msg['sender'])); ?>:</strong> <?php echo htmlspecialchars($msg['message_text']); ?> <small style="float: right; color: #888;"><?php echo htmlspecialchars(date('h:i A', strtotime($msg['sent_at']))); ?></small></div>
            <?php endwhile; ?>
            <?php else: ?>
            <p style="text-align:center; color:#888;">No messages yet.</p>
            <?php endif; ?>
        </div>
        <form action="MA.php" method="POST" class="chat-input">
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <select name="receiver_id" required style="flex-grow: 0.5;">
                <option value="">To Driver:</option>
                <?php mysqli_data_seek($active_drivers, 0); while($driver = $active_drivers->fetch_assoc()): ?>
                <option value="<?php echo $driver['user_id']; ?>"><?php echo htmlspecialchars($driver['name']); ?></option>
                <?php endwhile; ?>
            </select>
            <?php endif; ?>
            <input type="text" name="message_text" placeholder="Type message..." required>
            <button type="submit" name="send_message" class="btn btn-primary">Send</button>
        </form>
      </div>

      <div class="card">
        <h3>Emergency SOS Integration</h3>
        <?php if ($_SESSION['role'] === 'driver'): ?>
            <div class="card" style="text-align: center; margin-top: 1rem;">
                <h4>EMERGENCY SOS</h4>
                <div style="margin-top: 1rem; display: flex; justify-content: center; gap: 0.5rem;">
                    <button id="sendSosBtn" class="btn btn-danger">Send SOS</button>
                    <button id="shareLocationBtn" class="btn btn-secondary">Share Location</button>
                    <button id="callSupportBtn" class="btn btn-info">Call Support</button>
                </div>
            </div>
        <?php endif; ?>
        <div class="table-section" style="padding: 0; box-shadow: none; margin-top: 1rem;">
            <h4>Recent SOS Alerts</h4>
            <table>
                <thead><tr><th>Time</th><th>Driver</th><th>Description</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if($sos_alerts_result->num_rows > 0): mysqli_data_seek($sos_alerts_result, 0); ?>
                    <?php while($alert = $sos_alerts_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('h:i A', strtotime($alert['created_at']))); ?></td>
                        <td><?php echo htmlspecialchars($alert['driver_name']); ?></td>
                        <td><?php echo htmlspecialchars($alert['description']); ?></td>
                        <td><span class="status-badge status-<?php echo strtolower($alert['status']); ?>"><?php echo htmlspecialchars($alert['status']); ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr><td colspan="4">No SOS alerts.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
      </div>
    </div>
  </div>

  <div id="actionModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2 id="modalTitle"></h2>
        <div class="modal-body" id="modalBody"></div>
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

    const modal = document.getElementById("actionModal");
    const modalTitle = document.getElementById("modalTitle");
    const modalBody = document.getElementById("modalBody");
    const closeBtn = document.querySelector(".modal .close-button");

    function showModal(title, content) {
        modalTitle.innerHTML = title;
        modalBody.innerHTML = content;
        modal.style.display = "block";
    }
    
    closeBtn.onclick = function() { modal.style.display = "none"; }
    window.onclick = function(event) { if (event.target == modal) { modal.style.display = "none"; } }

    const checkInBtn = document.getElementById('checkInBtn');
    if(checkInBtn) checkInBtn.onclick = () => showModal("Check-In", "<p>Driver has been checked in for the day.</p>");
    
    const checkOutBtn = document.getElementById('checkOutBtn');
    if(checkOutBtn) checkOutBtn.onclick = () => showModal("Check-Out", "<p>Driver has been checked out.</p>");

    const assignTripBtn = document.getElementById('assignTripBtn');
    if(assignTripBtn) assignTripBtn.onclick = () => { window.location.href = 'VRDS.php'; };

    const viewRouteBtn = document.getElementById('viewRouteBtn');
    if(viewRouteBtn) viewRouteBtn.onclick = () => { window.location.href = 'DTPM.php'; };

    const sendSosBtn = document.getElementById('sendSosBtn');
    if(sendSosBtn) sendSosBtn.onclick = () => {
        const formHtml = `
            <form action="MA.php" method="POST">
                <div class="form-group">
                    <label for="description">Briefly describe the emergency:</label>
                    <textarea name="description" class="form-control" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" name="send_sos" class="btn btn-danger">Confirm & Send SOS</button>
                </div>
            </form>`;
        showModal("Confirm SOS Alert", formHtml);
    };

    const shareLocationBtn = document.getElementById('shareLocationBtn');
    if(shareLocationBtn) shareLocationBtn.onclick = () => showModal("Share Location", "<p>Your current GPS location has been sent to the admin.</p>");
    
    const callSupportBtn = document.getElementById('callSupportBtn');
    if(callSupportBtn) callSupportBtn.onclick = () => showModal("Call Support", "<p>Connecting to support hotline: +63 2 8888 7777</p>");

  </script>
</body>
</html>
