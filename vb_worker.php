<?php
/**
 * vb_worker.php — Voice Broadcasting ডায়ালার (FreeSWITCH VPS: 38.60.235.146)
 * Path: /var/www/html/vb_worker.php
 *
 * FreeSWITCH VPS-এ crontab -e দিয়ে যুক্ত করুন:
 *   * * * * * /usr/bin/php /var/www/html/vb_worker.php >> /var/log/vb_worker.log 2>&1
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
set_time_limit(0);

/* ============================ কনফিগ ============================ */
const ESL_HOST = '127.0.0.1';
const ESL_PORT = 8021;
const ESL_PASS = 'ClueCon';               // /etc/freeswitch/autoload_configs/event_socket.conf.xml এর password

// cPanel Remote MySQL কনফিগ (cPanel-এ 38.60.235.146 IP হোয়াইটলিস্ট থাকতে হবে)
const DB_HOST  = '103.163.246.205';
const DB_PORT  = 3306;
const DB_NAME  = 'register_pbx';
const DB_USER  = 'register_pbx';
const DB_PASS  = 'register_pbx';

const DOMAIN   = '38.60.235.146';

const ORIGINATE_TIMEOUT      = 30;      // রিং করার সর্বোচ্চ সময় (সেকেন্ড)
const MAX_ORIGINATE_PER_TICK = 2;       // প্রতি সেকেন্ডে সর্বোচ্চ নতুন কল (গেটওয়ে CPS সুরক্ষা)
const PRESS1_WAIT_MS         = 8000;    // অডিও শেষ হওয়ার পর ১ চাপার জন্য অপেক্ষা (মিলিসেকেন্ড)

// বিলিং (প্রথমে false রাখুন; চালু করতে true করুন)
const BILLING_ENABLED = false;
const RATE_PER_MIN    = 0.50;
const BILL_STEP       = 6;              // সেকেন্ড পালস

/* ============================ লক ============================ */
$lock = fopen('/tmp/vb_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // আরেকটি ওয়ার্কার ইতিমধ্যে চলছে
}

function logm(string $m): void
{
    echo date('Y-m-d H:i:s') . ' | ' . $m . "\n";
}

/* ============================ ESL ক্লায়েন্ট ============================ */
class Esl
{
    private $fp = null;
    private string $buf = '';

    public function connect(): bool
    {
        $this->close();
        $this->buf = '';
        $this->fp = @stream_socket_client('tcp://' . ESL_HOST . ':' . ESL_PORT, $en, $es, 5);
        if (!$this->fp) {
            logm("ESL socket error ({$en}): {$es}");
            return false;
        }
        $f = $this->wait(5);
        if (!$f || ($f['h']['Content-Type'] ?? '') !== 'auth/request') {
            return false;
        }
        $this->send('auth ' . ESL_PASS);
        $f = $this->wait(5);
        return $f && strpos($f['h']['Reply-Text'] ?? '', '+OK') === 0;
    }

    public function send(string $cmd): bool
    {
        if (!$this->fp) {
            return false;
        }
        $res = @fwrite($this->fp, $cmd . "\n\n");
        if ($res === false) {
            $this->close();
            return false;
        }
        return true;
    }

    public function alive(): bool
    {
        return $this->fp && !feof($this->fp);
    }

    public function pump(float $timeout = 0.5): void
    {
        if (!$this->fp) {
            return;
        }
        $r = [$this->fp];
        $w = $e = null;
        $n = @stream_select($r, $w, $e, 0, (int)($timeout * 1000000));
        if ($n > 0) {
            $d = @fread($this->fp, 8192);
            if ($d === '' || $d === false) {
                if (!$this->fp || feof($this->fp)) {
                    $this->close();
                }
            } else {
                $this->buf .= $d;
            }
        }
    }

    public function next(): ?array
    {
        $p = strpos($this->buf, "\n\n");
        if ($p === false) {
            return null;
        }
        $h = self::parse(substr($this->buf, 0, $p), false);
        $len = (int)($h['Content-Length'] ?? 0);
        if (strlen($this->buf) < $p + 2 + $len) {
            return null;
        }
        $body = substr($this->buf, $p + 2, $len);
        $this->buf = substr($this->buf, $p + 2 + $len);
        return ['h' => $h, 'b' => $body];
    }

