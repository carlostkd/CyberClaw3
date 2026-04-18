<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(0);

$SECRET_FILE = __DIR__ . '/.totp_secret';
$ISSUER      = 'LumoAgent';
$ACCOUNT     = 'agent@localhost';

if (is_file($SECRET_FILE)) {
    echo "A secret already exists at: $SECRET_FILE\n";
    echo "Delete it first if you want to generate a new one. Aborting.\n";
    exit(1);
}

function base32_encode_rfc4648($bytes) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bits); $i < $n; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    while (strlen($out) % 8 !== 0) $out .= '=';
    return $out;
}

$random = random_bytes(20);
$secret = rtrim(base32_encode_rfc4648($random), '=');

if (file_put_contents($SECRET_FILE, $secret) === false) {
    fwrite(STDERR, "Failed to write secret file\n");
    exit(1);
}
chmod($SECRET_FILE, 0600);

$otpauth_uri = 'otpauth://totp/'
    . rawurlencode($ISSUER) . ':' . rawurlencode($ACCOUNT)
    . '?secret=' . $secret
    . '&issuer=' . rawurlencode($ISSUER)
    . '&algorithm=SHA1&digits=6&period=30';

$qr_url = 'https://quickchart.io/qr?size=300&text=' . rawurlencode($otpauth_uri);

echo "============================================================\n";
echo " TOTP secret generated\n";
echo "============================================================\n";
echo " File:    $SECRET_FILE (chmod 600)\n";
echo " Secret:  $secret\n";
echo " URI:     $otpauth_uri\n";
echo "------------------------------------------------------------\n";
echo " Option A: scan a QR code\n";
echo "   Open this URL in your browser and scan with any TOTP app\n";
echo "   (Aegis, Authy, Google Authenticator, 2FAS, etc.):\n";
echo "   $qr_url\n";
echo "------------------------------------------------------------\n";
echo " Option B: enter the secret manually in your app\n";
echo "   Account: $ACCOUNT\n";
echo "   Issuer:  $ISSUER\n";
echo "   Secret:  $secret\n";
echo "   Type:    Time-based, 6 digits, SHA1, 30s period\n";
echo "============================================================\n";
echo "\n";

function base32_decode_rfc4648($b32) {
    $b32 = strtoupper(preg_replace('/=+$/', '', trim($b32)));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) return false;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function totp_code($secret_b32, $time = null, $period = 30, $digits = 6) {
    $key = base32_decode_rfc4648($secret_b32);
    if ($key === false) return null;
    $t = intval(($time ?? time()) / $period);
    $bin = pack('N*', 0, $t);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset])   & 0x7f) << 24) |
        ((ord($hash[$offset+1]) & 0xff) << 16) |
        ((ord($hash[$offset+2]) & 0xff) << 8)  |
         (ord($hash[$offset+3]) & 0xff)
    ) % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

$now = time();
$current = totp_code($secret, $now);
$remaining = 30 - ($now % 30);
echo " Current code (sanity check): $current  (valid for {$remaining}s)\n";
echo " Confirm this matches the code in your authenticator app before using the agent.\n";
