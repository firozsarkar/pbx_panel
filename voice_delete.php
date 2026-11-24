<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

$baseDirRaw = '/usr/local/freeswitch/recordings';
$baseDir = realpath($baseDirRaw);
if ($baseDir === false) {
    $baseDir = rtrim($baseDirRaw, '/');
}

if (!is_dir($baseDir)) {
    http_response_code(500);
    exit('Recordings directory not found.');
}

function ensureRecordingsPermissions(string $baseDir): void {
    if (function_exists('shell_exec')) {
        $escaped = escapeshellarg($baseDir);
        @shell_exec("chmod -R 775 {$escaped} 2>/dev/null; find {$escaped} -type d -exec chmod 2775 {} + 2>/dev/null; find {$escaped} -type f -exec chmod 664 {} + 2>/dev/null");
        return;
    }

    @chmod($baseDir, 0775);
}

ensureRecordingsPermissions($baseDir);

function resolveRecordingFile(string $relativePath, string $baseDir): ?string {
    $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($clean === '' || strpos($clean, "\0") !== false) {
        return null;
    }

    foreach (explode('/', $clean) as $seg) {
        if ($seg === '..') {
            return null;
        }
    }

    $candidate = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    $realCandidate = realpath($candidate);
    $fullPath = ($realCandidate !== false) ? $realCandidate : $candidate;

    $basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($fullPath, $basePrefix) !== 0) {
        return null;
    }

    if (!file_exists($fullPath) || is_dir($fullPath)) {
        return null;
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
        return null;
    }

    return $fullPath;
}

function deleteSingleFile(string $fullPath): bool {
    @chmod($fullPath, 0666);
    if (@unlink($fullPath)) {
        return true;
    }

    if (function_exists('shell_exec')) {
        @shell_exec('rm -f ' . escapeshellarg($fullPath) . ' 2>/dev/null');
        return !file_exists($fullPath);
    }

    return false;
}

function wantsJson(): bool {
    if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

function currentScriptName(): string {
    $self = basename((string)($_SERVER['PHP_SELF'] ?? 'voice_delete.php'));
    return $self !== '' ? $self : 'voice_delete.php';
}

$flashMessage = '';
$flashType = 'ok';

/**
 * সিঙ্গেল ফাইল ডিলিট (POST action=delete অথবা GET ?delete=...)
 */
$deleteTarget = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['file']) && is_string($_POST['file'])) {
    $deleteTarget = $_POST['file'];
} elseif (isset($_GET['delete']) && is_string($_GET['delete']) && $_GET['delete'] !== '') {
    $deleteTarget = $_GET['delete'];
}

if ($deleteTarget !== '') {
    $resolved = resolveRecordingFile($deleteTarget, $baseDir);
    if ($resolved === null) {
        $flashMessage = 'রেকর্ডিং ফাইলটি খুঁজে পাওয়া যায়নি বা পাথ সঠিক নয়।';
        $flashType = 'err';
        if (wantsJson()) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["status" => "error", "message" => $flashMessage], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $ok = deleteSingleFile($resolved);
        if ($ok) {
            $flashMessage = 'ফাইলটি ডিলিট হয়েছে: ' . basename($resolved);
            $flashType = 'ok';
        } else {
            $flashMessage = 'ফাইলটি ডিলিট করা যায়নি। পারমিশন চেক করুন।';
            $flashType = 'err';
        }

        if (wantsJson()) {
            http_response_code($ok ? 200 : 500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "status" => $ok ? "success" : "error",
                "message" => $flashMessage,
                "file" => basename($resolved)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/**
 * ১৫ দিনের পুরনো সব ফাইল ডিলিট (POST action=delete_old_15d)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_old_15d') {
    $cutoff = time() - (15 * 86400);
    $deletedCount = 0;
    $failedCount = 0;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            if ($info->isDir()) {
                continue;
            }
            $ext = strtolower($info->getExtension());
            if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
                continue;
            }
            $mtime = (int)@$info->getMTime();
            if ($mtime > 0 && $mtime < $cutoff) {
                $p = $info->getRealPath() ?: $info->getPathname();
                if (deleteSingleFile($p)) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $flashMessage = "১৫ দিনের পুরনো {$deletedCount}টি ফাইল ডিলিট হয়েছে" . ($failedCount > 0 ? " ({$failedCount}টি ব্যর্থ)" : "") . "।";
    $flashType = $failedCount > 0 ? 'err' : 'ok';
}

/**
 * সব রেকর্ডিং ফাইল স্ক্যান করা
 */
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : '';
$searchQuery  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$dates = [];
$recordings = [];

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isDir()) {
            continue;
        }

        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
            continue;
        }

        $realPath = $fileInfo->getRealPath();
        $fullPath = ($realPath !== false) ? $realPath : $fileInfo->getPathname();

        $relPath = ltrim(str_replace($baseDir, '', $fullPath), '/\\');
        $relPathUnix = str_replace('\\', '/', $relPath);

        $parts = explode('/', $relPathUnix);
        $mtime = (int)@$fileInfo->getMTime();
        $folderDate = (count($parts) > 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0]))
            ? $parts[0]
            : date('Y-m-d', $mtime > 0 ? $mtime : time());

        $dates[$folderDate] = true;

        if ($selectedDate !== '' && $folderDate !== $selectedDate) {
            continue;
        }

        $fileName = $fileInfo->getFilename();
        if ($searchQuery !== '' && stripos($fileName, $searchQuery) === false && stripos($relPathUnix, $searchQuery) === false) {
            continue;
        }

        $size = (int)@$fileInfo->getSize();

        $recordings[] = [
            'rel_path' => $relPathUnix,
            'name'     => $fileName,
            'date'     => $folderDate,
            'size'     => $size,
            'mtime'    => $mtime,
        ];
    }
} catch (Throwable $e) {
    // ignore
}

