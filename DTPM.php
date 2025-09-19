<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';
$message = '';

// Handle Add/Edit Driver
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_driver'])) {
    $id = $_POST['driver_id'];
    $name = $_POST['name'];
    $license_number = $_POST['license_number'];
    $status = $_POST['status'];
    $rating = $_POST['rating'];
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : NULL;

    if (empty($id)) { // Add
        $sql = "INSERT INTO drivers (name, license_number, status, rating, user_id) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdi", $name, $license_number, $status, $rating, $user_id);
    } else { // Update
        $sql = "UPDATE drivers SET name=?, license_number=?, status=?, rating=?, user_id=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssdii", $name, $license_number, $status, $rating, $user_id, $id);
    }

    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Driver saved successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error saving driver. The license number might already exist.</div>";
    }
    $stmt->close();
}

// Handle Delete Driver
if (isset($_GET['delete_driver'])) {
    $id = $_GET['delete_driver'];
    $sql = "DELETE FROM drivers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Driver deleted successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error: Cannot delete a driver assigned to a trip.</div>";
    }
    $stmt->close();
}


// Fetch Data
$tracking_data = $conn->query("SELECT v.type, v.model, d.name as driver_name, tl.latitude, tl.longitude, tl.speed_mph, tl.status_message
                               FROM tracking_log tl
                               JOIN trips t ON tl.trip_id = t.id
                               JOIN vehicles v ON t.vehicle_id = v.id
                               JOIN drivers d ON t.driver_id = d.id
                               WHERE t.status = 'En Route'");
$drivers = $conn->query("SELECT * FROM drivers");
$users = $conn->query("SELECT id, username FROM users WHERE role = 'driver'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driver & Trip Performance Monitoring | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
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

    <div class="table-section">
      <h3>Live Tracking Module</h3>
       <div style="margin-bottom: 1rem;">
          <button id="tripHistoryBtn" class="btn btn-info">Trip History Logs</button>
       </div>
      <table>
        <thead>
          <tr><th>Vehicle</th><th>Driver</th><th>Location (Lat, Lng)</th><th>Speed</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if($tracking_data->num_rows > 0): ?>
            <?php while($row = $tracking_data->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                <td><?php echo htmlspecialchars($row['driver_name']); ?></td>
                <td><?php echo htmlspecialchars($row['latitude'] . ', ' . $row['longitude']); ?></td>
                <td><?php echo htmlspecialchars($row['speed_mph']); ?> mph</td>
                <td><?php echo htmlspecialchars($row['status_message']); ?></td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5">No live tracking data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>      

    <div class="table-section-2">
      <h3>Driver Profiles</h3>
      <button id="addDriverBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Add Driver</button>
      <table>
        <thead>
          <tr><th>ID</th><th>Name</th><th>License</th><th>Status</th><th>Rating</th><th>Actions</th></tr>   
        </thead>
        <tbody>
            <?php if($drivers->num_rows > 0): ?>
               <?php while($row = $drivers->fetch_assoc()): ?>
              <tr>
                  <td><?php echo $row['id']; ?></td>
                  <td><?php echo htmlspecialchars($row['name']); ?></td>
                  <td><?php echo htmlspecialchars($row['license_number']); ?></td>
                  <td><span class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                  <td><?php echo htmlspecialchars($row['rating']); ?> ★</td>
                  <td>
                    <button class="btn btn-warning btn-sm editDriverBtn" 
                        data-id="<?php echo $row['id']; ?>"
                        data-name="<?php echo htmlspecialchars($row['name']); ?>"
                        data-license_number="<?php echo htmlspecialchars($row['license_number']); ?>"
                        data-status="<?php echo $row['status']; ?>"
                        data-rating="<?php echo $row['rating']; ?>"
                        data-user_id="<?php echo $row['user_id']; ?>">Edit</button>
                    <a href="DTPM.php?delete_driver=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                  </td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">No drivers found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- Driver Modal -->
  <div id="driverModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2 id="modalTitle">Add Driver</h2>
        <form action="DTPM.php" method="POST">
            <input type="hidden" id="driver_id" name="driver_id">
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" name="name" id="name" required>
            </div>
            <div class="form-group">
                <label for="license_number">License Number</label>
                <input type="text" name="license_number" id="license_number" required>
            </div>
            <div class="form-group">
                <label for="rating">Rating (1.0 - 5.0)</label>
                <input type="number" step="0.1" min="1" max="5" name="rating" id="rating" required>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" required>
                    <option value="Active">Active</option>
                    <option value="Suspended">Suspended</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
             <div class="form-group">
                <label for="user_id">Link to User Account</label>
                <select name="user_id" id="user_id">
                    <option value="">-- None --</option>
                    <?php mysqli_data_seek($users, 0); while($user = $users->fetch_assoc()): ?>
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary cancelBtn">Cancel</button>
                <button type="submit" name="save_driver" class="btn btn-primary">Save Driver</button>
            </div>
        </form>
    </div>
  </div>
  
  <!-- Info Modal -->
  <div id="infoModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2 id="infoModalTitle"></h2><div id="infoModalBody"></div></div></div>
     
  <script>
    document.getElementById('themeToggle').addEventListener('change', function() { document.body.classList.toggle('dark-mode', this.checked); });
    document.getElementById('hamburger').addEventListener('click', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      if (window.innerWidth <= 992) { sidebar.classList.toggle('show'); } 
      else { sidebar.classList.toggle('collapsed'); mainContent.classList.toggle('expanded'); }
    });
    
    document.querySelectorAll('.modal').forEach(modal => {
        const closeBtn = modal.querySelector('.close-button');
        const cancelBtn = modal.querySelector('.cancelBtn');
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        if(cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == modal) { modal.style.display = 'none'; } });
    });

    const driverModal = document.getElementById("driverModal");
    document.getElementById("addDriverBtn").addEventListener("click", () => {
        driverModal.querySelector('form').reset();
        driverModal.querySelector('#driver_id').value = '';
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

    const infoModal = document.getElementById("infoModal");
    function showInfoModal(title, content) {
        infoModal.querySelector("#infoModalTitle").textContent = title;
        infoModal.querySelector("#infoModalBody").innerHTML = content;
        infoModal.style.display = "block";
    }

    document.getElementById("tripHistoryBtn").addEventListener("click", () => {
        showInfoModal("Trip History Logs", "<p>A detailed log of all completed trips, including routes, times, and driver performance metrics, would be displayed here for analysis.</p>");
    });
  </script>
</body>
</html>



