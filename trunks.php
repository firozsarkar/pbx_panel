<?php
// ============================================================
//  trunks.php — FreeSWITCH Gateway / Trunk Manager
//  Single-file PHP app with file-based JSON database
// ============================================================

// ── 0. Bootstrap the JSON database ──────────────────────────
$dbFile = __DIR__ . '/trunks.json';
$dbDir  = __DIR__;

// Ensure the directory is writable
if (!is_writable($dbDir)) {
    @chmod($dbDir, 0755);
}

// Create the JSON file if it doesn't exist
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode([], JSON_PRETTY_PRINT));
    @chmod($dbFile, 0644);
}

// Ensure the file itself is writable
if (!is_writable($dbFile)) {
    @chmod($dbFile, 0644);
}

// ── 1. Helper: read / write trunks ──────────────────────────
function readTrunks(): array {
    global $dbFile;
    $raw = @file_get_contents($dbFile);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeTrunks(array $trunks): bool {
    global $dbFile;
    $result = file_put_contents($dbFile, json_encode(array_values($trunks), JSON_PRETTY_PRINT));
    return $result !== false;
}

// ── 2. Helper: run fs_cli safely ────────────────────────────
function fsCliRun(string $cmd): string {
    $escaped = escapeshellarg($cmd);
    $raw = @shell_exec("fs_cli -x {$escaped} 2>&1");
    return $raw === null ? '' : trim($raw);
}

// ── 3. Helper: parse gateway status ─────────────────────────
function getGatewayStatus(string $name): array {
    $safe   = escapeshellarg("sofia status gateway {$name}");
    $output = @shell_exec("fs_cli -x {$safe} 2>&1");
    if ($output === null) $output = '';

    $status = 'UNKNOWN';
    $ping   = '—';
    $calls  = '—';
    $state  = '—';

    // Extract Registration Status
    if (preg_match('/\bREGISTERED\b/i', $output))         $status = 'UP';
    elseif (preg_match('/\bFAILED\b/i', $output))         $status = 'FAILED';
    elseif (preg_match('/\bTRYING\b/i', $output))         $status = 'TRYING';
    elseif (preg_match('/\bNOREG\b/i', $output))          $status = 'NOREG';
    elseif (preg_match('/\bDOWN\b/i', $output))           $status = 'DOWN';
    elseif (preg_match('/No such gateway/i', $output))    $status = 'NOT_CONFIGURED';
    elseif (preg_match('/Unable to connect/i', $output))  $status = 'FS_OFFLINE';

    // Ping
    if (preg_match('/Ping\s+Time\s*:\s*([\d.]+\s*\w+)/i', $output, $m)) $ping = trim($m[1]);

    // Calls in / out
    if (preg_match('/Calls\s+In\s*:\s*(\d+)/i', $output, $m))  $calls = $m[1];

    // State
    if (preg_match('/State\s*:\s*(\S+)/i', $output, $m)) $state = trim($m[1]);

    return compact('status', 'ping', 'calls', 'state', 'output');
}

// ── 4. Helper: FreeSWITCH engine stats ──────────────────────
function getFsStats(): array {
    $version  = fsCliRun('version');
    $channels = fsCliRun('show channels count');
    $uptime   = fsCliRun('uptime');
    $calls    = fsCliRun('show calls count');

    // Parse channel count
    $chanCount = '?';
    if (preg_match('/(\d+)\s+total/i', $channels, $m)) $chanCount = $m[1];

    // Parse call count
    $callCount = '?';
    if (preg_match('/(\d+)\s+total/i', $calls, $m)) $callCount = $m[1];

    // Parse uptime seconds
    $uptimeStr = '?';
    if (preg_match('/(\d+)/i', $uptime, $m)) {
        $sec = (int)$m[1];
        $h   = floor($sec / 3600);
        $min = floor(($sec % 3600) / 60);
        $s   = $sec % 60;
        $uptimeStr = sprintf('%02dh %02dm %02ds', $h, $min, $s);
    }

    // Clean version
    $versionClean = preg_replace('/\n.*$/s', '', $version);

    // FS online check
    $fsOnline = (stripos($version, 'FreeSWITCH') !== false);

    return [
        'version'    => $versionClean ?: 'N/A',
        'channels'   => $chanCount,
        'calls'      => $callCount,
        'uptime'     => $uptimeStr,
        'online'     => $fsOnline,
    ];
}

// ── 5. CRUD action handling ──────────────────────────────────
$message = '';
$msgType = 'success';
$editTrunk = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── ADD ──────────────────────────────────────────────────
    if ($action === 'add') {
        $name     = trim($_POST['name']     ?? '');
        $realm    = trim($_POST['realm']    ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($name === '' || $realm === '') {
            $message = 'Gateway Name and Realm/IP are required.';
            $msgType = 'danger';
        } else {
            $trunks = readTrunks();
            // Check duplicate name
            foreach ($trunks as $t) {
                if (strtolower($t['name']) === strtolower($name)) {
                    $message = "A gateway named <strong>{$name}</strong> already exists.";
                    $msgType = 'warning';
                    break;
                }
            }
            if ($message === '') {
                $trunks[] = [
                    'id'       => uniqid('gw_', true),
                    'name'     => $name,
                    'realm'    => $realm,
                    'username' => $username,
                    'password' => $password,
                    'created'  => date('Y-m-d H:i:s'),
                ];
                writeTrunks($trunks);
                $message = "Gateway <strong>{$name}</strong> added successfully.";
            }
        }
    }

    // ── EDIT SAVE ────────────────────────────────────────────
    if ($action === 'update') {
        $id       = trim($_POST['id']       ?? '');
        $name     = trim($_POST['name']     ?? '');
        $realm    = trim($_POST['realm']    ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($name === '' || $realm === '') {
            $message = 'Gateway Name and Realm/IP are required.';
            $msgType = 'danger';
        } else {
            $trunks = readTrunks();
            $found  = false;
            foreach ($trunks as &$t) {
                if ($t['id'] === $id) {
                    $t['name']     = $name;
                    $t['realm']    = $realm;
                    $t['username'] = $username;
                    $t['password'] = $password;
                    $t['updated']  = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }
            unset($t);
            if ($found) {
                writeTrunks($trunks);
                $message = "Gateway <strong>{$name}</strong> updated successfully.";
            } else {
                $message = 'Gateway not found.';
                $msgType = 'danger';
            }
        }
    }

    // ── DELETE ───────────────────────────────────────────────
    if ($action === 'delete') {
        $id     = trim($_POST['id'] ?? '');
        $trunks = readTrunks();
        $name   = '';
        $trunks = array_filter($trunks, function($t) use ($id, &$name) {
            if ($t['id'] === $id) { $name = $t['name']; return false; }
            return true;
        });
        writeTrunks(array_values($trunks));
        $message = "Gateway <strong>{$name}</strong> deleted.";
        $msgType = 'warning';
    }
}

// ── Load edit form data ──────────────────────────────────────
if ($action === 'edit' && isset($_GET['id'])) {
    $editId = $_GET['id'];
    foreach (readTrunks() as $t) {
        if ($t['id'] === $editId) { $editTrunk = $t; break; }
    }
}

// ── 6. Load trunks & engine stats for display ───────────────
$trunks  = readTrunks();
$fsStats = getFsStats();

// Collect status for each gateway
$statusMap = [];
foreach ($trunks as $t) {
    $statusMap[$t['id']] = getGatewayStatus($t['name']);
}

// ── 7. Status badge helper ───────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'UP'             => ['bg-success',  'bi-check-circle-fill', 'REGISTERED'],
        'DOWN'           => ['bg-danger',   'bi-x-circle-fill',     'DOWN'],
        'FAILED'         => ['bg-danger',   'bi-exclamation-circle-fill', 'FAILED'],
        'TRYING'         => ['bg-warning',  'bi-arrow-repeat',      'TRYING'],
        'NOREG'          => ['bg-secondary','bi-dash-circle-fill',  'NO REG'],
        'UNKNOWN'        => ['bg-secondary','bi-question-circle-fill','UNKNOWN'],
        'NOT_CONFIGURED' => ['bg-dark',     'bi-gear-fill',         'NOT CONFIGURED'],
        'FS_OFFLINE'     => ['bg-danger',   'bi-wifi-off',          'FS OFFLINE'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['bg-secondary', 'bi-question', $status];
    return "<span class=\"badge {$cls} status-badge\"><i class=\"bi {$icon} me-1\"></i>{$label}</span>";
}

// Count statuses
$countUp      = 0;
$countDown    = 0;
$countUnknown = 0;
foreach ($statusMap as $s) {
    if ($s['status'] === 'UP') $countUp++;
    elseif (in_array($s['status'], ['DOWN','FAILED'])) $countDown++;
    else $countUnknown++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>FreeSWITCH Trunk Manager</title>

<!-- Bootstrap 5 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />

<style>
/* ── Root variables ─────────────────────────────────────── */
:root {
  --bg-main:        #0a0e1a;
  --bg-card:        rgba(255,255,255,0.055);
  --bg-card-hover:  rgba(255,255,255,0.09);
  --border-color:   rgba(255,255,255,0.10);
  --border-hover:   rgba(99,179,237,0.45);
  --accent:         #63b3ed;
  --accent-2:       #9f7aea;
  --accent-green:   #48bb78;
  --accent-red:     #fc8181;
  --accent-yellow:  #f6e05e;
  --text-primary:   #e2e8f0;
  --text-secondary: #94a3b8;
  --text-muted:     #64748b;
  --glow-blue:      0 0 25px rgba(99,179,237,0.18);
  --glow-green:     0 0 25px rgba(72,187,120,0.18);
  --radius:         14px;
  --radius-sm:      8px;
  --transition:     all 0.25s cubic-bezier(0.4,0,0.2,1);
}

/* ── Base ───────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
  margin: 0;
  font-family: 'Inter', sans-serif;
  background: var(--bg-main);
  color: var(--text-primary);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── Animated mesh background ───────────────────────────── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  z-index: -2;
  background:
    radial-gradient(ellipse 80% 60% at 20% 10%, rgba(99,179,237,0.09) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 85%, rgba(159,122,234,0.08) 0%, transparent 60%),
    radial-gradient(ellipse 50% 40% at 60% 50%, rgba(72,187,120,0.05) 0%, transparent 60%),
    linear-gradient(160deg, #0a0e1a 0%, #0d1424 40%, #0e0f1e 100%);
}

/* Subtle grid overlay */
body::after {
  content: '';
  position: fixed;
  inset: 0;
  z-index: -1;
  background-image:
    linear-gradient(rgba(99,179,237,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(99,179,237,0.03) 1px, transparent 1px);
  background-size: 40px 40px;
  mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 40%, transparent 100%);
}

/* ── Top Navbar ─────────────────────────────────────────── */
.top-nav {
  position: sticky;
  top: 0;
  z-index: 1000;
  background: rgba(10,14,26,0.82);
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-bottom: 1px solid var(--border-color);
  padding: 0 2rem;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.nav-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  text-decoration: none;
}

.brand-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: linear-gradient(135deg, #63b3ed, #9f7aea);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  color: #fff;
  box-shadow: 0 4px 15px rgba(99,179,237,0.35);
}

.brand-text {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text-primary);
  letter-spacing: -0.02em;
}

.brand-sub {
  font-size: 0.68rem;
  color: var(--text-muted);
  font-weight: 400;
  margin-top: 1px;
}

.nav-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 30px;
  font-size: 0.78rem;
  font-weight: 500;
}

.fs-status-pill {
  background: <?= $fsStats['online'] ? 'rgba(72,187,120,0.15)' : 'rgba(252,129,129,0.15)' ?>;
  border: 1px solid <?= $fsStats['online'] ? 'rgba(72,187,120,0.35)' : 'rgba(252,129,129,0.35)' ?>;
  color: <?= $fsStats['online'] ? 'var(--accent-green)' : 'var(--accent-red)' ?>;
}

/* ── Page wrapper ───────────────────────────────────────── */
.page-wrap { padding: 2rem 2rem 4rem; max-width: 1500px; margin: 0 auto; }

/* ── Section headings ───────────────────────────────────── */
.section-label {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 0.85rem;
}

/* ── Glass card ─────────────────────────────────────────── */
.glass-card {
  background: var(--bg-card);
  backdrop-filter: blur(16px) saturate(150%);
  -webkit-backdrop-filter: blur(16px) saturate(150%);
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  transition: var(--transition);
}

.glass-card:hover {
  border-color: var(--border-hover);
  box-shadow: var(--glow-blue);
}

.card-header-glass {
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid var(--border-color);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}

.card-title-glass {
  font-size: 0.95rem;
  font-weight: 650;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
}

.card-title-glass i { color: var(--accent); }

/* ── Stat cards ─────────────────────────────────────────── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }

.stat-card {
  padding: 1.2rem 1.4rem;
  position: relative;
  overflow: hidden;
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--line-color, linear-gradient(90deg, var(--accent), var(--accent-2)));
  border-radius: var(--radius) var(--radius) 0 0;
}

.stat-card .stat-icon {
  width: 40px; height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  margin-bottom: 0.8rem;
}

.stat-value {
  font-size: 1.9rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.03em;
  margin-bottom: 4px;
}

.stat-label {
  font-size: 0.72rem;
  color: var(--text-muted);
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

/* ── Version badge ──────────────────────────────────────── */
.version-badge {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.72rem;
  padding: 3px 10px;
  border-radius: 4px;
  background: rgba(99,179,237,0.1);
  border: 1px solid rgba(99,179,237,0.2);
  color: var(--accent);
}

/* ── Status badges ──────────────────────────────────────── */
.status-badge {
  font-size: 0.7rem !important;
  font-weight: 600 !important;
  padding: 5px 10px !important;
  letter-spacing: 0.05em;
  border-radius: 30px !important;
}

/* ── Trunk table ────────────────────────────────────────── */
.trunk-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
}

.trunk-table thead th {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--text-muted);
  padding: 0.8rem 1.1rem;
  border-bottom: 1px solid var(--border-color);
  white-space: nowrap;
}