    public function wait(float $sec): ?array
    {
        $t = microtime(true) + $sec;
        while (microtime(true) < $t) {
            if ($f = $this->next()) {
                return $f;
            }
            if (!$this->fp) {
                return null;
            }
            $this->pump(0.2);
        }
        return null;
    }

    public static function parse(string $txt, bool $decode): array
    {
        $h = [];
        foreach (explode("\n", $txt) as $l) {
            $p = strpos($l, ': ');
            if ($p !== false) {
                $v = substr($l, $p + 2);
                $h[substr($l, 0, $p)] = $decode ? rawurldecode($v) : $v;
            }
        }
        return $h;
    }

    public function close(): void
    {
        if ($this->fp) {
            @fclose($this->fp);
        }
        $this->fp = null;
    }
}

/* ============================ DB কানেকশন ============================ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (\Throwable $e) {
            $pdo = null;
        }
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    return $pdo;
}

/* ============================ হেল্পার ============================ */
function transferDial(string $to, string $gw): ?string
{
    $to = trim($to);
    if ($to === '') {
        return null;
    }
    if (strpos($to, '/') !== false) {
        return preg_match('/^[A-Za-z0-9_.@\/\-]+$/', $to) ? $to : null;
    }
    if (preg_match('/^\d{2,5}$/', $to)) {
        return 'user/' . $to . '@' . DOMAIN;
    }
    $cleanNum = preg_replace('/[^0-9]/', '', $to);
    if ($cleanNum !== '' && strlen($cleanNum) >= 6 && strlen($cleanNum) <= 15) {
        return 'sofia/gateway/' . $gw . '/' . $cleanNum;
    }
    return null;
}

function finishCall(array $c, string $uuid, array $h): void
{
    $billsec = (int)($h['variable_billsec'] ?? 0);
    $dur     = (int)($h['variable_duration'] ?? 0);
    $cause   = (string)($h['Hangup-Cause'] ?? '');

    if ($c['answered'] || $billsec > 0) {
        $status = 'answered';
    } elseif ($cause === 'USER_BUSY') {
        $status = 'busy';
    } elseif (in_array($cause, ['NO_ANSWER', 'NO_USER_RESPONSE', 'ORIGINATOR_CANCEL', 'RECOVERY_ON_TIMER_EXPIRE'], true)) {
        $status = 'no_answer';
    } else {
        $status = 'failed';
    }

    // রিট্রাই বাকি থাকলে আবার pending সেট হবে
    if ($status !== 'answered' && $c['attempts'] < 1 + $c['retry']) {
        $status = 'pending';
    }

    $cost = 0.0;
    if (BILLING_ENABLED && $billsec > 0) {
        $cost = round(ceil($billsec / BILL_STEP) * BILL_STEP / 60 * RATE_PER_MIN, 4);
    }

    $st = db()->prepare("UPDATE vb_contacts SET status=?, duration=?, billsec=?, dtmf=?, hangup_cause=?, cost=? WHERE id=?");
    $st->execute([$status, $dur, $billsec, substr($c['dtmf'], 0, 10), $cause, $cost, $c['cid']]);

    if ($cost > 0) {
        db()->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$cost, $c['uid']]);
    }
    logm("END uuid={$uuid} contact={$c['cid']} status={$status} billsec={$billsec} cause={$cause} dtmf={$c['dtmf']}");
}

