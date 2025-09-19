<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Role-based Redirect: Kung driver, sa mobile app ang punta.
if ($_SESSION['role'] === 'driver') {
    header("location: mobile_app.php");
    exit;
}

require_once 'db_connect.php';

// Fetch Dashboard Stats
$successful_deliveries = $conn->query("SELECT COUNT(*) as count FROM trips WHERE status = 'Completed'")->fetch_assoc()['count'];
$active_vehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'En Route'")->fetch_assoc()['count'];
$recent_trips = $conn->query("SELECT t.trip_code, t.destination, t.status, v.type FROM trips t JOIN vehicles v ON t.vehicle_id = v.id ORDER BY t.pickup_time DESC LIMIT 5");

$historical_query = $conn->query("
    SELECT SUM(tc.total_cost) as monthly_cost
    FROM trip_costs tc
    JOIN trips t ON tc.trip_id = t.id
    WHERE MONTH(t.pickup_time) = MONTH(CURDATE()) AND YEAR(t.pickup_time) = YEAR(CURDATE())
");
$current_month_cost = $historical_query->fetch_assoc()['monthly_cost'] ?? 0;

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
$daily_chart_data = []; 
if ($daily_costs_query) {
    $day_index = 1;
    while ($row = $daily_costs_query->fetch_assoc()) {
        $daily_costs_for_ai[] = [
            "day" => $day_index++,
            "total" => (float)$row['grand_total']
        ];
        $daily_chart_data[] = [
            "label" => date("M d", strtotime($row['trip_date'])),
            "cost" => (float)$row['grand_total']
        ];
    }
}
$daily_costs_json = json_encode($daily_costs_for_ai);
$daily_chart_json = json_encode($daily_chart_data);

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

// UPDATED QUERY to get initial locations WITH trip_id
$tracking_data_query = $conn->query("
    SELECT t.id as trip_id, v.type, v.model, d.name as driver_name, tl.latitude, tl.longitude
    FROM tracking_log tl
    JOIN trips t ON tl.trip_id = t.id
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d ON t.driver_id = d.id
    INNER JOIN (
        SELECT trip_id, MAX(log_time) AS max_log_time
        FROM tracking_log
        GROUP BY trip_id
    ) latest_log ON tl.trip_id = latest_log.trip_id AND tl.log_time = latest_log.max_log_time
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
  <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@latest/dist/tf.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <!-- ADDED SCRIPTS FOR LIVE TRACKING -->
  <script src="https://unpkg.com/leaflet.marker.slideto@0.2.0/Leaflet.Marker.SlideTo.js"></script>
  <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
  <script src="https://www.gstatic.com/firebasejs/8.10.1/firebase-database.js"></script>
</head>
<body>
  <div class="sidebar" id="sidebar">
    <div class="logo"><img src="logo.png" alt="SLATE Logo"></div>
    <div class="system-name">LOGISTIC 2 </div>
    
    <?php $role = $_SESSION['role']; ?>
    <a href="landpage.php" class="active">Dashboard</a>

    <?php if ($role === 'admin' || $role === 'staff'): ?>
        <a href="FVM.php">Fleet & Vehicle Management (FVM)</a>
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
      <div><h1>Admin Dashboard <span class="system-title">| LOGISTICS II</span></h1></div>
      <div class="theme-toggle-container">
        <span class="theme-label">Dark Mode</span>
        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
      </div>
    </div>

    <section class="logistics-section">
      <div class="dashboard-main-grid">
        
        <div class="dashboard-stats">
          <div class="dashboard-cards">
            <!-- Card 1: Successful Deliveries -->
            <div class="card">
              <div class="stat-icon icon-deliveries">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/><path fill-rule="evenodd" d="M10.146 8.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7 10.793l2.646-2.647a.5.5 0 0 1 .708 0z"/></svg>
              </div>
              <div class="stat-details">
                <h3>Successful Deliveries</h3>
                <div class="stat-value"><?php echo $successful_deliveries; ?></div>
              </div>
            </div>
            <!-- Card 2: Active Vehicles -->
            <div class="card">
              <div class="stat-icon icon-vehicles">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" viewBox="0 0 16 16"><path d="M0 3.5A1.5 1.5 0 0 1 1.5 2h9A1.5 1.5 0 0 1 12 3.5V5h1.02a1.5 1.5 0 0 1 1.17.563l1.481 1.85a1.5 1.5 0 0 1 .329.938V10.5a1.5 1.5 0 0 1-1.5 1.5H14a2 2 0 1 1-4 0H5a2 2 0 1 1-4 0H1.5a1.5 1.5 0 0 1-1.5-1.5v-7zM12 5V3.5a.5.5 0 0 0-.5-.5h-9a.5.5 0 0 0-.5.5V5h10zm-6 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm10 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM.5 10.5a.5.5 0 0 0 .5.5H2a1 1 0 0 1 2 0h5a1 1 0 0 1 2 0h1.5a.5.5 0 0 0 .5-.5V8.36a.5.5 0 0 0-.11-.312l-1.48-1.85A.5.5 0 0 0 13.02 6H12V5H1v5.5z"/></svg>
              </div>
              <div class="stat-details">
                <h3>Active Vehicles</h3>
                <div class="stat-value"><?php echo $active_vehicles; ?></div>
              </div>
            </div>
            <!-- Card 3: Trip Cost -->
            <div class="card">
              <div class="stat-icon icon-cost">
                 <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-wallet2" viewBox="0 0 16 16"><path d="M12.136.326A1.5 1.5 0 0 1 14 1.78V3h.5A1.5 1.5 0 0 1 16 4.5v8a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 12.5v-8A1.5 1.5 0 0 1 1.5 3H2V1.78a1.5 1.5 0 0 1 1.864-1.454l1.752.438a.5.5 0 0 1 .382.493V3h5.302V2.215a.5.5 0 0 1 .382-.493l1.752-.438zM2 4.5a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h13a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5h-13z"/></svg>
              </div>
              <div class="stat-details">
                <h3>Cost This Month</h3>
                <div class="stat-value">₱<?php echo number_format($current_month_cost, 2); ?></div>
              </div>
            </div>
            <!-- Card 4: AI Forecast -->
            <div class="card">
              <div class="stat-icon icon-ai">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bi bi-graph-up-arrow" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M0 0h1v15h15v1H0V0zm10 3.5a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-1 0V4.9l-3.613 4.417a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61L13.445 4H10.5a.5.5 0 0 1-.5-.5z"/></svg>
              </div>
              <div class="stat-details">
                <h3>AI Forecast</h3>
                <div id="daily-prediction-loader-card" class="stat-label">Calculating...</div>
                <div id="daily-prediction-result-card" class="stat-value" style="display:none;"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="card dashboard-map">
          <h3>Live Vehicle Map</h3>
          <div id="map"></div>
        </div>

        <div class="dashboard-cards-sidebar">
            <div class="table-section-2 card">
              <h3>Recent Trips</h3>
              <table>
                  <thead><tr><th>Trip Code</th><th>Vehicle</th><th>Destination</th><th>Status</th></tr></thead>
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

             <div class="ai-section card" style="margin-top: 1.5rem;">
                <h3>Daily Trip Cost Trend</h3>
                <div style="height: 250px;"><canvas id="costChart"></canvas></div>
            </div>
        </div>
      </div>
    </section>
  </div>

  <div id="tripDetailsModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h2 id="modalTitle">Trip Details</h2>
      <div id="modalBody">
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

    // --- LIVE TRACKING MAP ---
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
    firebase.initializeApp(firebaseConfig);
    const database = firebase.database();
    const markers = {};

    const map = L.map('map').setView([12.8797, 121.7740], 6); // Centered on PH
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Initial locations from PHP
    const locations = <?php echo $locations_json; ?>;
    locations.forEach(loc => {
        const popupContent = `<h3>${loc.type} ${loc.model}</h3><p>Driver: ${loc.driver_name}</p>`;
        const marker = L.marker([loc.latitude, loc.longitude]).addTo(map).bindPopup(popupContent);
        
        marker.options.duration = 2000;
        markers[loc.trip_id] = marker;
    });

    // Firebase listener for real-time updates
    const trackingRef = database.ref('live_tracking');
    trackingRef.on('child_changed', (snapshot) => {
        const tripId = snapshot.key;
        const locationData = snapshot.val();
        if (markers[tripId]) {
            const marker = markers[tripId];
            const newLatLng = [locationData.lat, locationData.lng];
            marker.slideTo(newLatLng, { duration: 2000 });
        }
    });

    // --- AI and Chart scripts ---
    const dailyCostDataForAI = <?php echo $daily_costs_json; ?>;
    async function trainAndPredictDaily() {
        const statusEl = document.getElementById('daily-prediction-loader-card');
        const resultEl = document.getElementById('daily-prediction-result-card');

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
        resultEl.textContent = '₱' + parseFloat(predictedCost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        resultEl.style.display = 'block';
    }

    const dailyChartData = <?php echo $daily_chart_json; ?>;
    function setupDailyChart() {
        if (dailyChartData.length === 0) return;
        const chartLabels = dailyChartData.map(d => d.label);
        const chartCosts = dailyChartData.map(d => d.cost);
        new Chart(document.getElementById('costChart'), {
            type: 'line', data: { labels: chartLabels, datasets: [{ label: 'Daily Cost', data: chartCosts, borderColor: 'rgba(78, 115, 223, 1)', backgroundColor: 'rgba(78, 115, 223, 0.1)', fill: true, tension: 0.3 }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { callback: function(value) { return '₱' + value.toLocaleString(); } } } } }
        });
    }
    
    window.onload = () => {
        trainAndPredictDaily();
        setupDailyChart();
        setTimeout(() => map.invalidateSize(), 200); // Invalidate map size after a short delay
    };

    // --- Trip details modal script ---
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
