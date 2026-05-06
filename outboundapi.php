<?php
// ==========================================
// কনফিগারেশন (এখানে আপনার গেটওয়ে এবং এক্সটেনশন সেট করুন)
// ==========================================
$allowed_extensions = ['1001', '1002', '1003']; // অনুমোদিত এক্সটেনশনগুলোর তালিকা
$allowed_gateways = [
    '1001' => 'external::09617171305',
    '1002' => 'external::09617171305',
    // প্রয়োজনে নতুন এক্সটেনশন এবং তার গেটওয়ে এখানে যুক্ত করতে পারবেন
];

if (isset($_GET['action']) && $_GET['action'] == 'call') {
    header('Content-Type: application/json');

    $extension = isset($_GET['ext']) ? trim($_GET['ext']) : '';
    $destination = isset($_GET['dest']) ? trim($_GET['dest']) : '';

    // ১. এক্সটেনশন যাচাইকরণ
    if (!in_array($extension, $allowed_extensions) || !isset($allowed_gateways[$extension])) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "message" => "অননুমোদিত অ্যাক্সেস। এই এক্সটেনশন থেকে কল করার অনুমতি নেই।"
        ]);
        exit;
    }

    $gateway = $allowed_gateways[$extension];

    // ২. গন্তব্য নম্বর যাচাইকরণ
    if (empty($destination)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "গন্তব্য নম্বর অনুপস্থিত।"
        ]);
        exit;
    }

    // ৩. ফ্রি-সুইচ কমান্ড তৈরি
    $cmd = "fs_cli -x \"originate sofia/internal/{$extension}@vps.hostserverbd.com &bridge(sofia/gateway/{$gateway}/{$destination})\"";
    
    // ৪. কমান্ড রান করা
    $output = shell_exec($cmd);

    if ($output !== null) {
        echo json_encode([
            "status" => "success",
            "message" => "কল সফলভাবে ইনিশিয়েট করা হয়েছে।",
            "extension" => $extension,
            "gateway" => $gateway,
            "destination" => $destination
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "কল করতে ব্যর্থ হয়েছে।"
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIP Outbound Call API</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: #0d0d0d;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            margin: 50px auto;
            background: #141414;
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #d4af37;
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.2);
        }
        h1 {
            color: #d4af37;
            text-align: center;
            margin-bottom: 25px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 22px;
            text-shadow: 0 0 10px rgba(212, 175, 55, 0.5);
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #d4af37;
            font-size: 13px;
            text-transform: uppercase;
        }
        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 4px;
            color: #ffffff;
            font-size: 14px;
        }
        input:focus {
            border-color: #d4af37;
            outline: none;
        }
        button {
            width: 100%;
            padding: 14px;
            background-color: #d4af37;
            border: none;
            border-radius: 4px;
            color: #0d0d0d;
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.3s;
        }
        button:hover {
            background-color: #e5c158;
        }
        #response {
            margin-top: 20px;
            padding: 12px;
            border-radius: 4px;
            display: none;
            font-size: 13px;
        }
        .success {
            background-color: rgba(212, 175, 55, 0.1);
            color: #d4af37;
            border: 1px solid #d4af37;
        }
        .error {
            background-color: rgba(255, 51, 51, 0.1);
            color: #ff3333;
            border: 1px solid #ff3333;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>VIP PBX API</h1>
    <form id="callForm">
        <label for="ext">এক্সটেনশন (Extension):</label>
        <input type="text" id="ext" name="ext" placeholder="1001" required>

        <label for="dest">গন্তব্য নম্বর (Destination Number):</label>
        <input type="text" id="dest" name="dest" placeholder="গন্তব্য নম্বর লিখুন" required>

        <button type="button" onclick="makeCall()">কল করুন</button>
    </form>

    <div id="response"></div>
</div>

<script>
    function makeCall() {
        const ext = document.getElementById('ext').value;
        const dest = document.getElementById('dest').value;
        const responseDiv = document.getElementById('response');

        if (!ext || !dest) {
            responseDiv.style.display = 'block';
            responseDiv.className = 'error';
            responseDiv.innerHTML = 'দয়া করে এক্সটেনশন এবং গন্তব্য নম্বর উভয়ই পূরণ করুন।';
            return;
        }

        fetch(`outboundapi.php?action=call&ext=${ext}&dest=${dest}`)
            .then(response => response.json())
            .then(data => {
                responseDiv.style.display = 'block';
                if (data.status === 'success') {
                    responseDiv.className = 'success';
                    responseDiv.innerHTML = data.message;
                } else {
                    responseDiv.className = 'error';
                    responseDiv.innerHTML = data.message;
                }
            })
            .catch(() => {
                responseDiv.style.display = 'block';
                responseDiv.className = 'error';
                responseDiv.innerHTML = 'অনুরোধটি সম্পন্ন করতে সমস্যা হয়েছে।';
            });
    }
</script>

</body>
</html>
