<?php
// ============================================================
//  trunks.php — FreeSWITCH Gateway / Trunk Manager v2
//  Auto-imports live gateways from FreeSWITCH engine
// ============================================================

// ── 0. Bootstrap JSON database ──────────────────────────────
$dbFile = __DIR__ . '/trunks.json';
$dbDir  = __DIR__;

if (!is_writable($dbDir))  @chmod($dbDir,  0755);
if (!file_exists($dbFile)) { file_put_contents($dbFile, json_encode([], JSON_PRETTY_PRINT)); @chmod($dbFile, 0644); }
if (!is_writable($dbFile)) @chmod($dbFile, 0644);

// ── 1. Read / Write helpers ──────────────────────────────────
function readTrunks(): array {
    global $dbFile;
    $raw  = @file_get_contents($dbFile);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function writeTrunks(array $trunks): bool {
    global $dbFile;
    return file_put_contents($dbFile, json_encode(array_values($trunks), JSON_PRETTY_PRINT)) !== false;
}

// ── 2. fs_cli runner ─────────────────────────────────────────
function fsCliRun(string $cmd): string {
    $out = @shell_exec('fs_cli -x ' . escapeshellarg($cmd) . ' 2>&1');
    return $out === null ? '' : trim($out);
}

// ── 3. Parse ONE gateway status ──────────────────────────────
function getGatewayStatus(string $name): array {
    $output = @shell_exec('fs_cli -x ' . escapeshellarg("sofia status gateway {$name}") . ' 2>&1');
    if ($output === null) $output = '';

    $status = 'UNKNOWN';
    $ping   = '—';
    $calls  = '—';
    $state  = '—';
    $realm  = '';
    $user   = '';

    if      (preg_match('/\bREGISTERED\b/i',  $output))                              $status = 'UP';
    elseif  (preg_match('/\bREGED\b/i',       $output))                              $status = 'UP';
    elseif  (preg_match('/\bFAILED\b/i',      $output))                              $status = 'FAILED';
    elseif  (preg_match('/\bEXPIRED?\b/i',    $output))                              $status = 'EXPIRED';
    elseif  (preg_match('/\bTRYING\b/i',      $output))                              $status = 'TRYING';
    elseif  (preg_match('/\bNOREG\b/i',       $output))                              $status = 'NOREG';
    elseif  (preg_match('/\bDOWN\b/i',        $output))                              $status = 'DOWN';
    elseif  (preg_match('/Invalid\s+Gateway/i',$output))                             $status = 'INVALID';
    elseif  (preg_match('/No such gateway/i',  $output))                             $status = 'INVALID';
    elseif  (preg_match('/(Unable to connect|connect.*refused)/i', $output))         $status = 'FS_OFFLINE';
    elseif  (trim($output) === '')                                                    $status = 'FS_OFFLINE';

    if (preg_match('/Ping\s+Time\s*:\s*([\d.]+\s*\w+)/i',   $output, $m)) $ping  = trim($m[1]);
    if (preg_match('/Calls\s+In\s*:\s*(\d+)/i',             $output, $m)) $calls = $m[1];
    if (preg_match('/State\s*:\s*(\S+)/i',                  $output, $m)) $state = trim($m[1]);
    if (preg_match('/Realm\s*:\s*(\S+)/i',                  $output, $m)) $realm = trim($m[1]);
    if (preg_match('/(?:Username|User)\s*:\s*(\S+)/i',      $output, $m)) $user  = trim($m[1]);

    return compact('status','ping','calls','state','realm','user','output');
}

// ── 4. AUTO-IMPORT: parse all gateways from FreeSWITCH ───────
function getFsGateways(): array {
    // "sofia status" lists all profiles and gateways
    $raw = fsCliRun('sofia status');
    if ($raw === '' || stripos($raw, 'Unable to connect') !== false) return [];

    $gateways = [];

    // Also try: sofia xmlstatus gateway  — but "sofia status" is most portable.
    // We look for lines like:  gateway_name    sip:realm     ...
    // Pattern in sofia status output:
    //   <gateway-name>          <realm>        <state>     <ping>
    // Real format example:
    //   gw-voip           sip:sip.example.com    REGED       5ms

    // Parse the gateway block from "sofia status" — gateways appear after profile sections
    // Format: "  <name>  <proxy/realm>  REGISTERED|REGED|FAILED|..."
    // Use "sofia status profile internal" won't always work; use global "sofia status"
    // Gateway lines have no leading colon and contain a known state keyword
    $lines = explode("\n", $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') !== false) continue;
        // Match: NAME   sip:REALM   STATE   [ping]
        if (preg_match('/^(\S+)\s+(sip:\S+|\S+\.\S+|\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?)\s+(REGED|REGISTERED|FAILED|TRYING|NOREG|DOWN|EXPIRE)/i', $line, $m)) {
            $gname = trim($m[1]);
            $realm = preg_replace('#^sip:#i', '', trim($m[2]));
            if (!in_array(strtolower($gname), ['name','profile','type','data','state','rate','ready'])) {
                $gateways[$gname] = $realm;
            }
        }
    }

    // Fallback: "sofia status" with "Gateway" keyword lines
    if (empty($gateways)) {
        // Try sofia xmlstatus
        $xmlRaw = fsCliRun('sofia xmlstatus');
        if (preg_match_all('/<name>(.*?)<\/name>.*?<proxy>(.*?)<\/proxy>/s', $xmlRaw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $gname = trim($m[1]);
                $realm = trim($m[2]);
                if ($gname !== '' && !preg_match('/^_/', $gname)) {
                    $gateways[$gname] = preg_replace('#^sip:#i', '', $realm);
                }
            }
        }
    }

    return $gateways;
}

// ── 5. AUTO-SYNC: import FS gateways → trunks.json ───────────
function autoImportFsGateways(): array {
    $fsGws  = getFsGateways();
    $trunks = readTrunks();
    $added  = [];

    if (empty($fsGws)) return $added;

    $existingNames = array_map(fn($t) => strtolower($t['name']), $trunks);

    foreach ($fsGws as $gwName => $realm) {
        if (in_array(strtolower($gwName), $existingNames)) continue;
        // Fetch more detail for this specific gateway
        $detail  = getGatewayStatus($gwName);
        $trunks[] = [
            'id'       => uniqid('gw_', true),
            'name'     => $gwName,
            'realm'    => $realm ?: ($detail['realm'] ?: ''),
            'username' => $detail['user'] ?: '',
            'password' => '',
            'source'   => 'auto',
            'created'  => date('Y-m-d H:i:s'),
        ];
        $added[] = $gwName;
        $existingNames[] = strtolower($gwName);
    }

    if (!empty($added)) writeTrunks($trunks);
    return $added;
}

// ── 6. Engine stats ──────────────────────────────────────────
function getFsStats(): array {
    $version  = fsCliRun('version');
    $channels = fsCliRun('show channels count');
    $uptime   = fsCliRun('uptime');
    $calls    = fsCliRun('show calls count');
    $sofia    = fsCliRun('sofia status');

    $chanCount = '0'; if (preg_match('/(\d+)\s+total/i', $channels, $m)) $chanCount = $m[1];
    $callCount = '0'; if (preg_match('/(\d+)\s+total/i', $calls,    $m)) $callCount = $m[1];

    $uptimeStr = '—';
    if (preg_match('/(\d+)/i', $uptime, $m)) {
        $sec = (int)$m[1];
        $uptimeStr = sprintf('%02dh %02dm %02ds', floor($sec/3600), floor(($sec%3600)/60), $sec%60);
    }

    $versionClean = trim(preg_replace('/\n.*$/s', '', $version));
    $fsOnline     = stripos($version, 'FreeSWITCH') !== false;

    // Count profiles from sofia status
    $profileCount = 0;
    if (preg_match_all('/^\s*(?:internal|external|\S+)\s+\d+\s+RUNNING/im', $sofia, $pm))
        $profileCount = count($pm[0]);

    return [
        'version'      => $versionClean ?: 'N/A',
        'channels'     => $chanCount,
        'calls'        => $callCount,
        'uptime'       => $uptimeStr,
        'online'       => $fsOnline,
        'profiles'     => $profileCount ?: '—',
    ];
}

// ── 7. Status badge ──────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'UP'         => ['bg-success',           'bi-check-circle-fill',       'REGISTERED'],
        'DOWN'       => ['bg-danger',             'bi-x-circle-fill',           'DOWN'],
        'FAILED'     => ['bg-danger',             'bi-exclamation-circle-fill', 'FAILED'],
        'EXPIRED'    => ['bg-warning text-dark',  'bi-hourglass-split',         'EXPIRED'],
        'TRYING'     => ['bg-warning text-dark',  'bi-arrow-repeat',            'TRYING'],
        'NOREG'      => ['bg-secondary',          'bi-dash-circle-fill',        'NO REG'],
        'INVALID'    => ['bg-danger',             'bi-slash-circle-fill',       'INVALID GW'],
        'UNKNOWN'    => ['bg-secondary',          'bi-question-circle-fill',    'UNKNOWN'],
        'FS_OFFLINE' => ['bg-danger',             'bi-wifi-off',                'FS OFFLINE'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['bg-secondary','bi-question',$status];
    return "<span class=\"badge {$cls} status-badge\"><i class=\"bi {$icon} me-1\"></i>{$label}</span>";
}

// ── 8. ACTIONS ───────────────────────────────────────────────
$message    = '';
$msgType    = 'success';
$editTrunk  = null;
$autoAdded  = [];
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// Auto-import trigger
if (($action === 'auto_import') || isset($_GET['auto_import'])) {
    $autoAdded = autoImportFsGateways();
    if (!empty($autoAdded)) {
        $message = 'Auto-imported <strong>' . count($autoAdded) . '</strong> gateway(s) from FreeSWITCH: <code>' . implode('</code>, <code>', array_map('htmlspecialchars', $autoAdded)) . '</code>';
        $msgType = 'success';
    } else {
        $message = 'No new gateways found in FreeSWITCH engine, or FreeSWITCH is offline.';
        $msgType = 'info';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ADD
    if ($action === 'add') {
        $name     = trim($_POST['name']     ?? '');
        $realm    = trim($_POST['realm']    ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($name === '' || $realm === '') {
            $message = 'Gateway Name and Realm/IP are required.'; $msgType = 'danger';
        } else {
            $trunks = readTrunks();
            $dup = false;
            foreach ($trunks as $t) { if (strtolower($t['name']) === strtolower($name)) { $dup = true; break; } }
            if ($dup) {
                $message = "Gateway <strong>{$name}</strong> already exists."; $msgType = 'warning';
            } else {
                $trunks[] = ['id'=>uniqid('gw_',true),'name'=>$name,'realm'=>$realm,'username'=>$username,'password'=>$password,'source'=>'manual','created'=>date('Y-m-d H:i:s')];
                writeTrunks($trunks);
                $message = "Gateway <strong>{$name}</strong> added successfully.";
            }
        }
    }

    // UPDATE
    if ($action === 'update') {
        $id = trim($_POST['id'] ?? ''); $name = trim($_POST['name'] ?? ''); $realm = trim($_POST['realm'] ?? '');
        $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? '');
        if ($name === '' || $realm === '') {
            $message = 'Gateway Name and Realm/IP are required.'; $msgType = 'danger';
        } else {
            $trunks = readTrunks(); $found = false;
            foreach ($trunks as &$t) {
                if ($t['id'] === $id) { $t['name']=$name; $t['realm']=$realm; $t['username']=$username; $t['password']=$password; $t['source']='manual'; $t['updated']=date('Y-m-d H:i:s'); $found=true; break; }
            } unset($t);
            writeTrunks($trunks);
            $message = $found ? "Gateway <strong>{$name}</strong> updated." : 'Gateway not found.';
            if (!$found) $msgType = 'danger';
        }
    }

    // DELETE
    if ($action === 'delete') {
        $id = trim($_POST['id'] ?? ''); $trunks = readTrunks(); $dname = '';
        $trunks = array_filter($trunks, function($t) use ($id, &$dname) { if ($t['id']===$id){$dname=$t['name'];return false;} return true; });
        writeTrunks(array_values($trunks));
        $message = "Gateway <strong>{$dname}</strong> deleted."; $msgType = 'warning';
    }
}

// Edit load
if ($action === 'edit' && isset($_GET['id'])) {
    foreach (readTrunks() as $t) { if ($t['id'] === $_GET['id']) { $editTrunk = $t; break; } }
}

// Load data for display
$trunks  = readTrunks();
$fsStats = getFsStats();
$statusMap = [];
foreach ($trunks as $t) { $statusMap[$t['id']] = getGatewayStatus($t['name']); }

$countUp = $countDown = $countUnknown = 0;
foreach ($statusMap as $s) {
    if ($s['status']==='UP') $countUp++;
    elseif (in_array($s['status'],['DOWN','FAILED','INVALID','EXPIRED'])) $countDown++;
    else $countUnknown++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>FreeSWITCH Trunk Manager</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>

<style>
/* ═══════════════════════════════════════════════════════════
   ROOT & BASE
═══════════════════════════════════════════════════════════ */
:root {
  --bg:           #080c18;
  --bg-card:      rgba(255,255,255,0.05);
  --bg-card-h:    rgba(255,255,255,0.085);
  --border:       rgba(255,255,255,0.09);
  --border-h:     rgba(99,179,237,0.40);
  --accent:       #63b3ed;
  --accent2:      #9f7aea;
  --green:        #48bb78;
  --red:          #fc8181;
  --yellow:       #f6e05e;
  --orange:       #f6ad55;
  --txt:          #e2e8f0;
  --txt2:         #94a3b8;
  --txt3:         #475569;
  --glow:         0 0 30px rgba(99,179,237,0.15);
  --r:            14px;
  --rs:           8px;
  --tr:           all .22s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  font-family:'Inter',sans-serif;
  background:var(--bg);
  color:var(--txt);
  min-height:100vh;
  overflow-x:hidden;
}

/* ── Background ──────────────────────────────────────────── */
body::before{
  content:'';position:fixed;inset:0;z-index:-2;
  background:
    radial-gradient(ellipse 90% 65% at 15%  8%,  rgba(99,179,237,.10) 0%,transparent 55%),
    radial-gradient(ellipse 70% 55% at 85% 90%,  rgba(159,122,234,.09) 0%,transparent 55%),
    radial-gradient(ellipse 55% 45% at 55% 48%,  rgba(72,187,120,.05)  0%,transparent 55%),
    linear-gradient(165deg,#080c18 0%,#0c1220 45%,#0d0e1d 100%);
}
body::after{
  content:'';position:fixed;inset:0;z-index:-1;
  background-image:
    linear-gradient(rgba(99,179,237,.025) 1px,transparent 1px),
    linear-gradient(90deg,rgba(99,179,237,.025) 1px,transparent 1px);
  background-size:44px 44px;
  mask-image:radial-gradient(ellipse 85% 85% at 50% 50%,#000 35%,transparent 100%);
}

/* ── Scrollbar ───────────────────────────────────────────── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.10);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.18)}

/* ═══════════════════════════════════════════════════════════
   TOP NAV
═══════════════════════════════════════════════════════════ */
.top-nav{
  position:sticky;top:0;z-index:1000;
  background:rgba(8,12,24,.85);
  backdrop-filter:blur(22px) saturate(180%);
  -webkit-backdrop-filter:blur(22px) saturate(180%);
  border-bottom:1px solid var(--border);
  padding:0 2rem;height:64px;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
.brand-icon{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;color:#fff;
  box-shadow:0 4px 16px rgba(99,179,237,.35);
  flex-shrink:0;
}
.brand-text{font-size:1.05rem;font-weight:750;color:var(--txt);letter-spacing:-.02em;line-height:1.15}
.brand-sub{font-size:.67rem;color:var(--txt3);font-weight:400}
.nav-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ─ pill badge ───── */
.pill{
  display:inline-flex;align-items:center;gap:6px;
  padding:5px 13px;border-radius:30px;font-size:.75rem;font-weight:600;
}
.pill-green{background:rgba(72,187,120,.13);border:1px solid rgba(72,187,120,.30);color:var(--green)}
.pill-red  {background:rgba(252,129,129,.13);border:1px solid rgba(252,129,129,.30);color:var(--red)}
.pill-blue {background:rgba(99,179,237,.12); border:1px solid rgba(99,179,237,.28); color:var(--accent)}

/* ═══════════════════════════════════════════════════════════
   PAGE WRAP
═══════════════════════════════════════════════════════════ */
.page{padding:2rem 2rem 5rem;max-width:1560px;margin:0 auto}

/* ── Section label ───────────────────────────────────────── */
.slabel{
  font-size:.68rem;font-weight:700;letter-spacing:.12em;
  text-transform:uppercase;color:var(--txt3);margin-bottom:.8rem;
  display:flex;align-items:center;gap:6px;
}

/* ═══════════════════════════════════════════════════════════
   GLASS CARD
═══════════════════════════════════════════════════════════ */
.gc{
  background:var(--bg-card);
  backdrop-filter:blur(18px) saturate(160%);
  -webkit-backdrop-filter:blur(18px) saturate(160%);
  border:1px solid var(--border);
  border-radius:var(--r);
  transition:var(--tr);
}
.gc:hover{border-color:var(--border-h);box-shadow:var(--glow)}

.gc-head{
  padding:1rem 1.3rem;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:10px;
}
.gc-title{
  font-size:.92rem;font-weight:700;color:var(--txt);
  display:flex;align-items:center;gap:8px;
}
.gc-title i{color:var(--accent)}

/* ═══════════════════════════════════════════════════════════
   STAT GRID
═══════════════════════════════════════════════════════════ */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem}
.stat-card{
  padding:1.15rem 1.3rem;position:relative;overflow:hidden;
}
.stat-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:var(--lc,linear-gradient(90deg,var(--accent),var(--accent2)));
  border-radius:var(--r) var(--r) 0 0;
}
.stat-icon{
  width:38px;height:38px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.05rem;margin-bottom:.75rem;
}
.stat-val{
  font-size:1.8rem;font-weight:800;line-height:1;
  letter-spacing:-.03em;margin-bottom:3px;
}
.stat-lbl{font-size:.68rem;color:var(--txt3);font-weight:600;text-transform:uppercase;letter-spacing:.09em}

/* ── Auto-import banner ──────────────────────────────────── */
.auto-banner{
  background:linear-gradient(135deg,rgba(99,179,237,.08),rgba(159,122,234,.08));
  border:1px solid rgba(99,179,237,.20);
  border-radius:var(--r);
  padding:1rem 1.4rem;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:12px;
}
.auto-banner-text{font-size:.85rem;color:var(--txt2)}
.auto-banner-text strong{color:var(--txt)}
.auto-banner-text code{color:var(--accent);font-family:'JetBrains Mono',monospace;font-size:.8rem}

/* ═══════════════════════════════════════════════════════════
   STATUS BADGES
═══════════════════════════════════════════════════════════ */
.status-badge{
  font-size:.69rem!important;font-weight:700!important;
  padding:4px 10px!important;letter-spacing:.05em;
  border-radius:30px!important;white-space:nowrap;
}

/* ═══════════════════════════════════════════════════════════
   TRUNK TABLE
═══════════════════════════════════════════════════════════ */
.tt{width:100%;border-collapse:separate;border-spacing:0}
.tt thead th{
  font-size:.67rem;font-weight:700;letter-spacing:.1em;
  text-transform:uppercase;color:var(--txt3);
  padding:.8rem 1rem;border-bottom:1px solid var(--border);
  white-space:nowrap;
}
.tt tbody tr{transition:var(--tr);border-bottom:1px solid rgba(255,255,255,.04)}
.tt tbody tr:hover{background:var(--bg-card-h)}
.tt tbody tr:last-child{border-bottom:none}
.tt td{padding:.85rem 1rem;font-size:.84rem;vertical-align:middle}

/* ── Row name cell ───────────────────────────────────────── */
.gw-name{display:flex;align-items:center;gap:9px;font-weight:650;color:var(--txt)}
.gw-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-up  {background:var(--green);box-shadow:0 0 7px var(--green);animation:blink 2.2s infinite}
.dot-dn  {background:var(--red)}
.dot-unk {background:var(--txt3)}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.45}}

/* ── Source tag ──────────────────────────────────────────── */
.src-auto  {font-size:.62rem;padding:2px 7px;border-radius:4px;background:rgba(99,179,237,.12);color:var(--accent);font-weight:600;font-family:'JetBrains Mono',monospace}
.src-manual{font-size:.62rem;padding:2px 7px;border-radius:4px;background:rgba(159,122,234,.12);color:var(--accent2);font-weight:600;font-family:'JetBrains Mono',monospace}

.mono{font-family:'JetBrains Mono',monospace;font-size:.77rem;color:var(--txt2)}

/* ═══════════════════════════════════════════════════════════
   BUTTONS
═══════════════════════════════════════════════════════════ */
.btn-g{
  padding:5px 12px;font-size:.75rem;font-weight:650;
  border-radius:6px;border:1px solid transparent;
  cursor:pointer;transition:var(--tr);
  display:inline-flex;align-items:center;gap:5px;
  text-decoration:none;
}
.btn-edit  {background:rgba(99,179,237,.11);border-color:rgba(99,179,237,.28);color:var(--accent)}
.btn-edit:hover{background:rgba(99,179,237,.22);border-color:var(--accent);color:var(--accent)}
.btn-del   {background:rgba(252,129,129,.09);border-color:rgba(252,129,129,.22);color:var(--red)}
.btn-del:hover{background:rgba(252,129,129,.20);border-color:var(--red);color:var(--red)}
.btn-info  {background:rgba(159,122,234,.10);border-color:rgba(159,122,234,.24);color:var(--accent2)}
.btn-info:hover{background:rgba(159,122,234,.20);border-color:var(--accent2);color:var(--accent2)}
.btn-auto  {background:rgba(246,173,85,.10);border-color:rgba(246,173,85,.28);color:var(--orange)}
.btn-auto:hover{background:rgba(246,173,85,.20);border-color:var(--orange);color:var(--orange);transform:translateY(-1px)}

.btn-primary-g{
  background:linear-gradient(135deg,rgba(99,179,237,.85),rgba(159,122,234,.85));
  border:1px solid rgba(99,179,237,.45);color:#fff;
  padding:8px 18px;border-radius:var(--rs);font-size:.83rem;font-weight:650;
  cursor:pointer;transition:var(--tr);
  display:inline-flex;align-items:center;gap:7px;
  box-shadow:0 4px 14px rgba(99,179,237,.18);text-decoration:none;
}
.btn-primary-g:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(99,179,237,.32);color:#fff}

/* ═══════════════════════════════════════════════════════════
   FORM CONTROLS
═══════════════════════════════════════════════════════════ */
.fc{
  background:rgba(255,255,255,.05)!important;
  border:1px solid var(--border)!important;
  border-radius:var(--rs)!important;
  color:var(--txt)!important;
  font-size:.84rem!important;padding:9px 13px!important;
  transition:var(--tr)!important;
}
.fc::placeholder{color:var(--txt3)!important}
.fc:focus{
  background:rgba(255,255,255,.08)!important;
  border-color:rgba(99,179,237,.5)!important;
  box-shadow:0 0 0 3px rgba(99,179,237,.11)!important;
  outline:none!important;color:var(--txt)!important;
}
.flabel{
  font-size:.72rem;font-weight:700;color:var(--txt2);
  text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;display:block;
}
.pw-wrap{position:relative}
.pw-eye{
  position:absolute;right:9px;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--txt3);cursor:pointer;
  font-size:.95rem;padding:0;transition:color .2s;
}
.pw-eye:hover{color:var(--accent)}

/* ═══════════════════════════════════════════════════════════
   ALERTS
═══════════════════════════════════════════════════════════ */
.ag{
  border-radius:var(--rs);border:1px solid;
  padding:11px 15px;font-size:.84rem;
  backdrop-filter:blur(10px);
  display:flex;align-items:flex-start;gap:10px;
}
.ag.success{background:rgba(72,187,120,.11); border-color:rgba(72,187,120,.28); color:#9ae6b4}
.ag.danger {background:rgba(252,129,129,.09);border-color:rgba(252,129,129,.28);color:#feb2b2}
.ag.warning{background:rgba(246,224,94,.09); border-color:rgba(246,224,94,.28); color:#fef08a}
.ag.info   {background:rgba(99,179,237,.09); border-color:rgba(99,179,237,.28); color:#bee3f8}

/* ═══════════════════════════════════════════════════════════
   OUTPUT BLOCK
═══════════════════════════════════════════════════════════ */
.outblock{
  font-family:'JetBrains Mono',monospace;font-size:.72rem;
  background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.07);
  border-radius:6px;padding:12px;
  white-space:pre-wrap;word-break:break-all;
  color:#94a3b8;max-height:220px;overflow-y:auto;line-height:1.65;
}

/* ═══════════════════════════════════════════════════════════
   MODAL
═══════════════════════════════════════════════════════════ */
.modal-glass .modal-content{
  background:rgba(11,15,30,.97);
  backdrop-filter:blur(28px);
  border:1px solid var(--border);
  border-radius:var(--r);color:var(--txt);
}
.modal-glass .modal-header{border-bottom:1px solid var(--border);padding:1rem 1.3rem}
.modal-glass .modal-footer{border-top:1px solid var(--border)}
.modal-glass .modal-title{font-weight:750;font-size:.97rem}
.modal-glass .btn-close{filter:invert(1) brightness(.65)}

/* ═══════════════════════════════════════════════════════════
   EMPTY STATE
═══════════════════════════════════════════════════════════ */
.empty{padding:4rem 2rem;text-align:center}
.empty .eicon{font-size:3rem;color:var(--txt3);margin-bottom:1rem}
.empty h5{color:var(--txt2);font-weight:700}
.empty p{color:var(--txt3);font-size:.84rem}

/* ═══════════════════════════════════════════════════════════
   MISC
═══════════════════════════════════════════════════════════ */
.spin{animation:spin .9s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

hr.div{border-color:var(--border);opacity:1}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:768px){
  .top-nav{padding:0 1rem}
  .page{padding:1rem 1rem 3rem}
  .stat-grid{grid-template-columns:repeat(2,1fr)}
  .tt td,.tt th{padding:.65rem .7rem}
  .nav-actions .pill{display:none}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     TOP NAV
═══════════════════════════════════════════════════════════ -->
<nav class="top-nav">
  <a class="nav-brand" href="trunks.php">
    <div class="brand-icon"><i class="bi bi-diagram-3-fill"></i></div>
    <div>
      <div class="brand-text">TrunkManager</div>
      <div class="brand-sub">FreeSWITCH Gateway Control Panel</div>
    </div>
  </a>

  <div class="nav-actions">
    <!-- FS Online/Offline pill -->
    <span class="pill <?= $fsStats['online'] ? 'pill-green' : 'pill-red' ?>">
      <i class="bi <?= $fsStats['online'] ? 'bi-circle-fill' : 'bi-circle' ?>"
         style="font-size:.5rem"></i>
      FreeSWITCH <?= $fsStats['online'] ? 'Online' : 'Offline' ?>
    </span>

    <!-- Gateway count -->
    <span class="pill pill-blue">
      <i class="bi bi-hdd-network-fill" style="font-size:.75rem"></i>
      <?= count($trunks) ?> Trunk<?= count($trunks)!==1?'s':'' ?>
    </span>

    <!-- Auto Import -->
    <a href="trunks.php?auto_import=1" class="btn-g btn-auto" id="autoBtn" title="Scan FreeSWITCH and auto-import all gateways">
      <i class="bi bi-lightning-charge-fill"></i> Auto-Import FS Gateways
    </a>

    <!-- Refresh -->
    <button class="btn-g btn-info" onclick="location.reload()" title="Refresh status">
      <i class="bi bi-arrow-clockwise" id="refreshIcon"></i> Refresh
    </button>

    <!-- Add trunk -->
    <button class="btn-primary-g" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg"></i> Add Trunk
    </button>
  </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     PAGE
═══════════════════════════════════════════════════════════ -->
<div class="page">

  <!-- Flash message -->
  <?php if ($message): ?>
  <div class="ag <?= $msgType ?> mb-4" id="flashMsg" role="alert">
    <i class="bi <?= match($msgType){
      'success'=>'bi-check-circle-fill',
      'danger' =>'bi-x-circle-fill',
      'warning'=>'bi-exclamation-triangle-fill',
      default  =>'bi-info-circle-fill'
    } ?> flex-shrink-0 mt-1"></i>
    <div class="flex-grow-1"><?= $message ?></div>
    <button style="background:none;border:none;color:inherit;cursor:pointer;font-size:1rem;flex-shrink:0"
            onclick="this.closest('#flashMsg').remove()">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════
       AUTO-IMPORT BANNER (when no trunks yet)
  ══════════════════════════════════════════════════════ -->
  <?php if (empty($trunks) && $fsStats['online']): ?>
  <div class="auto-banner mb-4">
    <div class="auto-banner-text">
      <i class="bi bi-lightning-charge-fill me-2" style="color:var(--orange)"></i>
      <strong>FreeSWITCH is online</strong> — No trunks in database yet.
      Click <strong>Auto-Import</strong> to pull all configured gateways directly from the engine.
    </div>
    <a href="trunks.php?auto_import=1" class="btn-g btn-auto">
      <i class="bi bi-lightning-charge-fill"></i> Auto-Import Now
    </a>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════
       ENGINE STATS
  ══════════════════════════════════════════════════════ -->
  <p class="slabel"><i class="bi bi-cpu-fill"></i> Engine Overview</p>
  <div class="stat-grid mb-4">

    <!-- Version -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#63b3ed,#9f7aea)">
      <div class="stat-icon" style="background:rgba(99,179,237,.11);color:var(--accent)">
        <i class="bi bi-info-circle-fill"></i>
      </div>
      <div class="stat-val" style="font-size:.82rem;letter-spacing:0;color:var(--accent)"
           title="<?= htmlspecialchars($fsStats['version']) ?>">
        <?= htmlspecialchars(strlen($fsStats['version'])>30 ? substr($fsStats['version'],0,30).'…' : $fsStats['version']) ?>
      </div>
      <div class="stat-lbl">FreeSWITCH Version</div>
    </div>

    <!-- Channels -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#48bb78,#38a169)">
      <div class="stat-icon" style="background:rgba(72,187,120,.11);color:var(--green)">
        <i class="bi bi-telephone-fill"></i>
      </div>
      <div class="stat-val" style="color:var(--green)"><?= htmlspecialchars($fsStats['channels']) ?></div>
      <div class="stat-lbl">Active Channels</div>
    </div>

    <!-- Calls -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#9f7aea,#805ad5)">
      <div class="stat-icon" style="background:rgba(159,122,234,.11);color:var(--accent2)">
        <i class="bi bi-headset"></i>
      </div>
      <div class="stat-val" style="color:var(--accent2)"><?= htmlspecialchars($fsStats['calls']) ?></div>
      <div class="stat-lbl">Active Calls</div>
    </div>

    <!-- Uptime -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#f6e05e,#d69e2e)">
      <div class="stat-icon" style="background:rgba(246,224,94,.09);color:var(--yellow)">
        <i class="bi bi-clock-fill"></i>
      </div>
      <div class="stat-val" style="font-size:1.25rem;color:var(--yellow)"><?= htmlspecialchars($fsStats['uptime']) ?></div>
      <div class="stat-lbl">Engine Uptime</div>
    </div>

    <!-- Total Trunks -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#f6ad55,#dd6b20)">
      <div class="stat-icon" style="background:rgba(246,173,85,.10);color:var(--orange)">
        <i class="bi bi-hdd-stack-fill"></i>
      </div>
      <div class="stat-val" style="color:var(--orange)"><?= count($trunks) ?></div>
      <div class="stat-lbl">Total Trunks</div>
    </div>

    <!-- UP / DOWN -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#48bb78,#fc8181)">
      <div class="stat-icon" style="background:rgba(255,255,255,.05);color:var(--txt2)">
        <i class="bi bi-bar-chart-fill"></i>
      </div>
      <div class="stat-val" style="font-size:1.25rem;display:flex;align-items:center;gap:8px">
        <span style="color:var(--green)"><?= $countUp ?></span>
        <span style="color:var(--txt3);font-size:.9rem">/</span>
        <span style="color:var(--red)"><?= $countDown ?></span>
        <?php if($countUnknown): ?>
        <span style="color:var(--txt3);font-size:.9rem">/</span>
        <span style="color:var(--txt3)"><?= $countUnknown ?></span>
        <?php endif; ?>
      </div>
      <div class="stat-lbl">Up / Down<?= $countUnknown?' / Unknown':'' ?></div>
    </div>

    <!-- Sofia Profiles -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#63b3ed,#48bb78)">
      <div class="stat-icon" style="background:rgba(99,179,237,.10);color:var(--accent)">
        <i class="bi bi-layers-fill"></i>
      </div>
      <div class="stat-val" style="color:var(--accent)"><?= htmlspecialchars($fsStats['profiles']) ?></div>
      <div class="stat-lbl">Sofia Profiles</div>
    </div>

    <!-- Auto / Manual -->
    <div class="gc stat-card" style="--lc:linear-gradient(90deg,#9f7aea,#f6ad55)">
      <div class="stat-icon" style="background:rgba(159,122,234,.10);color:var(--accent2)">
        <i class="bi bi-magic"></i>
      </div>
      <div class="stat-val" style="font-size:1.25rem;display:flex;align-items:center;gap:8px">
        <?php
          $autoCount   = count(array_filter($trunks, fn($t) => ($t['source']??'') === 'auto'));
          $manualCount = count($trunks) - $autoCount;
        ?>
        <span style="color:var(--accent)"><?= $autoCount ?></span>
        <span style="color:var(--txt3);font-size:.9rem">/</span>
        <span style="color:var(--accent2)"><?= $manualCount ?></span>
      </div>
      <div class="stat-lbl">Auto / Manual</div>
    </div>

  </div><!-- /stat-grid -->

  <!-- ══════════════════════════════════════════════════════
       GATEWAY TABLE
  ══════════════════════════════════════════════════════ -->
  <p class="slabel"><i class="bi bi-hdd-stack"></i> Configured Gateways</p>
  <div class="gc">
    <div class="gc-head">
      <h6 class="gc-title">
        <i class="bi bi-diagram-3"></i>
        Gateway Registry
        <span style="font-size:.7rem;color:var(--txt3);font-weight:400">
          <?= count($trunks) ?> trunk<?= count($trunks)!==1?'s':'' ?>
        </span>
      </h6>
      <span style="font-size:.7rem;color:var(--txt3)">
        <i class="bi bi-arrow-clockwise me-1"></i>Last refresh: <?= date('H:i:s') ?>
      </span>
    </div>

    <?php if (empty($trunks)): ?>
    <div class="empty">
      <div class="eicon"><i class="bi bi-hdd-network"></i></div>
      <h5>No Gateways Configured</h5>
      <p class="mt-2">
        <?php if ($fsStats['online']): ?>
          FreeSWITCH is online. Use <strong>Auto-Import</strong> to pull all gateways instantly,
          or add one manually.
        <?php else: ?>
          FreeSWITCH appears to be offline. Add gateways manually to track them.
        <?php endif; ?>
      </p>
      <div class="d-flex justify-content-center gap-3 mt-3 flex-wrap">
        <?php if ($fsStats['online']): ?>
        <a href="trunks.php?auto_import=1" class="btn-g btn-auto" style="padding:8px 18px;font-size:.84rem">
          <i class="bi bi-lightning-charge-fill"></i> Auto-Import from Engine
        </a>
        <?php endif; ?>
        <button class="btn-primary-g" data-bs-toggle="modal" data-bs-target="#addModal">
          <i class="bi bi-plus-lg"></i> Add Manually
        </button>
      </div>
    </div>

    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tt">
        <thead>
          <tr>
            <th>#</th>
            <th>Gateway Name</th>
            <th>Realm / Host</th>
            <th>Username</th>
            <th>Source</th>
            <th>Status</th>
            <th>Ping</th>
            <th>Calls In</th>
            <th>State</th>
            <th>Added</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trunks as $i => $trunk):
            $st    = $statusMap[$trunk['id']] ?? ['status'=>'UNKNOWN','ping'=>'—','calls'=>'—','state'=>'—','realm'=>'','user'=>'','output'=>''];
            $isUp  = $st['status'] === 'UP';
            $isDn  = in_array($st['status'], ['DOWN','FAILED','INVALID','EXPIRED','FS_OFFLINE']);
            $dot   = $isUp ? 'dot-up' : ($isDn ? 'dot-dn' : 'dot-unk');
            $src   = ($trunk['source'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
          ?>
          <tr>
            <!-- # -->
            <td style="color:var(--txt3);font-size:.75rem"><?= $i+1 ?></td>

            <!-- Name -->
            <td>
              <div class="gw-name">
                <span class="gw-dot <?= $dot ?>"></span>
                <?= htmlspecialchars($trunk['name']) ?>
              </div>
            </td>

            <!-- Realm -->
            <td><span class="mono"><?= htmlspecialchars($trunk['realm'] ?: '—') ?></span></td>

            <!-- Username -->
            <td><span class="mono"><?= htmlspecialchars($trunk['username'] ?: '—') ?></span></td>

            <!-- Source -->
            <td>
              <span class="src-<?= $src ?>">
                <?= $src === 'auto' ? '⚡ auto' : '✎ manual' ?>
              </span>
            </td>

            <!-- Status -->
            <td><?= statusBadge($st['status']) ?></td>

            <!-- Ping -->
            <td><span class="mono"><?= htmlspecialchars($st['ping']) ?></span></td>

            <!-- Calls -->
            <td><span class="mono"><?= htmlspecialchars($st['calls']) ?></span></td>

            <!-- State -->
            <td><span class="mono"><?= htmlspecialchars($st['state']) ?></span></td>

            <!-- Added -->
            <td style="color:var(--txt3);font-size:.73rem;white-space:nowrap">
              <?= htmlspecialchars($trunk['updated'] ?? $trunk['created'] ?? '—') ?>
            </td>

            <!-- Actions -->
            <td style="text-align:right;white-space:nowrap">
              <div class="d-flex gap-2 justify-content-end">
                <button class="btn-g btn-info"
                  onclick='openDetails(<?= htmlspecialchars(json_encode([
                    "name"   => $trunk['name'],
                    "realm"  => $trunk['realm'],
                    "user"   => $trunk['username'],
                    "src"    => $src,
                    "status" => $st['status'],
                    "ping"   => $st['ping'],
                    "state"  => $st['state'],
                    "calls"  => $st['calls'],
                    "output" => $st['output'],
                    "added"  => $trunk['created'] ?? '',
                  ]), ENT_QUOTES) ?>)'>
                  <i class="bi bi-terminal"></i> Details
                </button>
                <a class="btn-g btn-edit"
                   href="trunks.php?action=edit&id=<?= urlencode($trunk['id']) ?>">
                  <i class="bi bi-pencil-square"></i> Edit
                </a>
                <button class="btn-g btn-del"
                  onclick='openDelete(<?= htmlspecialchars(json_encode(["id"=>$trunk['id'],"name"=>$trunk['name']]), ENT_QUOTES) ?>)'>
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
  </div><!-- /gc table -->

  <!-- ── Footer ──────────────────────────────────────────── -->
  <div class="mt-4 d-flex align-items-center justify-content-between flex-wrap gap-2"
       style="font-size:.7rem;color:var(--txt3)">
    <span>
      <i class="bi bi-shield-check me-1" style="color:var(--accent)"></i>
      TrunkManager v2 &mdash; FreeSWITCH Gateway Control Panel
    </span>
    <span><i class="bi bi-clock me-1"></i><?= date('Y-m-d H:i:s T') ?></span>
  </div>

</div><!-- /page -->


<!-- ═══════════════════════════════════════════════════════════
     MODAL — ADD TRUNK
═══════════════════════════════════════════════════════════ -->
<div class="modal fade modal-glass" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-plus-circle-fill me-2" style="color:var(--accent)"></i>Add New Gateway
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="trunks.php" autocomplete="off">
        <input type="hidden" name="action" value="add"/>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="flabel">Gateway Name <span style="color:var(--red)">*</span></label>
              <input type="text" name="name" class="form-control fc"
                     placeholder="e.g. provider-primary"
                     pattern="[a-zA-Z0-9_\-]+"
                     title="Letters, numbers, hyphens and underscores only"
                     required/>
              <div style="font-size:.68rem;color:var(--txt3);margin-top:4px">
                <i class="bi bi-info-circle"></i> Must match the gateway name in FreeSWITCH config
              </div>
            </div>
            <div class="col-12">
              <label class="flabel">Realm / SIP Host <span style="color:var(--red)">*</span></label>
              <input type="text" name="realm" class="form-control fc"
                     placeholder="e.g. sip.provider.com or 203.0.113.1" required/>
            </div>
            <div class="col-md-6">
              <label class="flabel">Username</label>
              <input type="text" name="username" class="form-control fc"
                     placeholder="SIP username" autocomplete="off"/>
            </div>
            <div class="col-md-6">
              <label class="flabel">Password</label>
              <div class="pw-wrap">
                <input type="password" name="password" id="addPw" class="form-control fc"
                       placeholder="SIP password" autocomplete="new-password"/>
                <button type="button" class="pw-eye" onclick="togPw('addPw',this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
          </div>
          <div class="mt-3 p-3 rounded"
               style="background:rgba(99,179,237,.06);border:1px solid rgba(99,179,237,.14);font-size:.75rem;color:var(--txt2)">
            <i class="bi bi-shield-lock me-1" style="color:var(--accent)"></i>
            Credentials are stored in <code style="color:var(--accent)">trunks.json</code> locally on the server and are used only for <code style="color:var(--accent)">fs_cli</code> status lookups.
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn-g btn-del" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Cancel
          </button>
          <button type="submit" class="btn-primary-g">
            <i class="bi bi-plus-lg"></i> Add Gateway
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL — EDIT TRUNK
═══════════════════════════════════════════════════════════ -->
<div class="modal fade modal-glass" id="editModal" tabindex="-1" aria-hidden="true"
  <?= $editTrunk ? 'data-autoopen="1"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-pencil-square me-2" style="color:var(--accent)"></i>Edit Gateway
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="trunks.php" autocomplete="off">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="id" value="<?= htmlspecialchars($editTrunk['id'] ?? '') ?>"/>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="flabel">Gateway Name <span style="color:var(--red)">*</span></label>
              <input type="text" name="name" class="form-control fc"
                     value="<?= htmlspecialchars($editTrunk['name'] ?? '') ?>"
                     pattern="[a-zA-Z0-9_\-]+" required/>
            </div>
            <div class="col-12">
              <label class="flabel">Realm / SIP Host <span style="color:var(--red)">*</span></label>
              <input type="text" name="realm" class="form-control fc"
                     value="<?= htmlspecialchars($editTrunk['realm'] ?? '') ?>" required/>
            </div>
            <div class="col-md-6">
              <label class="flabel">Username</label>
              <input type="text" name="username" class="form-control fc"
                     value="<?= htmlspecialchars($editTrunk['username'] ?? '') ?>"
                     autocomplete="off"/>
            </div>
            <div class="col-md-6">
              <label class="flabel">Password</label>
              <div class="pw-wrap">
                <input type="password" name="password" id="editPw" class="form-control fc"
                       value="<?= htmlspecialchars($editTrunk['password'] ?? '') ?>"
                       autocomplete="new-password"/>
                <button type="button" class="pw-eye" onclick="togPw('editPw',this)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer gap-2">
          <a href="trunks.php" class="btn-g btn-del text-decoration-none">
            <i class="bi bi-x-lg"></i> Cancel
          </a>
          <button type="submit" class="btn-primary-g">
            <i class="bi bi-check-lg"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL — DELETE CONFIRM
═══════════════════════════════════════════════════════════ -->
<div class="modal fade modal-glass" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-exclamation-triangle-fill me-2" style="color:var(--yellow)"></i>Confirm Delete
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <p style="color:var(--txt2);font-size:.84rem">Permanently delete gateway:</p>
        <div id="delGwName" class="my-2"
             style="font-size:1.05rem;font-weight:750;color:var(--red)"></div>
        <p style="color:var(--txt3);font-size:.76rem">This action cannot be undone.</p>
      </div>
      <div class="modal-footer justify-content-center gap-3">
        <button class="btn-g btn-edit" data-bs-dismiss="modal">
          <i class="bi bi-arrow-left"></i> Cancel
        </button>
        <form method="POST" action="trunks.php">
          <input type="hidden" name="action" value="delete"/>
          <input type="hidden" name="id" id="delId" value=""/>
          <button type="submit" class="btn-g btn-del" style="padding:7px 16px">
            <i class="bi bi-trash3-fill"></i> Delete Gateway
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL — GATEWAY DETAILS
═══════════════════════════════════════════════════════════ -->
<div class="modal fade modal-glass" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-terminal-fill me-2" style="color:var(--accent2)"></i>
          Details &mdash; <span id="dtlName" style="color:var(--accent)"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div class="row g-3 mb-4" id="dtlMeta"></div>
        <label class="flabel mb-2">
          <i class="bi bi-terminal me-1"></i> Raw <code style="color:var(--accent)">fs_cli</code> Output
        </label>
        <div class="outblock" id="dtlOutput"></div>
      </div>
      <div class="modal-footer">
        <button class="btn-g btn-edit" data-bs-dismiss="modal">
          <i class="bi bi-x-lg"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Auto-open edit modal ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  const em = document.getElementById('editModal');
  if (em && em.dataset.autoopen === '1') new bootstrap.Modal(em).show();

  // Auto-dismiss flash
  const fl = document.getElementById('flashMsg');
  if (fl) {
    setTimeout(() => { fl.style.transition='opacity .6s'; fl.style.opacity='0'; }, 5000);
    setTimeout(() => fl.remove(), 5700);
  }
});

