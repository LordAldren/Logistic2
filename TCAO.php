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
            $message = "<div class='message-banner error'>Error: A cost record for this trip already exists. Please edit it from the list.</div>";
        } else {
            $sql = "INSERT INTO trip_costs (trip_id, fuel_cost, labor_cost, tolls_cost, other_cost) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("idddd", $trip_id, $fuel_cost, $labor_cost, $tolls_cost, $other_cost);
            if ($stmt->execute()) {
                $message = "<div class='message-banner success'>Trip cost saved successfully!</div>";
            } else {
                $message = "<div class='message-banner error'>Error saving trip cost: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else { // Update existing
        $sql = "UPDATE trip_costs SET trip_id=?, fuel_cost=?, labor_cost=?, tolls_cost=?, other_cost=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iddddi", $trip_id, $fuel_cost, $labor_cost, $tolls_cost, $other_cost, $id);
         if ($stmt->execute()) {
            $message = "<div class='message-banner success'>Trip cost updated successfully!</div>";
        } else {
            $message = "<div class='message-banner error'>Error updating trip cost: " . $conn->error . "</div>";
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
        $message = "<div class='message-banner success'>Cost record deleted successfully.</div>";
    } else {
        $message = "<div class='message-banner error'>Error deleting record.</div>";
    }
    $stmt->close();
}

// Fetch Data
$trip_costs = $conn->query("SELECT tc.*, t.trip_code, v.type, v.model 
                            FROM trip_costs tc 
                            JOIN trips t ON tc.trip_id = t.id
                            JOIN vehicles v ON t.vehicle_id = v.id
                            ORDER BY tc.id DESC");

$all_trips = $conn->query("SELECT id, trip_code, destination FROM trips ORDER BY pickup_time DESC");

// Data for AI Per-Trip Cost Prediction - Note: This query might need adjustment as it relied on the 'routes' table.
// For now, we remove the join to 'routes' to prevent errors.
$cost_prediction_data = $conn->query("
    SELECT tc.tolls_cost, tc.total_cost 
    FROM trip_costs tc
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
            <thead><tr><th>Date</th><th>Total Fuel Cost</th><th>Total Labor Cost</th><th>Total Tolls Cost</th><th>Grand Total</th></tr></thead>
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
                    <tr><td colspan="5">No daily cost data found.</td></tr>
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
                            <a href="TCAO.php?delete_cost=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?');">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">No per-trip cost data found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

  </div>
  
  <div id="actionModal" class="modal"><div class="modal-content"><span class="close-button">&times;</span><h2 id="modalTitle"></h2><div id="modalBody"></div></div></div>
     
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
                    <button type="button" class="btn btn-secondary cancelBtn" onclick="document.getElementById('actionModal').style.display='none'">Cancel</button>
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
            document.getElementById('ai-status').textContent = 'Not enough data for the AI model.';
            return;
        }
        tf.util.shuffle(predictionData);
        
        // Note: The features are simplified since distance_km is no longer available
        const features = predictionData.map(d => [parseFloat(d.tolls_cost) || 0]);
        const labels = predictionData.map(d => parseFloat(d.total_cost));
        const featureTensor = tf.tensor2d(features);
        const labelTensor = tf.tensor2d(labels, [labels.length, 1]);
        
        costModel = tf.sequential();
        costModel.add(tf.layers.dense({ inputShape: [1], units: 1 })); // Input shape is now 1
        costModel.compile({ optimizer: 'adam', loss: 'meanSquaredError' });
        
        await costModel.fit(featureTensor, labelTensor, { epochs: 50 });
        
        document.getElementById('ai-status').textContent = 'AI Model is ready.';
        
        // We hide the distance input as it's no longer used in the model
        const distanceInput = document.getElementById('distance');
        if (distanceInput) { distanceInput.style.display = 'none'; }
        
        document.getElementById('ai-predictor-form').style.display = 'block';
    }
    
    document.getElementById('predictBtn').addEventListener('click', () => {
        const tolls = parseFloat(document.getElementById('tolls').value);
        const output = document.getElementById('prediction-output');
        
        if (isNaN(tolls)) {
            output.innerHTML = `<div class='message-banner error'>Please enter a valid number for tolls.</div>`;
            return;
        }
        
        // Prediction now only uses tolls
        const inputTensor = tf.tensor2d([[tolls]]);
        const prediction = costModel.predict(inputTensor);
        const predictedCost = prediction.dataSync()[0];
        output.innerHTML = `<div class='message-banner success' style='padding: 0.5rem;'><strong>Predicted:</strong> ₱${predictedCost.toFixed(2)}</div>`;
    });


    // --- AI Daily Cost Predictor ---
    const dailyCostData = <?php echo $daily_costs_json; ?>;
    async function trainAndPredictDaily() {
        const statusEl = document.getElementById('daily-prediction-loader');
        if (dailyCostData.length < 2) {
            statusEl.textContent = 'Not enough daily data for the AI model.';
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
    
    window.onload = () => { 
        trainCostModel(); 
        trainAndPredictDaily();
    };
  </script>
</body>
</html>