.trunk-table tbody tr {
  transition: var(--transition);
  border-bottom: 1px solid rgba(255,255,255,0.04);
}

.trunk-table tbody tr:hover { background: var(--bg-card-hover); }

.trunk-table tbody tr:last-child { border-bottom: none; }

.trunk-table td {
  padding: 0.9rem 1.1rem;
  font-size: 0.85rem;
  vertical-align: middle;
}

.gw-name {
  font-weight: 600;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}

.gw-name-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.dot-up      { background: var(--accent-green); box-shadow: 0 0 8px var(--accent-green); animation: pulse-green 2s infinite; }
.dot-down    { background: var(--accent-red); }
.dot-unknown { background: var(--text-muted); }

@keyframes pulse-green {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.5; }
}

.mono { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; color: var(--text-secondary); }

/* ── Action buttons ─────────────────────────────────────── */
.btn-action {
  padding: 5px 12px;
  font-size: 0.75rem;
  font-weight: 600;
  border-radius: 6px;
  border: 1px solid transparent;
  cursor: pointer;
  transition: var(--transition);
  display: inline-flex;
  align-items: center;
  gap: 5px;
  text-decoration: none;
}

.btn-edit {
  background: rgba(99,179,237,0.12);
  border-color: rgba(99,179,237,0.3);
  color: var(--accent);
}
.btn-edit:hover {
  background: rgba(99,179,237,0.22);
  border-color: var(--accent);
  color: var(--accent);
}

