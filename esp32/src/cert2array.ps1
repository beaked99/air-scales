param(
  [string]$InputFile,
  [string]$ArrayName = "data",
  [string]$OutputFile = ""
)

$InputFile = Resolve-Path $InputFile

if (-not $OutputFile) {
  $OutputFile = "$ArrayName.h"
}

$data = [System.IO.File]::ReadAllBytes($InputFile)
$hexLines = $data | ForEach-Object { '0x{0:X2}' -f $_ }

$lineChunks = $hexLines -split '(.{1,16})' | Where-Object { $_ -ne "" }

$header = @()
$header += "unsigned char ${ArrayName}[] = {"
$header += ($lineChunks | ForEach-Object { "  $_," })
$header += "};"
$header += "unsigned int ${ArrayName}_len = $($data.Length);"

$header | Set-Content $OutputFile -Encoding ascii
Write-Host "✅ Wrote $OutputFile with array '$ArrayName' ($($data.Length) bytes)"
