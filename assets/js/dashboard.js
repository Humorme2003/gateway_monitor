let comparisonChart = null;
const HOST_COLORS = ['#0d6efd', '#198754', '#6610f2', '#fd7e14', '#dc3545', '#20c997', '#0dcaf0', '#ffc107', '#d63384'];
let hostColorMap = {};

function getHostColor(hostName) {
    if (!hostColorMap[hostName]) {
        const index = Object.keys(hostColorMap).length % HOST_COLORS.length;
        hostColorMap[hostName] = HOST_COLORS[index];
    }
    return hostColorMap[hostName];
}

function filterSpeedTestHistory() {
    const filterValue = document.getElementById('historyHostFilter').value;
    const rows = document.querySelectorAll('#speedTestHistory tr');
    
    rows.forEach(row => {
        if (!row.getAttribute('data-host')) return; // Skip "No tests found" row
        
        if (filterValue === 'all') {
            row.classList.remove('d-none');
        } else {
            const host = row.getAttribute('data-host');
            if (host === filterValue) {
                row.classList.remove('d-none');
            } else {
                row.classList.add('d-none');
            }
        }
    });
    updateDownloadLinks();
}

function filterMtrData() {
    const hostFilter = document.getElementById('mtrHostFilter').value;
    const hopFilter = document.getElementById('mtrHopFilter').value;
    const rows = document.querySelectorAll('#mtrRawData tr');
    
    rows.forEach(row => {
        if (!row.getAttribute('data-host')) return; 
        
        const host = row.getAttribute('data-host');
        const hop = row.getAttribute('data-hop');

        const hostMatch = (hostFilter === 'all' || host === hostFilter);
        const hopMatch = (hopFilter === 'all' || hop === hopFilter);

        if (hostMatch && hopMatch) {
            row.classList.remove('d-none');
        } else {
            row.classList.add('d-none');
        }
    });
    updateDownloadLinks();
}

async function initDashboard() {
    // Load settings
    const response = await fetch('api/settings.php');
    const settings = await response.json();

    if (settings.default_metric) document.getElementById('metricSelect').value = settings.default_metric;
    
    if (settings.default_hop) {
        const hopSelect = document.getElementById('hopSelect');
        const defaultHop = settings.default_hop;
        // If the default hop isn't in the initial list (only 'last' is), add it temporarily
        if (defaultHop !== 'last' && !hopSelect.querySelector(`option[value="${defaultHop}"]`)) {
            const opt = document.createElement('option');
            opt.value = defaultHop;
            opt.textContent = `Hop ${defaultHop}`;
            hopSelect.appendChild(opt);
        }
        hopSelect.value = defaultHop;
        
        // Same for the settings modal
        const modalHop = document.getElementById('defaultHop');
        if (defaultHop !== 'last' && !modalHop.querySelector(`option[value="${defaultHop}"]`)) {
            const opt = document.createElement('option');
            opt.value = defaultHop;
            opt.textContent = `Hop ${defaultHop}`;
            modalHop.appendChild(opt);
        }
        modalHop.value = defaultHop;
    }

    if (settings.default_period) document.getElementById('dataPeriod').value = settings.default_period;

    // Update other modal fields
    if (settings.default_metric) document.getElementById('defaultMetric').value = settings.default_metric;
    if (settings.default_period) document.getElementById('defaultPeriod').value = settings.default_period;
    if (settings.speedtest_interval) document.getElementById('speedtestInterval').value = settings.speedtest_interval;
    if (settings.speedtest_server_id) document.getElementById('speedtestServerId').value = settings.speedtest_server_id;
    if (settings.data_retention_days) document.getElementById('dataRetentionDays').value = settings.data_retention_days;

    loadChartData();
}

async function saveSettings() {
    const settings = {
        default_metric: document.getElementById('defaultMetric').value,
        default_hop: document.getElementById('defaultHop').value,
        default_period: document.getElementById('defaultPeriod').value,
        speedtest_interval: document.getElementById('speedtestInterval').value,
        speedtest_server_id: document.getElementById('speedtestServerId').value,
        data_retention_days: document.getElementById('dataRetentionDays').value
    };

    const response = await fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(settings)
    });

    if (response.ok) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('settingsModal'));
        modal.hide();
        // Update current view if it matches the new defaults or just reload to be sure
        location.reload(); 
    } else {
        alert('Failed to save settings');
    }
}

