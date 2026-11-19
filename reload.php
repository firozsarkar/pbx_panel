<?php
// Error reporting for debugging if needed (Optional)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Query parameters থেকে action এবং gateway name নেওয়া হচ্ছে
// উদাহরণ:
// ?action=rescan
// ?action=retry&gw=my_gateway_name
$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : 'rescan';
$gwRaw  = isset($_GET['gw']) ? trim($_GET['gw']) : '';

// Gateway name validate করা হচ্ছে (command injection ঠেকাতে)
$gwName = preg_replace('/[^a-zA-Z0-9_.-]/', '', $gwRaw);

$results = [];
$errorMessage = "";

if (!function_exists('shell_exec')) {
    $errorMessage = "Error: shell_exec() is disabled on your server.";
} elseif ($action === 'retry') {
    if ($gwName === '') {
        $errorMessage = "Error: Retry করার জন্য সঠিক Gateway নাম (?action=retry&gw=gateway_name) দিন।";
    } else {
        $safeGw = escapeshellarg($gwName);
        $commands = [
            'reloadxml' => 'fs_cli -x "reloadxml" 2>&1',
            'killgw'    => 'fs_cli -x ' . escapeshellarg("sofia profile external killgw {$gwName}") . ' 2>&1',
            'rescan'    => 'fs_cli -x "sofia profile external rescan" 2>&1',
        ];

        foreach ($commands as $key => $cmd) {
            $results[$key] = [
                'cmd'    => $cmd,
                'output' => shell_exec($cmd),
            ];
        }
    }
} elseif ($action === 'rescan') {
    $commands = [
        'reloadxml' => 'fs_cli -x "reloadxml" 2>&1',
        'rescan'    => 'fs_cli -x "sofia profile external rescan" 2>&1',
    ];

    foreach ($commands as $key => $cmd) {
        $results[$key] = [
            'cmd'    => $cmd,
            'output' => shell_exec($cmd),
        ];
    }
} else {
    $errorMessage = "Error: অজানা action ('" . htmlspecialchars($action) . "')। শুধু 'rescan' অথবা 'retry' ব্যবহার করুন।";
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeSWITCH Reload & Retry</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f8;
            padding: 20px;
            color: #222;
        }
        .container {
            max-width: 720px;
            margin: 40px auto;
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .controls input[type="text"] {
            flex: 1;
            min-width: 180px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        .btn-rescan {
            background-color: #2563eb;
            color: #fff;
        }
        .btn-retry {
            background-color: #d97706;
            color: #fff;
        }
        .alert-success {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        pre {
            background-color: #272822;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-family: "Courier New", Courier, monospace;
            font-size: 14px;
            margin-top: 6px;
        }
        .label {
            font-weight: bold;
            color: #333;
            margin-top: 15px;
            display: block;
            word-break: break-all;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>FreeSWITCH কমান্ড কন্ট্রোল (Rescan / Retry)</h2>

    <form method="GET" class="controls">
        <input type="text" name="gw" value="<?= htmlspecialchars($gwName); ?>" placeholder="Gateway নাম লিখুন (Retry এর জন্য)">
        <button type="submit" name="action" value="rescan" class="btn btn-rescan">Rescan</button>
        <button type="submit" name="action" value="retry" class="btn btn-retry">Retry (KillGW + Rescan)</button>
    </form>

    <?php if ($errorMessage !== ""): ?>
        <div class="alert-error">
            <?= htmlspecialchars($errorMessage); ?>
        </div>
    <?php else: ?>
        <div class="alert-success">
            <strong>সফল!</strong> Action: <code><?= htmlspecialchars($action); ?></code>
            <?php if ($action === 'retry'): ?>
                | Gateway: <code><?= htmlspecialchars($gwName); ?></code>
            <?php endif; ?>
            — কমান্ড এক্সিকিউট সম্পন্ন হয়েছে।
        </div>

        <?php foreach ($results as $item): ?>
            <div>
                <span class="label">&gt; $ <?= htmlspecialchars($item['cmd']); ?></span>
                <pre><?= htmlspecialchars($item['output'] !== null && trim($item['output']) !== '' ? $item['output'] : 'No output returned'); ?></pre>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
