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
require_once '../../config/api_vehicles.php';
$message = '';
$current_user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];

// --- FORM & ACTION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle Create Reservation (STAFF ONLY)
    if (isset($_POST['save_reservation']) && $user_role === 'staff') {
        $client_name = $_POST['client_name'];
        $vehicle_id = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : NULL;
        $reservation_date = $_POST['reservation_date'];
        $purpose = $_POST['purpose'];
        $load_capacity_needed = !empty($_POST['load_capacity_needed']) ? $_POST['load_capacity_needed'] : NULL;
        $destination_address = $_POST['destination_address'];

        $status = 'Pending';
        $reservation_code = 'R' . date('YmdHis');
        $sql = "INSERT INTO reservations (reservation_code, client_name, reserved_by_user_id, vehicle_id, reservation_date, purpose, status, load_capacity_needed, destination_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiisssis", $reservation_code, $client_name, $current_user_id, $vehicle_id, $reservation_date, $purpose, $status, $load_capacity_needed, $destination_address);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Reservation saved successfully! Waiting for Admin approval.</div>";
        } else {
            $message = "<div class='message-banner error'>Error saving reservation: " . $conn->error . "</div>";
        }
        $stmt->close();
    }

    // --- UPDATED: Handle Schedule Trip (Dispatch) ---
    elseif (isset($_POST['schedule_trip'])) {
        $reservation_id = $_POST['reservation_id_for_trip'];
        $driver_id = $_POST['driver_id'];
        $pickup_time = $_POST['pickup_time'];
        // Kuhanin ang vehicle mula sa form (kung meron)
        $selected_vehicle_id = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : null;

        $stmt = $conn->prepare("SELECT client_name, vehicle_id, destination_address FROM reservations WHERE id = ?");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        $res_result = $stmt->get_result();

        if ($res = $res_result->fetch_assoc()) {
            // Gamitin ang vehicle galing sa form kung meron, kung wala, gamitin ang nasa reservation
            $final_vehicle_id = !empty($selected_vehicle_id) ? $selected_vehicle_id : $res['vehicle_id'];

            if (empty($final_vehicle_id)) {
                $message = "<div class='message-banner error'>Error: Please assign a vehicle before scheduling the trip.</div>";
            } else {
                $trip_code = 'T' . date('YmdHis');
                $status = 'Scheduled';

                // Update reservation vehicle if it was initially empty
                if (empty($res['vehicle_id']) && !empty($final_vehicle_id)) {
                    $upd_res = $conn->prepare("UPDATE reservations SET vehicle_id = ? WHERE id = ?");
                    $upd_res->bind_param("ii", $final_vehicle_id, $reservation_id);
                    $upd_res->execute();
                    $upd_res->close();
                }

                $insert_trip_stmt = $conn->prepare("INSERT INTO trips (trip_code, reservation_id, vehicle_id, driver_id, client_name, destination, pickup_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_trip_stmt->bind_param("siiissss", $trip_code, $reservation_id, $final_vehicle_id, $driver_id, $res['client_name'], $res['destination_address'], $pickup_time, $status);

                if ($insert_trip_stmt->execute()) {
                    $message = "<div class='message-banner success'>Trip scheduled successfully! It will now appear in the Driver App and <a href='dispatch_control.php' style='color:white; font-weight:bold;'>Dispatch Page</a>.</div>";
                } else {
                    $message = "<div class='message-banner error'>Error scheduling trip: " . $conn->error . "</div>";
                }
                $insert_trip_stmt->close();
            }
        } else {
            $message = "<div class='message-banner error'>Could not find reservation details.</div>";
        }
        $stmt->close();
    }

    // Handle Accept/Reject Reservation (ADMIN ONLY)
    elseif (isset($_POST['update_status']) && $user_role === 'admin') {
        $id = $_POST['reservation_id'];
        $new_status = $_POST['new_status'];
        if ($new_status === 'Confirmed' || $new_status === 'Rejected') {
            $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $id);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Reservation status updated to $new_status.</div>";
            } else {
                $message = "<div class='message-banner error'>Error updating status.</div>";
            }
            $stmt->close();
        }
    }
}


// --- CSV REPORT GENERATION ---
if (isset($_GET['download_csv'])) {
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
    header('Content-Disposition: attachment; filename="reservations_report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Reservation Code', 'Client', 'Reserved By', 'Vehicle', 'Date', 'Address', 'Capacity (KG)', 'Purpose/Notes', 'Status']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['reservation_code'], $row['client_name'], $row['reserved_by'], ($row['vehicle_type'] ?? 'N/A') . ' ' . ($row['vehicle_model'] ?? ''), $row['reservation_date'], $row['destination_address'], $row['load_capacity_needed'], $row['purpose'], $row['status']]);
    }
    fclose($output);
    exit;
}


