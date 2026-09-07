param([string]$PhpDirectory = 'C:\php', [int]$Port = 9000)
$ErrorActionPreference = 'Stop'
$executable = Join-Path $PhpDirectory 'php-cgi.exe'
if (!(Test-Path -LiteralPath $executable)) { throw "PHP FastCGI was not found at $executable" }
Write-Host "Starting Adelia PHP FastCGI on 127.0.0.1:$Port. Press Ctrl+C to stop."
Push-Location $PSScriptRoot
try {
    & $executable -b "127.0.0.1:$Port" -c (Join-Path $PhpDirectory 'php.ini') -d cgi.fix_pathinfo=0 -d upload_max_filesize=2M -d post_max_size=3M
} finally {
    Pop-Location
}
