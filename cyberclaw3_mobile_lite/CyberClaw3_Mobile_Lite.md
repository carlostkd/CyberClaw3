# CyberClaw 3  for Termux, Lite

![cyberclaw3](../cyberclaw.png)

A stripped-down version of the CyberClaw Mobile without TOTP authentication. Relies on Android's sandboxing and per-command y/n confirmation as the only safety layers.

For full security (TOTP-gated), use `cyberclaw3_mobile.php`  instead.

## Why no TOTP here

On a non-rooted Android device, the worst an agent can do is:
- Mess with files in Termux home and `~/storage/shared`
- Make HTTP calls
- Drain battery

It cannot touch system directories, other apps, or the OS itself. The per-command y/n prompt catches the vast majority of mistakes, and the Android sandbox catches everything else. Adding TOTP on top of that is belt-and-suspenders for a low-risk environment.

Trade convenience for slightly less friction: no authenticator app needed, no 6-digit code on every run.

## Requirements

- Termux from F-Droid (not Play Store)
- Termux:API from F-Droid (optional, enables native popups and notifications)
- PHP 8.0+ with curl
- A Proton Lumo API key

## Install

```
pkg update && pkg upgrade
pkg install php curl termux-api
termux-setup-storage
```

The last command is optional, only needed if you want `~/storage/shared` access.

```
mkdir -p ~/lumo-agent && cd ~/lumo-agent
```

Copy `cyberclaw3_mobile_lite.php` into this folder, then edit it and fill in near the top:

```php
$API_KEY = 'your_lumo_api_key_here';
$MODEL   = 'lumo-garbage';
```

That's it. No TOTP setup required.

## Usage

```
php cyberclaw3_mobile_lite.php [--dry] [--yolo] [--continue <id>] "<goal>"
php cyberclaw3_mobile_lite.php --list
```

### Flags

| Flag                 | Description                                                 |
|----------------------|-------------------------------------------------------------|
| `--dry`              | Show proposed commands without executing anything           |
| `--yolo`             | Auto-approve every command, skip the y/n prompt entirely    |
| `--continue <id>`    | Resume a previous session                                   |
| `--list`             | List all past sessions                                      |
| `--help` / `-h`      | Print usage                                                 |

### Environment variables

| Variable            | Default          | Purpose                                     |
|---------------------|------------------|---------------------------------------------|
| `LUMO_AGENT_DIR`    | script dir       | Where log and sessions live                 |
| `LUMO_AGENT_WORK`   | same as above    | Working dir for shell commands              |

## Examples

**Basic, with y/n confirmation on each command**
```
php cyberclaw3_mobile_lite.php "list the 10 largest files in my Downloads folder"
```

**Preview a multi-step cleanup without executing**
```
LUMO_AGENT_WORK=~/storage/shared/Download \
php cyberclaw3_mobile_lite.php --dry "find and delete all .apk files older than 3 months"
```

**Fully autonomous for trusted read-only tasks**
```
php cyberclaw3_mobile_lite.php --yolo "check battery, show uptime, list running processes"
```

**Resume a previous session**
```
php cyberclaw3_mobile_lite.php --list
php cyberclaw3_mobile_lite.php --continue 20260418-143022-a1b3 "now also sort by size"
```

**SSH into a Pi and run diagnostics (y/n still catches mistakes)**
```
php cyberclaw3_mobile_lite.php "ssh pi@192.168.1.50 and run uptime, free -h, and df -h"
```

**Home Assistant API call**
```
php cyberclaw3_mobile_lite.php "call the HA REST API at http://ha.local:8123/api/states

 with my token from ~/.ha_token, show which lights are on"
```

## When to use `--yolo`

Only when all of these are true:
- The goal is clearly read-only (listing, checking, reading)
- You're running in a working dir that does not contain anything important
- You want to automate something repeatable

Never use `--yolo` for goals that involve deletion, moving files, ssh to production systems, or anything where a mistake would hurt.

## When to switch to the full (TOTP) version

- You start using the agent with `sudo` somehow (rooted phone, running on a Pi, etc.)
- You're accessing production systems via SSH and a stolen session could be leveraged
- You're triggering things on your Home Assistant that could have physical effects (unlock doors, toggle heating)
- You're storing sensitive tokens in the working dir

For anything beyond mobile file management and toy tasks, use `cyberclaw3_mobile.php`.

## Security reminder

Lite does not mean unsafe, but it does mean "small attack surface, not zero". The main risks on Termux Lite:

- An LLM hallucinating `rm -rf ~/` gets stopped by y/n, but NOT by `--yolo`
- Session files store command output including any secrets you happened to print. `chmod 700 sessions/` is still a good idea.


## Differences from the full version

| Feature                       | Full (`cyberclaw3_mobile.php`) | Lite (`cyberclaw3_mobile_lite.php`) |
|-------------------------------|---------------------------|--------------------------------|
| TOTP authentication           | required                  | removed                        |
| Per-command y/n prompt        | yes                       | yes (skippable with `--yolo`)  |
| `--dry` preview               | yes                       | yes                            |
| Session persistence/resume    | yes                       | yes                            |
| `--list` past sessions        | yes                       | yes                            |
| Termux:API popup/notification | yes                       | yes                            |
| `--yolo` auto-approve         | no                        | yes                            |
| Setup steps                   | 7                         | 3                              |