function dialTick(Esl $esl, array &$active): void
{
    $pdo = db();
    $camps = $pdo->query("
        SELECT k.*, u.balance, u.vb_daily_limit,
          ( (k.call_start_time <= k.call_end_time AND TIME(NOW()) BETWEEN k.call_start_time AND k.call_end_time)
            OR (k.call_start_time > k.call_end_time AND (TIME(NOW()) >= k.call_start_time OR TIME(NOW()) <= k.call_end_time)) ) AS in_window
        FROM vb_campaigns k
        JOIN users u ON u.id = k.user_id
        WHERE k.status = 'running' AND u.vb_enabled = 1 AND u.status = 'active'
    ")->fetchAll();

    foreach ($camps as $k) {
        $kid = (int)$k['id'];
        $cnt = 0;
        foreach ($active as $a) {
            if ($a['kid'] === $kid) {
                $cnt++;
            }
        }

        if (BILLING_ENABLED && (float)$k['balance'] <= 0) {
            $pdo->prepare("UPDATE vb_campaigns SET status='paused' WHERE id=?")->execute([$kid]);
            logm("Campaign {$kid} paused: balance শেষ");
            continue;
        }

        $fetched = 0;
        if ((int)$k['in_window'] === 1) {
            $gw    = (string)$k['gateway_name'];
            $audio = (string)$k['audio_file'];
            $cli   = preg_replace('/[^0-9+]/', '', (string)$k['caller_id']);

            if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $gw)
                || !preg_match('/^[A-Za-z0-9_\/.\-]+$/', $audio)
                || !is_file($audio)) {
                logm("Campaign {$kid} skipped: gateway ({$gw}) বা audio ফাইল ({$audio}) পাওয়া যায়নি");
                continue;
            }

            $slots = min((int)$k['max_channels'] - $cnt, MAX_ORIGINATE_PER_TICK);

            // দৈনিক কল লিমিট চেক
            $q = $pdo->prepare("SELECT COUNT(*) FROM vb_contacts c JOIN vb_campaigns kk ON kk.id = c.campaign_id
                                WHERE kk.user_id = ? AND c.last_attempt_at >= CURDATE()");
            $q->execute([$k['user_id']]);
            $slots = min($slots, (int)$k['vb_daily_limit'] - (int)$q->fetchColumn());

            if ($slots > 0) {
                $sql = sprintf(
                    "SELECT * FROM vb_contacts
                     WHERE campaign_id = %d AND status = 'pending' AND attempts < %d
                       AND (last_attempt_at IS NULL OR last_attempt_at <= NOW() - INTERVAL %d MINUTE)
                     ORDER BY id ASC LIMIT %d",
                    $kid, 1 + (int)$k['retry_count'], (int)$k['retry_delay_min'], $slots
                );

                foreach ($pdo->query($sql)->fetchAll() as $c) {
                    $fetched++;

                    // DNC চেক
                    $d = $pdo->prepare("SELECT 1 FROM vb_dnc WHERE user_id = ? AND number = ? LIMIT 1");
                    $d->execute([$k['user_id'], $c['number']]);
                    if ($d->fetchColumn()) {
                        $pdo->prepare("UPDATE vb_contacts SET status='dnc' WHERE id=?")->execute([$c['id']]);
                        continue;
                    }

                    $num = preg_replace('/[^0-9]/', '', (string)$c['number']);
                    if ($num === '') {
                        $pdo->prepare("UPDATE vb_contacts SET status='failed' WHERE id=?")->execute([$c['id']]);
                        continue;
                    }

                    $uuid = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))
                          . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(6));

                    $cl = $pdo->prepare("UPDATE vb_contacts SET status='calling', attempts=attempts+1, last_attempt_at=NOW(), call_uuid=? WHERE id=? AND status='pending'");
                    $cl->execute([$uuid, $c['id']]);
                    if ($cl->rowCount() !== 1) {
                        continue;
                    }

                    $vars = '{origination_uuid=' . $uuid
                          . ',vb_contact_id=' . $c['id'] . ',vb_campaign_id=' . $kid
                          . ($cli !== '' ? ',origination_caller_id_number=' . $cli . ',origination_caller_id_name=' . $cli : '')
                          . ',ignore_early_media=true,originate_timeout=' . ORIGINATE_TIMEOUT . '}';

                    $apps = (int)$k['press1_enabled'] === 1
                        ? "playback:{$audio},sleep:" . PRESS1_WAIT_MS . ",hangup"
                        : "playback:{$audio},hangup";

                    $esl->send("bgapi originate {$vars}sofia/gateway/{$gw}/{$num} '{$apps}' inline");

                    $active[$uuid] = [
                        'cid'      => (int)$c['id'],
                        'kid'      => $kid,
                        'uid'      => (int)$k['user_id'],
                        'attempts' => (int)$c['attempts'] + 1,
                        'retry'    => (int)$k['retry_count'],
                        'press1'   => (int)$k['press1_enabled'] === 1,
                        'xfer'     => (string)$k['transfer_to'],
                        'gw'       => $gw,
                        'answered' => false,
                        'dtmf'     => '',
                        'xferred'  => false,
                    ];
                    logm("DIAL campaign={$kid} contact={$c['id']} number={$num} uuid={$uuid}");
                }
            }
        }

        // সব কল ও রিট্রাই শেষ হলে ক্যাম্পেইন done
        if ($cnt === 0 && $fetched === 0) {
            $r = $pdo->prepare("
                SELECT COUNT(*) FROM vb_contacts c
                JOIN vb_campaigns cc ON cc.id = c.campaign_id
                WHERE c.campaign_id = ?
                  AND (c.status = 'calling' OR (c.status = 'pending' AND c.attempts < 1 + cc.retry_count))
            ");
            $r->execute([$kid]);
            if ((int)$r->fetchColumn() === 0) {
                $pdo->prepare("UPDATE vb_campaigns SET status='done' WHERE id=?")->execute([$kid]);
                logm("Campaign {$kid} done");
            }
        }
    }
}

