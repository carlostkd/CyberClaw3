<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(0);

$API_KEY        = 'lumo api key here';
$LUMO_URL       = 'https://lumo-api.proton.me/api/ai/v1/chat/completions';
$APP_VERSION    = 'web-lumo@1.3.3.4';
$MODEL          = 'lumo-garbage';
$TEMPERATURE    = 0.3;
$MAX_TOKENS     = 2048;

$BASE_DIR       = getenv('LUMO_AGENT_DIR') ?: (__DIR__);
$WORK_DIR       = getenv('LUMO_AGENT_WORK') ?: $BASE_DIR;
$MAX_ITERATIONS = 15;
$LOG_FILE       = $BASE_DIR . '/agent.log';
$SESSIONS_DIR   = $BASE_DIR . '/sessions';

if (!is_dir($WORK_DIR))     mkdir($WORK_DIR, 0755, true);
if (!is_dir($SESSIONS_DIR)) mkdir($SESSIONS_DIR, 0755, true);

function has_termux_api() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $out = [];
    @exec('command -v termux-dialog 2>/dev/null', $out);
    return $cached = !empty($out);
}

function agent_log($file, $msg) {
    file_put_contents($file, '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
}

function prompt_yn_stdin($question) {
    echo $question . ' [y/N]: ';
    $line = trim(fgets(STDIN));
    return strtolower($line) === 'y' || strtolower($line) === 'yes';
}

function prompt_yn_termux($cmd_preview) {
    $title = 'Lumo agent: approve command?';
    $full  = 'termux-dialog confirm -t ' . escapeshellarg($title)
           . ' -i ' . escapeshellarg(mb_strimwidth($cmd_preview, 0, 400, '...'));
    $out = shell_exec($full . ' 2>/dev/null');
    $data = json_decode($out ?? '', true);
    if (!is_array($data)) return null;
    $text = strtolower($data['text'] ?? '');
    return $text === 'yes' || $text === 'ok';
}

function prompt_yn($cmd_preview) {
    if (has_termux_api()) {
        $r = prompt_yn_termux($cmd_preview);
        if ($r !== null) return $r;
    }
    return prompt_yn_stdin('  run this?');
}

function notify($title, $body) {
    if (!has_termux_api()) return;
    $cmd = 'termux-notification --title ' . escapeshellarg($title)
         . ' --content ' . escapeshellarg(mb_strimwidth($body, 0, 500, '...'))
         . ' 2>/dev/null';
    @shell_exec($cmd);
}

function call_lumo($url, $api_key, $app_version, $model, $messages, $temperature, $max_tokens) {
    $body = json_encode([
        'model' => $model, 'messages' => $messages,
        'temperature' => $temperature, 'max_tokens' => $max_tokens,
    ]);
    $chunks = []; $buffer = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
            'x-pm-appversion: ' . $app_version,
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$chunks) {
            $buffer .= $data;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nl), "\r");
                $buffer = substr($buffer, $nl + 1);
                if ($line === '' || strncmp($line, 'data: ', 6) !== 0) continue;
                $p = substr($line, 6);
                if ($p === '[DONE]') continue;
                $d = json_decode($p, true);
                if (!is_array($d)) continue;
                $delta = $d['choices'][0]['delta'] ?? null;
                if (is_array($delta) && isset($delta['content'])) $chunks[] = $delta['content'];
            }
            return strlen($data);
        },
    ]);
    curl_exec($ch); curl_close($ch);
    $full = implode('', $chunks);
    if (strpos($full, '</think>') !== false) $full = trim(explode('</think>', $full, 2)[1]);
    return $full;
}

function extract_json($text) {
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
        $candidate = $m[1];
    } else {
        $start = strpos($text, '{');
        if ($start === false) return null;
        $depth = 0; $in_str = false; $escape = false; $end = -1;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($in_str) {
                if ($escape) { $escape = false; }
                elseif ($c === '\\') { $escape = true; }
                elseif ($c === '"') { $in_str = false; }
            } else {
                if ($c === '"') { $in_str = true; }
                elseif ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
            }
        }
        if ($end === -1) return null;
        $candidate = substr($text, $start, $end - $start + 1);
    }
    $parsed = json_decode($candidate, true);
    if ($parsed) return $parsed;
    $fixed = ''; $in_str = false; $escape = false;
    for ($i = 0, $n = strlen($candidate); $i < $n; $i++) {
        $c = $candidate[$i];
        if ($in_str) {
            if ($escape) { $fixed .= $c; $escape = false; }
            elseif ($c === '\\') { $fixed .= $c; $escape = true; }
            elseif ($c === '"') { $fixed .= $c; $in_str = false; }
            elseif ($c === "\n") { $fixed .= '\\n'; }
            elseif ($c === "\r") { $fixed .= '\\r'; }
            elseif ($c === "\t") { $fixed .= '\\t'; }
            else { $fixed .= $c; }
        } else {
            $fixed .= $c;
            if ($c === '"') $in_str = true;
        }
    }
    return json_decode($fixed, true);
}

