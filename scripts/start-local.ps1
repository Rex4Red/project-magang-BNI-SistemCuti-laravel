param([int]$Port = 3004)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $projectDirectory
$portablePhp = Join-Path $projectDirectory '.tools/php/php.exe'
$phpCommand = if (Test-Path -LiteralPath $portablePhp) { $portablePhp } else { (Get-Command php -ErrorAction Stop).Source }
if (!(Test-Path -LiteralPath 'vendor/autoload.php') -or !(Test-Path -LiteralPath 'public/build/index.html')) {
    throw 'Jalankan composer install dan npm run build terlebih dahulu.'
}
$localMysqlConfig = Join-Path $projectDirectory '.runtime/mysql/my.ini'
if (Test-Path -LiteralPath $localMysqlConfig) {
    if (!(Get-NetTCPConnection -LocalPort 3308 -State Listen -ErrorAction SilentlyContinue)) {
        $mysqlBinary = 'C:/xampp/mysql/bin/mysqld.exe'
        if (!(Test-Path -LiteralPath $mysqlBinary)) {
            throw 'Jalankan server MySQL sesuai koneksi .env.'
        }
        Start-Process -FilePath $mysqlBinary -ArgumentList ('--defaults-file="' + $localMysqlConfig + '"'), '--bind-address=127.0.0.1', '--console' -WindowStyle Hidden -RedirectStandardOutput '.runtime/mysql.stdout.log' -RedirectStandardError '.runtime/mysql.stderr.log'
        $mysqlDeadline = (Get-Date).AddSeconds(15)
        do {
            Start-Sleep -Milliseconds 250
            $mysqlReady = Get-NetTCPConnection -LocalPort 3308 -State Listen -ErrorAction SilentlyContinue
        } until ($mysqlReady -or (Get-Date) -ge $mysqlDeadline)
        if (!$mysqlReady) { throw 'MySQL belum siap. Periksa .runtime/mysql.stderr.log.' }
    }
}
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    Write-Host "Port $Port sudah digunakan. Periksa http://127.0.0.1:$Port atau pilih -Port lain."
    exit 0
}
& $phpCommand artisan serve --host=127.0.0.1 --port=$Port --no-reload
exit $LASTEXITCODE
