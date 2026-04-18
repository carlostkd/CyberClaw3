<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(0);

$API_KEY        = '';
$LUMO_URL       = 'https://lumo-api.proton.me/api/ai/v1/chat/completions';
$APP_VERSION    = 'web-lumo@1.3.3.4';
$MODEL          = 'lumo-garbage';
$TEMPERATURE    = 0.3;
$MAX_TOKENS     = 2048;
$TOOLS_DIR      = __DIR__ . '/tools';
$MAX_ITERATIONS = 10;

$ALLOWED_CMDS = ['ls','cat','head','tail','rm','mv','cp','mkdir','echo','grep','find','wc','touch'];

if (!is_dir($TOOLS_DIR)) {
    mkdir($TOOLS_DIR, 0755, true);
}

function tokenize($cmd) {
    $tokens = [];
    $buf = '';
    $in_single = false;
    $in_double = false;
    $escape = false;
    for ($i = 0, $n = strlen($cmd); $i < $n; $i++) {
        $c = $cmd[$i];
        if ($escape) { $buf .= $c; $escape = false; continue; }
        if ($c === '\\' && !$in_single) { $escape = true; continue; }
        if ($c === "'" && !$in_double) { $in_single = !$in_single; continue; }
        if ($c === '"' && !$in_single) { $in_double = !$in_double; continue; }
        if (!$in_single && !$in_double && ($c === ' ' || $c === "\t")) {
            if ($buf !== '') { $tokens[] = $buf; $buf = ''; }
            continue;
        }
        $buf .= $c;
    }
    if ($buf !== '') $tokens[] = $buf;
    return $tokens;
}

function validate_command($cmd, $allowed) {
    $dangerous = ['|','&',';','`','$(','>','<','>>','<<'];
    foreach ($dangerous as $d) {
        if (strpos($cmd, $d) !== false) {
            return [false, 'shell metacharacter not allowed: ' . $d];
        }
    }
    $tokens = tokenize($cmd);
    if (empty($tokens)) return [false, 'empty command'];
    $bin = $tokens[0];
    if (!in_array($bin, $allowed, true)) {
        return [false, "command not whitelisted: $bin"];
    }
    foreach (array_slice($tokens, 1) as $arg) {
        if ($arg === '' || $arg[0] === '-') continue;
        if ($arg[0] === '/' || $arg[0] === '~') {
            return [false, "absolute or home path not allowed: $arg"];
        }
        if (strpos($arg, '..') !== false) {
            return [false, "parent directory reference not allowed: $arg"];
        }
    }
    return [true, $tokens];
}

function run_shell($tools_dir, $cmd, $allowed) {
    [$ok, $result] = validate_command($cmd, $allowed);
    if (!$ok) return ['ok' => false, 'error' => $result];

    $tokens = $result;
    $quoted = array_map('escapeshellarg', $tokens);
    $joined = implode(' ', $quoted);
    $full   = 'cd ' . escapeshellarg($tools_dir) . ' && ' . $joined . ' 2>&1';

    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($full, $descriptors, $pipes, $tools_dir);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'proc_open failed'];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if (strlen($out) > 4000) $out = substr($out, 0, 4000) . "\n...[truncated]";
    return ['ok' => $code === 0, 'exit' => $code, 'output' => $out];
}