// --- DATA FETCHING ---
// Build WHERE clause for reservations based on filters
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

// Build and execute the reservations query (without local vehicle join - we use API)
$reservations_sql = "SELECT r.*, u.username as reserved_by 
                     FROM reservations r 
                     LEFT JOIN users u ON r.reserved_by_user_id = u.id";

if (!empty($where_clauses)) {
    $reservations_sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$reservations_sql .= " ORDER BY r.reservation_date DESC";

$stmt = $conn->prepare($reservations_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reservations = $stmt->get_result();

// --- API INTEGRATION FOR VEHICLES (using helper) ---
// Get available vehicles for dropdown
$api_available_vehicles = getAvailableAPIVehicles();

// Build vehicle lookup map for displaying in tables
$api_vehicle_map = getAPIVehicleMap();

// Keep drivers local for now
$available_drivers = $conn->query("SELECT id, name FROM drivers WHERE status = 'Active'");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Booking | VRDS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        /* === MODAL BASE STYLES === */
        .modal {
            display: none; /* Hidden by default - only shows when JS sets display:block */
            position: fixed;
            z-index: 99999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: var(--bg-panel, #1a1a2e);
            margin: 5% auto;
            padding: 30px;
            border: 1px solid rgba(0, 225, 255, 0.3);
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            position: relative;
            z-index: 100000;
            box-shadow: 0 0 30px rgba(0, 225, 255, 0.2);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .close-button {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .close-button:hover {
            color: #ff3838;
        }
        
        /* Form styling inside modals */
        .modal .form-group {
            margin-bottom: 1rem;
        }
        
        .modal .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-main, #e2e8f0);
        }
        
        .modal .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px;
            background: rgba(255,255,255,0.05);
            color: var(--text-main, #e2e8f0);
            font-size: 1rem;
        }
        
        .modal .form-control:focus {
            outline: none;
            border-color: var(--primary-color, #00e1ff);
            box-shadow: 0 0 10px rgba(0, 225, 255, 0.2);
        }
        
        .modal .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }
        
        /* Ensure buttons in tables are clickable */
        .table-section {
            position: relative;
            overflow: visible !important;
            z-index: 1;
        }
    </style>
</head>


<body>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="content" id="mainContent">
        <div class="header">
            <div class="hamburger" id="hamburger">☰</div>
            <div>
                <h1>Reservation Booking Management</h1>
            </div>
            <div class="theme-toggle-container">
                <span class="theme-label">Dark Mode</span>
                <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
            </div>
        </div>

        <?php echo $message; ?>

        <div class="table-section">
            <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <h3 style="margin: 0;">Reservation Booking</h3>
                
                <!-- Action Bar - All buttons in one clean row -->
                <div class="action-bar" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button id="openFilterModalBtn" class="btn btn-outline" style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                        </svg>
                        Search & Filter
                        <?php if (!empty($search_query) || !empty($start_date) || !empty($end_date) || !empty($status_filter)): ?>
                            <span class="badge" style="background: var(--primary-color); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;">Active</span>
                        <?php endif; ?>
                    </button>
                    
                    <?php if ($user_role === 'staff'): ?>
                        <button id="createReservationBtn" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Create Reservation
                        </button>
                    <?php endif; ?>
                    
                    <a href="reservation_booking.php?download_csv=true&<?php echo http_build_query($_GET); ?>" class="btn btn-success" style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Export CSV
                    </a>
                </div>
            </div>
            
            <!-- Active Filters Display -->
            <?php if (!empty($search_query) || !empty($start_date) || !empty($end_date) || !empty($status_filter)): ?>
            <div class="active-filters" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; padding: 0.75rem 1rem; background: rgba(0,225,255,0.1); border-radius: 8px; align-items: center;">
                <span style="color: var(--text-muted); font-size: 0.85rem;">Active filters:</span>
                <?php if (!empty($search_query)): ?>
                    <span class="filter-tag" style="background: var(--primary-color); color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem;">Search: <?php echo htmlspecialchars($search_query); ?></span>
                <?php endif; ?>
                <?php if (!empty($start_date)): ?>
                    <span class="filter-tag" style="background: var(--info-color); color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem;">From: <?php echo htmlspecialchars($start_date); ?></span>
                <?php endif; ?>
                <?php if (!empty($end_date)): ?>
                    <span class="filter-tag" style="background: var(--info-color); color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem;">To: <?php echo htmlspecialchars($end_date); ?></span>
                <?php endif; ?>
                <?php if (!empty($status_filter)): ?>
                    <span class="filter-tag" style="background: var(--warning-color); color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem;">Status: <?php echo htmlspecialchars($status_filter); ?></span>
                <?php endif; ?>
                <a href="reservation_booking.php" style="color: var(--danger-color); font-size: 0.85rem; text-decoration: none; margin-left: auto;">✕ Clear All</a>
            </div>
            <?php endif; ?>

            <!-- ==================== MODALS ==================== -->
            
            <!-- Search & Filter Modal -->
            <div id="filterModal" class="modal">
                <div class="modal-content" style="max-width: 600px;">
                    <span class="close-button">&times;</span>
                    <h2 style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="M21 21l-4.35-4.35"></path>
                        </svg>
                        Search & Filter Reservations
                    </h2>
                    <form action="reservation_booking.php" method="GET" style="margin-top: 1.5rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>Search by Code or Client Name</label>
                                <input type="text" name="search" class="form-control" placeholder="Enter reservation code or client name..." value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    <option value="Pending" <?php if ($status_filter == 'Pending') echo 'selected'; ?>>Pending</option>
                                    <option value="Confirmed" <?php if ($status_filter == 'Confirmed') echo 'selected'; ?>>Confirmed</option>
                                    <option value="Rejected" <?php if ($status_filter == 'Rejected') echo 'selected'; ?>>Rejected</option>
                                    <option value="Cancelled" <?php if ($status_filter == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions" style="margin-top: 1.5rem;">
                            <a href="reservation_booking.php" class="btn btn-secondary">Reset All</a>
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View Reservation Details Modal -->
            <div id="viewReservationModal" class="modal">
                <div class="modal-content" style="max-width: 550px;">
                    <span class="close-button">&times;</span>
                    <h2>Reservation Details</h2>
                    <div id="viewReservationBody"></div>
                </div>
            </div>

            <!-- Create/Edit Reservation Modal (Staff only) -->
            <?php if ($user_role === 'staff'): ?>
                <div id="reservationModal" class="modal">
                    <div class="modal-content" style="max-width: 700px;">
                        <span class="close-button">&times;</span>
                        <h2 id="reservationModalTitle" style="display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Create Reservation
                        </h2>
                        <div id="reservationModalBody"></div>
                    </div>
                </div>
            <?php endif; ?>


            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Client</th>
                        <th>Reserved By</th>
                        <th>Vehicle Details</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reservations->num_rows > 0):
                        mysqli_data_seek($reservations, 0);
                        while ($row = $reservations->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['reservation_code']); ?></td>
                                <td><?php echo htmlspecialchars($row['client_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['reserved_by'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    if (!empty($row['vehicle_id']) && isset($api_vehicle_map[$row['vehicle_id']])) {
                                        $v_info = $api_vehicle_map[$row['vehicle_id']];
                                        echo '<strong>' . htmlspecialchars($v_info['plate_no']) . '</strong><br>';
                                        echo '<small>' . htmlspecialchars($v_info['type'] . ' - ' . $v_info['model']) . '</small>';
                                    } elseif (!empty($row['vehicle_id'])) {
                                        echo '<span style="color:var(--warning-color);">ID: ' . $row['vehicle_id'] . ' (API)</span>';
                                    } else {
                                        echo '<span style="color:var(--danger-color); font-style:italic;">Not Assigned</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['reservation_date']); ?></td>
                                <td><span
                                        class="status-badge status-<?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                </td>
                                <td class="action-buttons">
                                    <button class="btn btn-info btn-sm viewReservationBtn"
                                        data-details='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'>View</button>

                                    <?php if ($row['status'] == 'Pending' && $user_role === 'admin'): ?>
                                        <form action="reservation_booking.php" method="POST" style="display: inline;">
                                            <input type="hidden" name="reservation_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="new_status" value="Confirmed">
                                            <button type="submit" name="update_status"
                                                class="btn btn-success btn-sm">Accept</button>
                                        </form>
                                        <form action="reservation_booking.php" method="POST" style="display: inline;">
                                            <input type="hidden" name="reservation_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="new_status" value="Rejected">
                                            <button type="submit" name="update_status" class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Scheduling is done from Dispatch Control page -->
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="7">No reservations found.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Link Central Sidebar Script -->

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const viewModal = document.getElementById("viewReservationModal");
            const reservationModal = document.getElementById("reservationModal");
            const filterModal = document.getElementById("filterModal");

            // Close modals on X button, cancel button, or clicking outside
            document.querySelectorAll('.modal').forEach(modal => {
                const closeBtn = modal.querySelector('.close-button');
                if (closeBtn) closeBtn.onclick = () => modal.style.display = 'none';
                const cancelBtn = modal.querySelector('.cancelBtn');
                if (cancelBtn) cancelBtn.onclick = () => modal.style.display = 'none';
                
                // Close on clicking outside modal content
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) modal.style.display = 'none';
                });
            });

            // Filter Modal
            const openFilterBtn = document.getElementById("openFilterModalBtn");
            if (openFilterBtn && filterModal) {
                openFilterBtn.addEventListener('click', () => {
                    filterModal.style.display = 'block';
                });
            }

            // View Modal Logic
            document.querySelectorAll('.viewReservationBtn').forEach(button => {
                button.addEventListener('click', () => {
                    const details = JSON.parse(button.dataset.details);
                    viewModal.querySelector("#viewReservationBody").innerHTML = `
                        <p><strong>Code:</strong> ${details.reservation_code}</p>
                        <p><strong>Client:</strong> ${details.client_name}</p>
                        <p><strong>Reserved By:</strong> ${details.reserved_by || 'N/A'}</p>
                        <p><strong>Date:</strong> ${details.reservation_date}</p>
                        <p><strong>Vehicle:</strong> ${details.vehicle_type || 'Not Assigned'} ${details.vehicle_model || ''}</p>
                        <p><strong>Destination Address:</strong> ${details.destination_address || 'Not specified'}</p>
                        <p><strong>Load Capacity Needed:</strong> ${details.load_capacity_needed ? details.load_capacity_needed + ' kg' : 'Not specified'}</p>
                        <p><strong>Purpose / Notes:</strong><br>${(details.purpose || 'Not specified').replace(/\n/g, '<br>')}</p>
                        <p><strong>Status:</strong> <span class="status-badge status-${details.status.toLowerCase()}">${details.status}</span></p>
                    `;
                    viewModal.style.display = 'block';
                });
            });

            // Create Reservation Modal Logic (Staff only)
            const createBtn = document.getElementById("createReservationBtn");
            if (createBtn) {
                createBtn.addEventListener('click', () => showReservationModal());
            }

            function showReservationModal() {
                if (!reservationModal) return;
                reservationModal.querySelector("#reservationModalTitle").innerHTML = "Create New Reservation";
                reservationModal.querySelector("#reservationModalBody").innerHTML = `
                    <form action='reservation_booking.php' method='POST'>
                        <input type='hidden' name='reservation_id' value="">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class='form-group'><label>Client</label><input type='text' name='client_name' class='form-control' required></div>
                            <div class='form-group'><label>Date</label><input type='date' name='reservation_date' class='form-control' required></div>
                            <div class='form-group'><label>Vehicle</label><select name='vehicle_id' class='form-control'><option value="">-- Optional: Assign Later --</option><?php 
                            if (!empty($api_available_vehicles)) {
                                foreach ($api_available_vehicles as $v) {
                                    echo "<option value='{$v['id']}'>" . htmlspecialchars($v['plate_no'] . ' - ' . $v['type'] . ' - ' . $v['model']) . "</option>";
                                }
                            }
                            ?></select></div>
                            <div class='form-group'><label>Load Capacity Needed (KG)</label><input type='number' name='load_capacity_needed' class='form-control' placeholder="e.g., 5000"></div>
                        </div>
                        <hr style="margin: 1.5rem 0; border-color: rgba(0,0,0,0.1);">
                        <div class="form-group"><label>Destination Address</label><input type="text" name="destination_address" class="form-control" placeholder="e.g., SM North Edsa, Quezon City" required></div>
                        <div class='form-group'><label>Purpose / Notes</label><textarea name='purpose' class='form-control' rows="3"></textarea></div>
                        <div class='form-actions'><button type='button' class='btn btn-secondary cancelBtn'>Cancel</button><button type='submit' name='save_reservation' class='btn btn-primary'>Save Reservation</button></div>
                    </form>`;
                reservationModal.style.display = "block";

                // Re-attach cancel button event for dynamically created form
                reservationModal.querySelector('.cancelBtn').onclick = () => reservationModal.style.display = 'none';
            }


        });
    </script>
    <script src="../../assets/js/dark_mode_handler.js" defer></script>
</body>

</html>