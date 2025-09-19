<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';
$message = '';

// Handle Add/Edit Trip Cost
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_trip_cost'])) {
    $id = $_POST['trip_cost_id'];
    $trip_id = $_POST['trip_id'];
    $fuel_cost = $_POST['fuel_cost'];
    $labor_cost = $_POST['labor_cost'];
    $tolls_cost = $_POST['tolls_cost'];
    $other_cost = $_POST['other_cost'];

    if (empty($id)) { // Insert new
        $check_stmt = $conn->prepare("SELECT id FROM trip_costs WHERE trip_id = ?");
        $check_stmt->bind_param("i", $trip_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        if ($check_stmt->num_rows > 0) {
            $message = "<div class='message-banner error'>Error: Mayroon nang record ng gastos para sa biyaheng ito. Paki-edit na lang ito mula sa listahan.</div>";
        } else {
            $sql = "INSERT INTO trip_costs (trip_id, fuel_cost, labor_cost, tolls_cost, other_cost) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idddd", $trip_id, $fuel_cost, $labor_cost, $tolls_cost, $other_cost);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Matagumpay na na-save ang gastos sa biyahe!</div>";
            } else {
                $message = "<div class='message-banner error'>Error sa pag-save ng gastos sa biyahe: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else { // Update existing
        $sql = "UPDATE trip_costs SET trip_id=?, fuel_cost=?, labor_cost=?, tolls_cost=?, other_cost=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iddddi", $trip_id, $fuel_cost, $labor_cost, $tolls_cost, $other_cost, $id);
         if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Matagumpay na na-update ang gastos sa biyahe!</div>";
        } else {
            $message = "<div class='message-banner error'>Error sa pag-update ng gastos sa biyahe: " . $conn->error . "</div>";
        }
        $stmt->close();
    }
}

// Handle Delete Cost
if (isset($_GET['delete_cost'])) {
    $id = $_GET['delete_cost'];
    $stmt = $conn->prepare("DELETE FROM trip_costs WHERE id = ?");
    $stmt->bind_param("i", $id);
    if($stmt->execute()){
        $message = "<div class='message-banner success'>Matagumpay na natanggal ang record ng gastos.</div>";
    } else {
        $message = "<div class='message-banner error'>Error sa pagtanggal ng record.</div>";
    }
    $stmt->close();
}

// Handle Save Route from Optimizer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_route'])) {
    $route_name = $_POST['route_name'];
    $distance_km = $_POST['distance_km'];
    $estimated_time = $_POST['estimated_time'];
    $estimated_cost = $_POST['estimated_cost'];
    $status = 'Recommended'; // Default status for new routes

    if (!empty($route_name) && !empty($distance_km) && !empty($estimated_time) && !empty($estimated_cost)) {
        $sql = "INSERT INTO routes (route_name, distance_km, estimated_time, estimated_cost, status) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdss", $route_name, $distance_km, $estimated_time, $estimated_cost, $status);
        if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Matagumpay na na-save ang ruta!</div>";
        } else {
            $message = "<div class='message-banner error'>Error sa pag-save ng ruta: " . $conn->error . "</div>";
        }
        $stmt->close();
    } else {
        $message = "<div class='message-banner error'>Error: Kulang ang impormasyon ng ruta para i-save.</div>";
    }
}


