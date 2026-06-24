$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$logDir = Join-Path $root 'storage\logs\tunnels'
$serverPort = 8000
$websocketPort = 8080

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

$laravelUrlFile = Join-Path $logDir 'laravel-url.txt'
$websocketUrlFile = Join-Path $logDir 'websocket-url.txt'
$laravelLogFile = Join-Path $logDir 'laravel-tunnel.log'
$websocketLogFile = Join-Path $logDir 'websocket-tunnel.log'

Remove-Item -Force -ErrorAction SilentlyContinue $laravelUrlFile, $websocketUrlFile, $laravelLogFile, $websocketLogFile

function Test-CommandExists {
    param([string] $Command)

    return [bool] (Get-Command $Command -ErrorAction SilentlyContinue)
}

function Start-CmdWindow {
    param(
        [string] $Title,
        [string] $Command
    )

    Start-Process -FilePath 'cmd.exe' -ArgumentList @('/k', "title $Title && cd /d `"$root`" && $Command")
}

function Start-PowerShellWindow {
    param(
        [string] $Title,
        [string] $Script,
        [string] $Name,
        [string] $LocalUrl,
        [string] $LogFile,
        [string] $UrlFile
    )

    $argumentLine = "-NoExit -ExecutionPolicy Bypass -Command `"& { `$Host.UI.RawUI.WindowTitle = '$Title'; & '$Script' -Name '$Name' -LocalUrl '$LocalUrl' -LogFile '$LogFile' -UrlFile '$UrlFile' }`""

    Start-Process -FilePath 'powershell.exe' -ArgumentList $argumentLine
}

Clear-Host
Write-Host '============================================================'
Write-Host 'CRM Omnichannel Stack'
Write-Host '============================================================'
Write-Host ''
Write-Host 'This will open 4 terminals:'
Write-Host "  1. Laravel server    http://127.0.0.1:$serverPort"
Write-Host "  2. Reverb websocket  http://127.0.0.1:$websocketPort"
Write-Host '  3. Cloudflare tunnel for Laravel'
Write-Host '  4. Cloudflare tunnel for websocket'
Write-Host ''

if (-not (Test-CommandExists 'php')) {
    Write-Host 'ERROR: php not found in PATH.' -ForegroundColor Red
    return
}

if (-not (Test-CommandExists 'cloudflared')) {
    Write-Host 'ERROR: cloudflared not found in PATH.' -ForegroundColor Red
    Write-Host 'Install Cloudflare Tunnel first, or add cloudflared.exe to PATH.'
    return
}

Start-CmdWindow -Title 'CRM Laravel Server :8000' -Command "set PHP_CLI_SERVER_WORKERS=4&& php artisan serve --host=127.0.0.1 --port=$serverPort --no-reload"
Start-Sleep -Seconds 2

Start-CmdWindow -Title 'CRM Reverb WebSocket :8080' -Command "php artisan reverb:start --host=0.0.0.0 --port=$websocketPort"
Start-Sleep -Seconds 2

$tunnelScript = Join-Path $PSScriptRoot 'run-cloudflare-tunnel.ps1'

Start-PowerShellWindow `
    -Title 'Cloudflare Laravel Tunnel :8000' `
    -Script $tunnelScript `
    -Name 'Laravel Server' `
    -LocalUrl "http://127.0.0.1:$serverPort" `
    -LogFile $laravelLogFile `
    -UrlFile $laravelUrlFile
Start-Sleep -Seconds 2

Start-PowerShellWindow `
    -Title 'Cloudflare WebSocket Tunnel :8080' `
    -Script $tunnelScript `
    -Name 'WebSocket Server' `
    -LocalUrl "http://127.0.0.1:$websocketPort" `
    -LogFile $websocketLogFile `
    -UrlFile $websocketUrlFile

Write-Host ''
Write-Host 'Waiting for Cloudflare tunnel URLs...'
Write-Host ''

$laravelUrl = $null
$websocketUrl = $null

for ($i = 1; $i -le 90; $i++) {
    if (-not $laravelUrl -and (Test-Path $laravelUrlFile)) {
        $laravelUrl = (Get-Content $laravelUrlFile -First 1).Trim()
    }

    if (-not $websocketUrl -and (Test-Path $websocketUrlFile)) {
        $websocketUrl = (Get-Content $websocketUrlFile -First 1).Trim()
    }

    if ($laravelUrl -and $websocketUrl) {
        break
    }

    Start-Sleep -Seconds 1
}

Write-Host '============================================================'
Write-Host 'TUNNEL URLS' -ForegroundColor Green
Write-Host '============================================================'

if ($laravelUrl) {
    $laravelHost = ([Uri] $laravelUrl).Host

    Write-Host 'Laravel / Meta App URL:'
    Write-Host $laravelUrl -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'Meta App Domains:'
    Write-Host $laravelHost -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'Facebook Login OAuth Redirect URI:'
    Write-Host "$laravelUrl/auth/facebook/callback" -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'Messenger Webhook Callback URL:'
    Write-Host "$laravelUrl/api/webhook/facebook" -ForegroundColor Yellow
} else {
    Write-Host 'Laravel tunnel URL not detected yet.' -ForegroundColor Red
    Write-Host "Check: $laravelLogFile"
}

Write-Host ''

if ($websocketUrl) {
    Write-Host 'Reverb WebSocket URL:'
    Write-Host $websocketUrl -ForegroundColor Yellow
} else {
    Write-Host 'WebSocket tunnel URL not detected yet.' -ForegroundColor Red
    Write-Host "Check: $websocketLogFile"
}

Write-Host '============================================================'
Write-Host ''
Write-Host 'Meta setup:'
Write-Host '  App Domains = Laravel tunnel host without https://'
Write-Host '  OAuth Redirect URI = Laravel URL + /auth/facebook/callback'
Write-Host '  Webhook Callback URL = Laravel URL + /api/webhook/facebook'
Write-Host ''
Write-Host '.env setup:'
Write-Host '  APP_URL=Laravel tunnel URL'
Write-Host '  FACEBOOK_REDIRECT_URI="${APP_URL}/auth/facebook/callback"'
Write-Host '  FACEBOOK_LOGIN_CONFIG_ID=Facebook Login for Business configuration ID'
Write-Host '  FACEBOOK_LOGIN_SCOPES=email,public_profile,pages_show_list,pages_manage_metadata,pages_read_engagement,pages_messaging'
Write-Host '  MESSENGER_APP_ID="${FACEBOOK_CLIENT_ID}"'
Write-Host '  MESSENGER_APP_SECRET="${FACEBOOK_CLIENT_SECRET}"'
Write-Host '  REVERB_PUBLIC_HOST=WebSocket tunnel host without https://'
Write-Host '  REVERB_PUBLIC_SCHEME=wss'
Write-Host '  REVERB_PUBLIC_PORT='
Write-Host ''
Write-Host 'Then run: php artisan config:clear'
Write-Host ''
