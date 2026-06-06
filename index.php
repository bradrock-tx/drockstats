<?php
// index.php
require_once 'config.php';

$playerId = '512d22b8-2ec9-4b5f-9031-24032cc0dcc5';

// 1. Fetch cumulative snapshots for charts
$stmt = $pdo->query("SELECT * FROM player_snapshots ORDER BY snapshot_date ASC");
$snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dates = []; $opsData = []; $isoData = [];
$currentStats = null;
if (count($snapshots) > 0) {
    foreach ($snapshots as $snap) {
        $dates[] = date('M j', strtotime($snap['snapshot_date']));
        $opsData[] = $snap['ops'];
        $isoData[] = $snap['iso'];
    }
    $currentStats = end($snapshots);
}

// 2. Fetch raw game logs for calculations & table
$logStmt = $pdo->query("SELECT * FROM player_game_logs ORDER BY game_date DESC");
$gameLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Mathematical Helpers for Advanced Features
$currentStreak = 0; $streakActive = true;
$lastGame = count($gameLogs) > 0 ? $gameLogs[0] : null;
$seasonHR = 0; $seasonSB = 0;

foreach ($gameLogs as $log) {
    $seasonHR += $log['hr'];
    $seasonSB += $log['sb'];
    if ($streakActive) {
        if ($log['hits'] > 0) { $currentStreak++; } 
        elseif ($log['ab'] > 0 && $log['hits'] == 0) { $streakActive = false; }
    }
}
$hrNeeded = max(0, 20 - $seasonHR);
$sbNeeded = max(0, 20 - $seasonSB);

// 4. Sabermetric Trailing Splits Engine (With Integrated wOBA Calculations)
function getAdvancedTrailingSplits($logs, $days) {
    if (empty($logs)) return null;
    $latestGameDate = strtotime($logs[0]['game_date']);
    $cutoffDate = strtotime("-{$days} days", $latestGameDate);
    
    $pa = 0; $ab = 0; $h = 0; $singles = 0; $doubles = 0; $triples = 0; $hr = 0; $sb = 0; $bb = 0; $hbp = 0; $sf = 0; $so = 0;
    $cs = 0; $pickoffs = 0; $hr_solo = 0; $hr_2run = 0; $f_po = 0; $f_a = 0; $f_e = 0;
    
    foreach ($logs as $log) {
        if (strtotime($log['game_date']) < $cutoffDate) continue; 
        $pa += $log['pa']; $ab += $log['ab']; $h += $log['hits'];
        $singles += $log['singles']; $doubles += $log['doubles']; $triples += $log['triples']; $hr += $log['hr'];
        $sb += $log['sb']; $bb += $log['bb']; $hbp += $log['hbp']; $sf += $log['sf']; $so += $log['so'];
        $cs += $log['cs'] ?? 0; $pickoffs += $log['pickoffs'] ?? 0;
        $hr_solo += $log['hr_solo'] ?? 0; $hr_2run += $log['hr_2run'] ?? 0;
        $f_po += $log['fielding_po'] ?? 0; $f_a += $log['fielding_a'] ?? 0; $f_e += $log['fielding_e'] ?? 0;
    }
    
    $tb = $singles + (2 * $doubles) + (3 * $triples) + (4 * $hr);
    $avg = ($ab > 0) ? ($h / $ab) : 0;
    $obp_denom = $ab + $bb + $hbp + $sf;
    $obp = ($obp_denom > 0) ? (($h + $bb + $hbp) / $obp_denom) : 0;
    $slg = ($ab > 0) ? ($tb / $ab) : 0;
    
    $woba_denom = $ab + $bb + $hbp + $sf;
    $woba = ($woba_denom > 0) ? ((0.7 * $bb) + (0.73 * $hbp) + (0.88 * $singles) + (1.25 * $doubles) + (1.58 * $triples) + (2.03 * $hr)) / $woba_denom : 0;
    
    return [
        'pa' => $pa, 'ab' => $ab, 'hr' => $hr, 'sb' => $sb, 'ops' => ($obp + $slg), 'avg' => $avg, 'obp' => $obp, 'slg' => $slg,
        'iso' => ($slg - $avg), 'bb_pct' => ($pa > 0 ? $bb / $pa : 0), 'k_pct' => ($pa > 0 ? $so / $pa : 0), 'bb_k_ratio' => ($so > 0 ? $bb / $so : 0),
        'babip' => (($ab - $so - $hr + $sf) > 0 ? ($h - $hr) / ($ab - $so - $hr + $sf) : 0), 'woba' => $woba,
        'cs' => $cs, 'pickoffs' => $pickoffs, 'hr_solo' => $hr_solo, 'hr_2run' => $hr_2run,
        'sb_pct' => ($sb + $cs > 0 ? $sb / ($sb + $cs) : 0), 'f_pct' => ($f_po + $f_a + $f_e > 0 ? ($f_po + $f_a) / ($f_po + $f_a + $f_e) : 1.000),
        'f_po' => $f_po, 'f_a' => $f_a, 'f_e' => $f_e, 'singles' => $singles, 'doubles' => $doubles, 'triples' => $triples, 'bb' => $bb, 'so' => $so
    ];
}
$last7  = getAdvancedTrailingSplits($gameLogs, 7);
$last30 = getAdvancedTrailingSplits($gameLogs, 30);