// ── Password toggle ──────────────────────────────────────────
function togPw(id, btn) {
  const inp = document.getElementById(id);
  const ico = btn.querySelector('i');
  if (inp.type === 'password') { inp.type = 'text';     ico.className = 'bi bi-eye-slash'; }
  else                         { inp.type = 'password'; ico.className = 'bi bi-eye'; }
}

// ── Delete modal ─────────────────────────────────────────────
function openDelete(d) {
  document.getElementById('delId').value       = d.id;
  document.getElementById('delGwName').textContent = d.name;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// ── Details modal ────────────────────────────────────────────
function openDetails(d) {
  document.getElementById('dtlName').textContent = d.name;

  const colors = {
    UP:'#48bb78', DOWN:'#fc8181', FAILED:'#fc8181',
    EXPIRED:'#f6e05e', TRYING:'#f6e05e', NOREG:'#94a3b8',
    INVALID:'#fc8181', UNKNOWN:'#94a3b8', FS_OFFLINE:'#fc8181'
  };
  const c = colors[d.status] || '#94a3b8';

  const items = [
    { l:'Status',       v: d.status, c: c },
    { l:'Realm / Host', v: d.realm  || '—' },
    { l:'Username',     v: d.user   || '—' },
    { l:'Ping Time',    v: d.ping   || '—' },
    { l:'Calls In',     v: d.calls  || '—' },
    { l:'FS State',     v: d.state  || '—' },
    { l:'Source',       v: d.src    || '—' },
    { l:'Added',        v: d.added  || '—' },
  ];

  document.getElementById('dtlMeta').innerHTML = items.map(function(it){
    return `<div class="col-md-3 col-6">
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
                  border-radius:8px;padding:11px 13px">
        <div style="font-size:.65rem;color:#475569;text-transform:uppercase;
                    letter-spacing:.09em;font-weight:700;margin-bottom:4px">${esc(it.l)}</div>
        <div style="font-size:.87rem;font-weight:700;color:${it.c||'#e2e8f0'}">${esc(String(it.v))}</div>
      </div></div>`;
  }).join('');

  const out = (d.output && d.output.trim()) ? d.output : '(no output — FreeSWITCH may be offline or gateway not loaded)';
  document.getElementById('dtlOutput').textContent = out;

  new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

// ── HTML escape ───────────────────────────────────────────────
function esc(str) {
  const d = document.createElement('div');
  d.appendChild(document.createTextNode(str));
  return d.innerHTML;
}

// ── Refresh spin ─────────────────────────────────────────────
document.querySelector('[onclick="location.reload()"]')?.addEventListener('click', function () {
  const ic = document.getElementById('refreshIcon');
  if (ic) ic.classList.add('spin');
});

// ── Auto-import button loading state ─────────────────────────
document.getElementById('autoBtn')?.addEventListener('click', function () {
  this.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Importing…';
  this.style.pointerEvents = 'none';
});
</script>

</body>
</html>