function toggleCustomRange() {
    const period = document.getElementById('dataPeriod').value;
    const customInputs = document.getElementById('customRangeInputs');
    if (period === 'custom') {
        customInputs.classList.remove('d-none');
    } else {
        customInputs.classList.add('d-none');
        loadChartData();
    }
}

function updateHopSelectors(maxHop) {
    const selectors = ['hopSelect', 'defaultHop', 'mtrHopFilter'];
    
    selectors.forEach(id => {
        const select = document.getElementById(id);
        if (!select) return;
        
        const currentValue = select.value;
        
        // Clear existing numbered options
        if (id === 'mtrHopFilter') {
            select.innerHTML = '<option value="all">All Hops</option>';
        } else {
            select.innerHTML = '<option value="last">Last Hop (Destination)</option>';
        }
        
        for (let i = 1; i <= maxHop; i++) {
            const option = document.createElement('option');
            option.value = i;
            option.textContent = `Hop ${i}${i === 1 ? ' (Local)' : ''}`;
            select.appendChild(option);
        }
        
        // Restore selection if it still exists
        if (currentValue === 'all' || currentValue === 'last' || parseInt(currentValue) <= maxHop) {
            select.value = currentValue;
        } else {
            // If it was a numbered hop that no longer exists, fall back to default
            if (id === 'mtrHopFilter') select.value = 'all';
            else if (id === 'hopSelect') select.value = 'last';
        }
    });
}