.btn-delete {
  background: rgba(252,129,129,0.1);
  border-color: rgba(252,129,129,0.25);
  color: var(--accent-red);
}
.btn-delete:hover {
  background: rgba(252,129,129,0.22);
  border-color: var(--accent-red);
  color: var(--accent-red);
}

.btn-details {
  background: rgba(159,122,234,0.1);
  border-color: rgba(159,122,234,0.25);
  color: var(--accent-2);
}
.btn-details:hover {
  background: rgba(159,122,234,0.2);
  border-color: var(--accent-2);
  color: var(--accent-2);
}

/* ── Primary button ─────────────────────────────────────── */
.btn-primary-glass {
  background: linear-gradient(135deg, rgba(99,179,237,0.85), rgba(159,122,234,0.85));
  border: 1px solid rgba(99,179,237,0.5);
  color: #fff;
  padding: 9px 20px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition);
  display: inline-flex;
  align-items: center;
  gap: 7px;
  box-shadow: 0 4px 15px rgba(99,179,237,0.2);
}
.btn-primary-glass:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 25px rgba(99,179,237,0.35);
  color: #fff;
  text-decoration: none;
}

/* ── Form controls ──────────────────────────────────────── */
.form-control-glass {
  background: rgba(255,255,255,0.05) !important;
  border: 1px solid var(--border-color) !important;
  border-radius: var(--radius-sm) !important;
  color: var(--text-primary) !important;
  font-size: 0.85rem !important;
  padding: 10px 14px !important;
  transition: var(--transition) !important;
}

