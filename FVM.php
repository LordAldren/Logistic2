<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Role-based Access Control: Bawal ang drivers dito.
if ($_SESSION['role'] === 'driver') {
    header("location: mobile_app.php");
    exit;
}

require_once 'db_connect.php';
$message = '';

// Handle Add/Edit Vehicle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_vehicle'])) {
    $id = $_POST['vehicle_id'];
    $type = $_POST['type'];
    $model = $_POST['model'];
    $tag_type = $_POST['tag_type'];
    $tag_code = $_POST['tag_code'];
    $load_capacity_kg = isset($_POST['load_capacity_kg']) && $_POST['load_capacity_kg'] !== '' ? (int)$_POST['load_capacity_kg'] : NULL;
    $status = $_POST['status'];
    $assigned_driver_id = isset($_POST['assigned_driver_id']) && $_POST['assigned_driver_id'] !== '' ? (int)$_POST['assigned_driver_id'] : NULL;

    if (empty($id)) { // Add new vehicle
        $check_sql = "SELECT id FROM vehicles WHERE tag_code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $tag_code);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $message = "<div class='message-banner error'>Error: A vehicle with this Tag Code already exists.</div>";
        } else {
            $sql = "INSERT INTO vehicles (type, model, tag_type, tag_code, load_capacity_kg, status, assigned_driver_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssisi", $type, $model, $tag_type, $tag_code, $load_capacity_kg, $status, $assigned_driver_id);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>New vehicle added successfully!</div>";
            } else {
                $message = "<div class='message-banner error'>Error saving vehicle: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else { // Update existing vehicle
        $check_sql = "SELECT id FROM vehicles WHERE tag_code = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $tag_code, $id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
             $message = "<div class='message-banner error'>Error: Another vehicle with this Tag Code already exists.</div>";
        } else {
            $sql = "UPDATE vehicles SET type=?, model=?, tag_type=?, tag_code=?, load_capacity_kg=?, status=?, assigned_driver_id=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssisii", $type, $model, $tag_type, $tag_code, $load_capacity_kg, $status, $assigned_driver_id, $id);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Vehicle updated successfully!</div>";
            } else {
                $message = "<div class='message-banner error'>Error updating vehicle: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}


// Handle Delete Vehicle
if (isset($_GET['delete_vehicle'])) {
    $id = $_GET['delete_vehicle'];
    $sql = "DELETE FROM vehicles WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Vehicle deleted successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting vehicle. It might be assigned to a trip.</div>";
    }
    $stmt->close();
}

// Handle Add/Edit Maintenance Schedule
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_schedule'])) {
    $schedule_id = $_POST['schedule_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $task = $_POST['task'];
    $schedule_date = $_POST['schedule_date'];
    $status = $_POST['status'];

    if (empty($schedule_id)) { // Add
        $sql = "INSERT INTO maintenance_schedule (vehicle_id, task, schedule_date, status) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isss", $vehicle_id, $task, $schedule_date, $status);
    } else { // Update
        $sql = "UPDATE maintenance_schedule SET vehicle_id=?, task=?, schedule_date=?, status=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssi", $vehicle_id, $task, $schedule_date, $status, $schedule_id);
    }

    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Maintenance schedule saved successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error saving schedule: " . $conn->error . "</div>";
    }
    $stmt->close();
}

// Handle Delete Maintenance Schedule
if (isset($_GET['delete_schedule'])) {
    $id = $_GET['delete_schedule'];
    $sql = "DELETE FROM maintenance_schedule WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Schedule deleted successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting schedule.</div>";
    }
    $stmt->close();
}

