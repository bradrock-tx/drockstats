// dashboard-analytics.js
/**
 * Dylan Rock Analytics Suite - Core Graphing & Interface Controller
 */

window.chartInstances = {};

$(document).ready(function() {
    // 1. Initialize standard table features
    if ($.fn.DataTable) {
        $('#gameLogTable').DataTable({
            "order": [[ 0, "desc" ]],
            "pageLength": 10
        });
    }

    // 2. Initialize Macro Visual Graph Components
    buildMacroTimelineChart();
    buildMicroMovingAverageChart();
    
    // Initial chart builds utilizing the baseline full-season variables
    buildSavantRadarChart(window.phpSeasonAggregates);
    buildPlateDisciplineDonutChart(window.phpSeasonAggregates);
    
    // Sync skin colors to default layout configuration rules
    syncSystemThemeValues();
});

/**
 * 1. Macro Timeline - Historical Season Progressions
 */
function buildMacroTimelineChart() {
    const ctx = document.getElementById('opsChart');
    if (!ctx) return;

    window.chartInstances.opsChart = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: window.phpTimelineDates,
            datasets: [{
                label: 'OPS',
                data: window.phpOpsTimeline,
                borderColor: '#2D2477',
                backgroundColor: 'rgba(45, 36, 119, 0.08)',
                fill: true,
                tension: 0.35
            }, {
                label: 'ISO',
                data: window.phpIsoTimeline,
                borderColor: '#2C9939',
                backgroundColor: 'transparent',
                borderDash: [4, 4],
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            scales: { x: { grid: {} }, y: { grid: {} } }
        }
    });
}

/**
 * 2. Micro Moving Average Tracker (Rolling 10-Game Windows)
 */
function buildMicroMovingAverageChart() {
    const ctx = document.getElementById('movingAverageChart');
    if (!ctx || !window.phpRawGameLogs) return;

    const logsChronological = [...window.phpRawGameLogs].reverse();
    const rollingLabels = [];
    const rollingOps = [];

    for (let i = 0; i < logsChronological.length; i++) {
        if (i >= 9) {
            const frame = logsChronological.slice(i - 9, i + 1);
            let totalAb = 0, totalH = 0, totalBb = 0, totalHbp = 0, totalSf = 0;
            let total2b = 0, total3b = 0, totalHr = 0;

            frame.forEach(game => {
                totalAb += parseInt(game.ab || 0);
                totalH += parseInt(game.hits || 0);
                totalBb += parseInt(game.bb || 0);
                totalHbp += parseInt(game.hbp || 0);
                totalSf += parseInt(game.sf || 0);
                total2b += parseInt(game.doubles || 0);
                total3b += parseInt(game.triples || 0);
                totalHr += parseInt(game.hr || 0);
            });

            let total1b = totalH - (total2b + total3b + totalHr);
            const tb = total1b + (2 * total2b) + (3 * total3b) + (4 * totalHr);
            
            const obpDenom = totalAb + totalBb + totalHbp + totalSf;
            const obp = obpDenom > 0 ? (totalH + totalBb + totalHbp) / obpDenom : 0;
            const slg = totalAb > 0 ? tb / totalAb : 0;

            rollingLabels.push("G" + (i + 1));
            rollingOps.push((obp + slg).toFixed(3));
        }
    }

    window.chartInstances.movingChart = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: rollingLabels,
            datasets: [{
                label: '10-G Rolling OPS',
                data: rollingOps,
                borderColor: '#17a2b8',
                backgroundColor: 'transparent',
                borderWidth: 3,
                pointRadius: 2,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            scales: { x: { grid: { display: false } }, y: { grid: {} } }
        }
    });
}

/**
 * 3. Savant 5-Tool Scaling Radar Chart Engine (Hard Rebuild DOM Pattern)
 */