function call_lumo($url, $api_key, $app_version, $model, $messages, $temperature, $max_tokens) {
    $body = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $max_tokens,
    ]);

    $chunks = [];
    $buffer = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $body,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
            'x-pm-appversion: ' . $app_version,
        ],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$chunks) {
            $buffer .= $data;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nl);
                $buffer = substr($buffer, $nl + 1);
                $line = rtrim($line, "\r");
                if ($line === '' || strncmp($line, 'data: ', 6) !== 0) continue;
                $payload = substr($line, 6);
                if ($payload === '[DONE]') continue;
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) continue;
                $delta = $decoded['choices'][0]['delta'] ?? null;
                if (is_array($delta) && isset($delta['content'])) {
                    $chunks[] = $delta['content'];
                }
            }
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);

    $full = implode('', $chunks);
    if (strpos($full, '</think>') !== false) {
        $full = trim(explode('</think>', $full, 2)[1]);
    }
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
                if ($escape)        { $escape = false; }
                elseif ($c === '\\') { $escape = true; }
                elseif ($c === '"') { $in_str = false; }
            } else {
                if ($c === '"')     { $in_str = true; }
                elseif ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
            }
        }
        if ($end === -1) return null;
        $candidate = substr($text, $start, $end - $start + 1);
    }

    $parsed = json_decode($candidate, true);
    if ($parsed) return $parsed;

    $fixed = '';
    $in_str = false;
    $escape = false;
    for ($i = 0, $n = strlen($candidate); $i < $n; $i++) {
        $c = $candidate[$i];
        if ($in_str) {
            if ($escape)          { $fixed .= $c; $escape = false; }
            elseif ($c === '\\')  { $fixed .= $c; $escape = true; }
            elseif ($c === '"')   { $fixed .= $c; $in_str = false; }
            elseif ($c === "\n")  { $fixed .= '\\n'; }
            elseif ($c === "\r")  { $fixed .= '\\r'; }
            elseif ($c === "\t")  { $fixed .= '\\t'; }
            else                  { $fixed .= $c; }
        } else {
            $fixed .= $c;
            if ($c === '"') $in_str = true;
        }
    }
    return json_decode($fixed, true);
}

$allowed_list = implode(', ', $ALLOWED_CMDS);

$SYSTEM_PROMPT = <<<SYS
You are a Linux shell agent operating inside a sandboxed directory. Your working directory is already set, always use relative paths.

On every turn you MUST reply with ONLY a single JSON object. No prose, no markdown fences, no explanations outside the JSON.

Shape:
{
  "thought": "short reasoning",
  "action": "shell" | "write" | "finish",
  "cmd": "a single shell command, only when action is shell",
  "path": "relative path, only when action is write",
  "content": "file content, only when action is write",
  "message": "only when action is finish"
}

Rules:
- Only these commands are allowed: $allowed_list
- No pipes, redirects, backticks, subshells, &&, ||, ;
- No absolute paths, no ~, no .. in arguments
- For creating files with content use the "write" action, NOT echo with redirect
- Do ONE action per turn. After each you receive a JSON result, then continue.
- When the user goal is fully complete, use action "finish"
SYS;

$user_goal = $argv[1] ?? 'list files in the current directory';

$messages = [
    ['role' => 'system', 'content' => $SYSTEM_PROMPT],
    ['role' => 'user',   'content' => $user_goal],
];

echo "Goal: $user_goal\n\n";

for ($i = 0; $i < $MAX_ITERATIONS; $i++) {
    echo "--- turn " . ($i + 1) . " ---\n";
    $raw = call_lumo($LUMO_URL, $API_KEY, $APP_VERSION, $MODEL, $messages, $TEMPERATURE, $MAX_TOKENS);

    if ($raw === '') { echo "empty response from lumo\n"; break; }

    $step = extract_json($raw);
    if (!$step || !isset($step['action'])) {
        echo "could not parse JSON, raw:\n$raw\n"; break;
    }

    echo "thought: " . ($step['thought'] ?? '') . "\n";
    echo "action:  " . $step['action'] . "\n";

    $messages[] = ['role' => 'assistant', 'content' => json_encode($step)];

    if ($step['action'] === 'finish') {
        echo "done: " . ($step['message'] ?? '') . "\n";
        break;
    }

    if ($step['action'] === 'shell') {
        echo "cmd:     " . ($step['cmd'] ?? '') . "\n";
        $result = run_shell($TOOLS_DIR, $step['cmd'] ?? '', $ALLOWED_CMDS);
    } elseif ($step['action'] === 'write') {
        $rel = $step['path'] ?? '';
        echo "write:   $rel\n";
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || $rel[0] === '~') {
            $result = ['ok' => false, 'error' => 'invalid path'];
        } else {
            $full = $TOOLS_DIR . '/' . ltrim($rel, '/');
            $dir = dirname($full);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $bytes = file_put_contents($full, $step['content'] ?? '');
            $result = $bytes === false
                ? ['ok' => false, 'error' => 'write failed']
                : ['ok' => true, 'bytes' => $bytes];
        }
    } else {
        $result = ['ok' => false, 'error' => 'unknown action'];
    }

    echo "result:  " . json_encode($result) . "\n\n";
    $messages[] = ['role' => 'user', 'content' => 'RESULT: ' . json_encode($result)];
}