.form-control-glass::placeholder { color: var(--text-muted) !important; }

.form-control-glass:focus {
  background: rgba(255,255,255,0.08) !important;
  border-color: rgba(99,179,237,0.5) !important;
  box-shadow: 0 0 0 3px rgba(99,179,237,0.12) !important;
  outline: none !important;
  color: var(--text-primary) !important;
}

.form-label-glass {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  margin-bottom: 6px;
}

/* ── Alert flash messages ───────────────────────────────── */
.alert-glass {
  border-radius: var(--radius-sm);
  border: 1px solid;
  padding: 12px 16px;
  font-size: 0.85rem;
  backdrop-filter: blur(10px);
}
.alert-glass.success {
  background: rgba(72,187,120,0.12);
  border-color: rgba(72,187,120,0.3);
  color: #9ae6b4;
}
.alert-glass.danger {
  background: rgba(252,129,129,0.1);
  border-color: rgba(252,129,129,0.3);
  color: #feb2b2;
}
.alert-glass.warning {
  background: rgba(246,224,94,0.1);
  border-color: rgba(246,224,94,0.3);
  color: #fef08a;
}

/* ── Output code block ──────────────────────────────────── */
.output-block {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.73rem;
  background: rgba(0,0,0,0.4);
  border: 1px solid rgba(255,255,255,0.07);
  border-radius: 6px;
  padding: 12px;
  white-space: pre-wrap;
  word-break: break-all;
  color: #a0aec0;
  max-height: 200px;
  overflow-y: auto;
  line-height: 1.6;
}

/* ── Modal customization ─────────────────────────────────── */
.modal-glass .modal-content {
  background: rgba(14,18,36,0.97);
  backdrop-filter: blur(24px);
  border: 1px solid var(--border-color);
  border-radius: var(--radius);
  color: var(--text-primary);
}
.modal-glass .modal-header {
  border-bottom: 1px solid var(--border-color);
  padding: 1.1rem 1.4rem;
}
.modal-glass .modal-footer {
  border-top: 1px solid var(--border-color);
}
.modal-glass .modal-title {
  font-weight: 700;
  font-size: 1rem;
}
.modal-glass .btn-close {
  filter: invert(1) brightness(0.7);
}
.modal-backdrop { backdrop-filter: blur(3px); }

/* ── Empty state ────────────────────────────────────────── */
.empty-state {
  padding: 4rem 2rem;
  text-align: center;
}
.empty-state .empty-icon {
  font-size: 3rem;
  color: var(--text-muted);
  margin-bottom: 1rem;
}
.empty-state h5 { color: var(--text-secondary); font-weight: 600; }
.empty-state p  { color: var(--text-muted); font-size: 0.85rem; }

