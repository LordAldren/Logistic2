<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../../auth/login.php");
    exit;
}
if ($_SESSION['role'] === 'driver') {
    header("location: ../mfc/mobile_app.php");
    exit;
}
require_once '../../config/db_connect.php';
$message = '';

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

// --- PANG-HANDLE NG CSV DOWNLOAD ---
if (isset($_GET['download_csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vehicle_list_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Type', 'Model', 'Tag Type', 'Tag Code', 'Capacity (KG)', 'Plate No', 'Status', 'Assigned Driver']);

    $search_query_csv = isset($_GET['query']) ? $conn->real_escape_string($_GET['query']) : '';
    $status_filter_csv = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

    $where_clauses_csv = [];
    if (!empty($search_query_csv)) {
        $where_clauses_csv[] = "(v.type LIKE '%$search_query_csv%' OR v.model LIKE '%$search_query_csv%' OR v.tag_code LIKE '%$search_query_csv%' OR v.plate_no LIKE '%$search_query_csv%' OR v.status LIKE '%$search_query_csv%')";
    }
    if (!empty($status_filter_csv)) {
        if ($status_filter_csv === 'Available') {
            $where_clauses_csv[] = "v.status IN ('Active', 'Idle')";
        } elseif ($status_filter_csv === 'Unavailable') {
            $where_clauses_csv[] = "v.status IN ('En Route', 'Maintenance', 'Breakdown', 'Inactive')";
        } else {
            $where_clauses_csv[] = "v.status = '$status_filter_csv'";
        }
    }
    $where_sql_csv = !empty($where_clauses_csv) ? "WHERE " . implode(" AND ", $where_clauses_csv) : "";

    $result = $conn->query("SELECT v.*, d.name as driver_name FROM vehicles v LEFT JOIN drivers d ON v.assigned_driver_id = d.id $where_sql_csv ORDER BY v.id DESC");
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['type'],
                $row['model'],
                $row['tag_type'],
                $row['tag_code'],
                $row['load_capacity_kg'],
                $row['plate_no'],
                $row['status'],
                $row['driver_name'] ?? 'N/A'
            ]);
        }
    }
    fclose($output);
    exit;
}
// --- WAKAS NG CSV DOWNLOAD LOGIC ---

// --- Fetch Vehicles from API ---
$api_result = fetchFromAPI('cargos_vehicles');
$api_vehicles = [];
$api_error = null;

if ($api_result['success']) {
    // --- ROBUST DATA EXTRACTION STRATEGY ---
    // Sometiles API returns grouped 'assets' (Cargo Van => [], Vehicle => [])
    // Sometimes it might return direct 'cargos' => [], 'vehicles' => []
    
    $assets_grouped = $api_result['data']['assets'] ?? [];
    $direct_cargos = $api_result['data']['cargos']['items'] ?? [];
    $direct_vehicles = $api_result['data']['vehicles']['items'] ?? [];

    // Strategy 1: Iterate over grouped assets
    if (!empty($assets_grouped)) {
        foreach ($assets_grouped as $category => $items) {
            if (is_array($items)) {
                $api_vehicles = array_merge($api_vehicles, $items);
            }
        }
    } 
    // Strategy 2: Fallback to direct keys (Legacy/Alternative format)
    elseif (!empty($direct_cargos) || !empty($direct_vehicles)) {
         if (!empty($direct_cargos)) $api_vehicles = array_merge($api_vehicles, $direct_cargos);
         if (!empty($direct_vehicles)) $api_vehicles = array_merge($api_vehicles, $direct_vehicles);
    }
    // Debug: If still empty, we might need to inspect the raw keys
    if (empty($api_vehicles) && isset($api_result['data']) && is_array($api_result['data'])) {
        // Last ditch: check if data itself is a list of items? unlikely but possible
        // Leaving empty for now, will catch in Debug View
    }
} else {
    $api_error = $api_result['error'] ?? $api_result['message'] ?? 'Failed to fetch vehicles from API';
}

// --- Calculate Statistics from API data ---
$stats = [
    'total' => count($api_vehicles),
    'available' => 0,
    'unavailable' => 0
];

foreach ($api_vehicles as $vehicle) {
    if (in_array($vehicle['status'], ['Operational'])) {
        $stats['available']++;
    } else {
        $stats['unavailable']++;
    }
}

