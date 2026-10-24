#!/usr/bin/env bash
set -euo pipefail
KEY=/root/.ssh/sot_vm_ed25519
TARGET=sardar@192.168.100.201
ssh -o StrictHostKeyChecking=no -i "$KEY" "$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
sudo php -r 'require "/var/www/superopticnet/config.php"; $db=app_db(); $ready=app_db_table_exists($db,"hr_positions"); echo json_encode(["table"=>$ready,"positions"=>$ready?(int)$db->query("SELECT COUNT(*) FROM hr_positions")->fetchColumn():0]);'
sudo systemctl disable --now superoptic-modem-bot.timer
sudo systemctl mask superoptic-modem-bot.service superoptic-modem-bot.timer
REMOTE