/* ── Divider ────────────────────────────────────────────── */
.divider { border-color: var(--border-color); opacity: 1; }

/* ── Refresh button spin ─────────────────────────────────── */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Scrollbar ──────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

/* ── Password visibility toggle ─────────────────────────── */
.pw-wrap { position: relative; }
.pw-toggle {
  position: absolute;
  right: 10px; top: 50%;
  transform: translateY(-50%);
  background: none; border: none;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 1rem;
  padding: 0;
  transition: color 0.2s;
}
.pw-toggle:hover { color: var(--accent); }

/* ── Responsive tweaks ───────────────────────────────────── */
@media (max-width: 768px) {
  .top-nav   { padding: 0 1rem; }
  .page-wrap { padding: 1rem 1rem 3rem; }
  .stat-grid { grid-template-columns: repeat(2, 1fr); }
  .trunk-table td, .trunk-table th { padding: 0.7rem 0.75rem; }
}
</style>
</head>

<body>

<!-- ── TOP NAV ─────────────────────────────────────────────── -->
<nav class="top-nav">
  <a class="nav-brand" href="trunks.php">
    <div class="brand-icon"><i class="bi bi-diagram-3-fill"></i></div>
    <div>
      <div class="brand-text">TrunkManager</div>
      <div class="brand-sub">FreeSWITCH Gateway Control</div>
    </div>
  </a>

  <div class="d-flex align-items-center gap-3">
    <span class="nav-pill fs-status-pill">
      <i class="bi <?= $fsStats['online'] ? 'bi-circle-fill' : 'bi-circle' ?> me-1" style="font-size:0.55rem"></i>
      FreeSWITCH <?= $fsStats['online'] ? 'Online' : 'Offline' ?>
    </span>
    <button class="btn-action btn-details" onclick="location.reload()" title="Refresh status">
      <i class="bi bi-arrow-clockwise" id="refreshIcon"></i> Refresh
    </button>
    <button class="btn-primary-glass" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg"></i> Add Trunk
    </button>
  </div>
</nav>