async function loadChartData() {
    const period = document.getElementById('dataPeriod').value;
    const hop = document.getElementById('hopSelect').value;
    const metric = document.getElementById('metricSelect').value;
    const chartContainer = document.querySelector('.chart-container');
    
    // Disable hop selector for Speedtest-based metrics
    const hopSelect = document.getElementById('hopSelect');
    if (['bufferbloat', 'download', 'upload', 'speedtest'].includes(metric)) {
        hopSelect.disabled = true;
        hopSelect.title = "Not applicable to Speedtest data";
    } else {
        hopSelect.disabled = false;
        hopSelect.title = "";
    }

    let url = `api/get_data.php?hop=${hop}&metric=${metric}`;
    
    if (period === 'custom') {
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        if (!start || !end) return;
        url += `&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
    } else {
        url += `&period=${period}`;
    }

    // Show loading state
    chartContainer.classList.add('loading');

    try {
        const response = await fetch(url);
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`API Error: ${response.status} - ${errorText}`);
        }
        
        const rawData = await response.json();
        
        if (rawData.error) {
            throw new Error(rawData.error);
        }

        let data;
        let events = [];
        
        if (rawData.data && rawData.events) {
            data = rawData.data;
            events = rawData.events;
            if (rawData.hosts) updateHostActions(rawData.hosts);
            if (rawData.max_hop !== undefined) updateHopSelectors(rawData.max_hop);
        } else {
            // Fallback for older API or unexpected structure
            data = rawData;
        }

        const hopTitle = hop === 'last' ? 'Last Hop' : `Hop ${hop}`;
        const metricLabel = document.getElementById('metricSelect').options[document.getElementById('metricSelect').selectedIndex].text;
        
        if (metric === 'bufferbloat') {
            document.getElementById('chartTitle').innerText = 'Bufferbloat History (Added Latency under Load)';
        } else if (metric === 'speedtest') {
            document.getElementById('chartTitle').innerText = 'Speed Test History (Download & Upload Mbps)';
        } else if (metric === 'download') {
            document.getElementById('chartTitle').innerText = 'Download Speed History (Mbps)';
        } else if (metric === 'upload') {
            document.getElementById('chartTitle').innerText = 'Upload Speed History (Mbps)';
        } else {
            document.getElementById('chartTitle').innerText = `${metricLabel} Comparison (${hopTitle})`;
        }

        const datasets = [];
        let i = 0;
        let labels;
        let rawTimestamps = []; // To store actual dates for better marker placement
        const annotations = [];

        // Determine date formatting based on period
        const showDate = ['120m', '8h', '24h', '7d', '30d', 'custom'].includes(period);

        // First pass: find ALL unique timestamps across ALL hosts to build a unified timeline
        // Normalize to nearest minute to align data from different hosts
        const normalizedTimestampsSet = new Set();
        for (const [, results] of Object.entries(data)) {
            if (!Array.isArray(results)) continue;
            results.forEach(r => { 
                if (r.timestamp) {
                    const d = new Date(r.timestamp);
                    d.setSeconds(0, 0);
                    normalizedTimestampsSet.add(d.getTime());
                }
            });
        }

        rawTimestamps = Array.from(normalizedTimestampsSet).map(t => new Date(t)).sort((a, b) => a - b);
        rawTimestamps = rawTimestamps.filter(d => !isNaN(d.getTime()));

        labels = rawTimestamps.map(date => {
            if (showDate) {
                return date.toLocaleDateString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            }
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        });

        // Second pass: build datasets aligned to the unified timeline
        for (const [hostName, results] of Object.entries(data)) {
            if (!Array.isArray(results)) continue;

            const color = HOST_COLORS[i % HOST_COLORS.length];
            const resultMap = new Map(results.map(r => {
                const d = new Date(r.timestamp);
                d.setSeconds(0, 0);
                return [d.getTime(), r];
            }));

            datasets.push({
                label: hostName,
                data: rawTimestamps.map(t => {
                    const r = resultMap.get(t.getTime());
                    return r ? r.value : null;
                }),
                borderColor: color,
                backgroundColor: color + '33',
                tension: 0.3,
                fill: false,
                spanGaps: true,
                pointRadius: (period === '7d' || period === '30d') ? 0 : 2,
                pointHoverRadius: 5,
                underLoad: rawTimestamps.map(t => {
                    const r = resultMap.get(t.getTime());
                    return r ? r.is_under_load : false;
                })
            });
            i++;
        }

        // Update underLoad flag and add vertical markers for speed test events
        if (!['bufferbloat', 'download', 'upload', 'speedtest'].includes(metric)) {
            events.forEach(event => {
                const eventTime = new Date(event.timestamp).getTime();
                const eventHost = event.host_name;
                let closestIdx = -1;
                let minDiff = Infinity;

                rawTimestamps.forEach((t, idx) => {
                    const diff = Math.abs(t.getTime() - eventTime);
                    if (diff < minDiff) {
                        minDiff = diff;
                        closestIdx = idx;
                    }

                    if (diff < 60000) { // Within 60 seconds of the minute-normalized point
                        datasets.forEach(ds => {
                            // Correlate loaded status to the specific host
                            if (ds.label === eventHost && ds.underLoad) {
                                ds.underLoad[idx] = true;
                            }
                        });
                    }
                });

                // Add orange vertical line for the event
                if (closestIdx !== -1 && minDiff < 60000 && period !== '7d' && period !== '30d') {
                    annotations.push({
                        type: 'line',
                        xMin: closestIdx,
                        xMax: closestIdx,
                        borderColor: 'rgba(255, 165, 0, 0.7)',
                        borderWidth: 2,
                        label: { display: false }
                    });
                }
            });
        }

        if (datasets.length === 0 || labels.length === 0) {
            chartContainer.classList.remove('loading');
            showNoDataMessage("No data available for the selected criteria.");
            if (comparisonChart) comparisonChart.destroy();
            updateGrades([]);
            return;
        }

        // Hide no data message if it exists
        hideNoDataMessage();

        updateGrades(events);
        updateRawDataTables(rawData.mtr && Object.keys(rawData.mtr).length > 0 ? rawData.mtr : data, events);
        updateDownloadLinks();

        const ctx = document.getElementById('comparisonChart').getContext('2d');
        if (comparisonChart) comparisonChart.destroy();

        comparisonChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    annotation: { annotations: annotations },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let unit = ' ms';
                                if (metric === 'stdev') unit = '';
                                if (metric === 'loss') unit = '%';
                                if (metric === 'download' || metric === 'upload' || metric === 'speedtest') unit = ' Mbps';
                                let label = context.dataset.label + ': ' + context.parsed.y + unit;
                                if (!['bufferbloat', 'download', 'upload', 'speedtest'].includes(metric) && context.dataset.underLoad && context.dataset.underLoad[context.dataIndex]) {
                                    label += ' (LOADED)';
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: metric === 'stdev' ? 'Value' : 
                                  (metric === 'loss' ? 'Loss (%)' : 
                                  (metric === 'bufferbloat' ? 'Added Latency (ms)' : 
                                  (metric === 'download' || metric === 'upload' || metric === 'speedtest' ? 'Speed (Mbps)' : 'Latency (ms)')))
                        },
                        grid: { color: '#e5e5e5' }
                    },
                    x: {
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
                        grid: { display: false }
                    }
                }
            }
        });
    } catch (error) {
        console.error("Error loading chart data:", error);
        showNoDataMessage(`Error: ${error.message}`);
        if (comparisonChart) comparisonChart.destroy();
    } finally {
        chartContainer.classList.remove('loading');
    }
}

function showNoDataMessage(msg) {
    let msgEl = document.getElementById('chart-message');
    if (!msgEl) {
        msgEl = document.createElement('div');
        msgEl.id = 'chart-message';
        document.querySelector('.chart-container').appendChild(msgEl);
    }
    msgEl.innerText = msg;
    msgEl.style.display = 'flex';
}

function hideNoDataMessage() {
    const msgEl = document.getElementById('chart-message');
    if (msgEl) msgEl.style.display = 'none';
}

function getGrade(bloat) {
    if (bloat < 2) return { grade: 'A+', color: 'text-success' };
    if (bloat < 10) return { grade: 'A', color: 'text-success' };
    if (bloat < 25) return { grade: 'B', color: 'text-info' };
    if (bloat < 50) return { grade: 'C', color: 'text-warning' };
    if (bloat < 100) return { grade: 'D', color: 'text-danger' };
    return { grade: 'F', color: 'text-danger' };
}

function updateGrades(events) {
    const container = document.getElementById('bufferbloatGrades');
    const historyContainer = document.getElementById('speedTestHistory');
    
    if (!events || events.length === 0) {
        container.innerHTML = '<p class="text-muted">No speed tests found in this period.</p>';
        historyContainer.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No speed tests found in this period.</td></tr>';
        return;
    }

    // Sort events by timestamp descending for history
    const sortedEvents = [...events].sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

    // Group by host and calculate stats
    const hostStats = {};
    events.forEach(event => {
        const key = event.host_name || 'Default';
        if (!hostStats[key]) {
            hostStats[key] = {
                latest: null,
                sumDown: 0,
                sumUp: 0,
                count: 0
            };
        }
        
        if (!hostStats[key].latest || new Date(event.timestamp) > new Date(hostStats[key].latest.timestamp)) {
            hostStats[key].latest = event;
        }
        
        hostStats[key].sumDown += parseFloat(event.download_mbps);
        hostStats[key].sumUp += parseFloat(event.upload_mbps);
        hostStats[key].count++;
    });

    let summaryHtml = '';
    // Sort hosts by name to ensure consistent display
    const sortedHosts = Object.keys(hostStats).sort();

    for (const hostName of sortedHosts) {
        const stats = hostStats[hostName];
        const latest = stats.latest;
        const avgDown = stats.sumDown / stats.count;
        const avgUp = stats.sumUp / stats.count;
        
        const downBloat = latest.latency_download - latest.latency_idle;
        const upBloat = latest.latency_upload - latest.latency_idle;
        
        const downGrade = getGrade(downBloat);
        const upGrade = getGrade(upBloat);
        const hostColor = getHostColor(hostName);

        summaryHtml += `
            <div class="mb-4">
                <h6 class="pb-1 mb-3" style="border-bottom: 2px solid ${hostColor};">
                    <i class="bi bi-cpu me-1" style="color: ${hostColor}"></i> ${hostName} 
                    <small class="text-muted fs-6 float-end fw-normal">Latest Test: ${new Date(latest.timestamp).toLocaleString()}</small>
                </h6>
                <div class="row">
                    <div class="col-md-3">
                        <div class="small text-muted">Download Speed</div>
                        <div class="h4 mb-0">${parseFloat(latest.download_mbps).toFixed(1)} Mbps</div>
                        <div class="small text-secondary">Avg: ${avgDown.toFixed(1)} Mbps</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Upload Speed</div>
                        <div class="h4 mb-0">${parseFloat(latest.upload_mbps).toFixed(1)} Mbps</div>
                        <div class="small text-secondary">Avg: ${avgUp.toFixed(1)} Mbps</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Download Bloat</div>
                        <div class="h2 ${downGrade.color}">${downGrade.grade} <small class="fs-6 fw-normal">(+${downBloat.toFixed(0)}ms)</small></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Upload Bloat</div>
                        <div class="h2 ${upGrade.color}">${upGrade.grade} <small class="fs-6 fw-normal">(+${upBloat.toFixed(0)}ms)</small></div>
                    </div>
                </div>
                <div class="small mt-1">
                    <a href="${latest.result_url}" target="_blank" class="text-decoration-none"><i class="bi bi-box-arrow-up-right me-1"></i>View latest on Speedtest.net</a>
                </div>
            </div>
        `;
    }
    container.innerHTML = summaryHtml;

    // Build history table
    let historyHtml = '';
    sortedEvents.forEach(event => {
        const hostName = event.host_name || 'Default';
        const downBloat = event.latency_download - event.latency_idle;
        const upBloat = event.latency_upload - event.latency_idle;
        const downGrade = getGrade(downBloat);
        const upGrade = getGrade(upBloat);
        const hostColor = getHostColor(hostName);
        
        historyHtml += `
            <tr data-host="${hostName}" style="--bs-table-bg: ${hostColor}25;">
                <td>
                    <span class="fw-bold" style="color: ${hostColor}">${hostName}</span>
                </td>
                <td><small>${new Date(event.timestamp).toLocaleString()}</small></td>
                <td>${parseFloat(event.download_mbps).toFixed(1)}</td>
                <td>${parseFloat(event.upload_mbps).toFixed(1)}</td>
                <td>${parseFloat(event.latency_idle).toFixed(1)}</td>
                <td><span class="${downGrade.color} fw-bold">${downGrade.grade}</span> <small class="text-muted">(+${downBloat.toFixed(0)}ms)</small></td>
                <td><span class="${upGrade.color} fw-bold">${upGrade.grade}</span> <small class="text-muted">(+${upBloat.toFixed(0)}ms)</small></td>
                <td>
                    <a href="${event.result_url}" target="_blank" class="btn btn-sm btn-link p-0"><i class="bi bi-box-arrow-up-right"></i></a>
                </td>
            </tr>
        `;
    });
    historyContainer.innerHTML = historyHtml;
    
    // Apply filtering if active
    filterSpeedTestHistory();
}

function updateHostActions(hosts) {
    const manageTableBody = document.getElementById('manageHostsTableBody');
    const mtrFilter = document.getElementById('mtrHostFilter');
    const speedFilter = document.getElementById('historyHostFilter');

    let tableHtml = '';
    let filterHtml = '<option value="all">All Hosts</option>';

    hosts.forEach((host) => {
        filterHtml += `<option value="${host.name}" data-id="${host.id}">${host.name}</option>`;

        // Row for management table
        tableHtml += `
            <tr>
                <td><input type="text" class="form-control form-control-sm" value="${host.name}" onchange="updateHostInline(${host.id}, 'name', this.value)"></td>
                <td><code class="small">${host.api_key}</code></td>
                <td><input type="text" class="form-control form-control-sm" value="${host.speedtest_server_id || ''}" placeholder="Global Default" onchange="updateHostInline(${host.id}, 'speedtest_server_id', this.value)"></td>
                <td>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteHost(${host.id}, '${host.name}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    if (manageTableBody) manageTableBody.innerHTML = tableHtml;

    // Update filters but preserve selection if possible
    [mtrFilter, speedFilter].forEach(filter => {
        if (filter) {
            const current = filter.value;
            filter.innerHTML = filterHtml;
            filter.value = current;
            if (filter.value !== current) filter.value = 'all';
        }
    });
}

async function updateHostInline(id, field, value) {
    const payload = {
        action: 'update',
        id: id,
        [field]: value
    };

    const response = await fetch('api/manage_hosts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    if (!response.ok) {
        alert('Failed to update host');
        loadChartData(); // Reload to revert UI
    }
}

function toggleAddHostForm() {
    const form = document.getElementById('addHostForm');
    const btn = document.getElementById('showAddHostBtn');
    if (form.classList.contains('d-none')) {
        form.classList.remove('d-none');
        btn.classList.add('d-none');
    } else {
        form.classList.add('d-none');
        btn.classList.remove('d-none');
    }
}

async function submitNewHost() {
    const name = document.getElementById('newHostName').value;
    const serverId = document.getElementById('newHostServerId').value;

    if (!name) {
        alert('Please enter a host name');
        return;
    }

    const response = await fetch('api/manage_hosts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add',
            name: name,
            speedtest_server_id: serverId
        })
    });

    if (response.ok) {
        document.getElementById('newHostName').value = '';
        document.getElementById('newHostServerId').value = '';
        toggleAddHostForm();
        loadChartData(); // This will refresh the hosts list
    } else {
        alert('Failed to add host');
    }
}

