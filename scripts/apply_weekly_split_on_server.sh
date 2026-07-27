#!/usr/bin/env bash
# Canlı sunucuda proje kökünde çalıştırın (production .env ile):
#   chmod +x scripts/apply_weekly_split_on_server.sh
#   ./scripts/apply_weekly_split_on_server.sh
set -euo pipefail
cd "$(dirname "$0")/.."

BACKUP="storage/app/aylik_faaliyets_backup_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p storage/app

echo "==> Yedek: ${BACKUP}"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host = (string) config("database.connections.mysql.host");
$port = (string) config("database.connections.mysql.port");
$db = (string) config("database.connections.mysql.database");
$user = (string) config("database.connections.mysql.username");
$pass = (string) (config("database.connections.mysql.password") ?? "");
$file = $argv[1];
putenv("MYSQL_PWD=".$pass);
$cmd = sprintf(
    "mysqldump -h%s -P%s -u%s %s aylik_faaliyets",
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($db)
);
$out = shell_exec($cmd." 2>&1");
if ($out === null || $out === "") {
    fwrite(STDERR, "mysqldump boş döndü\n");
    exit(1);
}
file_put_contents($file, $out);
echo "backup_bytes=".filesize($file)."\n";
' "$BACKUP"

echo "==> Haftalık ayrım"
php scripts/split_merged_weekly_reports.php

echo "==> Özet"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$n = Illuminate\Support\Facades\DB::table("aylik_faaliyets")->count();
echo "toplam_rapor={$n}\n";
'
