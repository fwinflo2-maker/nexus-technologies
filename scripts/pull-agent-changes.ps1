# Applique la branche agent (KYC + Comptes + Support + liens) dans CE dossier.
# Usage (PowerShell) :
#   cd "C:\Users\Florenzo\Documents\project\nexus-technologies"
#   powershell -ExecutionPolicy Bypass -File .\scripts\pull-agent-changes.ps1

$ErrorActionPreference = "Stop"
$Branch = "cursor/superadmin-kyc-manual-override-92bd"
$Marker = "nexus-frontend\src\views\admin\AdminAccounts.tsx"

Write-Host "==> Dossier : $(Get-Location)"
if (-not (Test-Path ".git")) {
  Write-Error "Ce n'est pas un repo git. Place-toi dans C:\Users\Florenzo\Documents\project\nexus-technologies"
}

Write-Host "==> Sauvegarde du WIP local (stash)…"
git stash push -u -m "wip-avant-pull-agent-$(Get-Date -Format yyyyMMdd-HHmmss)" | Out-Host

Write-Host "==> Fetch origin…"
git fetch origin $Branch

Write-Host "==> Checkout $Branch…"
git checkout -B $Branch "origin/$Branch"

Write-Host "==> Reset hard sur origin/$Branch (force les fichiers locaux)…"
git reset --hard "origin/$Branch"

Write-Host "==> Verification…"
if (-not (Test-Path $Marker)) {
  Write-Error "Fichier manquant : $Marker"
}
$hit = Select-String -Path $Marker -Pattern "Suspendre" -SimpleMatch
if (-not $hit) {
  Write-Error "ECHEC : 'Suspendre' introuvable dans AdminAccounts.tsx — mauvaise branche."
}

Write-Host ""
Write-Host "OK — fichiers a jour sur $Branch"
Write-Host "  git rev-parse HEAD :"
git rev-parse HEAD
Write-Host "  Marker trouve :"
$hit | ForEach-Object { Write-Host "   L$($_.LineNumber): $($_.Line.Trim())" }
Write-Host ""
Write-Host "Ensuite redémarre PHP (:8080) et Vite (:5173) DEPUIS CE dossier."
Write-Host "Ton WIP est dans : git stash list"