async function deleteHost(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"? This will also delete all its history.`)) {
        return;
    }

    const response = await fetch('api/manage_hosts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete',
            id: id
        })
    });

    if (response.ok) {
        loadChartData();
    } else {
        alert('Failed to delete host');
    }
}

function updateRawDataTables(data, events) {
    const mtrTableBody = document.getElementById('mtrRawData');
    if (!mtrTableBody) return;

    let mtrHtml = '';
    const allMtrRecords = [];
    for (const [hostName, results] of Object.entries(data)) {
        if (!Array.isArray(results)) continue;
        results.forEach(r => {
            if (r.target) {
                allMtrRecords.push({ ...r, hostName });
            }
        });
    }

    allMtrRecords.sort((a, b) => {
        const timeDiff = new Date(b.timestamp) - new Date(a.timestamp);
        if (timeDiff !== 0) return timeDiff;
        
        const hostCompare = a.hostName.localeCompare(b.hostName);
        if (hostCompare !== 0) return hostCompare;
        
        return a.hop_number - b.hop_number;
    });

    if (allMtrRecords.length === 0) {
        mtrHtml = '<tr><td colspan="8" class="text-center text-muted">No MTR data available for this selection.</td></tr>';
    } else {
        // Optimization: Group events by host to speed up correlation
        const eventTimesByHost = {};
        if (events) {
            events.forEach(e => {
                const host = e.host_name || 'Default';
                if (!eventTimesByHost[host]) eventTimesByHost[host] = [];
                eventTimesByHost[host].push(new Date(e.timestamp).getTime());
            });
        }

        allMtrRecords.forEach(r => {
            const hostColor = getHostColor(r.hostName);
            
            // Smart load detection: correlate with speed test events
            let isLoaded = r.is_under_load;
            if (!isLoaded && eventTimesByHost[r.hostName]) {
                const mtrTime = new Date(r.timestamp).getTime();
                // Check if any speedtest event for this host happened within 60 seconds
                isLoaded = eventTimesByHost[r.hostName].some(eventTime => Math.abs(mtrTime - eventTime) < 60000);
            }

            mtrHtml += `
                <tr data-host="${r.hostName}" data-hop="${r.hop_number}" style="--bs-table-bg: ${hostColor}25;">
                    <td><span class="fw-bold" style="color: ${hostColor}">${r.hostName}</span></td>
                    <td><small>${new Date(r.timestamp).toLocaleString()}</small></td>
                    <td><small>${r.target}</small></td>
                    <td>${r.hop_number}</td>
                    <td><small>${r.hostname || '???'}</small></td>
                    <td>${parseFloat(r.avg).toFixed(1)}</td>
                    <td>${parseFloat(r.loss).toFixed(1)}%</td>
                    <td>${isLoaded ? '<span class="badge bg-warning text-dark">LOADED</span>' : '-'}</td>
                </tr>
            `;
        });
    }
    mtrTableBody.innerHTML = mtrHtml;
    
    // Apply filtering if active
    filterMtrData();
}

