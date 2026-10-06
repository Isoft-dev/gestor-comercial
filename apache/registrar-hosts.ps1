# Ejecutar una vez como administrador para resolver http://techstore.local
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$line = "127.0.0.1 techstore.local"
$content = Get-Content $hosts -Raw
if ($content -notmatch "techstore\.local") {
    Add-Content -Path $hosts -Value "`r`n$line"
    Write-Output "Se agregó techstore.local al archivo hosts."
} else {
    Write-Output "techstore.local ya existe en el archivo hosts."
}
