<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gateway Monitor</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Gateway Monitor</span>
            <div class="d-flex align-items-center">
                <select id="metricSelect" class="form-select form-select-sm me-2" onchange="loadChartData()" aria-label="Metric Select">
                    <option value="avg">Average Latency</option>
                    <option value="last">Last Latency</option>
                    <option value="best">Best Latency</option>
                    <option value="worst">Worst Latency</option>
                    <option value="stdev">StDev (Unstability)</option>
                    <option value="loss">Packet Loss (%)</option>
                    <option value="bufferbloat">Bufferbloat (Added Latency)</option>
                    <option value="speedtest">Speed Test (Mbps)</option>
                </select>
                <select id="hopSelect" class="form-select form-select-sm me-2" onchange="loadChartData()" aria-label="Hop Select">
                    <option value="last">Last Hop (Destination)</option>
                </select>
                <select id="dataPeriod" class="form-select form-select-sm me-2" onchange="toggleCustomRange()" aria-label="Data Period">
                    <option value="30m">Last 30 mins</option>
                    <option value="60m">Last 60 mins</option>
                    <option value="120m">Last 2 hours</option>
                    <option value="8h">Last 8 hours</option>
                    <option value="24h">Last 24 hours</option>
                    <option value="7d">Last 7 days</option>
                    <option value="30d">Last 30 days</option>
                    <option value="custom">Custom Range</option>
                </select>
                <div id="customRangeInputs" class="d-none d-flex align-items-center me-2">
                    <input type="datetime-local" id="startDate" class="form-control form-control-sm me-1" onchange="loadChartData()" aria-label="Start Date">
                    <input type="datetime-local" id="endDate" class="form-control form-control-sm me-1" onchange="loadChartData()" aria-label="End Date">
                </div>
                <button class="btn btn-outline-light btn-sm me-2" onclick="loadChartData()">Refresh</button>
                <button class="btn btn-outline-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#manageHostsModal">
                    <i class="bi bi-cpu me-1"></i> Hosts
                </button>
                <button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal">
                    <i class="bi bi-gear me-1"></i> Settings
                </button>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title" id="chartTitle">Latency Comparison (Last Hop)</h5>
                        <div class="chart-container">
                            <canvas id="comparisonChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Analysis & Grades</h5>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#scoringSystem">
                                <i class="bi bi-info-circle me-1"></i> Scoring System
                            </button>
                        </div>
                        
                        <div id="scoringSystem" class="collapse mb-4">
                            <div class="card card-body bg-light border-0">
                                <h6 class="fw-bold">How are these grades calculated?</h6>
                                <p class="small text-muted mb-2">We measure "Added Latency" (Bloat), which is the extra delay added to your connection when it is under full load. Lower added latency means a more responsive connection for gaming and real-time apps.</p>
                                <div class="row g-2">
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-success w-100 p-2">A+ &lt; 2ms</div>
                                        <div class="small mt-1">Perfect</div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-success w-100 p-2">A &lt; 10ms</div>
                                        <div class="small mt-1">Competitive</div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-info w-100 p-2">B &lt; 25ms</div>
                                        <div class="small mt-1">Solid</div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-warning text-dark w-100 p-2">C &lt; 50ms</div>
                                        <div class="small mt-1">Fair</div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-danger w-100 p-2">D &lt; 100ms</div>
                                        <div class="small mt-1">Poor</div>
                                    </div>
                                    <div class="col-6 col-md-2 text-center">
                                        <div class="badge bg-danger w-100 p-2">F &gt; 100ms</div>
                                        <div class="small mt-1">Unplayable</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="bufferbloatGrades">
                            <p class="text-muted">Loading analysis...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <ul class="nav nav-tabs mb-3" id="dashboardTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="mtr-tab" data-bs-toggle="tab" data-bs-target="#mtr-pane" type="button" role="tab" aria-controls="mtr-pane" aria-selected="true">
                            <i class="bi bi-list-ul me-1"></i> MTR Raw Data
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="speedtest-tab" data-bs-toggle="tab" data-bs-target="#speedtest-pane" type="button" role="tab" aria-controls="speedtest-pane" aria-selected="false">
                            <i class="bi bi-speedometer2 me-1"></i> Speed Test History
                        </button>
                    </li>
                </ul>
                <div class="tab-content" id="dashboardTabsContent">
                    <!-- MTR Data Tab -->
                    <div class="tab-pane fade show active" id="mtr-pane" role="tabpanel" aria-labelledby="mtr-tab" tabindex="0">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0">MTR Results (Selected Period)</h5>
                                    <div class="d-flex align-items-center">
                                        <label for="mtrHostFilter" class="form-label small text-muted mb-0 me-2">Host:</label>
                                        <select id="mtrHostFilter" class="form-select form-select-sm w-auto me-2" onchange="filterMtrData()">
                                            <option value="all">All Hosts</option>
                                        </select>
                                        <label for="mtrHopFilter" class="form-label small text-muted mb-0 me-2">Hop:</label>
                                        <select id="mtrHopFilter" class="form-select form-select-sm w-auto me-3" onchange="filterMtrData()">
                                            <option value="all">All Hops</option>
                                        </select>
                                        <a id="downloadMtrCsv" href="#" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download me-1"></i> Download MTR CSV
                                        </a>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                                    <table id="mtrDataTable" class="table table-sm table-hover align-middle">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>Host</th>
                                                <th>Time</th>
                                                <th>Target</th>
                                                <th>Hop</th>
                                                <th>Hostname</th>
                                                <th>Avg</th>
                                                <th>Loss%</th>
                                                <th>Load</th>
                                            </tr>
                                        </thead>
                                        <tbody id="mtrRawData">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted">No data available.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Speedtest Tab -->
                    <div class="tab-pane fade" id="speedtest-pane" role="tabpanel" aria-labelledby="speedtest-tab" tabindex="0">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="card-title mb-0">Speed Test History</h5>
                                    <div class="d-flex align-items-center">
                                        <label for="historyHostFilter" class="form-label small text-muted mb-0 me-2">Filter:</label>
                                        <select id="historyHostFilter" class="form-select form-select-sm w-auto me-3" onchange="filterSpeedTestHistory()">
                                            <option value="all">All Hosts</option>
                                        </select>
                                        <a id="downloadSpeedtestCsv" href="#" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download me-1"></i> Download Speedtest CSV
                                        </a>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table id="speedHistoryTable" class="table table-sm table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Host</th>
                                                <th>Time</th>
                                                <th>Down (Mbps)</th>
                                                <th>Up (Mbps)</th>
                                                <th>Idle (ms)</th>
                                                <th>Down Bloat</th>
                                                <th>Up Bloat</th>
                                                <th>Result</th>
                                            </tr>
                                        </thead>
                                        <tbody id="speedTestHistory">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted">No speed tests found in this period.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Manage Hosts Modal -->
        <div class="modal fade modal-lg" id="manageHostsModal" tabindex="-1" aria-labelledby="manageHostsModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="manageHostsModalLabel">Manage Gateways</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Manage your WAN connections. API keys are used in your <code>monitor.sh</code> and <code>speedtest.sh</code> scripts.</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>API Key</th>
                                        <th>Speedtest Server</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="manageHostsTableBody">
                                    <!-- Rows injected by JS -->
                                </tbody>
                            </table>
                        </div>
                        <div id="addHostForm" class="mt-3 p-3 bg-light rounded d-none">
                            <h6>Add New Gateway</h6>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <input type="text" id="newHostName" class="form-control form-control-sm" placeholder="WAN Name (e.g. Starlink)" aria-label="New Host Name">
                                </div>
                                <div class="col-md-4">
                                    <input type="text" id="newHostServerId" class="form-control form-control-sm" placeholder="Server ID (Optional)" aria-label="Speedtest Server ID">
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-sm btn-primary w-100" onclick="submitNewHost()">Add</button>
                                </div>
                            </div>
                        </div>
                        <button id="showAddHostBtn" class="btn btn-sm btn-success mt-2" onclick="toggleAddHostForm()">
                            <i class="bi bi-plus-circle me-1"></i> Add New Gateway
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="settingsModalLabel">Global Configuration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="settingsForm">
                        <div class="mb-3">
                            <label for="defaultMetric" class="form-label">Default Dashboard Metric</label>
                            <select id="defaultMetric" class="form-select">
                                <option value="avg">Average Latency</option>
                                <option value="last">Last Latency</option>
                                <option value="best">Best Latency</option>
                                <option value="worst">Worst Latency</option>
                                <option value="stdev">StDev (Unstability)</option>
                                <option value="loss">Packet Loss (%)</option>
                                <option value="bufferbloat">Bufferbloat (Added Latency)</option>
                                <option value="speedtest">Speed Test (Mbps)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="defaultHop" class="form-label">Default Hop</label>
                            <select id="defaultHop" class="form-select">
                                <option value="last">Last Hop (Destination)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="defaultPeriod" class="form-label">Default Time Period</label>
                            <select id="defaultPeriod" class="form-select">
                                <option value="30m">Last 30 mins</option>
                                <option value="60m">Last 60 mins</option>
                                <option value="120m">Last 2 hours</option>
                                <option value="8h">Last 8 hours</option>
                                <option value="24h">Last 24 hours</option>
                                <option value="7d">Last 7 days</option>
                                <option value="30d">Last 30 days</option>
                            </select>
                        </div>
                        <div class="mb-3 border-top pt-3">
                            <h6 class="fw-bold">Speedtest Configuration</h6>
                            <label for="speedtestInterval" class="form-label">Test Interval (Minutes)</label>
                            <input type="number" id="speedtestInterval" class="form-control" min="15" step="15" value="60">
                            <div class="form-text">How often to run Bufferbloat tests.</div>
                        </div>
                        <div class="mb-3">
                            <label for="speedtestServerId" class="form-label">Global Default Server ID (Optional)</label>
                            <input type="text" id="speedtestServerId" class="form-control" placeholder="Automatic">
                            <div class="form-text">Used if no host-specific server is set. Find IDs at <a href="https://www.speedtest.net/performance/servers" target="_blank">speedtest.net</a></div>
                        </div>
                        <div class="mb-3 border-top pt-3">
                            <h6 class="fw-bold">Maintenance</h6>
                            <label for="dataRetentionDays" class="form-label">Data Retention (Days)</label>
                            <input type="number" id="dataRetentionDays" class="form-control" min="1" step="1" value="30">
                            <div class="form-text">Old data will be pruned automatically.</div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
