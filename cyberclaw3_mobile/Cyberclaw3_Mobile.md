# CyberClaw 3  for Termux (Android)

A mobile port of the CyberClaw 3, running entirely inside Termux on Android. You describe a goal in natural language, the model proposes shell commands, you approve each one with a native Android popup (or stdin y/n), the agent executes and loops until done.

No root required. Works within the limits of non-rooted Android.

## What works (no root)

- Everything in Termux home (`~`)
- Shared storage: `~/storage/shared` (Downloads, Pictures, Documents, DCIM, etc.)
- Full unix toolchain: `ls`, `cat`, `grep`, `find`, `curl`, `wget`, `git`, `ssh`, `rsync`, `jq`, etc.
- `termux-*` helpers: notifications, clipboard, location, battery, SMS, toast, wifi info
- Python, Node, Ruby, or any language you `pkg install`
- SSH into your servers / Raspberry Pi and run commands there
- Hitting REST APIs (Home Assistant, your own services)

## What does NOT work (needs root)

- `sudo`, `su`, system directories (`/system`, `/vendor`)
- Other apps' private data (`/data/data/<other_app>`)
- Installing apk files, modifying system settings
- Raw packet capture, low-level hardware access

## Requirements

- Android device with Termux installed
- **Termux** (get from F-Droid, NOT Google Play, the Play Store version is deprecated and broken)
- **Termux:API** (optional but recommended, also from F-Droid)
- PHP 8.0 or newer with curl
- A working Proton Lumo API key
- Any TOTP authenticator app (Proton is great on Android)

## Install

### 1. Install Termux and Termux:API

From F-Droid:
- https://f-droid.org/en/packages/com.termux/
- https://f-droid.org/en/packages/com.termux.api/

### 2. Install packages inside Termux

Open Termux and run:

```
pkg update
pkg upgrade
pkg install php curl termux-api
```

### 3. Grant storage access (optional, only if you want to read/write shared storage)

```
termux-setup-storage
```

Accept the Android permission prompt. This creates `~/storage/shared` symlinked to your phone's internal storage.

### 4. Create the agent folder

```
mkdir -p ~/cyberclaw3
cd ~/cyberclaw3
```

Copy `cyberclaw3_mobile.php` and `totp-setup.php` into this folder. You can transfer them via:
- Syncthing
- `scp` from your computer
- Clone from a git repo: `git clone <your-repo> .`
- Open in Termux from a downloaded file: `cp ~/storage/shared/Download/cyberclaw3_mobile.php .`

### 5. Configure the API key

Open `cyberclaw3_mobile.php` and fill in near the top:

```php
$API_KEY = 'your_lumo_api_key_here';
```

### 6. Generate the TOTP secret

```
php totp-setup.php
```

The script prints a QR URL, a base32 secret, and the current 6-digit code.

### 7. Add the secret to your authenticator

Option A, scan the QR: open the printed `https://quickchart.io/qr?...` URL in a browser and scan with Aegis / 2FAS / Authy. Heads up, the secret transits quickchart.io when the QR is rendered.

Option B, enter manually: in your authenticator, add a new account, select "Enter setup key" or equivalent, and paste the base32 secret. Use SHA1, 6 digits, 30s period.

### 8. Sanity check

The setup script prints the current code. Confirm it matches your authenticator app exactly.

## Usage

```
php cyberclaw3_mobile.php [--dry] [--continue <id>] <otp> "<goal>"
php cyberclaw3_mobile.php --list
```

### Flags

| Flag                  | Description                                               |
|-----------------------|-----------------------------------------------------------|
| `--dry`               | Show every proposed command without executing anything   |
| `--continue <id>`     | Resume a previous session by ID                           |
| `--list`              | List all past sessions sorted by most recent              |
| `--help` or `-h`      | Print usage info                                          |

### Environment variables

| Variable            | Default                 | What it does                                |
|---------------------|-------------------------|---------------------------------------------|
| `LUMO_AGENT_DIR`    | script directory        | Where secret, log, and sessions are stored  |
| `LUMO_AGENT_WORK`   | same as `LUMO_AGENT_DIR`| Working dir for shell commands              |

