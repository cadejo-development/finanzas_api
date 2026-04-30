/**
 * backup_daily.js
 *
 * Backup automático diario de compras_db (RDS PostgreSQL).
 *
 * Qué hace:
 *   1. Corre pg_dump para generar un .sql comprimido (.gz)
 *   2. Sube el archivo a S3 en la carpeta backups/compras_db/YYYY-MM-DD/
 *   3. Elimina backups locales y en S3 con más de RETENTION_DAYS días
 *
 * Configuración:
 *   - Editar la sección CONFIG al inicio del archivo
 *   - Requiere credenciales AWS configuradas (ver más abajo)
 *
 * Credenciales AWS (una de las dos opciones):
 *   Opción A: Variables de entorno (recomendado para Task Scheduler)
 *     AWS_ACCESS_KEY_ID=xxx
 *     AWS_SECRET_ACCESS_KEY=xxx
 *     AWS_REGION=us-east-2
 *   Opción B: Hardcodear en CONFIG.aws (solo para desarrollo)
 *
 * Uso manual:
 *   node backup_daily.js
 *   node backup_daily.js --no-s3      (solo backup local, sin subir a S3)
 *   node backup_daily.js --only-clean (solo limpiar backups viejos)
 *
 * Programar con Windows Task Scheduler:
 *   Ver instrucciones al final de este archivo.
 */

const { execSync, spawn } = require('child_process');
const fs   = require('fs');
const path = require('path');
const zlib = require('zlib');
const { S3Client, PutObjectCommand, ListObjectsV2Command, DeleteObjectsCommand } = require('@aws-sdk/client-s3');

// ══════════════════════════════════════════════════════════════════════════════
// CONFIGURACIÓN — editar según tu entorno
// ══════════════════════════════════════════════════════════════════════════════
const CONFIG = {
  // Base de datos
  pg: {
    host:     'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
    port:     5432,
    database: 'compras_db',
    user:     'cadejo_admin',
    password: 'Holamundo#3..',
  },

  // S3 — reemplaza con tu bucket real
  s3: {
    bucket:    'cadejo-backups',         // <-- CAMBIA esto por tu bucket de S3
    prefix:    'backups/compras_db/',    // carpeta dentro del bucket
    region:    'us-east-2',
    // Credenciales: si quedan null, se usan las variables de entorno AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY
    accessKeyId:     null,
    secretAccessKey: null,
  },

  // Retención
  retention: {
    localDays: 7,   // días a conservar localmente
    s3Days:    30,  // días a conservar en S3
  },

  // Carpeta local donde se guardan los backups
  localDir: path.join(__dirname, 'backups'),
};
// ══════════════════════════════════════════════════════════════════════════════

const NO_S3      = process.argv.includes('--no-s3');
const ONLY_CLEAN = process.argv.includes('--only-clean');

const ts  = () => new Date().toTimeString().slice(0, 8);
const log = s  => console.log(`[${ts()}] ${s}`);
const err = s  => console.error(`[${ts()}] ERROR: ${s}`);

// Formato YYYY-MM-DD
function dateStr(d = new Date()) {
  return d.toISOString().slice(0, 10);
}

// Inicializar S3 client
function makeS3() {
  const opts = { region: CONFIG.s3.region };
  if (CONFIG.s3.accessKeyId) {
    opts.credentials = {
      accessKeyId:     CONFIG.s3.accessKeyId,
      secretAccessKey: CONFIG.s3.secretAccessKey,
    };
  }
  return new S3Client(opts);
}

// ── 1. Crear backup local con pg_dump ─────────────────────────────────────────
async function runDump(outPath) {
  return new Promise((resolve, reject) => {
    log(`Ejecutando pg_dump → ${path.basename(outPath)}`);

    // pg_dump escribe a stdout, comprimimos con zlib en Node
    const env = {
      ...process.env,
      PGPASSWORD: CONFIG.pg.password,
    };

    const args = [
      '-h', CONFIG.pg.host,
      '-p', String(CONFIG.pg.port),
      '-U', CONFIG.pg.user,
      '-d', CONFIG.pg.database,
      '--no-password',
      '--format=plain',         // SQL legible
      '--verbose',
    ];

    const pg = spawn('pg_dump', args, { env });
    const gz = zlib.createGzip({ level: 9 });
    const out = fs.createWriteStream(outPath);

    pg.stdout.pipe(gz).pipe(out);

    let stderr = '';
    pg.stderr.on('data', d => { stderr += d.toString(); });

    pg.on('close', code => {
      if (code === 0) {
        const size = (fs.statSync(outPath).size / 1024 / 1024).toFixed(2);
        log(`  pg_dump OK — ${size} MB comprimido`);
        resolve();
      } else {
        reject(new Error(`pg_dump salió con código ${code}:\n${stderr.slice(-500)}`));
      }
    });

    pg.on('error', reject);
  });
}

// ── 2. Subir a S3 ─────────────────────────────────────────────────────────────
async function uploadToS3(s3, localPath, s3Key) {
  log(`Subiendo a s3://${CONFIG.s3.bucket}/${s3Key}...`);
  const body = fs.createReadStream(localPath);
  await s3.send(new PutObjectCommand({
    Bucket:      CONFIG.s3.bucket,
    Key:         s3Key,
    Body:        body,
    ContentType: 'application/gzip',
  }));
  log(`  Subida OK`);
}

