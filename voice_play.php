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
    @mkdir($baseDir, 0777, true);
    $realAfter = realpath($baseDirRaw);
    if ($realAfter !== false) {
        $baseDir = $realAfter;
    }
}

if (!is_dir($baseDir)) {
    http_response_code(500);
    exit('Recordings directory not found.');
}

/**
 * voice_play.php ভিজিট করলেই ডিরেক্টরি ও রেকর্ডিং ফাইলের পারমিশন অটো ঠিক করার চেষ্টা
 */
function ensureRecordingsPermissions(string $baseDir): void {
    if (function_exists('shell_exec')) {
        $escaped = escapeshellarg($baseDir);
        @shell_exec("chmod -R 775 {$escaped} 2>/dev/null; find {$escaped} -type d -exec chmod 2775 {} + 2>/dev/null; find {$escaped} -type f -exec chmod 664 {} + 2>/dev/null");
        return;
    }

    @chmod($baseDir, 0775);
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            $p = $info->getPathname();
            if ($info->isDir()) {
                @chmod($p, 0775);
            } else {
                @chmod($p, 0664);
            }
        }
    } catch (Throwable $e) {
        // ignore permission errors
    }
}

ensureRecordingsPermissions($baseDir);

/**
 * নিরাপদভাবে ফাইল পাথ যাচাই করে (Path Traversal ঠেকাতে)
 */
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

/**
 * আউটপুট বাফার পরিষ্কার করা
 */
function clearOutputBuffers(): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * ফাইল বাইট আকারে আউটপুটে পাঠানো (Range সমর্থন ও shell fallback সহ)
 */
function outputAudioFile(string $filePath, string $mime, string $disposition): void {
    @chmod($filePath, 0664);
    clearOutputBuffers();

    $fileName = basename($filePath);
    $fileSize = @filesize($filePath);
    if (!is_int($fileSize) || $fileSize < 0) {
        $stat = @stat($filePath);
        $fileSize = (is_array($stat) && isset($stat['size'])) ? (int)$stat['size'] : 0;
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (is_readable($filePath)) {
        if ($fileSize > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int)$matches[1];
            $end = ($matches[2] !== '') ? (int)$matches[2] : ($fileSize - 1);

            if ($start > $end || $end >= $fileSize) {
                http_response_code(416);
                header("Content-Range: bytes */{$fileSize}");
                exit;
            }

            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            header('Content-Length: ' . $length);

            $fp = @fopen($filePath, 'rb');
            if ($fp !== false) {
                fseek($fp, $start);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = fread($fp, min(8192, $remaining));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                fclose($fp);
                exit;
            }
        }

        if ($fileSize > 0) {
            header('Content-Length: ' . $fileSize);
        }
        @readfile($filePath);
        exit;
    }

    if (function_exists('shell_exec')) {
        $escaped = escapeshellarg($filePath);
        $raw = @shell_exec("cat {$escaped} 2>/dev/null");
        if (is_string($raw) && strlen($raw) > 0) {
            header('Content-Length: ' . strlen($raw));
            echo $raw;
            exit;
        }
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unable to read recording file.');
}

/**
 * ব্রাউজারে অডিও স্ট্রিম/প্লে করার হ্যান্ডলার (?stream=2026-11-19/file.wav)
 */
if (isset($_GET['stream']) && is_string($_GET['stream'])) {
    $filePath = resolveRecordingFile($_GET['stream'], $baseDir);
    if ($filePath === null) {
        http_response_code(404);
        exit('Recording not found.');
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
    ];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

    outputAudioFile($filePath, $mime, 'inline');
}

/**
 * ডাউনলোড হ্যান্ডলার (?download=2026-11-19/file.wav)
 */
if (isset($_GET['download']) && is_string($_GET['download'])) {
    $filePath = resolveRecordingFile($_GET['download'], $baseDir);
    if ($filePath === null) {
        http_response_code(404);
        exit('Recording not found.');
    }

    outputAudioFile($filePath, 'application/octet-stream', 'attachment');
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
    // ignore iteration errors
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
  <title>Call Recordings Player</title>
  <style>
    :root {
      --bg: #0b0f17;
      --card: #121826;
      --border: rgba(255, 255, 255, 0.08);
      --text: #e6edf7;
      --muted: #94a3b8;
      --gold: #eab308;
      --gold-soft: rgba(234, 179, 8, 0.14);
      --warn: #f87171;
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
      color: var(--gold);
    }
    .title p {
      margin: 0;
      font-size: 13px;
      color: var(--muted);
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
    }
    select, input[type="text"], button, a.btn {
      padding: 9px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: #0d1320;
      color: var(--text);
      font-size: 14px;
      text-decoration: none;
    }
    button {
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
    .table-wrap {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 720px;
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
    .zero-note {
      font-size: 12px;
      color: var(--warn);
      margin-top: 4px;
    }
    audio {
      height: 34px;
      width: 260px;
      max-width: 100%;
    }
    .dl-btn {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 7px;
      background: var(--gold-soft);
      color: var(--gold);
      border: 1px solid rgba(234, 179, 8, 0.35);
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
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
        <h1>Call Recordings</h1>
        <p>Directory: <code><?= h($baseDir) ?></code> • Total: <strong><?= count($recordings) ?></strong></p>
      </div>
    </div>

    <form method="get" class="filter-bar">
      <select name="date">
        <option value="">সব তারিখ</option>
        <?php foreach (array_keys($dates) as $d): ?>
          <option value="<?= h($d) ?>" <?= $selectedDate === $d ? 'selected' : '' ?>><?= h($d) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="q" value="<?= h($searchQuery) ?>" placeholder="নম্বর বা ফাইলের নাম খুঁজুন...">

      <button type="submit">ফিল্টার</button>
      <?php if ($selectedDate !== '' || $searchQuery !== ''): ?>
        <a href="<?= h(basename((string)($_SERVER['PHP_SELF'] ?? 'voice_play.php'))) ?>" class="btn reset-btn">রিসেট</a>
      <?php endif; ?>
    </form>

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
              <th>প্লেয়ার</th>
              <th>ডাউনলোড</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recordings as $rec): ?>
              <tr>
                <td>
                  <div class="file-name"><?= h($rec['name']) ?></div>
                  <div class="meta"><?= h($rec['rel_path']) ?></div>
                  <?php if ($rec['size'] <= 0): ?>
                    <div class="zero-note">ফাইল সাইজ 0 B (রেকর্ডিং অডিও ডাটা লেখা হয়নি)</div>
                  <?php endif; ?>
                </td>
                <td><?= h(date('Y-m-d H:i:s', $rec['mtime'] > 0 ? $rec['mtime'] : time())) ?></td>
                <td><?= h(formatBytes($rec['size'])) ?></td>
                <td>
                  <audio controls preload="metadata" src="?stream=<?= urlencode($rec['rel_path']) ?>"></audio>
                </td>
                <td>
                  <a class="dl-btn" href="?download=<?= urlencode($rec['rel_path']) ?>">Download</a>
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