// 5. LIVE FETCH: Season-wide Reference Payload
$advStatsUrl = "https://api.microservices.iscoresports.com/api/player-stats?playerId={$playerId}";
$ch = curl_init($advStatsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$advStatsJson = curl_exec($ch);
curl_close($ch);

$advBatting = []; $advRunning = []; $advFielding = []; $seasonWoba = 0;
if ($advStatsJson) {
    $parsed = json_decode($advStatsJson, true);
    $seasonNode = $parsed[0]['stats']['9843025b-3dd7-4b1b-8776-a6b53a3bdb7a'] ?? [];
    if (!empty($seasonNode)) {
        $advBatting  = $seasonNode['batting'] ?? [];
        $advRunning  = $seasonNode['running'] ?? [];
        $advFielding = $seasonNode['fieldingByPosition'] ?? [];
        
        $rOverall = $advRunning['overall'] ?? []; 
        $bo = $advBatting['overall'] ?? [];
        $w_den = ($bo['AB']??0) + ($bo['BB']??0) + ($bo['HBP']??0) + ($bo['SF']??0);
        $seasonWoba = ($w_den > 0) ? ((0.7 * ($bo['BB']??0)) + (0.73 * ($bo['HBP']??0)) + (0.88 * ($bo['1B']??0)) + (1.25 * ($bo['2B']??0)) + (1.58 * ($bo['3B']??0)) + (2.03 * ($bo['HR']??0))) / $w_den : 0;
        $fOverall = $advFielding['overall']['RF'] ?? [];
    }
}
function fmtRate($rate) {
    if (!is_numeric($rate) || $rate == 0) return '.000';
    $formatted = number_format((float)$rate, 3);
    return ($formatted[0] === '0') ? substr($formatted, 1) : $formatted;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dylan Rock Analytics</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="DR Analytics" />
    <link rel="manifest" href="/site.webmanifest" />
    <meta name="theme-color" content="#2D2477">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --legends-blue: #2D2477; --legends-green: #2C9939; --legends-yellow: #F3C303; --bg-main: #f8f9fa; --card-bg: #ffffff; --text-main: #212529; --text-title: #2D2477; --text-muted: #6c757d; --nav-pill-bg: #ffffff; --stat-value-color: #2D2477; }
        body.dark-mode { --bg-main: #121212; --card-bg: #1e1e1e; --text-main: #e0e0e0; --text-title: #ffffff; --text-muted: #a0a0a0; --nav-pill-bg: #2a2a2a; --stat-value-color: #ffffff; }
        body { background-color: var(--bg-main); color: var(--text-main); font-family: 'Segoe UI', Roboto, sans-serif; transition: background-color 0.3s, color 0.3s; }
        .navbar { background-color: var(--legends-blue); border-bottom: 4px solid var(--legends-green); }
        .logo-plate { background-color: #ffffff !important; display: flex; align-items: center; justify-content: center; padding: 4px; margin-right: 0.5rem; border-radius: 0.375rem; width: 38px; height: 38px; box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .hero-banner-card { background-color: var(--card-bg); box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: none; border-radius: 12px; }
        .hero-stats { border-left: 3px solid var(--legends-green); padding-left: 20px;}
        .stat-value { font-size: 2.5rem; font-weight: 800; color: var(--stat-value-color); line-height: 1; }
        .stat-label { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        .card { background-color: var(--card-bg); color: var(--text-main); box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: none; border-radius: 10px; transition: background-color 0.3s, color 0.3s; }
        .split-box { background-color: var(--card-bg); color: var(--text-main); border-radius: 8px; border-top: 4px solid var(--legends-green); padding: 15px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .split-title { font-size: 0.85rem; font-weight: bold; text-transform: uppercase; color: var(--text-title); }
        .split-line { font-size: 1.15rem; font-weight: bold; color: var(--text-main); }
        .ops-highlight { color: var(--legends-green); font-weight: 800; }
        .text-muted { color: var(--text-muted) !important; }
        .nav-pills .nav-link { color: var(--text-title); font-weight: bold; border-radius: 30px; padding: 10px 25px; }
        .nav-pills .nav-link.active { background-color: var(--legends-blue); color: white; box-shadow: 0 4px 10px rgba(45,36,119,0.3); }
        body.dark-mode .nav-pills .nav-link.active { background-color: var(--legends-green); color: #121212; box-shadow: 0 4px 10px rgba(44,153,57,0.3); }
        .sub-toggle .btn { border-radius: 20px; font-weight: 600; padding: 4px 18px; font-size: 0.85rem; color: var(--text-muted); border: 1px solid rgba(128,128,128,0.3); }
        .sub-toggle .btn.active { background-color: var(--legends-blue); color: #fff; border-color: var(--legends-blue); }
        body.dark-mode .sub-toggle .btn.active { background-color: var(--legends-green); color: #121212; border-color: var(--legends-green); }
        .metric-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(128,128,128,0.15); }
        .metric-row:last-child { border-bottom: none; }
        .metric-val { font-weight: 700; color: var(--text-title); }
        body.dark-mode .table { color: var(--text-main); }
        body.dark-mode .table-striped>tbody>tr:nth-of-type(odd)>* { color: var(--text-main); background-color: rgba(255,255,255,0.02); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4 py-2">
    <div class="container d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <div class="logo-plate"><img src="/DRock-Logo26.png" alt="DR Logo" style="max-height: 100%; max-width: 100%;"></div>
            <span class="fw-bold text-white fs-5 ms-1">Dylan Rock Analytics</span>
        </div>
        <div class="d-flex gap-3 align-items-center">
            <a href="https://x.com/dylanrock" target="_blank" class="text-white opacity-75 fs-5"><i class="bi bi-twitter-x"></i></a>
            <a href="https://instagram.com/dylan.g.rock/" target="_blank" class="text-white opacity-75 fs-5"><i class="bi bi-instagram"></i></a>
            <button onclick="toggleDarkMode()" id="darkModeToggle" class="btn btn-sm btn-outline-light ms-2 border-0 fs-5"><i class="bi bi-moon-fill"></i></button>
        </div>
    </div>
</nav>

<div class="container mb-5">
    <?php if ($currentStats): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card hero-banner-card overflow-hidden">
                <div class="row g-0 align-items-center">
                    <div class="col-md-3 text-center bg-white d-flex align-items-end justify-content-center pt-3 pt-md-0">
                        <img src="/27_Dylan_Rock.webp" alt="Dylan Rock" class="img-fluid" style="max-height: 220px;">
                    </div>
                    <div class="col-md-9 p-4">
                        <div class="row align-items-center">
                            <div class="col-xl-4 text-center text-xl-start mb-3 mb-xl-0">
                                <h1 class="fw-bold mb-0" style="color: var(--text-title); font-size: 2.2rem;">Dylan Rock</h1>
                                <h6 class="text-muted text-uppercase fw-semibold tracking-wider">Lexington Legends • OF</h6>
                            </div>
                            <div class="col-xl-8">
                                <div class="d-flex justify-content-between text-center flex-wrap hero-stats">
                                    <div><div class="stat-value"><?= fmtRate($currentStats['avg']) ?></div><div class="stat-label text-muted fw-bold">AVG</div></div>
                                    <div><div class="stat-value"><?= fmtRate($currentStats['obp']) ?></div><div class="stat-label text-muted fw-bold">OBP</div></div>
                                    <div><div class="stat-value"><?= fmtRate($currentStats['slg']) ?></div><div class="stat-label text-muted fw-bold">SLG</div></div>
                                    <div><div class="stat-value" style="color: var(--legends-green);"><?= fmtRate($currentStats['ops']) ?></div><div class="stat-label text-success fw-bold">OPS</div></div>
                                    <div><div class="stat-value" style="color: var(--legends-yellow);"><?= fmtRate($seasonWoba) ?></div><div class="stat-label fw-bold" style="color: var(--legends-yellow);">wOBA</div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-center mb-4">
        <ul class="nav nav-pills shadow-sm rounded-pill p-1" id="dashboardToggle" style="background-color: var(--nav-pill-bg);" role="tablist">
            <li class="nav-item"><button class="nav-link active" id="standard-tab" data-bs-toggle="pill" data-bs-target="#standard-view" type="button" role="tab"><i class="bi bi-clock-history me-2"></i>Recent Form & Logs</button></li>
            <li class="nav-item"><button class="nav-link" id="advanced-tab" data-bs-toggle="pill" data-bs-target="#advanced-view" type="button" role="tab"><i class="bi bi-crosshair me-2"></i>Advanced Analytics Suite</button></li>
        </ul>
    </div>

    <div class="tab-content" id="dashboardContent">
        <div class="tab-pane fade show active" id="standard-view" role="tabpanel">
            <div class="row mb-4 g-3">
                <div class="col-md-4"><div class="card h-100 p-3 text-center" style="border-left: 4px solid var(--legends-blue);"><div class="small fw-bold text-muted text-uppercase mb-1">Last Game (<?= date('m/d', strtotime($lastGame['game_date'])) ?>)</div><div class="fs-5 fw-bold"><?= $lastGame['hits'] ?>-<?= $lastGame['ab'] ?>, <?= $lastGame['hr'] ?> HR</div><div class="small text-muted mt-1">vs <?= htmlspecialchars($lastGame['opponent']) ?></div></div></div>
                <div class="col-md-4"><div class="card h-100 p-3 text-center" style="border-left: 4px solid var(--legends-green);"><div class="small fw-bold text-muted text-uppercase mb-1">Active Hitting Streak</div><div class="fs-3 fw-bold" style="color: var(--legends-green);"><?= $currentStreak ?> <span class="fs-6" style="color: var(--text-main);">Games</span></div></div></div>
                <div class="col-md-4"><div class="card h-100 p-3 text-center" style="border-left: 4px solid var(--legends-yellow);"><div class="small fw-bold text-muted text-uppercase mb-1">20/20 Watch</div><div class="d-flex justify-content-center gap-3"><div><span class="fs-4 fw-bold text-danger"><?= $seasonHR ?></span><span class="small text-muted">/20 HR</span></div><div><span class="fs-4 fw-bold text-primary"><?= $seasonSB ?></span><span class="small text-muted">/20 SB</span></div></div><div class="small text-muted mt-1">Needs <?= $hrNeeded ?> HR & <?= $sbNeeded ?> SB</div></div></div>
            </div>

            <div class="row mb-4 g-3">
                <div class="col-md-6"><div class="split-box"><div class="split-title">Last 7 Days</div><div class="split-line"><?= fmtRate($last7['avg']) ?> / <?= fmtRate($last7['ops']) ?> OPS</div></div></div>
                <div class="col-md-6"><div class="split-box" style="border-top-color: var(--legends-yellow);"><div class="split-title">Last 30 Days</div><div class="split-line"><?= fmtRate($last30['avg']) ?> / <?= fmtRate($last30['ops']) ?> OPS</div></div></div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-7"><div class="card h-100 p-3"><h5 class="fw-bold mb-0 text-title">Season Trajectory</h5><p class="text-muted small">Cumulative performance timeline</p><canvas id="opsChart" height="110"></canvas></div></div>
                <div class="col-lg-5"><div class="card h-100 p-3"><h5 class="fw-bold mb-0 text-title">Micro Form Tracker</h5><p class="text-muted small">10-Game moving window averages</p><canvas id="movingAverageChart" height="160"></canvas></div></div>
            </div>

            <div class="card p-3"><h5 class="fw-bold mb-3 text-title">Advanced Game Logs</h5><div class="table-responsive"><table id="gameLogTable" class="table table-striped table-hover align-middle"><thead class="text-center" style="background-color: var(--legends-blue); color: white;"><tr><th class="text-start">Date</th><th class="text-start">Opponent</th><th>H/A</th><th>PA</th><th>AB</th><th>R</th><th>H</th><th>2B</th><th>3B</th><th>HR</th><th>SB</th><th>RBI</th><th>BB</th><th>SO</th></tr></thead><tbody class="text-center"><?php foreach ($gameLogs as $log): ?><tr><td class="text-start"><?= date('m/d/y', strtotime($log['game_date'])) ?></td><td class="text-start fw-semibold"><?= htmlspecialchars($log['opponent']) ?></td><td><?= $log['home_away'] ?></td><td><?= $log['pa'] ?></td><td><?= $log['ab'] ?></td><td><?= $log['runs'] ?></td><td><?= $log['hits'] ?></td><td><?= $log['doubles'] ?></td><td><?= $log['triples'] ?></td><td><?= $log['hr'] ?></td><td><?= $log['sb'] ?></td><td><?= $log['rbi'] ?></td><td><?= $log['bb'] ?></td><td class="text-danger"><?= $log['so'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        </div>

        <div class="tab-pane fade" id="advanced-view" role="tabpanel">
            <div class="d-flex justify-content-center sub-toggle gap-2 mb-3">
                <button type="button" class="btn active" onclick="switchTrend('season', this)">Full Season</button>
                <button type="button" class="btn" onclick="switchTrend('7day', this)">Last 7 Days</button>
                <button type="button" class="btn" onclick="switchTrend('30day', this)">Last 30 Days</button>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3"><div class="card h-100 p-3 border-start border-4 border-primary"><h6 class="fw-bold text-uppercase small text-muted mb-3">Plate Discipline</h6>
                    <div class="metric-row"><span class="small text-muted">Walk Rate (BB%)</span><span class="metric-val" id="metric-bb-pct" data-season="<?= number_format(($advBatting['overall']['RATES']['BB_PCT'] ?? 0)*100,1) ?>%" data-7day="<?= number_format($last7['bb_pct']*100,1) ?>%" data-30day="<?= number_format($last30['bb_pct']*100,1) ?>%"><?= number_format(($advBatting['overall']['RATES']['BB_PCT'] ?? 0)*100,1) ?>%</span></div>
                    <div class="metric-row"><span class="small text-muted">Strikeout Rate (K%)</span><span class="metric-val" id="metric-k-pct" data-season="<?= number_format(($advBatting['overall']['RATES']['K_PCT'] ?? 0)*100,1) ?>%" data-7day="<?= number_format($last7['k_pct']*100,1) ?>%" data-30day="<?= number_format($last30['k_pct']*100,1) ?>%"><?= number_format(($advBatting['overall']['RATES']['K_PCT'] ?? 0)*100,1) ?>%</span></div>
                    <div class="metric-row"><span class="small text-muted">Weighted wOBA</span><span class="metric-val text-success" id="metric-woba" data-season="<?= fmtRate($seasonWoba) ?>" data-7day="<?= fmtRate($last7['woba']) ?>" data-30day="<?= fmtRate($last30['woba']) ?>"><?= fmtRate($seasonWoba) ?></span></div>
                    <div class="metric-row"><span class="small text-muted">BABIP Contact</span><span class="metric-val" id="metric-babip" data-season="<?= fmtRate($advBatting['overall']['RATES']['BABIP'] ?? 0) ?>" data-7day="<?= fmtRate($last7['babip']) ?>" data-30day="<?= fmtRate($last30['babip']) ?>"><?= fmtRate($advBatting['overall']['RATES']['BABIP'] ?? 0) ?></span></div>
                </div></div>
                <div class="col-md-6 col-xl-3"><div class="card h-100 p-3 border-start border-4 border-info"><h6 class="fw-bold text-uppercase small text-muted mb-3">Speed Metrics</h6>
                    <div class="metric-row"><span class="small text-muted">Stolen Bases</span><span class="metric-val text-info" id="metric-sb" data-season="<?= $rOverall['SB'] ?? 9 ?>" data-7day="<?= $last7['sb'] ?>" data-30day="<?= $last30['sb'] ?>"><?= $rOverall['SB'] ?? 9 ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Caught Stealing</span><span class="metric-val" id="metric-cs" data-season="<?= $advRunning['overall']['CS'] ?? 0 ?>" data-7day="<?= $last7['cs'] ?>" data-30day="<?= $last30['cs'] ?>"><?= $advRunning['overall']['CS'] ?? 0 ?></span></div>
                    <div class="metric-row"><span class="small text-muted text-success">SB Success %</span><span class="metric-val text-success" id="metric-sb-pct" data-season="<?= number_format(($advRunning['overall']['RATES']['SB_PCT'] ?? 1)*100,0) ?>%" data-7day="<?= number_format($last7['sb_pct']*100,0) ?>%" data-30day="<?= number_format($last30['sb_pct']*100,0) ?>%"><?= number_format(($advRunning['overall']['RATES']['SB_PCT'] ?? 1)*100,0) ?>%</span></div>
                    <div class="metric-row"><span class="small text-muted">Pickoffs Suffered</span><span class="metric-val text-danger" id="metric-po" data-season="<?= $advRunning['overall']['PO'] ?? 1 ?>" data-7day="<?= $last7['pickoffs'] ?>" data-30day="<?= $last30['pickoffs'] ?>"><?= $advRunning['overall']['PO'] ?? 1 ?></span></div>
                </div></div>
                <div class="col-md-6 col-xl-3"><div class="card h-100 p-3 border-start border-4 border-danger"><h6 class="fw-bold text-uppercase small text-muted mb-3">Power Distribution</h6>
                    <div class="metric-row"><span class="small text-muted">Isolated Power</span><span class="metric-val text-danger" id="metric-iso" data-season="<?= fmtRate($advBatting['overall']['RATES']['ISO'] ?? 0) ?>" data-7day="<?= fmtRate($last7['iso']) ?>" data-30day="<?= fmtRate($last30['iso']) ?>"><?= fmtRate($advBatting['overall']['RATES']['ISO'] ?? 0) ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Total Home Runs</span><span class="metric-val" id="metric-hr-total" data-season="<?= $advBatting['overall']['HR'] ?? 0 ?>" data-7day="<?= $last7['hr'] ?>" data-30day="<?= $last30['hr'] ?>"><?= $advBatting['overall']['HR'] ?? 0 ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Solo HRs</span><span class="metric-val" id="metric-hr-solo" data-season="<?= $advBatting['overall']['HR_SOLO'] ?? 0 ?>" data-7day="<?= $last7['hr_solo'] ?>" data-30day="<?= $last30['hr_solo'] ?>"><?= $advBatting['overall']['HR_SOLO'] ?? 0 ?></span></div>
                    <div class="metric-row"><span class="small text-muted">2-Run HRs</span><span class="metric-val" id="metric-hr-2run" data-season="<?= $advBatting['overall']['HR_2RUN'] ?? 0 ?>" data-7day="<?= $last7['hr_2run'] ?>" data-30day="<?= $last30['hr_2run'] ?>"><?= $advBatting['overall']['HR_2RUN'] ?? 0 ?></span></div>
                </div></div>
                <div class="col-md-6 col-xl-3"><div class="card h-100 p-3 border-start border-4 border-success"><h6 class="fw-bold text-uppercase small text-muted mb-3">Gold Glove Defense</h6>
                    <div class="metric-row"><span class="small text-muted">Fielding % (RF)</span><span class="metric-val text-success" id="metric-f-pct" data-season="<?= number_format((float)($fOverall['RATES']['FPCT'] ?? 1),3) ?>" data-7day="<?= number_format($last7['f_pct'],3) ?>" data-30day="<?= number_format($last30['f_pct'],3) ?>"><?= number_format((float)($fOverall['RATES']['FPCT'] ?? 1),3) ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Outfield Putouts</span><span class="metric-val" id="metric-f-po" data-season="<?= $fOverall['PO'] ?? 0 ?>" data-7day="<?= $last7['f_po'] ?>" data-30day="<?= $last30['f_po'] ?>"><?= $fOverall['PO'] ?? 0 ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Outfield Assists</span><span class="metric-val text-primary" id="metric-f-a" data-season="<?= $fOverall['A'] ?? 4 ?>" data-7day="<?= $last7['f_a'] ?>" data-30day="<?= $last30['f_a'] ?>"><?= $fOverall['A'] ?? 4 ?></span></div>
                    <div class="metric-row"><span class="small text-muted">Defensive Errors</span><span class="metric-val" id="metric-f-e" data-season="<?= $fOverall['E'] ?? 0 ?>" data-7day="<?= $last7['f_e'] ?>" data-30day="<?= $last30['f_e'] ?>"><?= $fOverall['E'] ?? 0 ?></span></div>
                </div></div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6"><div class="card p-3"><h5 class="fw-bold mb-0 text-title">Savant 5-Tool Diamond</h5><p class="text-muted small">Tool mastery scaled to elite benchmarks</p><div class="d-flex justify-content-center" id="radarChartContainer"><canvas id="toolRadarChart" style="max-width: 320px; max-height:320px;"></canvas></div></div></div>
                <div class="col-md-6"><div class="card p-3"><h5 class="fw-bold mb-0 text-title">Plate Appearance Footprint</h5><p class="text-muted small">Discipline breakdown per appearance</p><div class="d-flex justify-content-center" id="donutChartContainer"><canvas id="paDonutChart" style="max-width: 290px; max-height:290px;"></canvas></div></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card h-100 border-top border-4" style="border-top-color: var(--legends-blue) !important;">
                        <div class="card-body">
                            <h5 class="card-title fw-bold mb-3" style="color: var(--text-title);"><i class="bi bi-people-fill me-2"></i>The Platoon Advantage</h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle text-center mb-0">
                                    <thead class="small text-uppercase"><tr style="color: var(--text-muted);"><th class="text-start">Split</th><th>AB</th><th>HR</th><th>AVG</th><th>OBP</th><th>SLG</th><th class="text-success fw-bold">OPS</th></tr></thead>
                                    <tbody>
                                        <?php $vsL = $advBatting['byHand']['vsL'] ?? ['AB'=>0,'HR'=>0,'RATES'=>['AVG'=>0,'OBP'=>0,'SLG'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold">vs LHP</td><td><?= $vsL['AB'] ?></td><td><?= $vsL['HR'] ?></td><td><?= fmtRate($vsL['RATES']['AVG']) ?></td><td><?= fmtRate($vsL['RATES']['OBP']) ?></td><td><?= fmtRate($vsL['RATES']['SLG']) ?></td><td class="text-success fw-bold"><?= fmtRate($vsL['RATES']['OPS']) ?></td></tr>
                                        <?php $vsR = $advBatting['byHand']['vsR'] ?? ['AB'=>0,'HR'=>0,'RATES'=>['AVG'=>0,'OBP'=>0,'SLG'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold">vs RHP</td><td><?= $vsR['AB'] ?></td><td><?= $vsR['HR'] ?></td><td><?= fmtRate($vsR['RATES']['AVG']) ?></td><td><?= fmtRate($vsR['RATES']['OBP']) ?></td><td><?= fmtRate($vsR['RATES']['SLG']) ?></td><td class="text-success fw-bold"><?= fmtRate($vsR['RATES']['OPS']) ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card h-100 border-top border-4" style="border-top-color: var(--legends-green) !important;">
                        <div class="card-body">
                            <h5 class="card-title fw-bold mb-3" style="color: var(--text-title);"><i class="bi bi-fire me-2 text-danger"></i>The Clutch Factor</h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle text-center mb-0">
                                    <thead class="small text-uppercase"><tr style="color: var(--text-muted);"><th class="text-start">Situation</th><th>AB</th><th>HR</th><th>RBI</th><th>AVG</th><th class="text-success fw-bold">OPS</th></tr></thead>
                                    <tbody>
                                        <?php $risp = $advBatting['bySituation']['risp'] ?? ['AB'=>0,'HR'=>0,'RBI'=>0,'RATES'=>['AVG'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold">RISP</td><td><?= $risp['AB'] ?></td><td class="text-danger"><?= $risp['HR'] ?></td><td><?= $risp['RBI'] ?></td><td><?= fmtRate($risp['RATES']['AVG']) ?></td><td class="text-success fw-bold"><?= fmtRate($risp['RATES']['OPS']) ?></td></tr>
                                        <?php $twoOut = $advBatting['bySituation']['twoOut'] ?? ['AB'=>0,'HR'=>0,'RBI'=>0,'RATES'=>['AVG'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold">2 Outs</td><td><?= $twoOut['AB'] ?></td><td class="text-danger"><?= $twoOut['HR'] ?></td><td><?= $twoOut['RBI'] ?></td><td><?= fmtRate($twoOut['RATES']['AVG']) ?></td><td class="text-success fw-bold"><?= fmtRate($twoOut['RATES']['OPS']) ?></td></tr>
                                        <?php $basesEmpty = $advBatting['bySituation']['basesEmpty'] ?? ['AB'=>0,'HR'=>0,'RBI'=>0,'RATES'=>['AVG'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold opacity-75">Bases Empty</td><td><?= $basesEmpty['AB'] ?></td><td><?= $basesEmpty['HR'] ?></td><td><?= $basesEmpty['RBI'] ?></td><td><?= fmtRate($basesEmpty['RATES']['AVG']) ?></td><td><?= fmtRate($basesEmpty['RATES']['OPS']) ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card border-top border-4" style="border-top-color: var(--legends-yellow) !important;">
                        <div class="card-body">
                            <h5 class="card-title fw-bold mb-3" style="color: var(--text-title);"><i class="bi bi-eye-fill me-2" style="color: var(--legends-yellow);"></i>Count Discipline</h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle text-center mb-0">
                                    <thead class="small text-uppercase"><tr style="color: var(--text-muted);"><th class="text-start">Count State</th><th>PA</th><th>BB</th><th>SO</th><th>AVG</th><th>OBP</th><th class="text-success fw-bold">OPS</th></tr></thead>
                                    <tbody>
                                        <?php $ahead = $advBatting['bySituation']['aheadInCount'] ?? ['PA'=>0,'BB'=>0,'SO'=>0,'RATES'=>['AVG'=>0,'OBP'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold text-primary">Ahead in Count</td><td><?= $ahead['PA'] ?></td><td class="text-primary fw-bold"><?= $ahead['BB'] ?></td><td><?= $ahead['SO'] ?></td><td><?= fmtRate($ahead['RATES']['AVG']) ?></td><td><?= fmtRate($ahead['RATES']['OBP']) ?></td><td class="text-success fw-bold"><?= fmtRate($ahead['RATES']['OPS']) ?></td></tr>
                                        <?php $even = $advBatting['bySituation']['evenCount'] ?? ['PA'=>0,'BB'=>0,'SO'=>0,'RATES'=>['AVG'=>0,'OBP'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold text-secondary">Even Count</td><td><?= $even['PA'] ?></td><td><?= $even['BB'] ?></td><td><?= $even['SO'] ?></td><td><?= fmtRate($even['RATES']['AVG']) ?></td><td><?= fmtRate($even['RATES']['OBP']) ?></td><td class="text-success fw-bold"><?= fmtRate($even['RATES']['OPS']) ?></td></tr>
                                        <?php $behind = $advBatting['bySituation']['behindInCount'] ?? ['PA'=>0,'BB'=>0,'SO'=>0,'RATES'=>['AVG'=>0,'OBP'=>0,'OPS'=>0]]; ?>
                                        <tr><td class="text-start fw-bold text-danger">Behind in Count</td><td><?= $behind['PA'] ?></td><td><?= $behind['BB'] ?></td><td class="text-danger fw-bold"><?= $behind['SO'] ?></td><td><?= fmtRate($behind['RATES']['AVG']) ?></td><td><?= fmtRate($behind['RATES']['OBP']) ?></td><td class="text-success fw-bold"><?= fmtRate($behind['RATES']['OPS']) ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    window.phpTimelineDates = <?= json_encode($dates) ?>;
    window.phpOpsTimeline = <?= json_encode($opsData) ?>;
    window.phpIsoTimeline = <?= json_encode($isoData) ?>;
    window.phpRawGameLogs = <?= json_encode($gameLogs) ?>;
    
    window.phpSeasonAggregates = {
        avg: <?= (float)($currentStats['avg'] ?? 0) ?>,
        iso: <?= (float)($advBatting['overall']['RATES']['ISO'] ?? 0) ?>,
        bbK: <?= (float)($advBatting['overall']['RATES']['BB_K_RATIO'] ?? 0) ?>,
        sbPct: <?= (float)($advRunning['overall']['RATES']['SB_PCT'] ?? 1) ?>,
        fPct: <?= (float)($fOverall['RATES']['FPCT'] ?? 1) ?>,
        bb: <?= (int)($advBatting['overall']['BB'] ?? 0) ?>,
        so: <?= (int)($advBatting['overall']['SO'] ?? 0) ?>,
        singles: <?= (int)($advBatting['overall']['1B'] ?? 0) ?>,
        doubles: <?= (int)($advBatting['overall']['2B'] ?? 0) ?>,
        triples: <?= (int)($advBatting['overall']['3B'] ?? 0) ?>,
        hr: <?= (int)($advBatting['overall']['HR'] ?? 0) ?>,
        pa: <?= (int)($advBatting['overall']['PA'] ?? 1) ?>
    };

    window.php7DayAggregates = {
        avg: <?= (float)($last7['avg'] ?? 0) ?>,
        iso: <?= (float)($last7['iso'] ?? 0) ?>,
        bbK: <?= (float)($last7['bb_k_ratio'] ?? 0) ?>,
        sbPct: <?= (float)($last7['sb_pct'] ?? 1) ?>,
        fPct: <?= (float)($last7['f_pct'] ?? 1) ?>,
        bb: <?= (int)($last7['bb'] ?? 0) ?>,
        so: <?= (int)($last7['so'] ?? 0) ?>,
        singles: <?= (int)($last7['singles'] ?? 0) ?>,
        doubles: <?= (int)($last7['doubles'] ?? 0) ?>,
        triples: <?= (int)($last7['triples'] ?? 0) ?>,
        hr: <?= (int)($last7['hr'] ?? 0) ?>,
        pa: <?= (int)($last7['pa'] ?? 1) ?>
    };

    window.php30DayAggregates = {
        avg: <?= (float)($last30['avg'] ?? 0) ?>,
        iso: <?= (float)($last30['iso'] ?? 0) ?>,
        bbK: <?= (float)($last30['bb_k_ratio'] ?? 0) ?>,
        sbPct: <?= (float)($last30['sb_pct'] ?? 1) ?>,
        fPct: <?= (float)($last30['f_pct'] ?? 1) ?>,
        bb: <?= (int)($last30['bb'] ?? 0) ?>,
        so: <?= (int)($last30['so'] ?? 0) ?>,
        singles: <?= (int)($last30['singles'] ?? 0) ?>,
        doubles: <?= (int)($last30['doubles'] ?? 0) ?>,
        triples: <?= (int)($last30['triples'] ?? 0) ?>,
        hr: <?= (int)($last30['hr'] ?? 0) ?>,
        pa: <?= (int)($last30['pa'] ?? 1) ?>
    };
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script src="/dashboard-analytics.js?v=6.0"></script>
</body>
</html>