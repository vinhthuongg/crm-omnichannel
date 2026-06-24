param(
    [Parameter(Mandatory = $true)]
    [string] $Name,

    [Parameter(Mandatory = $true)]
    [string] $LocalUrl,

    [Parameter(Mandatory = $true)]
    [string] $LogFile,

    [Parameter(Mandatory = $true)]
    [string] $UrlFile
)

$ErrorActionPreference = 'Stop'

$logDir = Split-Path -Parent $LogFile
if ($logDir -and -not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
}

Remove-Item -Force -ErrorAction SilentlyContinue $LogFile, $UrlFile

Write-Host "============================================================"
Write-Host "$Name Cloudflare Tunnel"
Write-Host "Local: $LocalUrl"
Write-Host "Log:   $LogFile"
Write-Host "============================================================"
Write-Host ""

$urlPrinted = $false
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'

try {
    cloudflared tunnel --url $LocalUrl 2>&1 | ForEach-Object {
        $line = [string] $_
        $line | Tee-Object -FilePath $LogFile -Append

        if ($line -match 'https://[a-zA-Z0-9-]+\.trycloudflare\.com') {
            $url = $Matches[0]
            Set-Content -Path $UrlFile -Value $url

            if (-not $urlPrinted) {
                $urlPrinted = $true
                Write-Host ""
                Write-Host "============================================================" -ForegroundColor Green
                Write-Host "$Name TUNNEL URL:" -ForegroundColor Green
                Write-Host $url -ForegroundColor Yellow
                Write-Host "============================================================" -ForegroundColor Green
                Write-Host ""
            }
        }
    }
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}