function cleanupStale(array $active): void
{
    $sql = "UPDATE vb_contacts c JOIN vb_campaigns k ON k.id = c.campaign_id
            SET c.status = IF(c.attempts < 1 + k.retry_count, 'pending', 'failed')
            WHERE c.status = 'calling' AND c.last_attempt_at < NOW() - INTERVAL 20 MINUTE";
    if ($active) {
        $in = implode(',', array_map(fn($u) => db()->quote($u), array_keys($active)));
        $sql .= " AND c.call_uuid NOT IN ({$in})";
    }
    db()->exec($sql);
}

/* ============================ মেইন লুপ ============================ */
logm('Worker start');

try {
    db()->exec("UPDATE vb_contacts c JOIN vb_campaigns k ON k.id = c.campaign_id
                SET c.status = IF(c.attempts < 1 + k.retry_count, 'pending', 'failed')
                WHERE c.status = 'calling'");
} catch (\Throwable $e) {
    logm('Initial DB error: ' . $e->getMessage());
}

$esl = new Esl();
$active = [];
$lastTick = 0.0;
$lastClean = time();

while (true) {
    if (!$esl->alive()) {
        logm('ESL connecting...');
        if (!$esl->connect()) {
            logm('ESL connect failed, retry in 5s');
            sleep(5);
            continue;
        }
        $esl->send('event plain CHANNEL_ANSWER CHANNEL_HANGUP_COMPLETE DTMF');
        logm('ESL connected');
    }

    $esl->pump(0.5);

    while ($f = $esl->next()) {
        if (($f['h']['Content-Type'] ?? '') !== 'text/event-plain') {
            continue;
        }
        $ev   = Esl::parse($f['b'], true);
        $name = $ev['Event-Name'] ?? '';
        $uuid = $ev['Unique-ID'] ?? '';
        if (!isset($active[$uuid])) {
            continue;
        }

        try {
            if ($name === 'CHANNEL_ANSWER') {
                $active[$uuid]['answered'] = true;

            } elseif ($name === 'DTMF') {
                $digit = (string)($ev['DTMF-Digit'] ?? '');
                $active[$uuid]['dtmf'] .= $digit;
                $a = $active[$uuid];
                if ($digit === '1' && $a['press1'] && !$a['xferred']) {
                    $dial = transferDial($a['xfer'], $a['gw']);
                    if ($dial) {
                        $active[$uuid]['xferred'] = true;
                        $esl->send("bgapi uuid_transfer {$uuid} 'set:hangup_after_bridge=true,bridge:{$dial}'");
                        logm("TRANSFER uuid={$uuid} -> {$dial}");
                    }
                }

            } elseif ($name === 'CHANNEL_HANGUP_COMPLETE') {
                finishCall($active[$uuid], $uuid, $ev);
                unset($active[$uuid]);
            }
        } catch (\Throwable $e) {
            logm('Event error: ' . $e->getMessage());
        }
    }

    if (microtime(true) - $lastTick >= 1.0) {
        $lastTick = microtime(true);
        try {
            if ($esl->alive()) {
                dialTick($esl, $active);
            }
            if (time() - $lastClean >= 300) {
                $lastClean = time();
                cleanupStale($active);
            }
        } catch (\Throwable $e) {
            logm('Tick error: ' . $e->getMessage());
        }
    }
}
