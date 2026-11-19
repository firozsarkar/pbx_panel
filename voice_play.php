<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

$baseDir = realpath('/usr/local/freeswitch/recordings');
if ($baseDir === false || !is_dir($baseDir)) {
    http_response_code(500);
    exit('Recordings directory not found.');
}

/**
 * নিরাপদভাবে ফাইল পাথ যাচাই করে (Path Traversal ঠেকাতে)
 */
function resolveRecordingFile(string $relativePath, string $baseDir): ?string {
    $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($clean === '' || strpos($clean, "\0") !== false) {
        return null;
    }

    $fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . $clean);
    if ($fullPath === false || !is_file($fullPath)) {
        return null;
    }

    $basePrefix = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($fullPath, $basePrefix) !== 0) {
        return null;
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
        return null;
    }

    return $fullPath;
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
    $fileSize = filesize($filePath);
    $fileName = basename($filePath);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, must-revalidate');

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
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

        $fp = fopen($filePath, 'rb');
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

    header('Content-Length: ' . $fileSize);
    readfile($filePath);
    exit;
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

    $fileName = basename($filePath);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

/**
 * সব রেকর্ডিং ফাইল স্ক্যান করা
 */
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : '';
$searchQuery  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$dates = [];
$recordings = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
        continue;
    }

    $fullPath = $fileInfo->getRealPath();
    $relPath = ltrim(str_replace($baseDir, '', $fullPath), '/\\');
    $relPathUnix = str_replace('\\', '/', $relPath);

    $parts = explode('/', $relPathUnix);
    $folderDate = (count($parts) > 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0]))
        ? $parts[0]
        : date('Y-m-d', $fileInfo->getMTime());

    $dates[$folderDate] = true;

    if ($selectedDate !== '' && $folderDate !== $selectedDate) {
        continue;
    }

    $fileName = $fileInfo->getFilename();
    if ($searchQuery !== '' && stripos($fileName, $searchQuery) === false && stripos($relPathUnix, $searchQuery) === false) {
        continue;
    }

    $recordings[] = [
        'rel_path' => $relPathUnix,
        'name'     => $fileName,
        'date'     => $folderDate,
        'size'     => $fileInfo->getSize(),
        'mtime'    => $fileInfo->getMTime(),
    ];
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
      --accent: #38bdf8;
    }
    * { box-sizing: border-box; }
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
        <p>Directory: <code>/usr/local/freeswitch/recordings</code> • Total: <strong><?= count($recordings) ?></strong></p>
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
        <a href="voice_play.php" class="btn reset-btn">রিসেট</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap">
      <?php if (empty($recordings)): ?>
        <div class="empty">কোনো কল রেকর্ডিং ফাইল পাওয়া যায়নি।</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ফাইল / নম্বর</th>
              <th>তারিখ ও সময়</th>
              <th>সাইজ</th>
              <th>প্লেয়ার</th>
              <th>ডাউনলোড</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recordings as $rec): ?>
              <tr>
                <td>
                  <div class="file-name"><?= h($rec['name']) ?></div>
                  <div class="meta"><?= h($rec['rel_path']) ?></div>
                </td>
                <td><?= h(date('Y-m-d H:i:s', $rec['mtime'])) ?></td>
                <td><?= h(formatBytes($rec['size'])) ?></td>
                <td>
                  <audio controls preload="none" src="?stream=<?= urlencode($rec['rel_path']) ?>"></audio>
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