// ── 3. Limpiar backups locales viejos ─────────────────────────────────────────
function cleanLocal() {
  if (!fs.existsSync(CONFIG.localDir)) return;
  const cutoff = Date.now() - CONFIG.retention.localDays * 24 * 60 * 60 * 1000;
  const files  = fs.readdirSync(CONFIG.localDir).filter(f => f.endsWith('.sql.gz'));
  let deleted  = 0;
  for (const f of files) {
    const fp   = path.join(CONFIG.localDir, f);
    const mtime = fs.statSync(fp).mtimeMs;
    if (mtime < cutoff) {
      fs.unlinkSync(fp);
      deleted++;
      log(`  Local eliminado: ${f}`);
    }
  }
  if (deleted === 0) log(`  Sin archivos locales viejos que eliminar.`);
  else               log(`  ${deleted} archivos locales eliminados.`);
}

// ── 4. Limpiar backups S3 viejos ──────────────────────────────────────────────
async function cleanS3(s3) {
  log(`Limpiando backups S3 con más de ${CONFIG.retention.s3Days} días...`);
  const cutoff = new Date(Date.now() - CONFIG.retention.s3Days * 24 * 60 * 60 * 1000);

  const listed = await s3.send(new ListObjectsV2Command({
    Bucket: CONFIG.s3.bucket,
    Prefix: CONFIG.s3.prefix,
  }));

  if (!listed.Contents || listed.Contents.length === 0) {
    log('  Sin objetos en S3 que revisar.');
    return;
  }

  const toDelete = listed.Contents
    .filter(obj => obj.LastModified < cutoff)
    .map(obj => ({ Key: obj.Key }));

  if (toDelete.length === 0) {
    log('  Sin backups viejos en S3.');
    return;
  }

  await s3.send(new DeleteObjectsCommand({
    Bucket: CONFIG.s3.bucket,
    Delete: { Objects: toDelete },
  }));

  log(`  ${toDelete.length} objetos eliminados de S3.`);
  toDelete.forEach(o => log(`    - ${o.Key}`));
}

// ── main ──────────────────────────────────────────────────────────────────────
async function main() {
  log('════════════════════════════════════════════');
  log('BACKUP DIARIO — compras_db');
  log(`Fecha: ${dateStr()}`);
  if (NO_S3)      log('Modo: solo local (--no-s3)');
  if (ONLY_CLEAN) log('Modo: solo limpieza (--only-clean)');
  log('════════════════════════════════════════════\n');

  // Crear carpeta local si no existe
  if (!fs.existsSync(CONFIG.localDir)) {
    fs.mkdirSync(CONFIG.localDir, { recursive: true });
    log(`Carpeta creada: ${CONFIG.localDir}`);
  }

  if (!ONLY_CLEAN) {
    // ── Generar backup ──────────────────────────────────────────────────────
    const fileName = `compras_db_${dateStr()}.sql.gz`;
    const localPath = path.join(CONFIG.localDir, fileName);

    if (fs.existsSync(localPath)) {
      log(`Ya existe backup de hoy (${fileName}), omitiendo pg_dump.`);
    } else {
      await runDump(localPath);
    }

    // ── Subir a S3 ──────────────────────────────────────────────────────────
    if (!NO_S3) {
      const s3    = makeS3();
      const s3Key = `${CONFIG.s3.prefix}${dateStr()}/${fileName}`;
      await uploadToS3(s3, localPath, s3Key);

      // Limpiar S3 viejos
      log('\nLimpiando S3...');
      await cleanS3(s3);
    }
  }

  // ── Limpiar local ───────────────────────────────────────────────────────────
  log('\nLimpiando backups locales viejos...');
  cleanLocal();

  log('\n════════════════════════════════════════════');
  log('✓ Backup completado.');
  log('════════════════════════════════════════════');
}

main().catch(e => {
  err(e.message);
  console.error(e.stack);
  process.exit(1);
});

/*
 * ══════════════════════════════════════════════════════════════════════════════
 * INSTRUCCIONES — Programar con Windows Task Scheduler
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Opción A — Desde PowerShell (ejecutar como Administrador):
 *
 *   $action  = New-ScheduledTaskAction `
 *                -Execute "node" `
 *                -Argument "C:\Users\administrator\finanzas_api\database\backup_daily.js" `
 *                -WorkingDirectory "C:\Users\administrator\finanzas_api\database"
 *
 *   $trigger = New-ScheduledTaskTrigger -Daily -At "02:00AM"
 *
 *   $settings = New-ScheduledTaskSettingsSet `
 *                 -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
 *                 -RestartCount 3 `
 *                 -RestartInterval (New-TimeSpan -Minutes 10)
 *
 *   Register-ScheduledTask `
 *     -TaskName "CadejoDBBackup" `
 *     -Action $action `
 *     -Trigger $trigger `
 *     -Settings $settings `
 *     -RunLevel Highest `
 *     -Force
 *
 * Opción B — Con variable de entorno para credenciales AWS:
 *   Agrega las variables al entorno del sistema antes de registrar:
 *     AWS_ACCESS_KEY_ID=AKIA...
 *     AWS_SECRET_ACCESS_KEY=...
 *     AWS_REGION=us-east-2
 *
 * Para verificar que funciona:
 *   node backup_daily.js --no-s3    ← prueba sin S3 primero
 *   node backup_daily.js            ← prueba completa con S3
 * ══════════════════════════════════════════════════════════════════════════════
 */