// Handle Add/Edit Compliance
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_compliance'])) {
    $compliance_id = $_POST['compliance_id'];
    $vehicle_id = $_POST['vehicle_id'];
    $registration_expiry = $_POST['registration_expiry'];
    $insurance_expiry = $_POST['insurance_expiry'];
    $status = $_POST['status'];

    if (empty($compliance_id)) { // Add
        $check_sql = "SELECT id FROM compliance WHERE vehicle_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $vehicle_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
             $message = "<div class='message-banner error'>Error: Compliance record for this vehicle already exists. Please edit the existing one.</div>";
        } else {
            $sql = "INSERT INTO compliance (vehicle_id, registration_expiry, insurance_expiry, status) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $vehicle_id, $registration_expiry, $insurance_expiry, $status);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Compliance record saved successfully!</div>";
            } else {
                $message = "<div class='message-banner error'>Error saving compliance record: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else { // Update
        $sql = "UPDATE compliance SET vehicle_id=?, registration_expiry=?, insurance_expiry=?, status=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssi", $vehicle_id, $registration_expiry, $insurance_expiry, $status, $compliance_id);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Compliance record updated successfully!</div>";
        } else {
            $message = "<div class='message-banner error'>Error updating compliance record: " . $conn->error . "</div>";
        }
        $stmt->close();
    }
}

// Handle Delete Compliance
if (isset($_GET['delete_compliance'])) {
    $id = $_GET['delete_compliance'];
    $sql = "DELETE FROM compliance WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "<div class='message-banner success'>Compliance record deleted successfully!</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting compliance record.</div>";
    }
    $stmt->close();
}


// Fetch data
$search_query = isset($_GET['query']) ? $conn->real_escape_string($_GET['query']) : '';
$where_clause = '';
if (!empty($search_query)) {
    $where_clause = "WHERE v.type LIKE '%$search_query%' OR v.model LIKE '%$search_query%' OR v.tag_code LIKE '%$search_query%' OR d.name LIKE '%$search_query%'";
}