// --- View Mode Handling ---
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'list'; // 'list' or 'grid'

$search_query = isset($_GET['query']) ? $_GET['query'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Filter API vehicles based on search and status
$filtered_vehicles = $api_vehicles;

if (!empty($search_query)) {
    $filtered_vehicles = array_filter($filtered_vehicles, function($vehicle) use ($search_query) {
        $search = strtolower($search_query);
        return stripos($vehicle['asset_name'], $search) !== false ||
               stripos($vehicle['asset_type'], $search) !== false ||
               stripos($vehicle['asset_classification'] ?? '', $search) !== false ||
               stripos($vehicle['status'], $search) !== false;
    });
}

if (!empty($status_filter)) {
    $filtered_vehicles = array_filter($filtered_vehicles, function($vehicle) use ($status_filter) {
        if ($status_filter === 'Available') {
            return in_array($vehicle['status'], ['Operational']);
        } elseif ($status_filter === 'Unavailable') {
            return in_array($vehicle['status'], ['Under Maintenance', 'Decommissioned']);
        } else {
            return $vehicle['status'] === $status_filter;
        }
    });
}

// Convert to result-like object for compatibility
$vehicles_array = array_values($filtered_vehicles);

// Prepare query params for view toggles
$params_list = $_GET;
$params_list['view'] = 'list';
$params_grid = $_GET;
$params_grid['view'] = 'grid';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle List & Fleet | FVM</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .view-toggles {
            display: flex;
            gap: 0.5rem;
        }

        .view-btn {
            padding: 0.5rem 0.75rem;
            border: var(--border-tech);
            background: var(--bg-panel);
            cursor: pointer;
            border-radius: 0.35rem;
            color: var(--text-muted);
        }

        .view-btn.active {
            background: var(--primary-color);
            color: #000;
            border-color: var(--primary-color);
        }

        .view-btn svg {
            width: 18px;
            height: 18px;
            display: block;
        }

        .vehicle-thumbnail {
            width: 60px;
            height: 45px;
            object-fit: cover;
            border-radius: 0.25rem;
            background-color: rgba(0, 0, 0, 0.05);
            display: block;
        }

        /* Stats Cards for Vehicle Availability */
        .status-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .summary-card {
            background: var(--bg-panel);
            padding: 1.5rem;
            border-radius: 4px;
            border: var(--border-tech);
            border-left: 4px solid var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-glow);
            backdrop-filter: var(--glass-blur);
        }

        .summary-card.available {
            border-left-color: var(--success-color);
        }

        .summary-card.unavailable {
            border-left-color: var(--danger-color);
        }

        .summary-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-main);
            font-family: var(--font-data);
        }
    </style>
</head>