Example:

```
export LUMO_AGENT_DIR=~/lumo-agent
export LUMO_AGENT_WORK=~/storage/shared/Download
php cyberclaw3_mobile.php 127384 "organize files by extension into subfolders"
```

## Examples

**List Downloads folder**
```
LUMO_AGENT_WORK=~/storage/shared/Download php cyberclaw3_mobile.php 127384 "show me the 10 largest files"
```

**Clean old screenshots**
```
LUMO_AGENT_WORK=~/storage/shared/Pictures/Screenshots php cyberclaw3_mobile.php --dry 489021 "find screenshots older than 6 months, list them grouped by month"
```
Review the plan, then re-run without `--dry`.

**SSH into your Pi and run diagnostics**
```
php cyberclaw3_mobile.php 672031 "ssh pi@192.168.1.50 and check disk usage, memory, and last 20 syslog entries"
```

**Interact with Home Assistant REST API**
```
php cyberclaw3_mobile.php 938104 "use curl to call http://homeassistant.local:8123/api/states with my HA token from ~/.ha_token and list all switches that are currently on"
```

**Use Termux:API features**
```
php cyberclaw3_mobile.php 217809 "get my current battery status and location, save both to a file in Downloads"
```

**List past sessions**
```
php cyberclaw3_mobile.php --list
```

**Resume a session**
```
php cyberclaw3_mobile.php --continue 20260418-143022-a1b3 649012 "now also upload the result to my server"
```

## Confirmation UX

The agent auto-detects whether Termux:API is available:

- **If installed**, you get a native Android popup for each command with a yes/no dialog. Nice for one-handed use.
- **If not installed**, falls back to typing `y` or `n` in the terminal (same as desktop).

When a session finishes, you get an Android notification with the summary (only if Termux:API is installed).

## Tips for mobile use

- **Shortcut widget**: add a Termux:Widget shortcut to your home screen with a script like:
  ```bash
  #!/data/data/com.termux/files/usr/bin/bash
  read -p "OTP: " otp
  read -p "Goal: " goal
  cd ~/lumo-agent
  php cyberclaw3_mobile.php "$otp" "$goal"
  ```
- **Keep-awake**: `termux-wake-lock` at the start of long-running sessions so Android does not kill Termux in the background.
- **Copy session IDs easily**: `php cyberclaw3_mobile.php --list | termux-clipboard-set` (then paste to pick the ID).
- **Voice input**: Any Keyboard with voice typing works fine in Termux, so you can dictate goals directly.

## Security

TOTP verification is mandatory, same as the desktop version. Every command needs explicit y/n approval. Session files can contain command output including secrets if you dump env / config, so:

```
chmod 600 .totp_secret
chmod 700 sessions/
chmod 600 agent.log
```

## Troubleshooting

**"Invalid TOTP code"**
Your phone's clock drifted from the TOTP reference time. Usually fixed by toggling automatic time in Android settings.

**y/n popup never appears**
`termux-api` package installed in Termux but Termux:API app (from F-Droid) not installed or not granted permissions. Check: `termux-notification --title test --content hello`. If that fails, reinstall the companion app.

**Cannot access ~/storage/shared**
Run `termux-setup-storage` and grant the storage permission. If it still fails, open Android settings > Apps > Termux > Permissions, grant Storage manually.

**Session files growing too big**
`rm sessions/*.json` to start fresh, or cherry-pick which ones to keep. The only session you actually need is the one you want to resume.

**Agent proposes commands that need root**
It will fail and return an error, the agent sees the failure and tries something else. If it keeps looping, reject with `n` and redirect it in your next goal.

## Known caveats

- Termux on Android 12+ sometimes gets killed aggressively in the background. Use `termux-wake-lock` or keep the session screen active.
- The first call on a cold Termux session can be slow due to JIT / package loading. Subsequent calls are snappy.
