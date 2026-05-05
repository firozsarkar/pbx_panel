<?php

$basePath = "/etc/freeswitch/sip_profiles/external/";

// =========================
// CREATE TRUNK
// =========================
if (isset($_POST['create'])) {

    $name     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $proxy    = trim($_POST['proxy'] ?? '');

    if (!$name || !$username || !$password || !$proxy) {
        die("All fields are required!");
    }

    $xml = <<<XML
<include>
  <gateway name="$name">
    <param name="username" value="$username"/>
    <param name="password" value="$password"/>
    <param name="proxy" value="$proxy"/>
    <param name="register" value="true"/>
    <param name="expire-seconds" value="3600"/>
    <param name="retry-seconds" value="30"/>
  </gateway>
</include>
XML;

    $file = $basePath . $name . ".xml";

    if (file_put_contents($file, $xml)) {
        echo "<p style='color:green;'>Trunk created: $name</p>";
    } else {
        echo "<p style='color:red;'>Failed to create trunk (permission issue)</p>";
    }

    shell_exec("fs_cli -x 'reloadxml'");
    shell_exec("fs_cli -x 'sofia profile external rescan'");
}

// =========================
// DELETE TRUNK
// =========================
if (isset($_GET['delete'])) {

    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['delete']);
    $file = $basePath . $name . ".xml";

    if (file_exists($file)) {
        unlink($file);
        echo "<p style='color:red;'>Deleted trunk: $name</p>";
    }
}

// =========================
// STATUS CHECK
// =========================
$statusOutput = "";

if (isset($_GET['status'])) {

    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['status']);

    $statusOutput = shell_exec("fs_cli -x \"sofia status gateway $name\"");
}

// =========================
// LIST TRUNKS
// =========================
$trunks = glob($basePath . "*.xml");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Trunks Manager</title>
</head>
<body>

<h2>FreeSWITCH Trunks Manager</h2>

<hr>

<h3>Create Trunk</h3>

<form method="POST">
    <input type="text" name="name" placeholder="Trunk Name"><br><br>
    <input type="text" name="username" placeholder="Username"><br><br>
    <input type="text" name="password" placeholder="Password"><br><br>
    <input type="text" name="proxy" placeholder="SIP Server"><br><br>
    <button type="submit" name="create">Create Trunk</button>
</form>

<hr>

<h3>Trunk List</h3>

<?php foreach ($trunks as $t): 
    $name = basename($t, ".xml");
?>
    <div style="margin-bottom:10px;">
        <b><?php echo $name; ?></b>

        <a href="?status=<?php echo $name; ?>">Status</a> |
        <a href="?delete=<?php echo $name; ?>" onclick="return confirm('Delete?')">Delete</a>
    </div>
<?php endforeach; ?>

<hr>

<h3>Status Output</h3>
<pre>
<?php echo htmlspecialchars($statusOutput); ?>
</pre>

<hr>

<a href="?reload=1">Reload FreeSWITCH</a>

<?php
if (isset($_GET['reload'])) {
    shell_exec("fs_cli -x 'reloadxml'");
    shell_exec("fs_cli -x 'sofia profile external rescan'");
    echo "<p>Reload done</p>";
}
?>

</body>
</html>
