<?php

declare(strict_types=1);

/**
 * Helper function to run FreeSWITCH CLI commands safely.
 *
 * @return array{exit_code: int, output: string}
 */
function runFsCommand(string $fsCommand): array
{
    // Escape double quotes inside the fs_cli -x argument
    $escapedCmd = escapeshellarg($fsCommand);
    $fullCmd = "fs_cli -x {$escapedCmd} 2>&1";

    $outputLines = [];
    $exitCode = 0;

    exec($fullCmd, $outputLines, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $outputLines)),
    ];
}

echo "Checking if mod_xml_curl is loaded...\n";
$check = runFsCommand('module_exists mod_xml_curl');

echo "Output: " . ($check['output'] !== '' ? $check['output'] : '(empty)') . "\n";

if ($check['exit_code'] !== 0) {
    fwrite(STDERR, "Error running fs_cli (exit code {$check['exit_code']}). Check permissions or FreeSWITCH service.\n");
    exit(1);
}

$isLoaded = (strtolower($check['output']) === 'true');

if ($isLoaded) {
    echo "mod_xml_curl is ALREADY loaded.\n";
    exit(0);
}

echo "mod_xml_curl is not loaded. Loading module...\n";
$load = runFsCommand('load mod_xml_curl');

echo "Load output: " . ($load['output'] !== '' ? $load['output'] : '(empty)') . "\n";

// Verify again after loading
$verify = runFsCommand('module_exists mod_xml_curl');
$nowLoaded = (strtolower($verify['output']) === 'true');

if ($load['exit_code'] === 0 && $nowLoaded) {
    echo "Success: mod_xml_curl is now loaded and active.\n";
    exit(0);
}

fwrite(STDERR, "Failed to load mod_xml_curl. Verify module is compiled/installed and check FreeSWITCH logs.\n");
exit(1);