function buildSavantRadarChart(sa) {
    const container = document.getElementById('radarChartContainer');
    if (!container || !sa) return;

    if (window.chartInstances.radarChart) {
        window.chartInstances.radarChart.destroy();
    }

    container.innerHTML = '<canvas id="toolRadarChart" style="max-width: 320px; max-height:320px;"></canvas>';
    const ctx = document.getElementById('toolRadarChart');

    const contactScore = Math.min(100, Math.max(10, (sa.avg / 0.360) * 100));
    const powerScore = Math.min(100, Math.max(10, (sa.iso / 0.260) * 100));
    const disciplineScore = Math.min(100, Math.max(10, (sa.bbK / 1.15) * 100));
    const speedScore = Math.min(100, sa.sbPct * 100);
    const defenseScore = Math.min(100, sa.fPct * 100);

    const isDark = document.body.classList.contains('dark-mode');
    const labelColor = isDark ? '#e0e0e0' : '#6c757d';

    window.chartInstances.radarChart = new Chart(ctx.getContext('2d'), {
        type: 'radar',
        data: {
            labels: ['Contact (AVG)', 'Power (ISO)', 'Discipline (BB/K)', 'Speed (SB%)', 'Defense (FPCT)'],
            datasets: [{
                label: 'Tool Grade Profiles',
                data: [contactScore, powerScore, disciplineScore, speedScore, defenseScore], 
                backgroundColor: 'rgba(44, 153, 57, 0.18)',
                borderColor: '#2C9939',
                borderWidth: 2,
                pointBackgroundColor: '#2D2477',
                pointBorderColor: '#fff',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            scales: {
                r: {
                    angleLines: { color: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
                    grid: { color: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)' },
                    pointLabels: { font: { size: 11, weight: 'bold' }, color: labelColor },
                    suggestedMin: 0,
                    suggestedMax: 100,
                    ticks: { display: false }
                }
            },
            plugins: {
                legend: { labels: { color: labelColor } }
            }
        }
    });
}

/**
 * 4. Plate Appearance Donut Allocation Map (Hard Rebuild DOM Pattern)
 */
function buildPlateDisciplineDonutChart(sa) {
    const container = document.getElementById('donutChartContainer');
    if (!container || !sa) return;

    if (window.chartInstances.donutChart) {
        window.chartInstances.donutChart.destroy();
    }

    container.innerHTML = '<canvas id="paDonutChart" style="max-width: 290px; max-height:290px;"></canvas>';
    const ctx = document.getElementById('paDonutChart');

    const extraBaseHits = sa.doubles + sa.triples + sa.hr;
    const singles = sa.singles;
    const inPlayOuts = Math.max(0, sa.pa - (sa.bb + sa.so + singles + extraBaseHits));

    const isDark = document.body.classList.contains('dark-mode');
    const labelColor = isDark ? '#e0e0e0' : '#6c757d';

    window.chartInstances.donutChart = new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Walks (BB)', 'Strikeouts (SO)', 'Singles (1B)', 'XBH (2B/3B/HR)', 'In-Play Outs'],
            datasets: [{
                data: [sa.bb, sa.so, singles, extraBaseHits, inPlayOuts],
                backgroundColor: ['#2D2477', '#dc3545', '#6c757d', '#2C9939', '#F3C303'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 }, color: labelColor } }
            }
        }
    });
}

/**
 * Dynamic Interface Card & Chart Timeframe Tab Actions
 */
function switchTrend(timeframe, element) {
    $('.sub-toggle .btn').removeClass('active');
    $(element).addClass('active');

    // 1. Swap text fields matching dynamic markup attributes
    const targets = ['bb-pct', 'k-pct', 'woba', 'babip', 'sb', 'cs', 'sb-pct', 'po', 'iso', 'hr-total', 'hr-solo', 'hr-2run', 'f-pct', 'f-po', 'f-a', 'f-e'];
    targets.forEach(function(target) {
        const el = document.getElementById('metric-' + target);
        if (el) {
            const newVal = el.getAttribute('data-' + timeframe);
            if (newVal) el.textContent = newVal;
        }
    });

    // 2. Route correct array targets to graph data packages
    let activeData = window.phpSeasonAggregates;
    if (timeframe === '7day') activeData = window.php7DayAggregates;
    if (timeframe === '30day') activeData = window.php30DayAggregates;

    // 3. Fire complete re-draw loops on fresh layout elements
    if (activeData) {
        buildSavantRadarChart(activeData);
        buildPlateDisciplineDonutChart(activeData);
        syncSystemThemeValues();
    }
}

/**
 * System Theme Synchronization
 */
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const icon = document.querySelector('#darkModeToggle i');
    
    if (document.body.classList.contains('dark-mode')) {
        localStorage.setItem('darkMode', 'enabled');
        icon.className = 'bi bi-sun-fill';
    } else {
        localStorage.setItem('darkMode', 'disabled');
        icon.className = 'bi bi-moon-fill';
    }
    syncSystemThemeValues();
}

function syncSystemThemeValues() {
    const isDark = document.body.classList.contains('dark-mode');
    const txtColor = isDark ? '#e0e0e0' : '#6c757d';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';

    Object.keys(window.chartInstances).forEach(key => {
        const chart = window.chartInstances[key];
        if (!chart) return;

        if (chart.options.scales && chart.options.scales.x) {
            chart.options.scales.x.ticks.color = txtColor;
            chart.options.scales.y.ticks.color = txtColor;
            chart.options.scales.x.grid.color = gridColor;
            chart.options.scales.y.grid.color = gridColor;
        }
        
        if (chart.options.scales && chart.options.scales.r) {
            chart.options.scales.r.pointLabels.color = txtColor;
            chart.options.scales.r.angleLines.color = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';
            chart.options.scales.r.grid.color = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
        }

        if (chart.options.plugins && chart.options.plugins.legend) {
            chart.options.plugins.legend.labels.color = txtColor;
        }

        chart.update();
    });
}