#!/usr/bin/env bash
#
# SynapseMail public demo build.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
#
# Builds dist/SynapseMail_demo_v<V>.zip from the release package plus plugins/synapse_demo:
# no mail server, sample mailbox, demo / demo + 2FA 123456 + passcode 1234, read-only admin panel at
# /admin/synapsemail-demo (admin / demo + 2FA 123456). Upload: extract into the demo site's folder and point
# the document root to its public_html folder.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP=${PHP:-php}
die() { echo "ABORT: $*" >&2; exit 1; }

tools/build_release.sh >/dev/null
V=$(sed -n "s/.*const VERSION = '\([0-9.]*\)'.*/\1/p" app/plugins/synapse_core/synapse_core.php)
SRC=dist/SynapseMail_v$V
OUT=dist/SynapseMail_demo_v$V
[[ -d $SRC ]] || die "release staging folder $SRC missing"
rm -rf "$OUT" "$OUT.zip"
cp -R "$SRC" "$OUT"
rsync -a --exclude '.DS_Store' app/plugins/synapse_demo/ "$OUT/plugins/synapse_demo/"
rm -f "$OUT/setup.key"

# database, admin account and configuration (fresh secrets every build)
"$PHP" -r '
  [, $root] = $argv;
  require "$root/plugins/synapse_core/lib/synapse_secret.php";
  require "$root/plugins/synapse_core/lib/synapse_totp.php";
  $des = substr(strtr(base64_encode(random_bytes(32)), "+/=", "xyz"), 0, 24);
  $pdo = new PDO("sqlite:$root/db/synapsemail.db", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $pdo->exec(file_get_contents("$root/SQL/sqlite.initial.sql"));
  $pdo->exec("CREATE TABLE IF NOT EXISTS synapse_admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(64) NOT NULL UNIQUE,"
    . " password_hash VARCHAR(255) NOT NULL, totp_secret TEXT NOT NULL, totp_last_step INTEGER NOT NULL DEFAULT 0,"
    . " recovery_codes TEXT NOT NULL, created_at INTEGER NOT NULL, last_login INTEGER)");
  $pdo->prepare("INSERT INTO synapse_admins (username, password_hash, totp_secret, recovery_codes, created_at) VALUES (?, ?, ?, ?, ?)")
    ->execute(["admin", password_hash("demo", PASSWORD_DEFAULT), synapse_secret::encrypt(synapse_totp::secret(), $des), "[]", time()]);
  chmod("$root/db/synapsemail.db", 0640);
  file_put_contents("$root/config/synapse.inc.php", "<?php\n// SynapseMail public demo (no mail server). Keep this file private.\n"
    . "\$config[\"des_key\"] = " . var_export($des, true) . ";\n"
    . "\$config[\"db_dsnw\"] = \"sqlite:///\" . dirname(__DIR__) . \"/db/synapsemail.db?mode=0640\";\n"
    . "\$config[\"imap_host\"] = \"demo.invalid\";\n"
    . "\$config[\"smtp_host\"] = \"tls://127.0.0.1:9\"; // never used: the demo blocks sending before SMTP\n"
    . "\$config[\"synapse_service_name\"] = \"SynapseMail Demo\";\n"
    . "\$config[\"synapse_admin_key\"] = \"synapsemail-demo\";\n");
  file_put_contents("$root/config/config.local.inc.php", "<?php\n// SynapseMail public demo switches (demo build only)\n"
    . "\$config[\"synapse_demo\"] = true;\n"
    . "\$config[\"storage_driver\"] = \"demo\";\n"
    . "\$config[\"plugins\"] = [\"synapse_demo\", \"synapse_core\", \"archive\", \"zipdownload\", \"synapse_preview\", \"synapse_editor\"];\n"
    . "\$config[\"synapse_update_url\"] = \"\";\n"
    . "\$config[\"enable_spellcheck\"] = false;\n"
    . "\$config[\"create_default_folders\"] = false;\n"
    . "\$config[\"archive_mbox\"] = \"Archive\";\n"
    . "\$config[\"max_message_size\"] = \"2M\";\n"
    . "\$config[\"support_url\"] = \"https://github.com/xcodz/SynapseMail\";\n"
    . "// the admin panel shows sample data from its own showcase database and log folder\n"
    . "if (defined(\"SYNAPSE_ADMIN\")) {\n"
    . "    \$config[\"db_dsnw\"] = \"sqlite:///\" . dirname(__DIR__) . \"/db/admin-demo.db?mode=0640\";\n"
    . "    \$config[\"log_dir\"] = dirname(__DIR__) . \"/db/admin-demo-logs/\";\n"
    . "}\n");
  touch("$root/config/installed.lock");
' "$OUT"

# read-only admin panel: showcase database and sample logs
DES=$("$PHP" -r '$config = []; include $argv[1]; echo $config["des_key"];' "$OUT/config/synapse.inc.php")
"$PHP" tools/demo-showcase.php "$OUT" "$DES" >/dev/null
chmod 0640 "$OUT/db/admin-demo.db"

# gates: demo secrets are per build, no private patterns, the demo plugin is present and switched on
[[ -f $OUT/plugins/synapse_demo/synapse_demo.php ]] || die "demo plugin missing"
grep -q '"synapse_demo"\] = true' "$OUT/config/config.local.inc.php" || die "demo switch missing"
PATTERNS=${SECRET_PATTERNS:-$HOME/.synapsemail/secret-patterns.txt}
if [[ -f $PATTERNS ]] && grep -rIlE -f "$PATTERNS" "$OUT" >/dev/null; then die "private pattern found in the demo build"; fi

(cd "$OUT" && zip -qr -X "../SynapseMail_demo_v$V.zip" .)
echo "dist/SynapseMail_demo_v$V.zip: $(du -h "dist/SynapseMail_demo_v$V.zip" | cut -f1)"
