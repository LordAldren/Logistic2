<?php
/**
 * API Vehicle Helper
 * 
 * This file provides functions to fetch vehicle data from the Logistics 1 API
 * instead of the local vehicles database.
 */

// External API Configuration
$EXTERNAL_API_URL = 'http://192.168.1.31/logistics1/api/assets.php';

/**
 * Fetch data from the External Logistics 1 API
 * @param string $action API action to call
 * @param array $params Additional parameters
 * @return array API response
 */
function fetchFromExternalAPI($action, $params = []) {
    global $EXTERNAL_API_URL;
    $params['action'] = $action;
    $url = $EXTERNAL_API_URL . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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
 * @return array List of all vehicles
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
 * Get only available/operational vehicles from API
 * @return array List of available vehicles formatted for dropdowns
 */
function getAvailableAPIVehicles() {
    $all_vehicles = getAPIVehicles();
    $available = [];
    
    foreach ($all_vehicles as $v) {
        if (isset($v['status']) && $v['status'] === 'Operational') {
            $available[] = [
                'id' => $v['id'],
                'type' => $v['asset_type'] ?? $v['type'] ?? 'Vehicle',
                'model' => $v['asset_name'] ?? $v['model'] ?? 'Unknown',
                'plate_no' => $v['plate_no'] ?? 'No Plate',
                'status' => $v['status']
            ];
        }
    }
    
    return $available;
}

/**
 * Get a specific vehicle by ID from the External API
 * @param int $vehicle_id Vehicle ID to find
 * @return array|null Vehicle data or null if not found
 */
function getAPIVehicleById($vehicle_id) {
    $all_vehicles = getAPIVehicles();
    
    foreach ($all_vehicles as $v) {
        if (isset($v['id']) && $v['id'] == $vehicle_id) {
            return [
                'id' => $v['id'],
                'type' => $v['asset_type'] ?? $v['type'] ?? 'Vehicle',
                'model' => $v['asset_name'] ?? $v['model'] ?? 'Unknown',
                'plate_no' => $v['plate_no'] ?? 'No Plate',
                'status' => $v['status'] ?? 'Unknown',
                'capacity' => $v['capacity'] ?? $v['load_capacity_kg'] ?? null
            ];
        }
    }
    
    return null;
}

/**
 * Build a vehicle lookup map (id => vehicle info)
 * Useful for displaying vehicle info in tables
 * @return array Associative array of vehicle_id => vehicle_info
 */
function getAPIVehicleMap() {
    $all_vehicles = getAPIVehicles();
    $map = [];
    
    foreach ($all_vehicles as $v) {
        $map[$v['id']] = [
            'id' => $v['id'],
            'type' => $v['asset_type'] ?? $v['type'] ?? 'Vehicle',
            'model' => $v['asset_name'] ?? $v['model'] ?? 'Unknown',
            'plate_no' => $v['plate_no'] ?? 'No Plate',
            'status' => $v['status'] ?? 'Unknown'
        ];
    }
    
    return $map;
}
?>
