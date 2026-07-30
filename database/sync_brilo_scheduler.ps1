# sync_brilo_scheduler.ps1
# Corre automaticamente a las 03:00 AM via Windows Task Scheduler.
# Prerrequisito: FortiClient VPN "CLIN" conectada (habilitar Auto Connect en FortiClient).
#
# Para registrar la tarea manualmente (ejecutar PowerShell como Administrador):
#   schtasks /create /tn "Cadejo-SyncBrilo" /tr "powershell -NonInteractive -ExecutionPolicy Bypass -File \"C:\Users\administrator\finanzas_api\database\sync_brilo_scheduler.ps1\"" /sc DAILY /st 03:00 /ru SYSTEM /f

$scriptDir  = "C:\Users\administrator\finanzas_api"
$nodeScript = "$scriptDir\database\sync_brilo_stock_inventario.js"
$logFile    = "$scriptDir\storage\logs\sync-brilo-stock.log"
$vpnHost    = $env:DB_HOST_ORIGEN   # IP/hostname del SQL Server de Brilo (leido del .env)

# ── Cargar variables del .env para obtener el host de Brilo ──────────────────
if (-not $vpnHost) {
    $envFile = "$scriptDir\.env"
    if (Test-Path $envFile) {
        Get-Content $envFile | ForEach-Object {
            if ($_ -match '^\s*DB_HOST_ORIGEN\s*=\s*(.+)') {
                $vpnHost = $Matches[1].Trim().Trim('"').Trim("'")
            }
        }
    }
}

function Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    Write-Host $line
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

# ── Verificar que la VPN esta activa probando conectividad al SQL Server ──────
Log "=== Inicio sync Brilo ==="

if ($vpnHost) {
    $ping = Test-Connection -ComputerName $vpnHost -Count 1 -Quiet -ErrorAction SilentlyContinue
    if (-not $ping) {
        Log "ERROR: No hay conectividad con $vpnHost. Verifica que FortiClient VPN 'CLIN' este conectada."
        Log "=== Sync abortado ==="
        exit 1
    }
    Log "VPN OK — conectividad con $vpnHost confirmada."
} else {
    Log "ADVERTENCIA: DB_HOST_ORIGEN no encontrado en .env, se omite verificacion de VPN."
}

# ── Sucursales a sincronizar (las mapeadas en sync_brilo_stock_inventario.js) ─
# Agregar aqui el sucursal_id cuando se habilite una nueva sucursal en el script Node.
$sucursales = @(3, 11)

$errores = 0
foreach ($sucId in $sucursales) {
    Log "Sincronizando sucursal #$sucId ..."
    $result = & node $nodeScript $sucId 2>&1
    $result | ForEach-Object { Log "  $_" }
    if ($LASTEXITCODE -ne 0) {
        Log "  ERROR en sucursal #$sucId (exit code $LASTEXITCODE)"
        $errores++
    } else {
        Log "  Sucursal #$sucId OK"
    }
}

if ($errores -gt 0) {
    Log "=== Sync completado con $errores error(es) ==="
    exit 1
} else {
    Log "=== Sync completado exitosamente ==="
    exit 0
}