<body>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="content" id="mainContent">
        <div class="header">
            <div class="hamburger" id="hamburger">☰</div>
            <div>
                <h1>Vehicle Fleet Management</h1>
            </div>
            <div class="theme-toggle-container">
                <span class="theme-label">Dark Mode</span>
                <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
            </div>
        </div>

        <?php echo $message; ?>

        <!-- Status Summary Cards -->
        <div class="status-summary">
            <div class="summary-card">
                <div>
                    <div class="summary-label">Total Fleet</div>
                    <div class="summary-value"><?php echo $stats['total']; ?></div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="color: var(--primary-color); opacity: 0.7;">
                    <path
                        d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2" />
                    <circle cx="7" cy="17" r="2" />
                    <circle cx="17" cy="17" r="2" />
                    <path d="M5 17h9" />
                </svg>
            </div>
            <div class="summary-card available">
                <div>
                    <div class="summary-label">Available</div>
                    <div class="summary-value"><?php echo $stats['available']; ?></div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="color: var(--success-color); opacity: 0.7;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="summary-card unavailable">
                <div>
                    <div class="summary-label">Unavailable</div>
                    <div class="summary-value"><?php echo $stats['unavailable']; ?></div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    style="color: var(--danger-color); opacity: 0.7;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
        </div>

        <div class="card" id="vehicle-list">
            <div
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <h3>Fleet Inventory</h3>
                    <div class="view-toggles">
                        <a href="vehicle_list.php?<?php echo http_build_query($params_list); ?>"
                            class="view-btn <?php echo $view_mode === 'list' ? 'active' : ''; ?>" title="List View">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="8" y1="6" x2="21" y2="6"></line>
                                <line x1="8" y1="12" x2="21" y2="12"></line>
                                <line x1="8" y1="18" x2="21" y2="18"></line>
                                <line x1="3" y1="6" x2="3.01" y2="6"></line>
                                <line x1="3" y1="12" x2="3.01" y2="12"></line>
                                <line x1="3" y1="18" x2="3.01" y2="18"></line>
                            </svg>
                        </a>
                        <a href="vehicle_list.php?<?php echo http_build_query($params_grid); ?>"
                            class="view-btn <?php echo $view_mode === 'grid' ? 'active' : ''; ?>" title="Grid View">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                        </a>
                    </div>
                </div>

                <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <form action="vehicle_list.php" method="GET" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                        <select name="status" class="form-control" onchange="this.form.submit()" style="width: auto;">
                            <option value="">All Statuses</option>
                            <option value="Available" <?php if ($status_filter == 'Available')
                                echo 'selected'; ?>>
                                Available (Active/Idle)</option>
                            <option value="Unavailable" <?php if ($status_filter == 'Unavailable')
                                echo 'selected'; ?>>
                                Unavailable (Busy/Maint)</option>
                            <option value="En Route" <?php if ($status_filter == 'En Route')
                                echo 'selected'; ?>>En Route
                            </option>
                            <option value="Maintenance" <?php if ($status_filter == 'Maintenance')
                                echo 'selected'; ?>>
                                Maintenance</option>
                        </select>
                        <input type="text" name="query" class="form-control" placeholder="Search vehicle..."
                            value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                    <a href="vehicle_list.php?download_csv=true&<?php echo http_build_query($_GET); ?>"
                        class="btn btn-success">Download CSV</a>
                </div>
            </div>

            <div id="viewVehicleModal" class="modal">
                <div class="modal-content">
                    <span class="close-button">&times;</span>
                    <h2>Vehicle Details</h2>
                    <div id="viewVehicleBody" style="line-height: 1.8;"></div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary cancelBtn">Close</button>
                    </div>
                </div>
            </div>

            <?php if ($view_mode === 'list'): ?>
                <!-- LIST VIEW (TABLE) -->
                <div class="table-section">
                    <table>
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Tracking No</th>
                                <th>Asset Name</th>
                                <th>Type</th>
                                <th>Class</th>
                                <th>Plate No</th>
                                <th>Usage</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($vehicles_array)): ?>
                                <?php foreach ($vehicles_array as $vehicle):
                                    // Get image from API or use placeholder
                                    if (!empty($vehicle['image_path'])) {
                                        $image = 'http://192.168.1.31/logistics1/' . $vehicle['image_path'];
                                    } else {
                                        $image = 'https://placehold.co/400x300/e2e8f0/64748b?text=' . urlencode($vehicle['asset_type']);
                                    }
                                    ?>
                                    <tr>
                                        <td><img src="<?php echo htmlspecialchars($image); ?>" alt="Asset"
                                                class="vehicle-thumbnail" onerror="this.src='https://placehold.co/400x300/e2e8f0/64748b?text=No+Image'"></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($vehicle['tracking_number'] ?? $vehicle['id']); ?></div>
                                            <div style="font-size: 0.8em; color: var(--text-muted);">ID: <?php echo $vehicle['id']; ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($vehicle['asset_name']); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['asset_type']); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['asset_classification'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['plate_no'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['usage_frequency'] ?? 'N/A'); ?></td>
                                        <td><span
                                                class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $vehicle['status'])); ?>"><?php echo htmlspecialchars($vehicle['status']); ?></span>
                                        </td>
                                        <td>
                                            <button class="btn btn-info btn-sm viewVehicleBtn" 
                                                data-id="<?php echo $vehicle['id']; ?>"
                                                data-tracking_number="<?php echo htmlspecialchars($vehicle['tracking_number'] ?? ''); ?>"
                                                data-asset_name="<?php echo htmlspecialchars($vehicle['asset_name'] ?? ''); ?>"
                                                data-type="<?php echo htmlspecialchars($vehicle['asset_type']); ?>"
                                                data-classification="<?php echo htmlspecialchars($vehicle['asset_classification'] ?? 'N/A'); ?>"
                                                data-usage="<?php echo htmlspecialchars($vehicle['usage_frequency'] ?? 'N/A'); ?>"
                                                data-plate_no="<?php echo htmlspecialchars($vehicle['plate_no'] ?? 'N/A'); ?>"
                                                data-purchase_date="<?php echo htmlspecialchars($vehicle['purchase_date'] ?? 'N/A'); ?>"
                                                data-maintenance_priority="<?php echo htmlspecialchars($vehicle['maintenance_priority'] ?? 'N/A'); ?>"
                                                data-status="<?php echo htmlspecialchars($vehicle['status']); ?>">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem;">
                                        <?php if ($api_error): ?>
                                            <div style="color: #ef4444; margin-bottom: 1rem;">
                                                <strong>API Error:</strong> <?php echo htmlspecialchars($api_error); ?>
                                                <br><small>Unable to fetch vehicles from the remote API.</small>
                                            </div>
                                        <?php else: ?>
                                            <div style="color: var(--text-muted); margin-bottom: 1rem;">
                                                No vehicles found matching your criteria.
                                            </div>
                                        <?php endif; ?>

                                        <!-- DEBUG INFO: Only visible if needed -->
                                        <details style="text-align: left; background: rgba(0,0,0,0.05); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
                                            <summary style="cursor: pointer; color: var(--primary-color);">Show Debug Info</summary>
                                            <pre style="white-space: pre-wrap; word-break: break-all; font-size: 0.8rem; margin-top: 0.5rem; color: var(--text-main);">
