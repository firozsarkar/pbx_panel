<?php
// JSON Output Header
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit;
}

// জাস্ট এই দুটি ইনপুট গ্রহণ করবে
$extension_name = isset($_POST['extension_name']) ? trim($_POST['extension_name']) : '';
$gateway_number = isset($_POST['gateway_number']) ? trim($_POST['gateway_number']) : '';

// প্রয়োজনীয় ফিল্ড যাচাই
if (empty($extension_name) || empty($gateway_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'extension_name and gateway_number are required.'
    ]);
    exit;
}

// বাকি সব নিজে থেকেই সেটআপ হবে
$dir = '/etc/freeswitch/dialplan/public/';

// XML Structure তৈরি
$xml_output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<include>\n  <extension name=\"{$extension_name}\">\n    <condition field=\"destination_number\" expression=\"^(\d+)$\">\n      <action application=\"bridge\" data=\"sofia/gateway/external::{$gateway_number}/$1\"/>\n    </condition>\n  </extension>\n</include>";

// ডিরেক্টরি চেক এবং তৈরি করা
if (!file_exists($dir)) {
    @mkdir($dir, 0775, true);
}

// ফাইল পাথ তৈরি: extension_name_gateway_number_outbound.xml
$file_path = $dir . $extension_name . "_" . $gateway_number . "_outbound.xml";

// ফাইল সেভ করা
if (@file_put_contents($file_path, $xml_output)) {
    // অটো FreeSWITCH রিলোড করা
    $output = shell_exec('fs_cli -x "reloadxml" 2>&1');

    echo json_encode([
        'status' => 'success',
        'file_path' => $file_path,
        'message' => 'ফাইলটি সফলভাবে তৈরি হয়েছে এবং FreeSWITCH রিলোড করা হয়েছে।'
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ফাইলটি সেভ করা যায়নি! ফোল্ডার পারমিশন (Permissions) চেক করুন।'
    ]);
}
?>
        }
        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 4px;
            color: #ffffff;
            font-size: 14px;
        }
        input:focus, select:focus {
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
    <h1>VIP PBX Outbound Call</h1>
    <form id="callForm">
        <label for="ext">এক্সটেনশন সিলেক্ট করুন:</label>
        <select id="ext" name="ext" required>
            <option value="">-- এক্সটেনশন নির্বাচন করুন --</option>
            <?php
            $current_data = json_decode(file_get_contents($json_file), true);
            if ($current_data) {
                foreach ($current_data as $ext_num => $details) {
                    echo "<option value='{$ext_num}'>{$ext_num} - {$details['client']}</option>";
                }
            }
            ?>
        </select>

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
            responseDiv.innerHTML = 'দয়া করে এক্সটেনশন নির্বাচন করুন এবং গন্তব্য নম্বর পূরণ করুন।';
            return;
        }

        fetch(`outbound.php?action=call&ext=${ext}&dest=${dest}`)
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
        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 4px;
            color: #ffffff;
            font-size: 14px;
        }
        input:focus, select:focus {
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
    <h1>VIP PBX Outbound Call</h1>
    <form id="callForm">
        <label for="ext">এক্সটেনশন সিলেক্ট করুন:</label>
        <select id="ext" name="ext" required>
            <option value="">-- এক্সটেনশন নির্বাচন করুন --</option>
            <?php
            $current_data = json_decode(file_get_contents($json_file), true);
            if ($current_data) {
                foreach ($current_data as $ext_num => $details) {
                    echo "<option value='{$ext_num}'>{$ext_num} - {$details['client']}</option>";
                }
            }
            ?>
        </select>

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
            responseDiv.innerHTML = 'দয়া করে এক্সটেনশন নির্বাচন করুন এবং গন্তব্য নম্বর পূরণ করুন।';
            return;
        }

        fetch(`outbound.php?action=call&ext=${ext}&dest=${dest}`)
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
