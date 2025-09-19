<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
require_once 'db_connect.php';

// Fetch Dashboard Stats
$successful_deliveries = $conn->query("SELECT COUNT(*) as count FROM trips WHERE status = 'Completed'")->fetch_assoc()['count'];
$active_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'En Route'")->fetch_assoc()['count'];
$recent_trips = $conn->query("SELECT t.trip_code, t.destination, t.status, v.type FROM trips t JOIN vehicles v ON t.vehicle_id = v.id ORDER BY t.pickup_time DESC LIMIT 5");

// Fetch MONTHLY historical data for the top card
$historical_query = $conn->query("
    SELECT SUM(tc.total_cost) as monthly_cost
    FROM trip_costs tc
    JOIN trips t ON tc.trip_id = t.id
    WHERE MONTH(t.pickup_time) = MONTH(CURDATE()) AND YEAR(t.pickup_time) = YEAR(CURDATE())
");
$current_month_cost = $historical_query->fetch_assoc()['monthly_cost'] ?? 0;

// Fetch DAILY aggregated costs for AI analysis AND the daily chart
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
$daily_chart_data = []; // Data specifically for the daily graph
if ($daily_costs_query) {
    $day_index = 1;
    while ($row = $daily_costs_query->fetch_assoc()) {
        // This is for the AI model to predict tomorrow's cost
        $daily_costs_for_ai[] = [
            "day" => $day_index++,
            "total" => (float)$row['grand_total']
        ];
        // This is for displaying the daily data on the chart
        $daily_chart_data[] = [
            "label" => date("M d", strtotime($row['trip_date'])),
            "cost" => (float)$row['grand_total']
        ];
    }
}
$daily_costs_json = json_encode($daily_costs_for_ai);
$daily_chart_json = json_encode($daily_chart_data);

// Fetch the cost from the latest day with recorded trips
$latest_date_query = $conn->query("SELECT MAX(DATE(t.pickup_time)) as latest_date FROM trips t JOIN trip_costs tc ON t.id = tc.trip_id");
$latest_date_row = $latest_date_query->fetch_assoc();
$latest_date = $latest_date_row['latest_date'] ?? null;

$latest_daily_cost = 0;
if ($latest_date) {
    $cost_query = $conn->prepare("
        SELECT SUM(tc.total_cost) as total
        FROM trip_costs tc
        JOIN trips t ON tc.trip_id = t.id
        WHERE DATE(t.pickup_time) = ?
    ");
    $cost_query->bind_param("s", $latest_date);
    $cost_query->execute();
    $result = $cost_query->get_result();
    $latest_daily_cost = $result->fetch_assoc()['total'] ?? 0;
}


// Fetch live tracking data for the map
$tracking_data_query = $conn->query("
    SELECT v.type, v.model, d.name as driver_name, tl.latitude, tl.longitude
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
  <title>Admin Dashboard | LOGISTICS II</title>
  <link rel="stylesheet" href="style.css">
  <!-- TensorFlow.js and Chart.js Libraries -->
  <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest/dist/tf.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Leaflet.js for Maps -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2 </div>
    <a href="landpage.php" class="active">Dashboard</a>
    <a href="FVM.php">Fleet & Vehicle Management (FVM)</a>
    <a href="VRDS.php">Vehicle Reservation & Dispatch System (VRDS)</a>
    <a href="DTPM.php">Driver and Trip Performance Monitoring</a>
    <a href="TCAO.php">Transport Cost Analysis & Optimization (TCAO)</a>
    <a href="MA.php">Mobile Fleet Command App</a>
    <a href="logout.php">Logout</a>
  </div>

  <div class="content" id="mainContent">
    <div class="header">
      <div class="hamburger" id="hamburger">☰</div>
      <div><h1>Admin Dashboard <span class="system-title">| LOGISTICS II</span></h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>

    <section class="logistics-section">
      <h2>Logistics Overview</h2>
      <div class="dashboard-cards">
        <div class="card"><h3>Successful Deliveries</h3><div class="stat-value"><?php echo $successful_deliveries; ?></div><div class="stat-label">All Time</div></div>
        <div class="card"><h3>Active Vehicles</h3><div class="stat-value"><?php echo $active_vehicles; ?></div><div class="stat-label">Currently En Route</div></div>
        <div class="card"><h3>Trip Cost This Month</h3><div class="stat-value">₱<?php echo number_format($current_month_cost, 2); ?></div><div class="stat-label">Current Month</div></div>
        <div class="card">
            <h3>AI Forecast for Tomorrow</h3>
            <div id="daily-prediction-loader-card" class="stat-label">Calculating...</div>
            <div id="daily-prediction-result-card" class="stat-value" style="display:none; color: var(--success-color);"></div>
            <div class="stat-label">Predicted Total Cost</div>
        </div>
      </div>

      <!-- Live Map Section -->
      <div class="card map-container-full">
        <h3>Live Vehicle Map</h3>
        <div id="map"></div>
      </div>

      <div class="dashboard-grid-bottom">
        <div class="ai-section card">
            <h3>AI-Powered Analytics</h3>
            <div class="card">
                <h3>Latest Daily Cost</h3>
                <div class="stat-value">₱<?php echo number_format($latest_daily_cost, 2); ?></div>
                <div class="stat-label"><?php echo $latest_date ? date("F d, Y", strtotime($latest_date)) : 'No data available'; ?></div>
            </div>
            <div class="card" style="margin-top: 1.5rem;">
                <h3>Daily Trip Cost Trend</h3>
                <div style="height: 250px;"><canvas id="costChart"></canvas></div>
            </div>
        </div>

        <div class="table-section-2">
          <h3>Recent Trips</h3>
          <table>
              <thead><tr><th>Trip Code</th><th>Vehicle Type</th><th>Destination</th><th>Status</th></tr></thead>
              <tbody>
                  <?php if ($recent_trips->num_rows > 0): ?>
                      <?php while($trip = $recent_trips->fetch_assoc()): ?>
                      <tr class="clickable-row" 
                          data-trip_code="<?php echo htmlspecialchars($trip['trip_code']); ?>"
                          data-vehicle_type="<?php echo htmlspecialchars($trip['type']); ?>"
                          data-destination="<?php echo htmlspecialchars($trip['destination']); ?>"
                          data-status="<?php echo htmlspecialchars($trip['status']); ?>">
                          <td><?php echo htmlspecialchars($trip['trip_code']); ?></td>
                          <td><?php echo htmlspecialchars($trip['type']); ?></td>
                          <td><?php echo htmlspecialchars($trip['destination']); ?></td>
                          <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '.', $trip['status'])); ?>"><?php echo htmlspecialchars($trip['status']); ?></span></td>
                      </tr>
                      <?php endwhile; ?>
                  <?php else: ?>
                      <tr><td colspan="4">No recent trips found.</td></tr>
                  <?php endif; ?>
              </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>

  <!-- Pop-up Modal HTML -->
  <div id="tripDetailsModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h2 id="modalTitle">Trip Details</h2>
      <div id="modalBody">
        <!-- Trip details will be populated by JavaScript -->
      </div>
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

    // --- Leaflet Map Initialization ---
    const map = L.map('map').setView([12.8797, 121.7740], 5); // Philippines center
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    const locations = <?php echo $locations_json; ?>;
    locations.forEach(loc => {
        const popupContent = `<h3>${loc.type} ${loc.model}</h3><p>Driver: ${loc.driver_name}</p>`;
        L.marker([loc.latitude, loc.longitude]).addTo(map)
            .bindPopup(popupContent);
    });

    // --- AI Prediction for DAILY forecast VALUE ---
    const dailyCostDataForAI = <?php echo $daily_costs_json; ?>;
    async function trainAndPredictDaily() {
        const statusEl = document.getElementById('daily-prediction-loader-card');
        if (dailyCostDataForAI.length < 2) {
            statusEl.textContent = 'Not enough data.';
            return;
        }
        const days = dailyCostDataForAI.map(d => d.day);
        const totals = dailyCostDataForAI.map(d => d.total);
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
        const resultEl = document.getElementById('daily-prediction-result-card');
        resultEl.textContent = '₱' + parseFloat(predictedCost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        resultEl.style.display = 'block';
    }

    // --- Setup for DAILY chart ---
    const dailyChartData = <?php echo $daily_chart_json; ?>;
    function setupDailyChart() {
        if (dailyChartData.length === 0) {
            return;
        }
        const chartLabels = dailyChartData.map(d => d.label);
        const chartCosts = dailyChartData.map(d => d.cost);

        new Chart(document.getElementById('costChart'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Daily Cost',
                    data: chartCosts,
                    borderColor: 'rgba(78, 115, 223, 1)',
                    backgroundColor: 'rgba(78, 115, 223, 0.1)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                scales: {
                    y: {
                        ticks: {
                            callback: function(value, index, values) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }
    
    window.onload = () => {
        trainAndPredictDaily();
        setupDailyChart();
    };

    // --- JavaScript for Pop-up Modal ---
    const tripModal = document.getElementById('tripDetailsModal');
    const modalTitle = tripModal.querySelector('#modalTitle');
    const modalBody = tripModal.querySelector('#modalBody');
    const closeModal = tripModal.querySelector('.close-button');

    closeModal.onclick = function() { tripModal.style.display = 'none'; }
    window.onclick = function(event) { if (event.target == tripModal) { tripModal.style.display = 'none'; } }
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const tripCode = row.dataset.trip_code;
            const vehicleType = row.dataset.vehicle_type;
            const destination = row.dataset.destination;
            const status = row.dataset.status;
            modalTitle.textContent = `Details for Trip: ${tripCode}`;
            modalBody.innerHTML = `
                <p><strong>Vehicle:</strong> ${vehicleType}</p>
                <p><strong>Destination:</strong> ${destination}</p>
                <p><strong>Status:</strong> <span class="status-badge status-${status.toLowerCase().replace(' ', '.')}">${status}</span></p>
                <p style="margin-top: 1rem; font-size: 0.9em; color: #888;"><i>(This is a view-only summary. To edit, please go to the VRDS module.)</i></p>
            `;
            tripModal.style.display = 'block';
        });
    });
  </script>
</body>
</html>