<strong>Debug Diagnostics:</strong>
API URL: <?php echo htmlspecialchars($API_BASE_URL); ?>
API Success: <?php echo $api_result['success'] ? 'TRUE' : 'FALSE'; ?>
Total Items Fetched: <?php echo count($api_vehicles); ?>
Filtered Count: <?php echo count($filtered_vehicles); ?>
Search Query: "<?php echo htmlspecialchars($search_query); ?>"
Status Filter: "<?php echo htmlspecialchars($status_filter); ?>"

<strong>API Data Structure Keys:</strong>
<?php 
if (isset($api_result['data']) && is_array($api_result['data'])) {
    echo "Top Level Keys: " . implode(', ', array_keys($api_result['data'])) . "\n";
    if (isset($api_result['data']['assets'])) {
        echo "Assets Categories: " . implode(', ', array_keys($api_result['data']['assets'])) . "\n";
    } else {
        echo "'assets' key NOT FOUND in data.\n";
    }
} else {
    echo "Data object missing or invalid.\n";
}
?>

<strong>First Item (if any):</strong>
<?php 
if (!empty($api_vehicles)) {
    print_r($api_vehicles[0]);
} else {
    echo "No items available to inspect.";
}
?>
</pre>
                                        </details>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- GRID VIEW (GALLERY) -->
                <div class="vehicle-gallery">
                    <?php if (!empty($vehicles_array)): ?>
                        <?php foreach ($vehicles_array as $vehicle):
                            // Image logic aligned with Table View
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
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                            <div class="vehicle-title"><?php echo htmlspecialchars($vehicle['asset_name']); ?></div>
                                            <span
                                                class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $vehicle['status'])); ?>"><?php echo htmlspecialchars($vehicle['status']); ?></span>
                                        </div>
                                        <div class="vehicle-info" style="margin-bottom: 0.5rem;">
                                            <strong>Type:</strong> <?php echo htmlspecialchars($vehicle['asset_type']); ?><br>
                                            <strong>Track #:</strong> <?php echo htmlspecialchars($vehicle['tracking_number'] ?? 'N/A'); ?><br>
                                            <strong>Plate:</strong> <?php echo htmlspecialchars($vehicle['plate_no'] ?? '-'); ?>
                                            <br>
                                            <strong>Usage:</strong>
                                            <?php echo htmlspecialchars($vehicle['usage_frequency'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <button class="btn btn-info viewVehicleBtn" style="margin-top: 0.5rem;"
                                        data-id="<?php echo $vehicle['id']; ?>"
                                        data-tracking_number="<?php echo htmlspecialchars($vehicle['tracking_number'] ?? ''); ?>"
                                        data-asset_name="<?php echo htmlspecialchars($vehicle['asset_name'] ?? ''); ?>"
                                        data-type="<?php echo htmlspecialchars($vehicle['asset_type']); ?>"
                                        data-classification="<?php echo htmlspecialchars($vehicle['asset_classification'] ?? 'N/A'); ?>"
                                        data-usage="<?php echo htmlspecialchars($vehicle['usage_frequency'] ?? 'N/A'); ?>"
                                        data-plate_no="<?php echo htmlspecialchars($vehicle['plate_no'] ?? 'N/A'); ?>"
                                        data-purchase_date="<?php echo htmlspecialchars($vehicle['purchase_date'] ?? 'N/A'); ?>"
                                        data-maintenance_priority="<?php echo htmlspecialchars($vehicle['maintenance_priority'] ?? 'N/A'); ?>"
                                        data-status="<?php echo htmlspecialchars($vehicle['status']); ?>">View
                                        Details</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No vehicles found matching your criteria.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Sidebar logic is now handled by central sidebar.js

            // --- Modal Logic ---
            const viewVehicleModal = document.getElementById('viewVehicleModal');
            const viewVehicleBody = document.getElementById('viewVehicleBody');

            // Close modal handlers
            document.querySelectorAll('.modal').forEach(modal => {
                const closeBtn = modal.querySelector('.close-button');
                const cancelBtn = modal.querySelector('.cancelBtn');
                if (closeBtn) { closeBtn.addEventListener('click', () => modal.style.display = 'none'); }
                if (cancelBtn) { cancelBtn.addEventListener('click', () => modal.style.display = 'none'); }
            });

            // View Button Handler
            // View Button Handler
            document.querySelectorAll('.viewVehicleBtn').forEach(button => {
                button.addEventListener('click', () => {
                    const ds = button.dataset;
                    const type = ds.type.toLowerCase();
                    const status = ds.status;
                    const statusClass = status.toLowerCase().replace(' ', '-');
                    
                    // Image Logic
                    const imagePath = '../../assets/images/';
                    let imageUrl = 'https://placehold.co/400x300/e2e8f0/64748b?text=' + encodeURIComponent(ds.type);
                    if (ds.asset_name && ds.asset_name.toLowerCase().includes('elf')) { imageUrl = imagePath + 'elf.PNG'; }
                    else if (ds.asset_name && ds.asset_name.toLowerCase().includes('hiace')) { imageUrl = imagePath + 'hiace.PNG'; }
                    else if (ds.asset_name && ds.asset_name.toLowerCase().includes('canter')) { imageUrl = imagePath + 'canter.PNG'; }

                    const detailsHtml = `
                <img src="${imageUrl}" alt="${ds.type}" style="width: 100%; height: auto; max-height: 250px; object-fit: cover; border-radius: 0.35rem; margin-bottom: 1rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <p><strong>ID:</strong> ${ds.id}</p>
                        <p><strong>Tracking #:</strong> ${ds.tracking_number || 'N/A'}</p>
                        <p><strong>Asset Name:</strong> ${ds.asset_name || 'N/A'}</p>
                        <p><strong>Type:</strong> ${ds.type}</p>
                        <p><strong>Plate No:</strong> ${ds.plate_no || 'N/A'}</p>
                    </div>
                    <div>
                        <p><strong>Classification:</strong> ${ds.classification || 'N/A'}</p>
                        <p><strong>Usage Freq:</strong> ${ds.usage || 'N/A'}</p>
                        <p><strong>Maint. Priority:</strong> ${ds.maintenance_priority || 'N/A'}</p>
                        <p><strong>Purchase Date:</strong> ${ds.purchase_date || 'N/A'}</p>
                    </div>
                </div>
                <p style="margin-top: 1rem;"><strong>Status:</strong> <span class="status-badge status-${statusClass}">${status}</span></p>
            `;
                    viewVehicleBody.innerHTML = detailsHtml;
                    viewVehicleModal.style.display = 'block';
                });
            });
        });
    </script>
    <script src="../../assets/js/dark_mode_handler.js" defer></script>
</body>

</html>