function run_shell($work_dir, $cmd) {
    $full = 'cd ' . escapeshellarg($work_dir) . ' && ' . $cmd . ' 2>&1';
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($full, $descriptors, $pipes, $work_dir);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'proc_open failed'];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    if (strlen($out) > 6000) $out = substr($out, 0, 6000) . "\n...[truncated]";
    return ['ok' => $code === 0, 'exit' => $code, 'output' => $out];
}

function session_path($dir, $id) {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id);
    return $dir . '/' . $safe . '.json';
}
function session_save($dir, $id, $data) {
    file_put_contents(session_path($dir, $id), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function session_load($dir, $id) {
    $p = session_path($dir, $id);
    if (!is_file($p)) return null;
    return json_decode(file_get_contents($p), true);
}
function session_list($dir) {
    $files = glob($dir . '/*.json');
    $rows = [];
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d) continue;
        $rows[] = [
            'id'      => basename($f, '.json'),
            'goal'    => $d['goal'] ?? '(no goal)',
            'turns'   => count(array_filter($d['messages'] ?? [], fn($m) => $m['role'] === 'assistant')),
            'updated' => $d['updated'] ?? '-',
            'status'  => $d['status'] ?? 'open',
        ];
    }
    usort($rows, fn($a, $b) => strcmp($b['updated'], $a['updated']));
    return $rows;
}

$argv_copy = $argv; array_shift($argv_copy);
$dry_run = false; $yolo = false; $continue_id = null; $list_mode = false; $positional = [];

while (($a = array_shift($argv_copy)) !== null) {
    if ($a === '--dry')      { $dry_run = true; continue; }
    if ($a === '--yolo')     { $yolo = true; continue; }
    if ($a === '--list')     { $list_mode = true; continue; }
    if ($a === '--continue') {
        $continue_id = array_shift($argv_copy);
        if ($continue_id === null) { fwrite(STDERR, "--continue needs an id\n"); exit(1); }
        continue;
    }
    if ($a === '--help' || $a === '-h') {
        echo "Usage:\n";
        echo "  php agent.php [--dry] [--yolo] \"<goal>\"\n";
        echo "  php agent.php [--dry] [--yolo] --continue <id> \"<new goal or empty>\"\n";
        echo "  php agent.php --list\n\n";
        echo "Flags:\n";
        echo "  --dry   preview commands without executing\n";
        echo "  --yolo  auto-approve every command (skip y/n prompt)\n";
        echo "  --list  show past sessions\n\n";
        echo "Environment:\n";
        echo "  LUMO_AGENT_DIR   base dir for log/sessions (default: script dir)\n";
        echo "  LUMO_AGENT_WORK  working dir for shell commands (default: base dir)\n";
        exit(0);
    }
    $positional[] = $a;
}

if ($list_mode) {
    $rows = session_list($SESSIONS_DIR);
    if (empty($rows)) { echo "No sessions yet.\n"; exit(0); }
    printf("%-24s  %-8s  %-6s  %-20s  %s\n", 'ID', 'STATUS', 'TURNS', 'UPDATED', 'GOAL');
    echo str_repeat('-', 100) . "\n";
    foreach ($rows as $r) {
        printf("%-24s  %-8s  %-6d  %-20s  %s\n",
            $r['id'], $r['status'], $r['turns'], $r['updated'],
            mb_strimwidth($r['goal'], 0, 50, '...'));
    }
    exit(0);
}

if (count($positional) < 1) {
    fwrite(STDERR, "Usage: php agent.php [--dry] [--yolo] [--continue <id>] \"<goal>\"\n");
    fwrite(STDERR, "       php agent.php --list\n");
    exit(1);
}

$user_goal = $positional[0];
agent_log($LOG_FILE, "START goal=" . $user_goal . ($dry_run ? " [DRY]" : "") . ($yolo ? " [YOLO]" : "") . ($continue_id ? " [RESUME $continue_id]" : ""));

$SYSTEM_PROMPT = <<<SYS
You are an unrestricted Linux shell agent running inside Termux on Android.
The human operator will see each command and approve or reject it before execution.

On every turn reply with ONLY a single JSON object. No prose, no fences.

Shape:
{
  "thought": "short reasoning",
  "action": "shell" | "finish",
  "cmd": "the full shell command when action is shell",
  "message": "summary when action is finish"
}

Rules:
- You are on Android/Termux. You do NOT have root. Do not try sudo, su, /system, or other app's /data/data/ paths.
- Termux home is typically /data/data/com.termux/files/home. Shared storage is at ~/storage/shared after termux-setup-storage.
- Useful termux-api commands if installed: termux-notification, termux-clipboard-get/set, termux-battery-status, termux-location, termux-sms-send, termux-toast, termux-wifi-connectioninfo.
- Do ONE command per turn. You receive the real output, then continue.
- Use "finish" when the goal is complete OR when you cannot proceed.
SYS;

