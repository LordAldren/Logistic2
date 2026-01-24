<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assets API Tester</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .test-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        .test-section h2 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .endpoint-url {
            background: #f7fafc;
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin-bottom: 15px;
            word-break: break-all;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .response-container {
            margin-top: 20px;
            display: none;
        }
        
        .response-container.show {
            display: block;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .status-success {
            background: #48bb78;
            color: white;
        }
        
        .status-error {
            background: #f56565;
            color: white;
        }
        
        .response-data {
            background: #1a202c;
            color: #68d391;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #667eea;
            font-weight: 600;
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        input[type="number"] {
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            width: 200px;
            margin-right: 10px;
        }
        
        input[type="number"]:focus {
            outline: none;
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Assets API Tester</h1>
        
        <!-- Test 1: Cargos and Vehicles -->
        <div class="test-section">
            <h2>📦 Test 1: Get Cargos and Vehicles</h2>
            <div class="endpoint-url">
                GET http://192.168.1.31/logistics1/api/assets.php?action=cargos_vehicles
            </div>
            <button onclick="testCargosVehicles()">Run Test</button>
            <div class="loading" id="loading1">
                <div class="spinner"></div>
                Loading...
            </div>
            <div class="response-container" id="response1">
                <div class="status-badge" id="status1"></div>
                <div class="stats" id="stats1"></div>
                <pre class="response-data" id="data1"></pre>
            </div>
        </div>
        
        <!-- Test 2: All Assets -->
        <div class="test-section">
            <h2>📋 Test 2: Get All Assets</h2>
            <div class="endpoint-url">
                GET http://192.168.1.31/logistics1/api/assets.php?action=list
            </div>
            <button onclick="testAllAssets()">Run Test</button>
            <div class="loading" id="loading2">
                <div class="spinner"></div>
                Loading...
            </div>
            <div class="response-container" id="response2">
                <div class="status-badge" id="status2"></div>
                <div class="stats" id="stats2"></div>
                <pre class="response-data" id="data2"></pre>
            </div>
        </div>
        
        <!-- Test 3: Filter by Type -->
        <div class="test-section">
            <h2>🔍 Test 3: Filter by Type</h2>
            <div class="endpoint-url">
                GET /logistics1/api/assets.php?action=list&type=Cargo Van,Vehicle
            </div>
            <button onclick="testFilteredAssets()">Run Test</button>
            <div class="loading" id="loading3">
                <div class="spinner"></div>
                Loading...
            </div>
            <div class="response-container" id="response3">
                <div class="status-badge" id="status3"></div>
                <div class="stats" id="stats3"></div>
                <pre class="response-data" id="data3"></pre>
            </div>
        </div>
        
        <!-- Test 4: Get Single Asset -->
        <div class="test-section">
            <h2>🎯 Test 4: Get Single Asset by ID</h2>
            <div class="endpoint-url">
                GET /logistics1/api/assets.php?action=get&id=<span id="assetIdDisplay">1</span>
            </div>
            <input type="number" id="assetId" value="1" min="1" placeholder="Asset ID">
            <button onclick="testSingleAsset()">Run Test</button>
            <div class="loading" id="loading4">
                <div class="spinner"></div>
                Loading...
            </div>
            <div class="response-container" id="response4">
                <div class="status-badge" id="status4"></div>
                <pre class="response-data" id="data4"></pre>
            </div>
        </div>
        
        <!-- Test 5: Get Asset Types -->
        <div class="test-section">
            <h2>🏷️ Test 5: Get Available Asset Types</h2>
            <div class="endpoint-url">
                GET http://192.168.1.31/logistics1/api/assets.php?action=cargos_vehicles
            </div>
            <button onclick="testAssetTypes()">Run Test</button>
            <div class="loading" id="loading5">
                <div class="spinner"></div>
                Loading...
            </div>
            <div class="response-container" id="response5">
                <div class="status-badge" id="status5"></div>
                <pre class="response-data" id="data5"></pre>
            </div>
        </div>
        
        <!-- Run All Tests -->
        <div class="test-section" style="text-align: center;">
            <button onclick="runAllTests()" style="font-size: 1.2rem; padding: 15px 40px;">
                ▶️ Run All Tests
            </button>
        </div>
    </div>
    
    <script>
        const baseUrl = window.location.origin + '/logistics1/api/assets.php';
        
        // Update asset ID display
        document.getElementById('assetId').addEventListener('input', function() {
            document.getElementById('assetIdDisplay').textContent = this.value;
        });
        
        async function makeRequest(url, testNumber) {
            const loadingEl = document.getElementById(loading${testNumber});
            const responseEl = document.getElementById(response${testNumber});
            const statusEl = document.getElementById(status${testNumber});
            const dataEl = document.getElementById(data${testNumber});
            
            loadingEl.classList.add('show');
            responseEl.classList.remove('show');
            
            try {
                const response = await fetch(url);
                const data = await response.json();
                
                loadingEl.classList.remove('show');
                responseEl.classList.add('show');
                
                if (data.success) {
                    statusEl.textContent = '✅ Success';
                    statusEl.className = 'status-badge status-success';
                } else {
                    statusEl.textContent = '❌ Error';
                    statusEl.className = 'status-badge status-error';
                }
                
                dataEl.textContent = JSON.stringify(data, null, 2);
                
                return data;
            } catch (error) {
                loadingEl.classList.remove('show');
                responseEl.classList.add('show');
                statusEl.textContent = '❌ Request Failed';
                statusEl.className = 'status-badge status-error';
                dataEl.textContent = Error: ${error.message};
                return null;
            }
        }
        
        async function testCargosVehicles() {
            const url = ${baseUrl}?action=cargos_vehicles;
            const data = await makeRequest(url, 1);
            
            if (data && data.success) {
                const statsEl = document.getElementById('stats1');
                statsEl.innerHTML = `
                    <div class="stat-card">
                        <div class="stat-number">${data.data.cargos.count}</div>
                        <div class="stat-label">Cargo Vans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.data.vehicles.count}</div>
                        <div class="stat-label">Vehicles</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.data.cargos.count + data.data.vehicles.count}</div>
                        <div class="stat-label">Total</div>
                    </div>
                `;
            }
        }
        
        async function testAllAssets() {
            const url = ${baseUrl}?action=list;
            const data = await makeRequest(url, 2);
            
            if (data && data.success) {
                const statsEl = document.getElementById('stats2');
                statsEl.innerHTML = `
                    <div class="stat-card">
                        <div class="stat-number">${data.data.total}</div>
                        <div class="stat-label">Total Assets</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.data.types.length}</div>
                        <div class="stat-label">Asset Types</div>
                    </div>
                `;
            }
        }
        
        async function testFilteredAssets() {
            const url = ${baseUrl}?action=list&type=Cargo Van,Vehicle;
            const data = await makeRequest(url, 3);
            
            if (data && data.success) {
                const statsEl = document.getElementById('stats3');
                statsEl.innerHTML = `
                    <div class="stat-card">
                        <div class="stat-number">${data.data.total}</div>
                        <div class="stat-label">Filtered Assets</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">${data.data.types.length}</div>
                        <div class="stat-label">Types Returned</div>
                    </div>
                `;
            }
        }
        
        async function testSingleAsset() {
            const assetId = document.getElementById('assetId').value;
            const url = ${baseUrl}?action=get&id=${assetId};
            await makeRequest(url, 4);
        }
        
        async function testAssetTypes() {
            const url = ${baseUrl}?action=types;
            await makeRequest(url, 5);
        }
        
        async function runAllTests() {
            await testCargosVehicles();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testAllAssets();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testFilteredAssets();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testSingleAsset();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testAssetTypes();
        }
    </script>
</body>
</html>