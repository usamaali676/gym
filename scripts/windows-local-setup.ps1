<#
  Sets up and runs IronPulse (website + API for the mobile app) on a Windows machine with Laragon.
  Run it from PowerShell in the project folder:

      cd C:\laragon\www\gymApp
      powershell -ExecutionPolicy Bypass -File scripts\windows-local-setup.ps1

  What it does: updates the code from the branch below, installs PHP and JS dependencies, creates .env and a SQLite
  database, migrates and seeds it, builds the website assets, optionally renders the exercise demo clips, and starts the
  server on all network interfaces (so an Android emulator or a phone on your Wi-Fi can reach it).

  Written for Windows but NOT run on Windows by its author: read the output, and tell me where it stops if it does.
  Needs on PATH (Laragon's "Terminal" window has them): git, php 8.2+, composer, node 20+ and npm.
#>
param(
    [string]$Branch = 'claude/exciting-mayer-i78ysz',
    [int]$Port = 8000,
    [switch]$RenderClips,   # also render the exercise demo clips (needs ffmpeg and `npm i --no-save playwright`)
    [switch]$SkipPull       # do not touch git
)

$ErrorActionPreference = 'Stop'
function Step($text) { Write-Host "`n==> $text" -ForegroundColor Green }
function Need($cmd) { if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) { throw "'$cmd' was not found on PATH. Open Laragon's Terminal, or install it." } }

Set-Location (Split-Path -Parent $PSScriptRoot)
foreach ($c in 'git', 'php', 'composer', 'node', 'npm') { Need $c }

if (-not $SkipPull) {
    Step "Updating the code from branch $Branch"
    git fetch origin $Branch
    git checkout $Branch
    git pull origin $Branch
}

Step 'Installing PHP dependencies'
composer install --no-interaction --prefer-dist

if (-not (Test-Path .env)) {
    Step 'Creating .env'
    Copy-Item .env.example .env
    php artisan key:generate
}

Step 'Creating the SQLite database (database\database.sqlite)'
if (-not (Test-Path database\database.sqlite)) { New-Item -ItemType File database\database.sqlite | Out-Null }

Step 'Migrating and seeding (an existing database keeps its data; seeders are idempotent)'
php artisan migrate --seed --force

Step 'Installing JavaScript dependencies and building the website assets'
npm ci
npm run build

if ($RenderClips) {
    Step 'Rendering the exercise demo clips (about a minute per exercise)'
    npm i --no-save playwright
    npx playwright install chromium
    php artisan exercises:demo-manifest
    node scripts\render-exercise-demos.mjs --manifest=storage\app\demo-manifest.json --out=storage\app\demo-render
    php artisan exercises:import-demos storage\app\demo-render
}

php artisan storage:link 2>$null

$ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.*' -or $_.IPAddress -like '10.*' } | Select-Object -First 1).IPAddress
Step "Starting the server"
Write-Host "  Website:                    http://localhost:$Port"
Write-Host "  Android emulator reaches it at  http://10.0.2.2:$Port   (the app's debug default)"
if ($ip) { Write-Host "  A phone on your Wi-Fi:        http://${ip}:$Port   (flutter run --dart-define=API_BASE_URL=http://${ip}:$Port)" }
Write-Host "  Stop with Ctrl+C.`n"
php artisan serve --host=0.0.0.0 --port=$Port