<!-- ── PAGE WRAP ───────────────────────────────────────────── -->
<div class="page-wrap">

  <!-- Flash message -->
  <?php if ($message): ?>
  <div class="alert-glass <?= $msgType ?> mb-4 d-flex align-items-center gap-2" role="alert" id="flashMsg">
    <i class="bi <?= $msgType === 'success' ? 'bi-check-circle-fill' : ($msgType === 'danger' ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill') ?>"></i>
    <span><?= $message ?></span>
    <button class="ms-auto" style="background:none;border:none;color:inherit;font-size:1rem;cursor:pointer" onclick="document.getElementById('flashMsg').remove()">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <?php endif; ?>

  <!-- ── ENGINE STATS ROW ───────────────────────────────────── -->
  <p class="section-label"><i class="bi bi-cpu me-1"></i>Engine Overview</p>
  <div class="stat-grid mb-4">

    <!-- FS Version -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#63b3ed,#9f7aea)">
      <div class="stat-icon" style="background:rgba(99,179,237,0.12);color:#63b3ed">
        <i class="bi bi-info-circle-fill"></i>
      </div>
      <div class="stat-value" style="font-size:0.85rem;letter-spacing:0;color:var(--accent)" title="<?= htmlspecialchars($fsStats['version']) ?>">
        <?= htmlspecialchars(substr($fsStats['version'], 0, 28) . (strlen($fsStats['version']) > 28 ? '…' : '')) ?>
      </div>
      <div class="stat-label">FreeSWITCH Version</div>
    </div>

    <!-- Active Channels -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#48bb78,#38a169)">
      <div class="stat-icon" style="background:rgba(72,187,120,0.12);color:#48bb78">
        <i class="bi bi-telephone-fill"></i>
      </div>
      <div class="stat-value" style="color:var(--accent-green)"><?= htmlspecialchars($fsStats['channels']) ?></div>
      <div class="stat-label">Active Channels</div>
    </div>

    <!-- Active Calls -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#9f7aea,#805ad5)">
      <div class="stat-icon" style="background:rgba(159,122,234,0.12);color:#9f7aea">
        <i class="bi bi-headset"></i>
      </div>
      <div class="stat-value" style="color:var(--accent-2)"><?= htmlspecialchars($fsStats['calls']) ?></div>
      <div class="stat-label">Active Calls</div>
    </div>

    <!-- Uptime -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#f6e05e,#d69e2e)">
      <div class="stat-icon" style="background:rgba(246,224,94,0.1);color:#f6e05e">
        <i class="bi bi-clock-fill"></i>
      </div>
      <div class="stat-value" style="font-size:1.35rem;color:var(--accent-yellow)"><?= htmlspecialchars($fsStats['uptime']) ?></div>
      <div class="stat-label">Engine Uptime</div>
    </div>

    <!-- Trunk Count -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#fc8181,#e53e3e)">
      <div class="stat-icon" style="background:rgba(252,129,129,0.1);color:#fc8181">
        <i class="bi bi-hdd-network-fill"></i>
      </div>
      <div class="stat-value" style="color:var(--accent-red)"><?= count($trunks) ?></div>
      <div class="stat-label">Total Trunks</div>
    </div>

    <!-- UP / DOWN summary -->
    <div class="glass-card stat-card" style="--line-color: linear-gradient(90deg,#48bb78,#fc8181)">
      <div class="stat-icon" style="background:rgba(255,255,255,0.06);color:var(--text-secondary)">
        <i class="bi bi-bar-chart-fill"></i>
      </div>
      <div class="stat-value" style="font-size:1.3rem;display:flex;align-items:center;gap:8px">
        <span style="color:var(--accent-green)"><?= $countUp ?></span>
        <span style="color:var(--text-muted);font-size:1rem">/</span>
        <span style="color:var(--accent-red)"><?= $countDown ?></span>
        <span style="color:var(--text-muted);font-size:0.9rem"><?php if($countUnknown): ?> / <span style="color:var(--text-muted)"><?= $countUnknown ?></span><?php endif; ?></span>
      </div>
      <div class="stat-label">Up / Down / Unknown</div>
    </div>

  </div><!-- /stat-grid -->

  <!-- ── TRUNK TABLE ─────────────────────────────────────────── -->
  <p class="section-label"><i class="bi bi-hdd-stack me-1"></i>Configured Gateways</p>
  <div class="glass-card">
    <div class="card-header-glass">
      <h6 class="card-title-glass">
        <i class="bi bi-diagram-3"></i>
        Gateway Registry
        <span class="ms-2" style="font-size:0.72rem;color:var(--text-muted);font-weight:400"><?= count($trunks) ?> trunk<?= count($trunks) !== 1 ? 's' : '' ?></span>
      </h6>
      <div class="d-flex align-items-center gap-2">
        <span style="font-size:0.72rem;color:var(--text-muted)">
          <i class="bi bi-arrow-clockwise me-1"></i>Updated: <?= date('H:i:s') ?>
        </span>
      </div>
    </div>

    <?php if (empty($trunks)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="bi bi-hdd-network"></i></div>
      <h5>No Gateways Configured</h5>
      <p>Click <strong>Add Trunk</strong> to register your first FreeSWITCH gateway.</p>
      <button class="btn-primary-glass mt-2" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg"></i> Add Your First Trunk
      </button>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="trunk-table">
        <thead>
          <tr>
            <th>Gateway</th>
            <th>Realm / Host</th>
            <th>Username</th>
            <th>Status</th>
            <th>Ping</th>
            <th>Calls In</th>
            <th>State</th>
            <th>Created</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trunks as $trunk):
            $st    = $statusMap[$trunk['id']] ?? ['status'=>'UNKNOWN','ping'=>'—','calls'=>'—','state'=>'—','output'=>''];
            $isUp  = $st['status'] === 'UP';
            $isDown= in_array($st['status'], ['DOWN','FAILED']);
            $dotCls= $isUp ? 'dot-up' : ($isDown ? 'dot-down' : 'dot-unknown');
          ?>
          <tr>
            <!-- Name -->
            <td>
              <div class="gw-name">
                <span class="gw-name-dot <?= $dotCls ?>"></span>
                <?= htmlspecialchars($trunk['name']) ?>
              </div>
            </td>

            <!-- Realm -->
            <td><span class="mono"><?= htmlspecialchars($trunk['realm']) ?></span></td>

            <!-- Username -->
            <td><span class="mono"><?= htmlspecialchars($trunk['username'] ?: '—') ?></span></td>

            <!-- Status -->
            <td><?= statusBadge($st['status']) ?></td>

            <!-- Ping -->
            <td><span class="mono" style="font-size:0.75rem"><?= htmlspecialchars($st['ping']) ?></span></td>

            <!-- Calls -->
            <td><span class="mono"><?= htmlspecialchars($st['calls']) ?></span></td>

            <!-- State -->
            <td><span class="mono"><?= htmlspecialchars($st['state']) ?></span></td>

            <!-- Created -->
            <td style="color:var(--text-muted);font-size:0.75rem"><?= htmlspecialchars($trunk['created'] ?? '—') ?></td>

            <!-- Actions -->
            <td style="text-align:right;white-space:nowrap">
              <div class="d-flex gap-2 justify-content-end">
                <button class="btn-action btn-details"
                  onclick='showDetails(<?= htmlspecialchars(json_encode([
                    "name"   => $trunk['name'],
                    "realm"  => $trunk['realm'],
                    "user"   => $trunk['username'],
                    "status" => $st['status'],
                    "ping"   => $st['ping'],
                    "state"  => $st['state'],
                    "calls"  => $st['calls'],
                    "output" => $st['output'],
                  ]), ENT_QUOTES) ?>)'>
                  <i class="bi bi-terminal"></i> Details
                </button>
                <a class="btn-action btn-edit"
                   href="trunks.php?action=edit&id=<?= urlencode($trunk['id']) ?>">
                  <i class="bi bi-pencil-square"></i> Edit
                </a>
                <button class="btn-action btn-delete"
                  onclick='confirmDelete(<?= htmlspecialchars(json_encode(["id"=>$trunk['id'],"name"=>$trunk['name']]), ENT_QUOTES) ?>)'>
                  <i class="bi bi-trash3"></i> Delete
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div><!-- /glass-card trunk table -->

  <!-- ── FOOTER ──────────────────────────────────────────────── -->
  <div class="mt-4 d-flex align-items-center justify-content-between flex-wrap gap-2"
       style="font-size:0.72rem;color:var(--text-muted)">
    <span>
      <i class="bi bi-shield-check me-1" style="color:var(--accent)"></i>
      TrunkManager &mdash; FreeSWITCH Gateway Control Panel
    </span>
    <span>
      <i class="bi bi-clock me-1"></i><?= date('Y-m-d H:i:s T') ?>
    </span>
  </div>