function updateDownloadLinks() {
    const period = document.getElementById('dataPeriod').value;
    const start = document.getElementById('startDate').value;
    const end = document.getElementById('endDate').value;
    
    let params = `period=${period}`;
    if (period === 'custom' && start && end) {
        params = `start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
    }

    const mtrFilter = document.getElementById('mtrHostFilter');
    const mtrHopFilter = document.getElementById('mtrHopFilter');
    const speedFilter = document.getElementById('historyHostFilter');

    const mtrHostId = mtrFilter && mtrFilter.selectedOptions[0] ? mtrFilter.selectedOptions[0].getAttribute('data-id') : null;
    const mtrHop = mtrHopFilter ? mtrHopFilter.value : 'all';
    const speedHostId = speedFilter && speedFilter.selectedOptions[0] ? speedFilter.selectedOptions[0].getAttribute('data-id') : null;

    const mtrLink = document.getElementById('downloadMtrCsv');
    const speedLink = document.getElementById('downloadSpeedtestCsv');

    if (mtrLink) {
        let mtrParams = params;
        if (mtrHostId) mtrParams += `&host_id=${mtrHostId}`;
        if (mtrHop && mtrHop !== 'all') mtrParams += `&hop=${mtrHop}`;
        mtrLink.href = `api/export.php?type=mtr&${mtrParams}`;
    }
    if (speedLink) {
        let speedParams = params;
        if (speedHostId) speedParams += `&host_id=${speedHostId}`;
        speedLink.href = `api/export.php?type=speedtest&${speedParams}`;
    }
}

initDashboard();
// Auto-refresh every minute
setInterval(loadChartData, 60000);