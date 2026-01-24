<?php
/**
 * Mileage Data API
 * 
 * This API endpoint shares mileage and usage data for integration with other systems
 * like Logistics 1's Asset Lifecycle and Maintenance module.
 * 
 * The vehicle data is fetched from the Logistics 1 API (not local database).
 * 
 * Base URL: http://[your-ip]/finallog/api/mileage.php
 * 
 * Available Actions:
 * - all_vehicles   : Get mileage summary for all API vehicles
 * - vehicle        : Get detailed mileage for a specific vehicle (requires vehicle_id)
 * - usage_logs     : Get raw usage logs (optional: vehicle_id, start_date, end_date)
 * - trip_mileage   : Get mileage data from completed trips
 * - summary        : Get overall fleet mileage summary
 * 
 * Example Usage:
 * - http://[your-ip]/finallog/api/mileage.php?action=all_vehicles
 * - http://[your-ip]/finallog/api/mileage.php?action=vehicle&vehicle_id=1
 * - http://[your-ip]/finallog/api/mileage.php?action=usage_logs&start_date=2025-10-01&end_date=2025-10-31
 * - http://[your-ip]/finallog/api/mileage.php?action=trip_mileage
 * - http://[your-ip]/finallog/api/mileage.php?action=summary
 */

// Allow cross-origin requests for API access
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'error' => 'Only GET method is allowed',
        'timestamp' => date('c')
    ]);
    exit();
}

require_once '../config/db_connect.php';

// =====================================================
// EXTERNAL API CONFIGURATION (LOGISTICS 1)
// =====================================================
$EXTERNAL_API_URL = 'http://192.168.1.31/logistics1/api/assets.php';

/**
 * Fetch data from the External Logistics 1 API
 */
function fetchFromExternalAPI($action, $params = []) {
    global $EXTERNAL_API_URL;
    $params['action'] = $action;
    $url = $EXTERNAL_API_URL . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    $data = json_decode($response, true);
    return $data ?: ['success' => false, 'error' => 'Invalid JSON response'];
}

/**
 * Get all vehicles from the External API
 */
function getAPIVehicles() {
    $api_result = fetchFromExternalAPI('cargos_vehicles');
    $all_vehicles = [];
    
    if (isset($api_result['success']) && $api_result['success']) {
        $assets_grouped = $api_result['data']['assets'] ?? [];
        
        if (!empty($assets_grouped)) {
            foreach ($assets_grouped as $items) {
                if (is_array($items)) {
                    $all_vehicles = array_merge($all_vehicles, $items);
                }
            }
        } else {
            // Fallback for flat structure
            $all_vehicles = array_merge(
                $api_result['data']['cargos']['items'] ?? [],
                $api_result['data']['vehicles']['items'] ?? []
            );
        }
    }
    
    return $all_vehicles;
}

/**
 * Get a specific vehicle by ID from the External API
 */
function getAPIVehicleById($vehicle_id) {
    $all_vehicles = getAPIVehicles();
    
    foreach ($all_vehicles as $v) {
        if (isset($v['id']) && $v['id'] == $vehicle_id) {
            return $v;
        }
    }
    
    return null;
}

// =====================================================
// MAIN API LOGIC
// =====================================================
$action = isset($_GET['action']) ? $_GET['action'] : 'summary';

$response = [
    'success' => false,
    'action' => $action,
    'timestamp' => date('c'),
    'source_system' => 'Logistics2_SLATE',
    'vehicle_source' => 'Logistics1_API',
    'data' => null
];