</div><!-- /page-wrap -->


<!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════ -->

<!-- ── ADD TRUNK MODAL ──────────────────────────────────────── -->
<div class="modal fade modal-glass" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addModalLabel">
          <i class="bi bi-plus-circle-fill me-2" style="color:var(--accent)"></i>Add New Gateway
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="trunks.php">
        <input type="hidden" name="action" value="add" />
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label-glass">Gateway Name <span style="color:var(--accent-red)">*</span></label>
              <input type="text" name="name" class="form-control form-control-glass"
                     placeholder="e.g. provider-primary" required
                     pattern="[a-zA-Z0-9_\-]+" title="Use letters, numbers, hyphens, underscores only" />
              <div style="font-size:0.7rem;color:var(--text-muted);margin-top:4px">
                Alphanumeric, hyphens and underscores only
              </div>
            </div>
            <div class="col-12">
              <label class="form-label-glass">Realm / SIP Host <span style="color:var(--accent-red)">*</span></label>
              <input type="text" name="realm" class="form-control form-control-glass"
                     placeholder="e.g. sip.provider.com or 192.168.1.1" required />
            </div>
            <div class="col-md-6">
              <label class="form-label-glass">Username</label>
              <input type="text" name="username" class="form-control form-control-glass"
                     placeholder="SIP username" autocomplete="off" />
            </div>
            <div class="col-md-6">
              <label class="form-label-glass">Password</label>
              <div class="pw-wrap">
                <input type="password" name="password" id="addPw" class="form-control form-control-glass"
                       placeholder="SIP password" autocomplete="new-password" />
                <button type="button" class="pw-toggle" onclick="togglePw('addPw', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
          </div>
          <div class="mt-3 p-3 rounded" style="background:rgba(99,179,237,0.06);border:1px solid rgba(99,179,237,0.15);font-size:0.75rem;color:var(--text-secondary)">
            <i class="bi bi-info-circle me-1" style="color:var(--accent)"></i>
            Credentials are stored in <code style="color:var(--accent)">trunks.json</code> and used only for status lookups via <code style="color:var(--accent)">fs_cli</code>.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-action btn-delete" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Cancel
          </button>
          <button type="submit" class="btn-primary-glass">
            <i class="bi bi-plus-lg"></i> Add Gateway
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── EDIT TRUNK MODAL ──────────────────────────────────────── -->
<div class="modal fade modal-glass" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true"
  <?= $editTrunk ? 'data-open="1"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">
          <i class="bi bi-pencil-square me-2" style="color:var(--accent)"></i>Edit Gateway
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="trunks.php">
        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="id" id="editId" value="<?= htmlspecialchars($editTrunk['id'] ?? '') ?>" />
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label-glass">Gateway Name <span style="color:var(--accent-red)">*</span></label>
              <input type="text" name="name" id="editName" class="form-control form-control-glass"
                     value="<?= htmlspecialchars($editTrunk['name'] ?? '') ?>"
                     placeholder="e.g. provider-primary" required
                     pattern="[a-zA-Z0-9_\-]+" title="Use letters, numbers, hyphens, underscores only" />
            </div>
            <div class="col-12">
              <label class="form-label-glass">Realm / SIP Host <span style="color:var(--accent-red)">*</span></label>
              <input type="text" name="realm" id="editRealm" class="form-control form-control-glass"
                     value="<?= htmlspecialchars($editTrunk['realm'] ?? '') ?>"
                     placeholder="e.g. sip.provider.com" required />
            </div>
            <div class="col-md-6">
              <label class="form-label-glass">Username</label>
              <input type="text" name="username" id="editUser" class="form-control form-control-glass"
                     value="<?= htmlspecialchars($editTrunk['username'] ?? '') ?>"
                     placeholder="SIP username" autocomplete="off" />
            </div>
            <div class="col-md-6">
              <label class="form-label-glass">Password</label>
              <div class="pw-wrap">
                <input type="password" name="password" id="editPw" class="form-control form-control-glass"
                       value="<?= htmlspecialchars($editTrunk['password'] ?? '') ?>"
                       placeholder="SIP password" autocomplete="new-password" />
                <button type="button" class="pw-toggle" onclick="togglePw('editPw', this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="trunks.php" class="btn-action btn-delete text-decoration-none">
            <i class="bi bi-x-lg"></i> Cancel
          </a>
          <button type="submit" class="btn-primary-glass">
            <i class="bi bi-check-lg"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── DELETE CONFIRM MODAL ────────────────────────────────── -->