krsort($dates);
usort($recordings, static function (array $a, array $b): int {
    return $b['mtime'] <=> $a['mtime'];
});

function formatBytes(int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delete Call Recordings</title>
  <style>
    :root {
      --bg: #0b0f17;
      --card: #121826;
      --border: rgba(255, 255, 255, 0.08);
      --text: #e6edf7;
      --muted: #94a3b8;
      --gold: #eab308;
      --warn: #f87171;
      --danger-bg: rgba(248, 113, 113, 0.14);
      --danger-border: rgba(248, 113, 113, 0.35);
      --ok-bg: rgba(34, 197, 94, 0.14);
      --ok-border: rgba(34, 197, 94, 0.35);
      --ok-text: #4ade80;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .container {
      max-width: 1120px;
      margin: 0 auto;
    }
    .header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-bottom: 20px;
    }
    .title h1 {
      margin: 0 0 4px;
      font-size: 22px;
      color: var(--warn);
    }
    .title p {
      margin: 0;
      font-size: 13px;
      color: var(--muted);
    }
    .alert {
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
      border: 1px solid transparent;
    }
    .alert-ok {
      background: var(--ok-bg);
      border-color: var(--ok-border);
      color: var(--ok-text);
    }
    .alert-err {
      background: var(--danger-bg);
      border-color: var(--danger-border);
      color: var(--warn);
    }
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      background: var(--card);
      padding: 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      margin-bottom: 18px;
      align-items: center;
    }
    select, input[type="text"], button.filter-btn, a.btn {
      padding: 9px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: #0d1320;
      color: var(--text);
      font-size: 14px;
      text-decoration: none;
    }
    button.filter-btn {
      background: var(--gold);
      color: #0b0f17;
      font-weight: 600;
      cursor: pointer;
      border: none;
    }
    a.reset-btn {
      background: transparent;
      color: var(--muted);
    }
    .bulk-btn {
      padding: 9px 12px;
      border-radius: 8px;
      background: var(--danger-bg);
      color: var(--warn);
      border: 1px solid var(--danger-border);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      margin-left: auto;
    }
    .table-wrap {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 700px;
    }
    th, td {
      padding: 12px 14px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      font-size: 14px;
      vertical-align: middle;
    }
    th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    tr:last-child td {
      border-bottom: none;
    }
    .file-name {
      font-weight: 600;
      color: var(--text);
      word-break: break-all;
    }
    .meta {
      font-size: 12px;
      color: var(--muted);
      margin-top: 3px;
    }
    .del-btn {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 7px;
      background: var(--danger-bg);
      color: var(--warn);
      border: 1px solid var(--danger-border);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .del-btn:hover {
      background: rgba(248, 113, 113, 0.24);
    }
    .empty {
      padding: 36px;
      text-align: center;
      color: var(--muted);
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="title">
        <h1>Delete Call Recordings</h1>
        <p>Directory: <code><?= h($baseDir) ?></code> • Total: <strong><?= count($recordings) ?></strong></p>
      </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
      <div class="alert <?= $flashType === 'ok' ? 'alert-ok' : 'alert-err' ?>">
        <?= h($flashMessage) ?>
      </div>
    <?php endif; ?>

    <div class="filter-bar">
      <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <select name="date">
          <option value="">সব তারিখ</option>
          <?php foreach (array_keys($dates) as $d): ?>
            <option value="<?= h($d) ?>" <?= $selectedDate === $d ? 'selected' : '' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>

        <input type="text" name="q" value="<?= h($searchQuery) ?>" placeholder="নম্বর বা ফাইলের নাম খুঁজুন...">

        <button type="submit" class="filter-btn">ফিল্টার</button>
        <?php if ($selectedDate !== '' || $searchQuery !== ''): ?>
          <a href="<?= h(currentScriptName()) ?>" class="btn reset-btn">রিসেট</a>
        <?php endif; ?>
      </form>

      <form method="post" action="<?= h(currentScriptName()) ?>" onsubmit="return confirm('১৫ দিনের পুরনো সব রেকর্ডিং ফাইল স্থায়ীভাবে ডিলিট করতে চান?');" style="margin-left:auto;">
        <input type="hidden" name="action" value="delete_old_15d">
        <button type="submit" class="bulk-btn">১৫ দিনের পুরনো ফাইল ডিলিট করুন</button>
      </form>
    </div>

    <div class="table-wrap">
      <?php if (empty($recordings)): ?>
        <div class="empty">কোনো কল রেকর্ডিং ফাইল পাওয়া যায়নি।</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ফাইল / নম্বর</th>
              <th>তারিখ ও সময়</th>
              <th>সাইজ</th>
              <th>ডিলিট</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recordings as $rec): ?>
              <tr>
                <td>
                  <div class="file-name"><?= h($rec['name']) ?></div>
                  <div class="meta"><?= h($rec['rel_path']) ?></div>
                </td>
                <td><?= h(date('Y-m-d H:i:s', $rec['mtime'] > 0 ? $rec['mtime'] : time())) ?></td>
                <td><?= h(formatBytes($rec['size'])) ?></td>
                <td>
                  <form method="post" action="<?= h(currentScriptName()) ?>" onsubmit="return confirm('এই রেকর্ডিং ফাইলটি স্থায়ীভাবে ডিলিট করতে চান?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="file" value="<?= h($rec['rel_path']) ?>">
                    <button type="submit" class="del-btn">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
