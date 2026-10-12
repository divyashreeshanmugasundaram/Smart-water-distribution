<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
authRequireSignedIn();

try {
    require __DIR__ . '/db.php';
} catch (PDOException $exception) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><title>Database connection error</title>';
    echo '<body><h1>Database connection failed</h1><p>';
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '</p><p>Check the MySQL service, database name, and WATER_DB_* settings in db.php.</p></body></html>';
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function litres(int $value): string
{
    return number_format($value) . ' L';
}

function activeRunId(PDO $pdo, bool $lock = false): int
{
    $sql = "SELECT active_run_id FROM system_state WHERE state_key = 'current_day'";
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $runId = $pdo->query($sql)->fetchColumn();
    if ($runId === false) {
        throw new RuntimeException('The current simulation day has not been initialized. Run schema.sql first.');
    }

    return (int) $runId;
}

function isReachable(string $start, string $target, array $graph): bool
{
    $queue = [$start];
    $visited = [$start => true];

    while ($queue !== []) {
        $current = array_shift($queue);
        if ($current === $target) {
            return true;
        }

        foreach ($graph[$current] ?? [] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $visited[$neighbor] = true;
                $queue[] = $neighbor;
            }
        }
    }

    return false;
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: index.php');
    exit;
}

$csrfToken = authCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(400);
        exit('Invalid form token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_request') {
            $areaId = filter_input(INPUT_POST, 'area_id', FILTER_UNSAFE_RAW);
            $requestedLitres = filter_input(INPUT_POST, 'requested_litres', FILTER_VALIDATE_INT);

            if (!is_string($areaId) || !preg_match('/^A[0-9]+$/', $areaId)) {
                redirectWithMessage('error', 'Choose a valid demand area.');
            }
            if ($requestedLitres === false || $requestedLitres === null || $requestedLitres < 1) {
                redirectWithMessage('error', 'Requested litres must be a positive whole number.');
            }

            $areaQuery = $pdo->prepare('SELECT area_id FROM demand_areas WHERE area_id = ?');
            $areaQuery->execute([$areaId]);
            if ($areaQuery->fetchColumn() === false) {
                redirectWithMessage('error', 'The selected demand area does not exist.');
            }

            $pdo->beginTransaction();
            $runId = activeRunId($pdo, true);
            $insert = $pdo->prepare(
                'INSERT INTO water_requests (run_id, area_id, requested_litres) VALUES (?, ?, ?)'
            );
            $insert->execute([$runId, $areaId, $requestedLitres]);
            $pdo->commit();
            redirectWithMessage('success', 'Water request added to the current allocation queue.');
        }

        if ($action === 'allocate') {
            $pdo->beginTransaction();
            $runId = activeRunId($pdo, true);

            $requestsQuery = $pdo->prepare(
                "SELECT wr.request_id, wr.area_id, wr.requested_litres,
                        da.name AS area_name, da.area_type, da.priority, da.daily_demand,
                        da.allocated_water
                 FROM water_requests wr
                 JOIN demand_areas da ON da.area_id = wr.area_id
                 WHERE wr.run_id = ? AND wr.status = 'pending'
                 ORDER BY
                    CASE
                        WHEN da.area_type = 'critical_institution' THEN 3
                        WHEN da.priority = 'High' THEN 2
                        ELSE 1
                    END DESC,
                    wr.request_id ASC
                 FOR UPDATE"
            );
            $requestsQuery->execute([$runId]);
            $requests = $requestsQuery->fetchAll();

            if ($requests === []) {
                $pdo->commit();
                redirectWithMessage('info', 'There are no pending water requests to allocate.');
            }

            $areasQuery = $pdo->query('SELECT area_id, daily_demand, allocated_water FROM demand_areas FOR UPDATE');
            $areas = [];
            foreach ($areasQuery->fetchAll() as $area) {
                $areas[$area['area_id']] = $area;
            }

            $reservoirs = $pdo->query(
                'SELECT reservoir_id, remaining_supply FROM reservoirs ORDER BY reservoir_id FOR UPDATE'
            )->fetchAll();
            $pipelines = $pdo->query(
                'SELECT endpoint_from, endpoint_to FROM pipelines WHERE is_working = 1'
            )->fetchAll();
            $graph = [];
            foreach ($pipelines as $pipeline) {
                $from = $pipeline['endpoint_from'];
                $to = $pipeline['endpoint_to'];
                $graph[$from][] = $to;
                $graph[$to][] = $from;
            }

            $updateReservoir = $pdo->prepare(
                'UPDATE reservoirs SET remaining_supply = remaining_supply - ? WHERE reservoir_id = ?'
            );
            $updateArea = $pdo->prepare(
                'UPDATE demand_areas SET allocated_water = allocated_water + ? WHERE area_id = ?'
            );
            $insertHistory = $pdo->prepare(
                'INSERT INTO allocation_history
                    (run_id, request_id, area_id, reservoir_id, allocated_litres, result)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $markProcessed = $pdo->prepare(
                "UPDATE water_requests SET status = 'processed' WHERE request_id = ?"
            );

            foreach ($requests as $request) {
                $areaId = $request['area_id'];
                $area = $areas[$areaId];
                $unmet = max(0, (int) $area['daily_demand'] - (int) $area['allocated_water']);

                $selectedReservoir = null;
                foreach ($reservoirs as $index => $reservoir) {
                    if (
                        (int) $reservoir['remaining_supply'] > 0
                        && isReachable($reservoir['reservoir_id'], $areaId, $graph)
                    ) {
                        $selectedReservoir = $index;
                        break;
                    }
                }

                $allocated = 0;
                $reservoirId = null;
                if ($unmet === 0) {
                    $result = 'demand_met';
                } elseif ($selectedReservoir === null) {
                    $hasConnection = false;
                    foreach ($reservoirs as $reservoir) {
                        if (isReachable($reservoir['reservoir_id'], $areaId, $graph)) {
                            $hasConnection = true;
                            break;
                        }
                    }
                    $result = $hasConnection ? 'no_supply' : 'disconnected';
                } else {
                    $reservoirId = $reservoirs[$selectedReservoir]['reservoir_id'];
                    $allocated = min(
                        (int) $request['requested_litres'],
                        $unmet,
                        (int) $reservoirs[$selectedReservoir]['remaining_supply']
                    );

                    if ($allocated > 0) {
                        $updateReservoir->execute([$allocated, $reservoirId]);
                        $updateArea->execute([$allocated, $areaId]);
                        $reservoirs[$selectedReservoir]['remaining_supply'] -= $allocated;
                        $areas[$areaId]['allocated_water'] += $allocated;
                        $result = 'allocated';
                    } else {
                        $result = 'demand_met';
                    }
                }

                $insertHistory->execute([
                    $runId,
                    $request['request_id'],
                    $areaId,
                    $reservoirId,
                    $allocated,
                    $result,
                ]);
                $markProcessed->execute([$request['request_id']]);
            }

            $pdo->commit();
            redirectWithMessage('success', 'Pending requests were processed in priority and arrival order.');
        }

        if ($action === 'fail_pipeline' || $action === 'repair_pipeline') {
            $pipelineId = $_POST['pipeline_id'] ?? '';
            if (!is_string($pipelineId) || !preg_match('/^P[0-9]+$/', $pipelineId)) {
                redirectWithMessage('error', 'Choose a valid pipeline.');
            }

            $pdo->beginTransaction();
            $pipelineQuery = $pdo->prepare(
                'SELECT is_working FROM pipelines WHERE pipeline_id = ? FOR UPDATE'
            );
            $pipelineQuery->execute([$pipelineId]);
            $working = $pipelineQuery->fetchColumn();

            if ($working === false) {
                $pdo->rollBack();
                redirectWithMessage('error', 'The selected pipeline does not exist.');
            }

            if ($action === 'fail_pipeline') {
                if ((int) $working === 0) {
                    $pdo->rollBack();
                    redirectWithMessage('info', $pipelineId . ' is already failed.');
                }

                $update = $pdo->prepare('UPDATE pipelines SET is_working = 0 WHERE pipeline_id = ?');
                $update->execute([$pipelineId]);
                $maintenance = $pdo->prepare(
                    "INSERT INTO maintenance_requests (pipeline_id, issue, status)
                     VALUES (?, 'Pipeline failure', 'open')"
                );
                $maintenance->execute([$pipelineId]);
                $pdo->commit();
                redirectWithMessage('success', $pipelineId . ' marked failed and added to the maintenance queue.');
            }

            if ((int) $working === 1) {
                $pdo->rollBack();
                redirectWithMessage('info', $pipelineId . ' is already working.');
            }

            $update = $pdo->prepare('UPDATE pipelines SET is_working = 1 WHERE pipeline_id = ?');
            $update->execute([$pipelineId]);
            $pdo->commit();
            redirectWithMessage('success', $pipelineId . ' repaired. Its maintenance record remains in the queue.');
        }

        if ($action === 'reset_day') {
            $pdo->beginTransaction();
            $runId = activeRunId($pdo, true);

            $endRun = $pdo->prepare('UPDATE simulation_days SET ended_at = CURRENT_TIMESTAMP WHERE run_id = ?');
            $endRun->execute([$runId]);
            $pdo->exec('INSERT INTO simulation_days (started_at) VALUES (CURRENT_TIMESTAMP)');
            $newRunId = (int) $pdo->lastInsertId();

            $updateState = $pdo->prepare(
                "UPDATE system_state SET active_run_id = ? WHERE state_key = 'current_day'"
            );
            $updateState->execute([$newRunId]);
            $pdo->exec('UPDATE reservoirs SET remaining_supply = daily_supply');
            $pdo->exec('UPDATE demand_areas SET allocated_water = 0');

            $pdo->commit();
            redirectWithMessage(
                'success',
                'A new simulation day started. Supply and area allocations were reset; earlier records were retained.'
            );
        }

        redirectWithMessage('error', 'Unknown action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('WaterGrid action failed: ' . $exception->getMessage());
        redirectWithMessage('error', 'The operation failed and no partial changes were saved. Check the PHP error log.');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrfToken = $_SESSION['csrf_token'];
$runId = activeRunId($pdo);

$reservoirs = $pdo->query(
    'SELECT reservoir_id, name, daily_supply, remaining_supply FROM reservoirs ORDER BY reservoir_id'
)->fetchAll();
$areas = $pdo->query(
    'SELECT area_id, name, area_type, priority, daily_demand, allocated_water
     FROM demand_areas ORDER BY area_id'
)->fetchAll();
$pipelines = $pdo->query(
    'SELECT pipeline_id, endpoint_from, endpoint_to, is_working FROM pipelines ORDER BY pipeline_id'
)->fetchAll();

$pendingQuery = $pdo->prepare(
    "SELECT wr.request_id, wr.area_id, da.name AS area_name, da.priority,
            da.area_type, wr.requested_litres, wr.created_at
     FROM water_requests wr
     JOIN demand_areas da ON da.area_id = wr.area_id
     WHERE wr.run_id = ? AND wr.status = 'pending'
     ORDER BY
        CASE
            WHEN da.area_type = 'critical_institution' THEN 3
            WHEN da.priority = 'High' THEN 2
            ELSE 1
        END DESC,
        wr.request_id ASC"
);
$pendingQuery->execute([$runId]);
$pendingRequests = $pendingQuery->fetchAll();

$maintenance = $pdo->query(
    "SELECT mr.maintenance_id, mr.pipeline_id, mr.issue, mr.created_at,
            p.endpoint_from, p.endpoint_to
     FROM maintenance_requests mr
     JOIN pipelines p ON p.pipeline_id = mr.pipeline_id
     WHERE mr.status = 'open'
     ORDER BY mr.maintenance_id"
)->fetchAll();
$history = $pdo->query(
    'SELECT ah.allocation_id, ah.allocated_litres, ah.result, ah.created_at,
            da.area_id, da.name AS area_name, r.name AS reservoir_name
     FROM allocation_history ah
     JOIN demand_areas da ON da.area_id = ah.area_id
     LEFT JOIN reservoirs r ON r.reservoir_id = ah.reservoir_id
     ORDER BY ah.allocation_id DESC
     LIMIT 12'
)->fetchAll();

$graph = [];
foreach ($pipelines as $pipeline) {
    if ((int) $pipeline['is_working'] !== 1) {
        continue;
    }
    $graph[$pipeline['endpoint_from']][] = $pipeline['endpoint_to'];
    $graph[$pipeline['endpoint_to']][] = $pipeline['endpoint_from'];
}
$reservoirIds = array_column($reservoirs, 'reservoir_id');
$connectedCount = 0;
foreach ($areas as $area) {
    foreach ($reservoirIds as $reservoirId) {
        if (isReachable($reservoirId, $area['area_id'], $graph)) {
            $connectedCount++;
            break;
        }
    }
}

$totalSupply = array_sum(array_map(static fn(array $row): int => (int) $row['remaining_supply'], $reservoirs));
$dailyDemand = array_sum(array_map(static fn(array $row): int => (int) $row['daily_demand'], $areas));
$pipelineCount = count($pipelines);
$failedCount = count(array_filter($pipelines, static fn(array $row): bool => (int) $row['is_working'] !== 1));
$supplyPercentage = $dailyDemand > 0 ? min(100, ($totalSupply / $dailyDemand) * 100) : 0;
$supplyPercentageLabel = number_format($supplyPercentage, 1);
$csrfField = '<input type="hidden" name="csrf_token" value="' . e($csrfToken) . '">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Smart Water Distribution System operations dashboard">
    <title>WaterGrid | Operations Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="#overview" aria-label="WaterGrid dashboard">
                <span class="brand-mark" aria-hidden="true">W</span>
                <span class="brand-name">water<span>grid</span></span>
            </a>
            <div class="sidebar-label">WORKSPACE</div>
            <nav class="navigation" aria-label="Main navigation">
                <a class="nav-link active" href="#overview"><span class="nav-icon">⌂</span>Overview</a>
                <a class="nav-link" href="#areas"><span class="nav-icon">▦</span>Demand areas</a>
                <a class="nav-link" href="#reservoirs"><span class="nav-icon">◉</span>Reservoirs</a>
                <a class="nav-link" href="#pipelines"><span class="nav-icon">⌁</span>Pipelines</a>
                <a class="nav-link" href="#requests"><span class="nav-icon">⇢</span>Water requests</a>
                <a class="nav-link" href="#maintenance"><span class="nav-icon">⚒</span>Maintenance</a>
                <a class="nav-link" href="#history"><span class="nav-icon">◷</span>Allocation history</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="system-status"><span class="status-dot"></span>Database connected</div>
                <div class="sidebar-caption">Smart Water Distribution<br>Management</div>
            </div>
        </aside>

        <main class="main-content" id="overview">
            <header class="topbar">
                <div>
                    <div class="eyebrow">NETWORK OPERATIONS</div>
                    <h1>Overview</h1>
                </div>
                <div class="topbar-right">
                    <span class="preview-tag live-tag"><span class="status-dot"></span>Live database</span>
                    <span class="signed-in-user"><?= e($_SESSION['username'] ?? $_SESSION['user_email']) ?></span>
                    <form method="post" action="logout.php" class="logout-form">
                        <?= $csrfField ?>
                        <button type="submit" class="button button-secondary">Sign out</button>
                    </form>
                </div>
            </header>

            <?php if (is_array($flash)): ?>
                <div class="notice notice-<?= e($flash['type']) ?>" role="status">
                    <span class="notice-icon" aria-hidden="true">i</span>
                    <p><?= e($flash['message']) ?></p>
                </div>
            <?php endif; ?>

            <section class="metrics" aria-label="Network summary">
                <article class="metric-card">
                    <div class="metric-top"><span>Remaining reservoir supply</span><span class="metric-icon blue">◉</span></div>
                    <div class="metric-value"><?= number_format($totalSupply) ?> <small>L</small></div>
                    <div class="metric-foot">Across <?= count($reservoirs) ?> reservoirs</div>
                </article>
                <article class="metric-card">
                    <div class="metric-top"><span>Daily demand</span><span class="metric-icon violet">▦</span></div>
                    <div class="metric-value"><?= number_format($dailyDemand) ?> <small>L</small></div>
                    <div class="metric-foot">Across <?= count($areas) ?> demand areas</div>
                </article>
                <article class="metric-card">
                    <div class="metric-top"><span>Network pipelines</span><span class="metric-icon teal">⌁</span></div>
                    <div class="metric-value"><?= $pipelineCount ?> <small>total</small></div>
                    <div class="metric-foot">
                        <span class="inline-dot <?= $failedCount > 0 ? 'dot-failed' : '' ?>"></span>
                        <?= $failedCount === 0 ? 'All pipelines operational' : $failedCount . ' failed pipeline(s)' ?>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-top"><span>Connected areas</span><span class="metric-icon green">⌖</span></div>
                    <div class="metric-value"><?= $connectedCount ?> <small>/ <?= count($areas) ?></small></div>
                    <div class="metric-foot">
                        <span class="inline-dot <?= $connectedCount < count($areas) ? 'dot-failed' : '' ?>"></span>
                        <?= $connectedCount === count($areas) ? 'All areas reachable' : (count($areas) - $connectedCount) . ' area(s) disconnected' ?>
                    </div>
                </article>
            </section>

            <section class="content-grid" id="reservoirs">
                <article class="panel reservoir-panel">
                    <div class="panel-heading">
                        <div><div class="eyebrow">WATER SOURCES</div><h2>Reservoir status</h2></div>
                        <span class="count-label"><?= count($reservoirs) ?> reservoirs</span>
                    </div>
                    <div class="reservoir-list">
                        <?php foreach ($reservoirs as $index => $reservoir):
                            $percentage = (int) $reservoir['daily_supply'] > 0
                                ? min(100, (int) $reservoir['remaining_supply'] / (int) $reservoir['daily_supply'] * 100)
                                : 0;
                            ?>
                            <div class="reservoir-row">
                                <div class="reservoir-symbol"><?= e($reservoir['reservoir_id']) ?></div>
                                <div class="reservoir-info">
                                    <div class="row-title"><?= e($reservoir['name']) ?> <span class="capacity"><?= litres((int) $reservoir['daily_supply']) ?></span></div>
                                    <div class="progress-track"><span class="progress-fill <?= $index % 2 === 0 ? 'fill-blue' : 'fill-teal' ?>" style="width:<?= e((string) $percentage) ?>%"></span></div>
                                    <div class="progress-label"><span>Remaining supply</span><strong><?= litres((int) $reservoir['remaining_supply']) ?></strong></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="panel-footnote">Supply values reflect allocations for the current simulation day.</div>
                </article>

                <article class="panel allocation-panel">
                    <div class="panel-heading">
                        <div><div class="eyebrow">TODAY'S SUPPLY</div><h2>Supply vs. demand</h2></div>
                        <span class="round-icon">↗</span>
                    </div>
                    <div class="comparison">
                        <div class="comparison-number"><?= number_format($totalSupply) ?> <small>L</small></div>
                        <span class="comparison-divider">of</span>
                        <div class="comparison-number muted-number"><?= number_format($dailyDemand) ?> <small>L</small></div>
                    </div>
                    <div class="progress-track large-track"><span class="progress-fill fill-blue" style="width:<?= e((string) $supplyPercentage) ?>%"></span></div>
                    <div class="progress-label"><span>Available supply</span><strong><?= e($supplyPercentageLabel) ?>% of demand</strong></div>
                    <div class="allocation-callout">Supply is <?= $totalSupply >= $dailyDemand ? 'sufficient for' : 'lower than' ?> the total daily demand.</div>
                    <form method="post" class="reset-form">
                        <?= $csrfField ?>
                        <input type="hidden" name="action" value="reset_day">
                        <button class="button button-secondary" type="submit">Start new simulation day</button>
                    </form>
                </article>
            </section>

            <section class="panel areas-panel" id="areas">
                <div class="panel-heading">
                    <div><div class="eyebrow">DISTRIBUTION NETWORK</div><h2>Demand areas <span class="heading-count"><?= count($areas) ?></span></h2></div>
                    <span class="legend"><span class="legend-dot"></span>Connected</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>AREA</th><th>TYPE</th><th>PRIORITY</th><th>DAILY DEMAND</th><th>ALLOCATED</th><th>UNMET</th><th>STATUS</th></tr></thead>
                        <tbody>
                        <?php foreach ($areas as $area):
                            $connected = false;
                            foreach ($reservoirIds as $reservoirId) {
                                if (isReachable($reservoirId, $area['area_id'], $graph)) {
                                    $connected = true;
                                    break;
                                }
                            }
                            $unmet = max(0, (int) $area['daily_demand'] - (int) $area['allocated_water']);
                            $priorityClass = strtolower($area['priority']);
                            ?>
                            <tr>
                                <td><span class="id-badge"><?= e($area['area_id']) ?></span><span class="primary-cell"><?= e($area['name']) ?></span></td>
                                <td><?= $area['area_type'] === 'critical_institution' ? 'Critical institution' : 'Residential' ?></td>
                                <td><span class="priority <?= e($priorityClass) ?>"><?= e($area['priority']) ?></span></td>
                                <td><?= litres((int) $area['daily_demand']) ?></td>
                                <td><?= litres((int) $area['allocated_water']) ?></td>
                                <td><?= litres($unmet) ?></td>
                                <td><span class="status-pill <?= $connected ? 'connected' : 'disconnected' ?>"><?= $connected ? 'Connected' : 'Disconnected' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="lower-grid">
                <article class="panel" id="requests">
                    <div class="panel-heading">
                        <div><div class="eyebrow">DISTRIBUTION</div><h2>Water requests</h2></div>
                        <span class="count-label"><?= count($pendingRequests) ?> pending</span>
                    </div>
                    <form method="post" class="request-form">
                        <?= $csrfField ?>
                        <input type="hidden" name="action" value="add_request">
                        <label for="area_id">Demand area</label>
                        <select name="area_id" id="area_id" required>
                            <option value="">Select an area</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= e($area['area_id']) ?>"><?= e($area['area_id'] . ' - ' . $area['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="requested_litres">Requested litres</label>
                        <input type="number" id="requested_litres" name="requested_litres" min="1" step="1" required>
                        <div class="button-row"><button class="button button-primary" type="submit">Add request</button></div>
                    </form>
                    <?php if ($pendingRequests === []): ?>
                        <div class="empty-state compact-empty">
                            <span class="empty-icon">⇢</span><strong>No pending requests</strong><span>New requests will appear here.</span>
                        </div>
                    <?php else: ?>
                        <div class="request-list">
                            <?php foreach ($pendingRequests as $request): ?>
                                <div class="request-item">
                                    <span class="id-badge"><?= e($request['area_id']) ?></span>
                                    <span class="request-name"><?= e($request['area_name']) ?><small><?= e($request['priority']) ?> priority</small></span>
                                    <strong><?= litres((int) $request['requested_litres']) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="button-row">
                        <?= $csrfField ?>
                        <input type="hidden" name="action" value="allocate">
                        <button class="button button-primary" type="submit" <?= $pendingRequests === [] ? 'disabled' : '' ?>>Allocate pending water</button>
                    </form>
                </article>

                <article class="panel" id="maintenance">
                    <div class="panel-heading">
                        <div><div class="eyebrow">INFRASTRUCTURE</div><h2>Maintenance queue</h2></div>
                        <span class="count-label"><?= count($maintenance) ?> open</span>
                    </div>
                    <?php if ($maintenance === []): ?>
                        <div class="empty-state">
                            <span class="empty-icon">⚒</span><strong>All clear</strong><span>No pipeline maintenance requests.</span>
                        </div>
                    <?php else: ?>
                        <div class="maintenance-list">
                            <?php foreach ($maintenance as $item): ?>
                                <div class="maintenance-item">
                                    <span class="id-badge"><?= e($item['pipeline_id']) ?></span>
                                    <span class="request-name"><?= e($item['issue']) ?><small><?= e($item['endpoint_from']) ?> ↔ <?= e($item['endpoint_to']) ?></small></span>
                                    <span class="status-pill disconnected">Open</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="panel pipelines-panel" id="pipelines">
                <div class="panel-heading">
                    <div><div class="eyebrow">INFRASTRUCTURE</div><h2>Pipelines <span class="heading-count"><?= $pipelineCount ?></span></h2></div>
                    <span class="legend"><span class="legend-dot"></span>Working</span>
                </div>
                <div class="pipeline-grid">
                    <?php foreach ($pipelines as $pipeline): ?>
                        <div class="pipeline-item">
                            <span class="pipeline-id"><?= e($pipeline['pipeline_id']) ?></span>
                            <span><?= e($pipeline['endpoint_from']) ?> <i>↔</i> <?= e($pipeline['endpoint_to']) ?></span>
                            <b class="<?= (int) $pipeline['is_working'] === 1 ? 'pipe-working' : 'pipe-failed' ?>">
                                <?= (int) $pipeline['is_working'] === 1 ? 'Working' : 'Failed' ?>
                            </b>
                            <form method="post" class="pipeline-action">
                                <?= $csrfField ?>
                                <input type="hidden" name="pipeline_id" value="<?= e($pipeline['pipeline_id']) ?>">
                                <input type="hidden" name="action" value="<?= (int) $pipeline['is_working'] === 1 ? 'fail_pipeline' : 'repair_pipeline' ?>">
                                <button class="mini-button" type="submit"><?= (int) $pipeline['is_working'] === 1 ? 'Fail' : 'Repair' ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel history-panel" id="history">
                <div class="panel-heading">
                    <div><div class="eyebrow">RECORDS</div><h2>Allocation history</h2></div>
                    <span class="count-label">Latest 12 records</span>
                </div>
                <?php if ($history === []): ?>
                    <div class="history-empty">No allocations recorded yet.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>TIME</th><th>AREA</th><th>ALLOCATED</th><th>RESERVOIR</th><th>RESULT</th></tr></thead>
                            <tbody>
                            <?php foreach ($history as $record):
                                $resultLabels = [
                                    'allocated' => 'Allocated',
                                    'demand_met' => 'Demand already met',
                                    'disconnected' => 'Disconnected',
                                    'no_supply' => 'No reservoir supply',
                                ];
                                ?>
                                <tr>
                                    <td><?= e($record['created_at']) ?></td>
                                    <td><span class="id-badge"><?= e($record['area_id']) ?></span><span class="primary-cell"><?= e($record['area_name']) ?></span></td>
                                    <td><?= litres((int) $record['allocated_litres']) ?></td>
                                    <td><?= e($record['reservoir_name'] ?? '—') ?></td>
                                    <td><?= e($resultLabels[$record['result']] ?? $record['result']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="footer">
                <span>WaterGrid <span class="footer-separator">·</span> Smart Water Distribution System</span>
                <span>Connected to MySQL</span>
            </footer>
        </main>
    </div>
</body>
</html>