<div class="modal fade modal-glass" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-exclamation-triangle-fill me-2" style="color:var(--accent-yellow)"></i>Confirm Delete
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <p style="color:var(--text-secondary);font-size:0.85rem">
          You are about to permanently delete gateway:
        </p>
        <div id="deleteGwName" class="my-2"
             style="font-size:1rem;font-weight:700;color:var(--accent-red)"></div>
        <p style="color:var(--text-muted);font-size:0.78rem">This action cannot be undone.</p>
      </div>
      <div class="modal-footer justify-content-center gap-3">
        <button class="btn-action btn-edit" data-bs-dismiss="modal">
          <i class="bi bi-arrow-left"></i> Cancel
        </button>
        <form method="POST" action="trunks.php" id="deleteForm">
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" id="deleteId" value="" />
          <button type="submit" class="btn-action btn-delete">
            <i class="bi bi-trash3-fill"></i> Delete Gateway
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── GATEWAY DETAILS MODAL ────────────────────────────────── -->
<div class="modal fade modal-glass" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-terminal-fill me-2" style="color:var(--accent-2)"></i>
          Gateway Details &mdash; <span id="detailGwName" style="color:var(--accent)"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Meta grid -->
        <div class="row g-3 mb-4" id="detailMeta"></div>
        <!-- Raw output -->
        <label class="form-label-glass mb-2">
          <i class="bi bi-terminal me-1"></i> Raw fs_cli Output
        </label>
        <div class="output-block" id="detailOutput"></div>
      </div>
      <div class="modal-footer">
        <button class="btn-action btn-edit" data-bs-dismiss="modal">
          <i class="bi bi-x-lg"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Auto-open edit modal if ?action=edit ────────────────────
document.addEventListener('DOMContentLoaded', function () {
  const editEl = document.getElementById('editModal');
  if (editEl && editEl.dataset.open === '1') {
    new bootstrap.Modal(editEl).show();
  }

  // Auto-dismiss flash after 5s
  const flash = document.getElementById('flashMsg');
  if (flash) setTimeout(() => flash.style.opacity = '0', 4500);
});

// ── Password visibility toggle ───────────────────────────────
function togglePw(inputId, btn) {
  const input = document.getElementById(inputId);
  const icon  = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

// ── Delete modal trigger ─────────────────────────────────────
function confirmDelete(data) {
  document.getElementById('deleteId').value      = data.id;
  document.getElementById('deleteGwName').textContent = data.name;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// ── Details modal trigger ────────────────────────────────────
function showDetails(data) {
  document.getElementById('detailGwName').textContent = data.name;

  const statusColors = {
    UP: '#48bb78', DOWN: '#fc8181', FAILED: '#fc8181',
    TRYING: '#f6e05e', NOREG: '#94a3b8',
    UNKNOWN: '#94a3b8', NOT_CONFIGURED: '#a0aec0', FS_OFFLINE: '#fc8181'
  };
  const color = statusColors[data.status] || '#94a3b8';

  const metaItems = [
    { label: 'Registration Status', value: data.status, color: color },
    { label: 'Realm / Host',        value: data.realm || '—' },
    { label: 'Username',            value: data.user  || '—' },
    { label: 'Ping Time',           value: data.ping  || '—' },
    { label: 'Calls In',            value: data.calls || '—' },
    { label: 'State',               value: data.state || '—' },
  ];

  let metaHtml = '';
  metaItems.forEach(function(item) {
    metaHtml += `
      <div class="col-md-4 col-6">
        <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);
                    border-radius:8px;padding:12px 14px">
          <div style="font-size:0.68rem;color:var(--text-muted);text-transform:uppercase;
                      letter-spacing:0.08em;font-weight:600;margin-bottom:4px">${item.label}</div>
          <div style="font-size:0.9rem;font-weight:600;color:${item.color || 'var(--text-primary)'}">
            ${escHtml(String(item.value))}
          </div>
        </div>
      </div>`;
  });

  document.getElementById('detailMeta').innerHTML = metaHtml;
  document.getElementById('detailOutput').textContent =
    data.output && data.output.trim() !== '' ? data.output : '(no output — FreeSWITCH may be offline)';

  new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

// ── HTML escape helper ───────────────────────────────────────
function escHtml(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(str));
  return d.innerHTML;
}

// ── Refresh spin animation ───────────────────────────────────
document.querySelector('[onclick="location.reload()"]')?.addEventListener('click', function () {
  const icon = document.getElementById('refreshIcon');
  if (icon) icon.classList.add('spin');
});
</script>

</body>
</html>