$vehicles_result = $conn->query("SELECT v.*, d.name as driver_name FROM vehicles v LEFT JOIN drivers d ON v.assigned_driver_id = d.id $where_clause ORDER BY v.id DESC");
$maintenance_result = $conn->query("SELECT m.*, v.type, v.model FROM maintenance_schedule m JOIN vehicles v ON m.vehicle_id = v.id ORDER BY m.schedule_date DESC");
$compliance_result = $conn->query("SELECT c.*, v.type, v.model FROM compliance c JOIN vehicles v ON c.vehicle_id = v.id ORDER BY c.registration_expiry ASC");
$drivers_result = $conn->query("SELECT id, name FROM drivers WHERE status = 'Active'");
$all_vehicles = $conn->query("SELECT id, type, model FROM vehicles ORDER BY type, model");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fleet & Vehicle Management | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2</div>

    <?php $role = $_SESSION['role']; ?>
    <a href="landpage.php">Dashboard</a>

    <?php if ($role === 'admin' || $role === 'staff'): ?>
        <a href="FVM.php" class="active">Fleet & Vehicle Management (FVM)</a>
        <a href="VRDS.php">Vehicle Reservation & Dispatch System (VRDS)</a>
        <a href="DTPM.php">Driver and Trip Performance Monitoring</a>
        <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
    <?php endif; ?>
    
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Fleet & Vehicle Management</h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>
    
    <?php echo $message; ?>

    <div class="table-section">
      <h3>Vehicle Inventory</h3>
      <div class="search-container">
        <button id="addVehicleBtn" class="btn btn-primary">Add Vehicle</button>
        <form action="FVM.php" method="GET" style="display: flex; gap: 0.5rem;">
          <input type="text" name="query" class="search-box" placeholder="Search vehicle or driver..." value="<?php echo htmlspecialchars($search_query); ?>">
          <button type="submit" class="btn btn-secondary">Search</button>
        </form>
      </div>

      <table>
        <thead>
          <tr>
            <th>ID</th><th>Type</th><th>Model</th><th>Assigned To</th><th>Tag Code</th><th>Load Cap.</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($vehicles_result->num_rows > 0): ?>
            <?php while($row = $vehicles_result->fetch_assoc()): ?>
            <tr>
              <td><?php echo $row['id']; ?></td>
              <td><?php echo htmlspecialchars($row['type']); ?></td>
              <td><?php echo htmlspecialchars($row['model']); ?></td>
              <td><?php echo htmlspecialchars($row['driver_name'] ?? 'N/A'); ?></td>
              <td><?php echo htmlspecialchars($row['tag_code']); ?></td>
              <td><?php echo htmlspecialchars($row['load_capacity_kg']); ?> kg</td>
              <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
              <td>
                <button class="btn btn-warning btn-sm editVehicleBtn"
                  data-id="<?php echo $row['id']; ?>"
                  data-type="<?php echo htmlspecialchars($row['type']); ?>"
                  data-model="<?php echo htmlspecialchars($row['model']); ?>"
                  data-tag_type="<?php echo htmlspecialchars($row['tag_type']); ?>"
                  data-tag_code="<?php echo htmlspecialchars($row['tag_code']); ?>"
                  data-load_capacity_kg="<?php echo htmlspecialchars($row['load_capacity_kg']); ?>"
                  data-status="<?php echo htmlspecialchars($row['status']); ?>"
                  data-assigned_driver_id="<?php echo $row['assigned_driver_id']; ?>">Edit</button>
                <a href="FVM.php?delete_vehicle=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this vehicle?');">Delete</a>
              </td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="8">No vehicles found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>      

    <div class="table-section-2">
      <h3>Maintenance Scheduler</h3>
      <button id="addScheduleBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Add Schedule</button>
      <table>
        <thead><tr><th>Vehicle</th><th>Task</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if($maintenance_result->num_rows > 0): mysqli_data_seek($maintenance_result, 0); ?>
                <?php while($row = $maintenance_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                    <td><?php echo htmlspecialchars($row['task']); ?></td>
                    <td><?php echo htmlspecialchars($row['schedule_date']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td>
                        <button class="btn btn-warning btn-sm editScheduleBtn"
                            data-id="<?php echo $row['id']; ?>"
                            data-vehicle_id="<?php echo $row['vehicle_id']; ?>"
                            data-task="<?php echo htmlspecialchars($row['task']); ?>"
                            data-schedule_date="<?php echo $row['schedule_date']; ?>"
                            data-status="<?php echo $row['status']; ?>">Edit</button>
                        <a href="FVM.php?delete_schedule=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5">No maintenance schedules found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="table-section-2">
      <h3>Compliance</h3>
        <button id="addComplianceBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Add Compliance Record</button>
      <table>
        <thead><tr><th>Vehicle</th><th>Registration Exp.</th><th>Insurance Exp.</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if($compliance_result->num_rows > 0): mysqli_data_seek($compliance_result, 0); ?>
               <?php while($row = $compliance_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                    <td><?php echo htmlspecialchars($row['registration_expiry']); ?></td>
                    <td><?php echo htmlspecialchars($row['insurance_expiry']); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td>
                        <button class="btn btn-warning btn-sm editComplianceBtn"
                            data-id="<?php echo $row['id']; ?>"
                            data-vehicle_id="<?php echo $row['vehicle_id']; ?>"
                            data-registration_expiry="<?php echo $row['registration_expiry']; ?>"
                            data-insurance_expiry="<?php echo $row['insurance_expiry']; ?>"
                            data-status="<?php echo htmlspecialchars($row['status']); ?>">Edit</button>
                        <a href="FVM.php?delete_compliance=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5">No compliance records found.</td></tr>
            <?php endif; ?>
        </tbody>
      </table>
    </div>  
  </div>

  <!-- Vehicle Modal -->
  <div id="vehicleModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h2 id="modalTitle">Add Vehicle</h2>
      <form action="FVM.php" method="post">
        <input type="hidden" id="vehicle_id" name="vehicle_id">
        <div class="form-group"><label for="type">Type</label><input type="text" id="type" name="type" required></div>
        <div class="form-group"><label for="model">Model</label><input type="text" id="model" name="model" required></div>
        <div class="form-group"><label for="tag_type">Tag Type</label><select id="tag_type" name="tag_type" required><option value="RFID">RFID</option><option value="Barcode">Barcode</option><option value="QR Code">QR Code</option></select></div>
        <div class="form-group"><label for="tag_code">Tag Code</label><input type="text" id="tag_code" name="tag_code" required></div>
        <div class="form-group"><label for="load_capacity_kg">Load Capacity (kg)</label><input type="number" id="load_capacity_kg" name="load_capacity_kg" required></div>
        <div class="form-group"><label for="status">Status</label><select id="status" name="status" required><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Maintenance">Maintenance</option></select></div>
        <div class="form-group"><label for="assigned_driver_id">Assign Driver</label><select id="assigned_driver_id" name="assigned_driver_id"><option value="">-- None --</option><?php mysqli_data_seek($drivers_result, 0); while($driver = $drivers_result->fetch_assoc()){ echo "<option value='{$driver['id']}'>".htmlspecialchars($driver['name'])."</option>"; } ?></select></div>
        <div class="form-actions"><button type="button" class="btn btn-secondary cancelBtn">Cancel</button><button type="submit" name="save_vehicle" class="btn btn-primary">Save Vehicle</button></div>
      </form>
    </div>
  </div>
  
  <!-- Maintenance Schedule Modal -->
  <div id="scheduleModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h2 id="scheduleModalTitle">Add Schedule</h2>
      <form action="FVM.php" method="post">
        <input type="hidden" id="schedule_id" name="schedule_id">
        <div class="form-group"><label for="schedule_vehicle_id">Vehicle</label><select id="schedule_vehicle_id" name="vehicle_id" required><option value="">-- Select Vehicle --</option><?php mysqli_data_seek($all_vehicles, 0); while($v = $all_vehicles->fetch_assoc()){ echo "<option value='{$v['id']}'>".htmlspecialchars($v['type'].' - '.$v['model'])."</option>"; } ?></select></div>
        <div class="form-group"><label for="task">Task</label><input type="text" id="task" name="task" required></div>
        <div class="form-group"><label for="schedule_date">Date</label><input type="date" id="schedule_date" name="schedule_date" required></div>
        <div class="form-group"><label for="schedule_status">Status</label><select id="schedule_status" name="status" required><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div>
        <div class="form-actions"><button type="button" class="btn btn-secondary cancelBtn">Cancel</button><button type="submit" name="save_schedule" class="btn btn-primary">Save Schedule</button></div>
      </form>
    </div>
  </div>
  
  <!-- Compliance Modal -->
    <div id="complianceModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 id="complianceModalTitle">Add Compliance Record</h2>
            <form action="FVM.php" method="post">
                <input type="hidden" id="compliance_id" name="compliance_id">
                <div class="form-group">
                    <label for="compliance_vehicle_id">Vehicle</label>
                    <select id="compliance_vehicle_id" name="vehicle_id" required>
                        <option value="">-- Select Vehicle --</option>
                        <?php mysqli_data_seek($all_vehicles, 0); while($v = $all_vehicles->fetch_assoc()){ echo "<option value='{$v['id']}'>".htmlspecialchars($v['type'].' - '.$v['model'])."</option>"; } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="registration_expiry">Registration Expiry</label>
                    <input type="date" id="registration_expiry" name="registration_expiry" required>
                </div>
                <div class="form-group">
                    <label for="insurance_expiry">Insurance Expiry</label>
                    <input type="date" id="insurance_expiry" name="insurance_expiry" required>
                </div>
                <div class="form-group">
                    <label for="compliance_status">Status</label>
                    <input type="text" id="compliance_status" name="status" placeholder="e.g., Active, Pending Renewal" required>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary cancelBtn">Cancel</button>
                    <button type="submit" name="save_compliance" class="btn btn-primary">Save Record</button>
                </div>
            </form>
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

    document.querySelectorAll('.modal').forEach(modal => {
        const closeBtn = modal.querySelector('.close-button');
        const cancelBtn = modal.querySelector('.cancelBtn');
        closeBtn.addEventListener('click', () => modal.style.display = 'none');
        if(cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
        window.addEventListener('click', (event) => { if (event.target == modal) { modal.style.display = 'none'; } });
    });

    const vehicleModal = document.getElementById('vehicleModal');
    document.getElementById('addVehicleBtn').addEventListener('click', () => {
      vehicleModal.querySelector('form').reset();
      vehicleModal.querySelector('#vehicle_id').value = '';
      vehicleModal.querySelector('#modalTitle').textContent = 'Add New Vehicle';
      vehicleModal.style.display = 'block';
    });

    document.querySelectorAll('.editVehicleBtn').forEach(button => {
      button.addEventListener('click', () => {
        vehicleModal.querySelector('form').reset();
        vehicleModal.querySelector('#modalTitle').textContent = 'Edit Vehicle';
        vehicleModal.querySelector('#vehicle_id').value = button.dataset.id;
        vehicleModal.querySelector('#type').value = button.dataset.type;
        vehicleModal.querySelector('#model').value = button.dataset.model;
        vehicleModal.querySelector('#tag_type').value = button.dataset.tag_type;
        vehicleModal.querySelector('#tag_code').value = button.dataset.tag_code;
        vehicleModal.querySelector('#load_capacity_kg').value = button.dataset.load_capacity_kg;
        vehicleModal.querySelector('#status').value = button.dataset.status;
        vehicleModal.querySelector('#assigned_driver_id').value = button.dataset.assigned_driver_id;
        vehicleModal.style.display = 'block';
      });
    });
    
    const scheduleModal = document.getElementById('scheduleModal');
    document.getElementById('addScheduleBtn').addEventListener('click', () => {
      scheduleModal.querySelector('form').reset();
      scheduleModal.querySelector('#schedule_id').value = '';
      scheduleModal.querySelector('#scheduleModalTitle').textContent = 'Add New Schedule';
      scheduleModal.style.display = 'block';
    });

    document.querySelectorAll('.editScheduleBtn').forEach(button => {
      button.addEventListener('click', () => {
        scheduleModal.querySelector('form').reset();
        scheduleModal.querySelector('#scheduleModalTitle').textContent = 'Edit Schedule';
        scheduleModal.querySelector('#schedule_id').value = button.dataset.id;
        scheduleModal.querySelector('#schedule_vehicle_id').value = button.dataset.vehicle_id;
        scheduleModal.querySelector('#task').value = button.dataset.task;
        scheduleModal.querySelector('#schedule_date').value = button.dataset.schedule_date;
        scheduleModal.querySelector('#schedule_status').value = button.dataset.status;
        scheduleModal.style.display = 'block';
      });
    });
    
    const complianceModal = document.getElementById('complianceModal');
    document.getElementById('addComplianceBtn').addEventListener('click', () => {
      complianceModal.querySelector('form').reset();
      complianceModal.querySelector('#compliance_id').value = '';
      complianceModal.querySelector('#complianceModalTitle').textContent = 'Add New Compliance Record';
      complianceModal.style.display = 'block';
    });

    document.querySelectorAll('.editComplianceBtn').forEach(button => {
      button.addEventListener('click', () => {
        complianceModal.querySelector('form').reset();
        complianceModal.querySelector('#complianceModalTitle').textContent = 'Edit Compliance Record';
        complianceModal.querySelector('#compliance_id').value = button.dataset.id;
        complianceModal.querySelector('#compliance_vehicle_id').value = button.dataset.vehicle_id;
        complianceModal.querySelector('#registration_expiry').value = button.dataset.registration_expiry;
        complianceModal.querySelector('#insurance_expiry').value = button.dataset.insurance_expiry;
        complianceModal.querySelector('#compliance_status').value = button.dataset.status;
        complianceModal.style.display = 'block';
      });
    });
  </script>
</body>
</html>