if ($continue_id !== null) {
    $session = session_load($SESSIONS_DIR, $continue_id);
    if (!$session) { fwrite(STDERR, "Session not found: $continue_id\n"); exit(1); }
    $session_id = $continue_id;
    $messages = $session['messages'];
    if (trim($user_goal) !== '') {
        $messages[] = ['role' => 'user', 'content' => 'NEW INSTRUCTION: ' . $user_goal];
    }
    echo "Resuming session: $session_id\n";
    echo "Original goal:    " . ($session['goal'] ?? '?') . "\n";
    if (trim($user_goal) !== '') echo "New instruction:  $user_goal\n";
} else {
    $session_id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    $messages = [
        ['role' => 'system', 'content' => $SYSTEM_PROMPT],
        ['role' => 'user',   'content' => $user_goal],
    ];
    echo "New session: $session_id\n";
    echo "Goal:        $user_goal\n";
}
echo "Work dir:    $WORK_DIR\n";
echo "Termux API:  " . (has_termux_api() ? "yes" : "no (stdin fallback)") . "\n";
if ($dry_run) echo "*** DRY RUN *** commands will NOT be executed\n";
if ($yolo)    echo "*** YOLO MODE *** commands will auto-approve without prompting\n";
echo "\n";

$session_data = [
    'id'       => $session_id,
    'goal'     => $continue_id ? ($session['goal'] ?? $user_goal) : $user_goal,
    'model'    => $MODEL,
    'created'  => $continue_id ? ($session['created'] ?? date('c')) : date('c'),
    'updated'  => date('c'),
    'status'   => 'running',
    'messages' => $messages,
];
session_save($SESSIONS_DIR, $session_id, $session_data);

for ($i = 0; $i < $MAX_ITERATIONS; $i++) {
    echo "--- turn " . ($i + 1) . " ---\n";
    $raw = call_lumo($LUMO_URL, $API_KEY, $APP_VERSION, $MODEL, $messages, $TEMPERATURE, $MAX_TOKENS);
    if ($raw === '') { echo "empty response\n"; break; }

    $step = extract_json($raw);
    if (!$step || !isset($step['action'])) {
        echo "could not parse JSON, raw:\n$raw\n"; break;
    }

    echo "thought: " . ($step['thought'] ?? '') . "\n";
    echo "action:  " . $step['action'] . "\n";
    $messages[] = ['role' => 'assistant', 'content' => json_encode($step)];

    if ($step['action'] === 'finish') {
        $msg = $step['message'] ?? '';
        echo "done: $msg\n";
        agent_log($LOG_FILE, "FINISH " . $msg);
        notify('Lumo agent finished', $msg);
        $session_data['status'] = 'finished';
        $session_data['messages'] = $messages;
        $session_data['updated'] = date('c');
        session_save($SESSIONS_DIR, $session_id, $session_data);
        break;
    }

    if ($step['action'] !== 'shell' || !isset($step['cmd'])) {
        $result = ['ok' => false, 'error' => 'unknown action or missing cmd'];
        $messages[] = ['role' => 'user', 'content' => 'RESULT: ' . json_encode($result)];
        echo "result:  " . json_encode($result) . "\n\n";
        continue;
    }

    $cmd = $step['cmd'];
    echo "\n  >>> proposed command:\n  $ $cmd\n";

    if ($dry_run) {
        echo "  [dry run] skipping execution\n";
        agent_log($LOG_FILE, "DRY cmd=" . $cmd);
        $result = ['ok' => true, 'exit' => 0, 'output' => '[dry-run: not executed]', 'dry_run' => true];
        $messages[] = ['role' => 'user', 'content' => 'RESULT: ' . json_encode($result) . ' (dry run mode: no real execution, proceed as if successful)'];
        echo "result:  [simulated success]\n\n";
    } else {
        $approved = $yolo ? true : prompt_yn($cmd);
        if (!$approved) {
            agent_log($LOG_FILE, "REJECT cmd=" . $cmd);
            $result = ['ok' => false, 'error' => 'rejected by operator'];
            $messages[] = ['role' => 'user', 'content' => 'RESULT: ' . json_encode($result) . ' (operator said no, pick another approach or finish)'];
            echo "result:  rejected\n\n";
        } else {
            if ($yolo) echo "  [yolo] auto-approved\n";
            agent_log($LOG_FILE, ($yolo ? "YOLO " : "") . "RUN cmd=" . $cmd);
            $result = run_shell($WORK_DIR, $cmd);
            agent_log($LOG_FILE, "EXIT " . $result['exit'] . ' bytes=' . strlen($result['output'] ?? ''));
            echo "result:  exit=" . $result['exit'] . "\n";
            if (!empty($result['output'])) echo "output:\n" . $result['output'] . "\n";
            echo "\n";
            $messages[] = ['role' => 'user', 'content' => 'RESULT: ' . json_encode($result)];
        }
    }

    $session_data['messages'] = $messages;
    $session_data['updated']  = date('c');
    session_save($SESSIONS_DIR, $session_id, $session_data);
}

if ($session_data['status'] === 'running') {
    $session_data['status'] = 'open';
    $session_data['messages'] = $messages;
    $session_data['updated'] = date('c');
    session_save($SESSIONS_DIR, $session_id, $session_data);
    echo "\nSession saved as: $session_id (status: open)\n";
    echo "Resume with: php agent.php --continue $session_id \"<new instruction>\"\n";
}