try {
    switch ($action) {
        
        // ======================================================
        // GET ALL VEHICLES (FROM API) WITH MILEAGE FROM TRIPS
        // ======================================================
        case 'all_vehicles':
            $api_vehicles = getAPIVehicles();
            $vehicles_with_mileage = [];
            
            foreach ($api_vehicles as $v) {
                $vehicle_id = $v['id'];
                
                // Get trip data for this vehicle from local DB
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as trip_count,
                           SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_trips
                    FROM trips 
                    WHERE vehicle_id = ?
                ");
                $stmt->bind_param("i", $vehicle_id);
                $stmt->execute();
                $trip_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                // Calculate estimated mileage from completed trips
                $mileage_stmt = $conn->prepare("SELECT id FROM trips WHERE vehicle_id = ? AND status = 'Completed'");
                $mileage_stmt->bind_param("i", $vehicle_id);
                $mileage_stmt->execute();
                $trip_ids = $mileage_stmt->get_result();
                
                $total_distance = 0;
                $total_fuel = 0;
                while ($trip = $trip_ids->fetch_assoc()) {
                    srand($trip['id']);
                    $distance = rand(150, 1500) / 10;
                    $total_distance += $distance;
                    $total_fuel += $distance / 8; // 8 km per liter average
                }
                $mileage_stmt->close();
                
                // Get fuel costs from trip_costs if available
                $cost_stmt = $conn->prepare("
                    SELECT COALESCE(SUM(tc.fuel_cost), 0) as total_fuel_cost
                    FROM trip_costs tc
                    JOIN trips t ON tc.trip_id = t.id
                    WHERE t.vehicle_id = ?
                ");
                $cost_stmt->bind_param("i", $vehicle_id);
                $cost_stmt->execute();
                $cost_data = $cost_stmt->get_result()->fetch_assoc();
                $cost_stmt->close();
                
                $vehicles_with_mileage[] = [
                    'vehicle_id' => (int)$v['id'],
                    'plate_no' => $v['plate_no'] ?? 'No Plate',
                    'vehicle_type' => $v['asset_type'] ?? $v['type'] ?? 'Unknown',
                    'vehicle_name' => $v['asset_name'] ?? $v['model'] ?? 'Unknown',
                    'current_status' => $v['status'] ?? 'Unknown',
                    'mileage_data' => [
                        'total_trips' => (int)$trip_data['trip_count'],
                        'completed_trips' => (int)$trip_data['completed_trips'],
                        'estimated_total_distance_km' => round($total_distance, 1),
                        'estimated_fuel_consumed_liters' => round($total_fuel, 1),
                        'fuel_efficiency_km_per_liter' => $total_fuel > 0 ? round($total_distance / $total_fuel, 2) : 0,
                        'total_fuel_cost_php' => (float)$cost_data['total_fuel_cost']
                    ]
                ];
            }
            
            $response['success'] = true;
            $response['data'] = [
                'vehicle_count' => count($vehicles_with_mileage),
                'vehicles' => $vehicles_with_mileage
            ];
            break;
            
        // ======================================================
        // GET SPECIFIC VEHICLE (FROM API) WITH MILEAGE DETAILS
        // ======================================================
        case 'vehicle':
            $vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
            
            if ($vehicle_id <= 0) {
                $response['error'] = 'vehicle_id is required';
                break;
            }
            
            // Get vehicle from External API
            $vehicle = getAPIVehicleById($vehicle_id);
            
            if (!$vehicle) {
                $response['error'] = 'Vehicle not found in Logistics 1 API';
                break;
            }
            
            // Get all trips for this vehicle
            $trips_stmt = $conn->prepare("
                SELECT t.*, d.name AS driver_name
                FROM trips t
                LEFT JOIN drivers d ON t.driver_id = d.id
                WHERE t.vehicle_id = ?
                ORDER BY t.pickup_time DESC
            ");
            $trips_stmt->bind_param("i", $vehicle_id);
            $trips_stmt->execute();
            $trips_result = $trips_stmt->get_result();
            
            $trips = [];
            $total_distance = 0;
            $total_fuel = 0;
            $completed_trips = 0;
            
            while ($trip = $trips_result->fetch_assoc()) {
                // Calculate estimated distance
                srand($trip['id']);
                $distance = rand(150, 1500) / 10;
                $fuel = $distance / 8;
                
                if ($trip['status'] === 'Completed') {
                    $total_distance += $distance;
                    $total_fuel += $fuel;
                    $completed_trips++;
                }
                
                $trips[] = [
                    'trip_id' => (int)$trip['id'],
                    'trip_code' => $trip['trip_code'],
                    'driver_name' => $trip['driver_name'] ?? 'Unknown',
                    'destination' => $trip['destination'],
                    'pickup_time' => $trip['pickup_time'],
                    'actual_arrival_time' => $trip['actual_arrival_time'],
                    'status' => $trip['status'],
                    'estimated_distance_km' => round($distance, 1),
                    'estimated_fuel_liters' => round($fuel, 1)
                ];
            }
            $trips_stmt->close();
            
            // Get fuel costs
            $cost_stmt = $conn->prepare("
                SELECT COALESCE(SUM(tc.fuel_cost), 0) as total_fuel_cost
                FROM trip_costs tc
                JOIN trips t ON tc.trip_id = t.id
                WHERE t.vehicle_id = ?
            ");
            $cost_stmt->bind_param("i", $vehicle_id);
            $cost_stmt->execute();
            $cost_data = $cost_stmt->get_result()->fetch_assoc();
            $cost_stmt->close();
            
            $response['success'] = true;
            $response['data'] = [
                'vehicle' => [
                    'vehicle_id' => (int)$vehicle['id'],
                    'plate_no' => $vehicle['plate_no'] ?? 'No Plate',
                    'vehicle_type' => $vehicle['asset_type'] ?? $vehicle['type'] ?? 'Unknown',
                    'vehicle_name' => $vehicle['asset_name'] ?? $vehicle['model'] ?? 'Unknown',
                    'status' => $vehicle['status'] ?? 'Unknown',
                    'capacity' => $vehicle['capacity'] ?? $vehicle['load_capacity_kg'] ?? null
                ],
                'mileage_summary' => [
                    'total_trips' => count($trips),
                    'completed_trips' => $completed_trips,
                    'total_estimated_distance_km' => round($total_distance, 1),
                    'total_estimated_fuel_liters' => round($total_fuel, 1),
                    'fuel_efficiency_km_per_liter' => $total_fuel > 0 ? round($total_distance / $total_fuel, 2) : 0,
                    'total_fuel_cost_php' => (float)$cost_data['total_fuel_cost']
                ],
                'trip_history' => $trips
            ];
            break;
            
        // ======================================================
        // GET RAW USAGE LOGS (with optional filters)
        // ======================================================
        case 'usage_logs':
            $vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null;
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
            
            // Get API vehicles for mapping
            $api_vehicles = getAPIVehicles();
            $vehicle_map = [];
            foreach ($api_vehicles as $v) {
                $vehicle_map[$v['id']] = [
                    'plate_no' => $v['plate_no'] ?? 'No Plate',
                    'type' => $v['asset_type'] ?? $v['type'] ?? 'Unknown',
                    'name' => $v['asset_name'] ?? $v['model'] ?? 'Unknown'
                ];
            }
            
            $sql = "SELECT ul.* FROM usage_logs ul WHERE 1=1";
            $params = [];
            $types = '';
            
            if ($vehicle_id) {
                $sql .= " AND ul.vehicle_id = ?";
                $params[] = $vehicle_id;
                $types .= 'i';
            }
            
            if ($start_date) {
                $sql .= " AND ul.log_date >= ?";
                $params[] = $start_date;
                $types .= 's';
            }
            
            if ($end_date) {
                $sql .= " AND ul.log_date <= ?";
                $params[] = $end_date;
                $types .= 's';
            }
            
            $sql .= " ORDER BY ul.log_date DESC";
            
            $stmt = $conn->prepare($sql);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $logs = [];
            while ($row = $result->fetch_assoc()) {
                $vid = $row['vehicle_id'];
                $vehicle_info = $vehicle_map[$vid] ?? ['plate_no' => 'Unknown', 'type' => 'Unknown', 'name' => 'Unknown'];
                
                $logs[] = [
                    'log_id' => (int)$row['id'],
                    'vehicle_id' => (int)$row['vehicle_id'],
                    'plate_no' => $vehicle_info['plate_no'],
                    'vehicle_type' => $vehicle_info['type'],
                    'vehicle_name' => $vehicle_info['name'],
                    'log_date' => $row['log_date'],
                    'mileage_km' => (float)$row['mileage'],
                    'fuel_liters' => (float)$row['fuel_usage'],
                    'metrics' => $row['metrics'],
                    'created_at' => $row['created_at']
                ];
            }
            
            $response['success'] = true;
            $response['data'] = [
                'filters' => [
                    'vehicle_id' => $vehicle_id,
                    'start_date' => $start_date,
                    'end_date' => $end_date
                ],
                'total_records' => count($logs),
                'logs' => $logs
            ];
            break;
            
        // ======================================================
        // GET MILEAGE FROM COMPLETED TRIPS (with API vehicles)
        // ======================================================
        case 'trip_mileage':
            $vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null;
            
            // Get API vehicles for mapping
            $api_vehicles = getAPIVehicles();
            $vehicle_map = [];
            foreach ($api_vehicles as $v) {
                $vehicle_map[$v['id']] = [
                    'plate_no' => $v['plate_no'] ?? 'No Plate',
                    'type' => $v['asset_type'] ?? $v['type'] ?? 'Unknown',
                    'name' => $v['asset_name'] ?? $v['model'] ?? 'Unknown'
                ];
            }
            
            $sql = "
                SELECT 
                    t.id AS trip_id,
                    t.trip_code,
                    t.vehicle_id,
                    t.driver_id,
                    d.name AS driver_name,
                    t.destination,
                    t.pickup_time,
                    t.actual_arrival_time,
                    t.status,
                    tc.fuel_cost
                FROM trips t
                LEFT JOIN drivers d ON t.driver_id = d.id
                LEFT JOIN trip_costs tc ON t.id = tc.trip_id
                WHERE t.status = 'Completed'
            ";
            
            if ($vehicle_id) {
                $sql .= " AND t.vehicle_id = ?";
            }
            
            $sql .= " ORDER BY t.pickup_time DESC";
            
            $stmt = $conn->prepare($sql);
            if ($vehicle_id) {
                $stmt->bind_param("i", $vehicle_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $trips = [];
            while ($row = $result->fetch_assoc()) {
                // Calculate estimated distance based on trip ID
                srand($row['trip_id']);
                $distance_km = rand(150, 1500) / 10;
                $fuel_consumption = $distance_km / 8;
                
                $vid = $row['vehicle_id'];
                $vehicle_info = $vehicle_map[$vid] ?? ['plate_no' => 'Unknown', 'type' => 'Unknown', 'name' => 'Unknown'];
                
                $trips[] = [
                    'trip_id' => (int)$row['trip_id'],
                    'trip_code' => $row['trip_code'],
                    'vehicle_id' => (int)$row['vehicle_id'],
                    'plate_no' => $vehicle_info['plate_no'],
                    'vehicle_type' => $vehicle_info['type'],
                    'vehicle_name' => $vehicle_info['name'],
                    'driver_id' => (int)$row['driver_id'],
                    'driver_name' => $row['driver_name'] ?? 'Unknown',
                    'destination' => $row['destination'],
                    'pickup_time' => $row['pickup_time'],
                    'arrival_time' => $row['actual_arrival_time'],
                    'estimated_distance_km' => round($distance_km, 1),
                    'estimated_fuel_liters' => round($fuel_consumption, 1),
                    'fuel_cost_php' => $row['fuel_cost'] ? (float)$row['fuel_cost'] : null
                ];
            }
            
            // Calculate totals
            $total_distance = array_sum(array_column($trips, 'estimated_distance_km'));
            $total_fuel = array_sum(array_column($trips, 'estimated_fuel_liters'));
            
            $response['success'] = true;
            $response['data'] = [
                'filter_vehicle_id' => $vehicle_id,
                'completed_trips_count' => count($trips),
                'total_estimated_distance_km' => round($total_distance, 1),
                'total_estimated_fuel_liters' => round($total_fuel, 1),
                'trips' => $trips
            ];
            break;
            
        // ======================================================
        // GET FLEET SUMMARY (with API vehicles)
        // ======================================================
        case 'summary':
        default:
            // Get vehicles from API
            $api_vehicles = getAPIVehicles();
            $vehicle_count = count($api_vehicles);
            
            // Count by status
            $active_vehicles = 0;
            $maintenance_vehicles = 0;
            foreach ($api_vehicles as $v) {
                $status = strtolower($v['status'] ?? '');
                if (in_array($status, ['operational', 'active', 'en route', 'idle'])) {
                    $active_vehicles++;
                } elseif (in_array($status, ['maintenance', 'under maintenance'])) {
                    $maintenance_vehicles++;
                }
            }
            
            // Total mileage from usage logs
            $mileage_data = $conn->query("
                SELECT 
                    COALESCE(SUM(mileage), 0) as total_mileage,
                    COALESCE(SUM(fuel_usage), 0) as total_fuel
                FROM usage_logs
            ")->fetch_assoc();
            
            // Trip statistics
            $trip_stats = $conn->query("
                SELECT 
                    COUNT(*) as total_trips,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_trips,
                    SUM(CASE WHEN status = 'En Route' THEN 1 ELSE 0 END) as active_trips
                FROM trips
            ")->fetch_assoc();
            
            // Calculate estimated total distance from completed trips
            $completed_trips = $conn->query("SELECT id FROM trips WHERE status = 'Completed'");
            $total_trip_distance = 0;
            $total_trip_fuel = 0;
            while ($t = $completed_trips->fetch_assoc()) {
                srand($t['id']);
                $distance = rand(150, 1500) / 10;
                $total_trip_distance += $distance;
                $total_trip_fuel += $distance / 8;
            }
            
            // Total fuel costs
            $fuel_cost = $conn->query("SELECT COALESCE(SUM(fuel_cost), 0) as total FROM trip_costs")->fetch_assoc()['total'];
            
            $response['success'] = true;
            $response['data'] = [
                'fleet_stats' => [
                    'total_vehicles' => $vehicle_count,
                    'active_vehicles' => $active_vehicles,
                    'vehicles_in_maintenance' => $maintenance_vehicles,
                    'vehicle_source' => 'Logistics 1 API'
                ],
                'mileage_summary' => [
                    'total_logged_mileage_km' => (float)$mileage_data['total_mileage'],
                    'total_fuel_consumed_liters' => (float)$mileage_data['total_fuel'],
                    'estimated_trip_distance_km' => round($total_trip_distance, 1),
                    'estimated_trip_fuel_liters' => round($total_trip_fuel, 1),
                    'total_fuel_cost_php' => (float)$fuel_cost,
                    'avg_fuel_efficiency_km_per_liter' => $total_trip_fuel > 0 
                        ? round($total_trip_distance / $total_trip_fuel, 2) 
                        : 0
                ],
                'trip_stats' => [
                    'total_trips' => (int)$trip_stats['total_trips'],
                    'completed_trips' => (int)$trip_stats['completed_trips'],
                    'active_trips' => (int)$trip_stats['active_trips']
                ],
                'api_info' => [
                    'available_actions' => [
                        'summary' => 'Fleet overview (default)',
                        'all_vehicles' => 'All API vehicles with mileage summary',
                        'vehicle' => 'Specific vehicle details (requires vehicle_id)',
                        'usage_logs' => 'Raw usage log data (optional: vehicle_id, start_date, end_date)',
                        'trip_mileage' => 'Mileage from completed trips (optional: vehicle_id)'
                    ],
                    'example_calls' => [
                        'summary' => '/api/mileage.php?action=summary',
                        'all_vehicles' => '/api/mileage.php?action=all_vehicles',
                        'vehicle' => '/api/mileage.php?action=vehicle&vehicle_id=1',
                        'usage_logs' => '/api/mileage.php?action=usage_logs&start_date=2025-10-01&end_date=2025-10-31',
                        'trip_mileage' => '/api/mileage.php?action=trip_mileage'
                    ]
                ]
            ];
            break;
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'Error: ' . $e->getMessage();
}

$conn->close();

// Output JSON response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