// Fetch Data
$trip_costs = $conn->query("SELECT tc.*, t.trip_code, v.type, v.model 
                            FROM trip_costs tc 
                            JOIN trips t ON tc.trip_id = t.id
                            JOIN vehicles v ON t.vehicle_id = v.id
                            ORDER BY tc.id DESC");

$routes = $conn->query("SELECT r.*, v.type as vehicle_type, v.model as vehicle_model, v.load_capacity_kg 
                        FROM routes r
                        LEFT JOIN trips t ON r.trip_id = t.id
                        LEFT JOIN vehicles v ON t.vehicle_id = v.id
                        ORDER BY r.id DESC");

$all_trips = $conn->query("SELECT id, trip_code, destination FROM trips ORDER BY pickup_time DESC");

// Data for AI Per-Trip Cost Prediction
$cost_prediction_data = $conn->query("
    SELECT r.distance_km, tc.tolls_cost, tc.total_cost 
    FROM trip_costs tc
    JOIN trips t ON tc.trip_id = t.id
    JOIN routes r ON t.id = r.trip_id
    WHERE r.distance_km IS NOT NULL AND tc.tolls_cost IS NOT NULL AND tc.total_cost IS NOT NULL
");
$prediction_json = json_encode($cost_prediction_data->fetch_all(MYSQLI_ASSOC));

// Fetch Daily Aggregated Costs for AI analysis and table display
$daily_costs_query = $conn->query("
    SELECT
        DATE(t.pickup_time) as trip_date,
        SUM(tc.total_cost) as grand_total
    FROM trip_costs tc
    JOIN trips t ON tc.trip_id = t.id
    GROUP BY DATE(t.pickup_time)
    ORDER BY trip_date ASC
");
$daily_costs_for_ai = [];
if ($daily_costs_query) {
    $day_index = 1;
    while ($row = $daily_costs_query->fetch_assoc()) {
        $daily_costs_for_ai[] = [
            "day" => $day_index++,
            "date" => $row['trip_date'],
            "total" => (float)$row['grand_total']
        ];
    }
}
$daily_costs_json = json_encode($daily_costs_for_ai);

// Fetch detailed daily costs for the table (ordered descending for display)
$daily_costs_table_result = $conn->query("
    SELECT
        DATE(t.pickup_time) as trip_date,
        SUM(tc.fuel_cost) as total_fuel,
        SUM(tc.labor_cost) as total_labor,
        SUM(tc.tolls_cost) as total_tolls,
        SUM(tc.total_cost) as grand_total
    FROM trip_costs tc
    JOIN trips t ON tc.trip_id = t.id
    GROUP BY DATE(t.pickup_time)
    ORDER BY trip_date DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Transport Cost Analysis & Optimization | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest/dist/tf.min.js"></script>
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
    <a href="DTPM.php">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php" class="active">Transport Cost Analysis & Optimization (TCAO)</a>
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Transport Cost Analysis & Optimization</h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>
    
    <?php echo $message; ?>

    <div class="grid-container">
        <div class="card ai-section">
            <h3>AI Daily Cost Forecast</h3>
            <div id="daily-prediction-loader" class="stat-label">Training AI Model on daily trends...</div>
            <div id="daily-prediction-result" class="stat-value" style="display:none; color: var(--success-color);"></div>
            <div class="stat-label">Predicted Total Cost for Tomorrow</div>
        </div>
        <div class="card ai-section">
            <h3>AI Per-Trip Cost Predictor</h3>
            <p id="ai-status" class="stat-label">Training model...</p>
            <div id="ai-predictor-form" style="display:none;">
                <div class="form-group" style="margin-bottom: 0.5rem;">
                    <input type="number" id="distance" placeholder="Trip Distance (km)" class="form-control">
                </div>
                <div class="form-group">
                    <input type="number" id="tolls" placeholder="Estimated Tolls (₱)" class="form-control">
                </div>
                <button id="predictBtn" class="btn btn-primary btn-sm">Predict Cost</button>
                <div id="prediction-output" style="margin-top: 0.5rem;"></div>
            </div>
        </div>
    </div>

    <div class="table-section-2">
        <h3>Daily Cost Breakdown Engine</h3>
        <table>
            <thead><tr><th>Petsa</th><th>Kabuuang Gastos sa Fuel</th><th>Kabuuang Gastos sa Labor</th><th>Kabuuang Gastos sa Tolls</th><th>Grand Total</th></tr></thead>
            <tbody>
                <?php if ($daily_costs_table_result && $daily_costs_table_result->num_rows > 0): ?>
                    <?php while($row = $daily_costs_table_result->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo date("M d, Y", strtotime($row['trip_date'])); ?></strong></td>
                        <td>₱<?php echo number_format($row['total_fuel'], 2); ?></td>
                        <td>₱<?php echo number_format($row['total_labor'], 2); ?></td>
                        <td>₱<?php echo number_format($row['total_tolls'], 2); ?></td>
                        <td><strong>₱<?php echo number_format($row['grand_total'], 2); ?></strong></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5">Walang nakitang data ng pang-araw-araw na gastos.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="table-section-2">
        <h3>Per-Trip Cost Breakdown</h3>
        <button id="addCostBtn" class="btn btn-primary" style="margin-bottom: 1rem;">Add Trip Cost</button>
        <table>
            <thead><tr><th>Trip Code</th><th>Vehicle</th><th>Fuel Cost</th><th>Labor Cost</th><th>Tolls</th><th>Total</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($trip_costs->num_rows > 0): mysqli_data_seek($trip_costs, 0); ?>
                    <?php while($row = $trip_costs->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['trip_code']); ?></td>
                        <td><?php echo htmlspecialchars($row['type'] . ' ' . $row['model']); ?></td>
                        <td>₱<?php echo number_format($row['fuel_cost'], 2); ?></td>
                        <td>₱<?php echo number_format($row['labor_cost'], 2); ?></td>
                        <td>₱<?php echo number_format($row['tolls_cost'], 2); ?></td>
                        <td><strong>₱<?php echo number_format($row['total_cost'], 2); ?></strong></td>
                        <td>
                            <button class="btn btn-warning btn-sm editCostBtn" data-id="<?php echo $row['id']; ?>" data-trip_id="<?php echo $row['trip_id']; ?>" data-fuel_cost="<?php echo $row['fuel_cost']; ?>" data-labor_cost="<?php echo $row['labor_cost']; ?>" data-tolls_cost="<?php echo $row['tolls_cost']; ?>" data-other_cost="<?php echo $row['other_cost']; ?>">Edit</button>
                            <a href="TCAO.php?delete_cost=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Sigurado ka ba?');">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">Walang nakitang data ng gastos sa bawat biyahe.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>


    <div class="table-section-2">
      <h3>Route Optimizer</h3>
      <div class="card" style="margin-bottom: 1.5rem;">
        <h4>Hanapin ang Pinakamahusay na Ruta</h4>
        <div class="form-group">
            <label for="startLocation">Simulang Lokasyon</label>
            <input type="text" id="startLocation" class="form-control" placeholder="e.g., Quezon City, Metro Manila">
        </div>
        <div class="form-group">
            <label for="endLocation">Destinasyon</label>
            <input type="text" id="endLocation" class="form-control" placeholder="e.g., Baguio City, Benguet">
        </div>
        <button id="findRouteBtn" class="btn btn-primary">Find Optimal Route</button>
        <div id="route-output" style="margin-top: 1rem; display: none;">
            <h4>Mga Detalye ng Inirekumendang Ruta:</h4>
            <p><strong>Distansya:</strong> <span id="routeDistance"></span> km</p>
            <p><strong>Tagal:</strong> <span id="routeDuration"></span></p>
            <p><strong>Tantiyang Gastos:</strong> ₱<span id="routeCost"></span></p>
            <button id="viewRouteMapBtn" class="btn btn-info btn-sm" style="margin-top: 0.5rem;">Tingnan ang Ruta sa Mapa</button>
            
            <form action="TCAO.php" method="POST" style="margin-top: 1rem; border-top: 1px solid #ddd; padding-top: 1rem;" class="dark-mode-form-border">
                <input type="hidden" name="distance_km" id="hiddenDistance">
                <input type="hidden" name="estimated_time" id="hiddenDuration">
                <input type="hidden" name="estimated_cost" id="hiddenCost">
                <div class="form-group">
                    <label for="routeName">I-save ang ruta na ito na may pangalan:</label>
                    <input type="text" name="route_name" id="routeName" class="form-control" placeholder="e.g., Manila to Baguio (Day Route)" required>
                </div>
                <button type="submit" name="save_route" class="btn btn-success">Save Route to Logs</button>
            </form>
        </div>
      </div>
      <div style="margin-bottom: 1rem;">
         <button id="kpiBtn" class="btn btn-info">KPI & Report Generator</button>
         <button id="insightsBtn" class="btn btn-success">Suggestion & Insights</button>
     </div>
      <h4>Saved Routes Log</h4>
      <table>
        <thead><tr><th>Route Name</th><th>Vehicle</th><th>Distance</th><th>Est. Time</th><th>Cost</th><th>Status</th></tr></thead>
        <tbody>
           <?php if ($routes->num_rows > 0): mysqli_data_seek($routes, 0); ?>
               <?php while($row = $routes->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['route_name']); ?></td>
                    <td><?php echo htmlspecialchars(($row['vehicle_type'] ?? 'N/A') . ' ' . ($row['vehicle_model'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($row['distance_km']); ?> km</td>
                    <td><?php echo htmlspecialchars($row['estimated_time']); ?></td>
                    <td>₱<?php echo number_format($row['estimated_cost'], 2); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">Walang nakitang data ng ruta.</td></tr>
           <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
  
  <div id="actionModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2 id="modalTitle"></h2><div id="modalBody"></div></div></div>

  <!-- Map Modal for Route Optimizer -->
  <div id="routeMapModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <span class="close-button">&times;</span>
        <h2>Optimized Route Map</h2>
        <div id="routeMap" style="height: 500px; width: 100%; border-radius: 0.35rem;"></div>
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
    const closeBtn = modal.querySelector(".close-button");
    closeBtn.onclick = function() { modal.style.display = "none"; }
    window.onclick = function(event) { if (event.target == modal) { modal.style.display = "none"; } }

    function showModal(title, content) {
        modalTitle.innerHTML = title;
        modalBody.innerHTML = content;
        modal.style.display = "block";
    }

    document.getElementById('kpiBtn').onclick = () => showModal("KPI & Report Generator", "<p>Dito ipapakita ang mga ulat tungkol sa performance ng mga biyahe, tulad ng on-time delivery rates, average cost per trip, at fuel efficiency. Makakatulong ito sa paggawa ng desisyon para mapabuti ang operasyon.</p>");
    document.getElementById('insightsBtn').onclick = () => showModal("Suggestions & Insights", "<p>Batay sa historical data, magbibigay ang AI ng mga suhestiyon dito. Halimbawa, 'Napansin namin na mas mataas ng 15% ang gastos sa gasolina tuwing Biyernes. Isaalang-alang ang pag-adjust ng schedule.' o 'Ang Route A ay palaging may traffic tuwing umaga. Subukan ang Route C bilang alternatibo.'<p>");


    // --- Add/Edit Trip Cost Modal Logic ---
    function openCostModal(data = {}) {
        const isEdit = data.id !== undefined;
        const title = isEdit ? 'Edit Trip Cost' : 'Add Trip Cost';
        const formHtml = `
            <form action='TCAO.php' method='POST'>
                <input type="hidden" name="trip_cost_id" value="${data.id || ''}">
                <div class='form-group'>
                    <label for='trip_id'>Select Trip</label>
                    <select name='trip_id' class='form-control' required>
                        <option value="">-- Select a Trip --</option>
                        <?php mysqli_data_seek($all_trips, 0); while($t = $all_trips->fetch_assoc()) { echo "<option value='{$t['id']}'>" . htmlspecialchars($t['trip_code'] . ' - ' . $t['destination']) . "</option>"; } ?>
                    </select>
                </div>
                 <div class='form-group'><label>Fuel Cost</label><input type='number' step='0.01' name='fuel_cost' class='form-control' value="${data.fuel_cost || ''}" required></div>
                 <div class='form-group'><label>Labor Cost</label><input type='number' step='0.01' name='labor_cost' class='form-control' value="${data.labor_cost || ''}" required></div>
                 <div class='form-group'><label>Tolls Cost</label><input type='number' step='0.01' name='tolls_cost' class='form-control' value="${data.tolls_cost || ''}" required></div>
                 <div class='form-group'><label>Other Cost</label><input type='number' step='0.01' name='other_cost' class='form-control' value="${data.other_cost || ''}" required></div>
                <div class='form-actions'>
                    <button type='submit' name='save_trip_cost' class='btn btn-primary'>Save Cost</button>
                </div>
            </form>`;
        showModal(title, formHtml);
        if (isEdit) {
            modal.querySelector(`select[name='trip_id']`).value = data.trip_id;
        }
    }

    document.getElementById('addCostBtn').addEventListener('click', () => openCostModal());
    document.querySelectorAll('.editCostBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            openCostModal(btn.dataset);
        });
    });

    // --- AI Per-Trip Cost Predictor ---
    const predictionData = <?php echo $prediction_json; ?>;
    let costModel;

    async function trainCostModel() {
        if (predictionData.length < 2) {
            document.getElementById('ai-status').textContent = 'Kulang ang data para sa AI model.';
            return;
        }
        tf.util.shuffle(predictionData);
        const features = predictionData.map(d => [parseFloat(d.distance_km), parseFloat(d.tolls_cost)]);
        const labels = predictionData.map(d => parseFloat(d.total_cost));
        const featureTensor = tf.tensor2d(features);
        const labelTensor = tf.tensor2d(labels, [labels.length, 1]);
        costModel = tf.sequential();
        costModel.add(tf.layers.dense({ inputShape: [2], units: 1 }));
        costModel.compile({ optimizer: 'adam', loss: 'meanSquaredError' });
        await costModel.fit(featureTensor, labelTensor, { epochs: 50 });
        document.getElementById('ai-status').textContent = 'Handa na ang AI Model.';
        document.getElementById('ai-predictor-form').style.display = 'block';
    }
    
    document.getElementById('predictBtn').addEventListener('click', () => {
        const distance = parseFloat(document.getElementById('distance').value);
        const tolls = parseFloat(document.getElementById('tolls').value);
        const output = document.getElementById('prediction-output');
        if (isNaN(distance) || isNaN(tolls)) {
            output.innerHTML = `<div class='message-banner error'>Please enter valid numbers.</div>`;
            return;
        }
        const inputTensor = tf.tensor2d([[distance, tolls]]);
        const prediction = costModel.predict(inputTensor);
        const predictedCost = prediction.dataSync()[0];
        output.innerHTML = `<div class='message-banner success' style='padding: 0.5rem;'><strong>Predicted:</strong> ₱${predictedCost.toFixed(2)}</div>`;
    });

    // --- AI Daily Cost Predictor ---
    const dailyCostData = <?php echo $daily_costs_json; ?>;
    async function trainAndPredictDaily() {
        const statusEl = document.getElementById('daily-prediction-loader');
        if (dailyCostData.length < 2) {
            statusEl.textContent = 'Kulang ang daily data para sa AI model.';
            return;
        }
        const days = dailyCostData.map(d => d.day);
        const totals = dailyCostData.map(d => d.total);
        const xs = tf.tensor1d(days);
        const ys = tf.tensor1d(totals);
        const model = tf.sequential();
        model.add(tf.layers.dense({ units: 1, inputShape: [1] }));
        model.compile({ loss: 'meanSquaredError', optimizer: tf.train.adam(0.1) });
        await model.fit(xs, ys, { epochs: 200 });
        const nextDayIndex = days.length + 1;
        const prediction = model.predict(tf.tensor1d([nextDayIndex]));
        const predictedCost = prediction.dataSync()[0];
        statusEl.style.display = 'none';
        const resultEl = document.getElementById('daily-prediction-result');
        resultEl.textContent = '₱' + parseFloat(predictedCost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        resultEl.style.display = 'block';
    }
    
    // --- Route Optimizer Logic (Using OSRM and OpenStreetMap) ---
    const findRouteBtn = document.getElementById('findRouteBtn');
    const routeOutputDiv = document.getElementById('route-output');
    let routeGeometry; 
    let routeLayer = null;

    findRouteBtn.addEventListener('click', async () => {
        const start = document.getElementById('startLocation').value;
        const end = document.getElementById('endLocation').value;
        if (!start || !end) {
            alert('Pakilagay ang simula at dulo ng lokasyon.');
            return;
        }
        findRouteBtn.textContent = 'Calculating...';
        findRouteBtn.disabled = true;
        try {
            // Geocoding using Nominatim (free)
            const startCoordsUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(start)}&countrycodes=PH&limit=1`;
            const endCoordsUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(end)}&countrycodes=PH&limit=1`;
            const [startResponse, endResponse] = await Promise.all([fetch(startCoordsUrl), fetch(endCoordsUrl)]);
            const startData = await startResponse.json();
            const endData = await endResponse.json();
            if (startData.length === 0 || endData.length === 0) {
                throw new Error('Hindi mahanap ang isa o parehong lokasyon. Paki-specify pa.');
            }
            const startCoords = { lat: startData[0].lat, lon: startData[0].lon };
            const endCoords = { lat: endData[0].lat, lon: endData[0].lon };
            
            // Routing using OSRM (free)
            const directionsUrl = `https://router.project-osrm.org/route/v1/driving/${startCoords.lon},${startCoords.lat};${endCoords.lon},${endCoords.lat}?overview=full&geometries=geojson`;
            const directionsResponse = await fetch(directionsUrl);
            const directionsData = await directionsResponse.json();
            if (directionsData.code !== 'Ok' || directionsData.routes.length === 0) {
                 throw new Error('Walang rutang nahanap sa pagitan ng mga lokasyong ito.');
            }
            
            const route = directionsData.routes[0];
            routeGeometry = route.geometry;
            const distanceKm = (route.distance / 1000).toFixed(2);
            const durationSeconds = route.duration;
            const hours = Math.floor(durationSeconds / 3600);
            const minutes = Math.floor((durationSeconds % 3600) / 60);
            const durationFormatted = `${hours}h ${minutes}m`;
            // Simple cost estimation formula
            const estimatedCost = (distanceKm * 15 + (durationSeconds / 3600) * 100).toFixed(2);
            document.getElementById('routeDistance').textContent = distanceKm;
            document.getElementById('routeDuration').textContent = durationFormatted;
            document.getElementById('routeCost').textContent = estimatedCost;
            document.getElementById('hiddenDistance').value = distanceKm;
            document.getElementById('hiddenDuration').value = durationFormatted;
            document.getElementById('hiddenCost').value = estimatedCost;
            routeOutputDiv.style.display = 'block';
        } catch (error) {
            alert(`Error: ${error.message}`);
        } finally {
            findRouteBtn.textContent = 'Find Optimal Route';
            findRouteBtn.disabled = false;
        }
    });
    
    const routeMapModal = document.getElementById('routeMapModal');
    let routeMap; 
    document.getElementById('viewRouteMapBtn').addEventListener('click', () => {
        routeMapModal.style.display = 'block';
        if (!routeMap) {
            routeMap = L.map('routeMap').setView([12.8797, 121.7740], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(routeMap);
        }
        
        // This timeout ensures the map container is visible before Leaflet tries to calculate its size
        setTimeout(() => {
            routeMap.invalidateSize(); // Important for maps in modals
            if (routeLayer) {
                routeMap.removeLayer(routeLayer);
            }
            if (routeGeometry) {
                routeLayer = L.geoJSON(routeGeometry, {
                    style: { color: '#3887be', weight: 5, opacity: 0.75 }
                }).addTo(routeMap);
                routeMap.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }
        }, 10);
    });
    routeMapModal.querySelector('.close-button').onclick = () => { routeMapModal.style.display = 'none'; };

    window.onload = () => { 
        trainCostModel(); 
        trainAndPredictDaily();
    };
  </script>
</body>
</html>

