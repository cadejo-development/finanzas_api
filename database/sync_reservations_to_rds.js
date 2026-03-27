/**
 * sync_reservations_to_rds.js
 * Trae reservas desde Railway (via API) e inserta las que faltan en RDS mansion_db.
 * No borra nada — solo INSERT de ids que no existen en RDS.
 */
const https = require('https');
const { Client } = require('pg');

const API_BASE = 'https://cadejo-mansion-backend-production.up.railway.app/api/v1/reservations';
const PER_PAGE = 100;

const rdsConfig = {
  host: 'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',
  port: 5432,
  user: 'cadejo_admin',
  password: 'Holamundo#3..',
  database: 'mansion_db',
  ssl: { rejectUnauthorized: false },
};

function fetchPage(page) {
  return new Promise((resolve, reject) => {
    const url = `${API_BASE}?page=${page}&per_page=${PER_PAGE}`;
    https.get(url, (res) => {
      let body = '';
      res.on('data', d => body += d);
      res.on('end', () => {
        try { resolve(JSON.parse(body)); }
        catch (e) { reject(new Error('JSON parse error page ' + page)); }
      });
    }).on('error', reject);
  });
}

async function main() {
  const rds = new Client(rdsConfig);
  await rds.connect();
  console.log('RDS conectado.');

  // Cargar todos los IDs que ya existen en RDS
  const { rows: existing } = await rds.query('SELECT id FROM reservations');
  const existingIds = new Set(existing.map(r => Number(r.id)));
  console.log(`RDS: ${existingIds.size} reservas existentes (max id: ${Math.max(...existingIds)})`);

  // Paginar la API
  const first = await fetchPage(1);
  const lastPage = first.reservations.last_page;
  const total = first.reservations.total;
  console.log(`Railway API: ${total} reservas, ${lastPage} páginas`);

  let insertadas = 0;
  let saltadas = 0;

  const procesarPagina = async (data) => {
    const rows = data.reservations?.data ?? [];
    const nuevas = rows.filter(r => !existingIds.has(Number(r.id)));
    saltadas += rows.length - nuevas.length;

    for (const r of nuevas) {
      await rds.query(
        `INSERT INTO reservations
          (id, date, time_slot, shift, party_size, customer_name, customer_phone,
           customer_email, status, confirmed_at, checked_in_at, no_show_at, completed_at,
           notes, admin_notes, aud_usuario, created_at, updated_at, confirmation_number,
           mesa, terms_accepted_at, is_event)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22)
         ON CONFLICT (id) DO NOTHING`,
        [
          r.id,
          r.date ? r.date.slice(0, 10) : null,
          r.time_slot,
          r.shift,
          r.party_size,
          r.customer_name,
          r.customer_phone,
          r.customer_email,
          r.status,
          r.confirmed_at,
          r.checked_in_at,
          r.no_show_at,
          r.completed_at,
          r.notes,
          r.admin_notes,
          r.aud_usuario,
          r.created_at,
          r.updated_at,
          r.confirmation_number,
          r.mesa,
          r.terms_accepted_at,
          r.is_event ?? false,
        ]
      );
      existingIds.add(Number(r.id));
      insertadas++;
    }
  };

  // Procesar primera página ya descargada
  await procesarPagina(first);
  console.log(`Página 1/${lastPage} procesada`);

  // Resto de páginas
  for (let p = 2; p <= lastPage; p++) {
    const data = await fetchPage(p);
    await procesarPagina(data);
    if (p % 10 === 0 || p === lastPage) {
      console.log(`Página ${p}/${lastPage} | insertadas: ${insertadas} | saltadas: ${saltadas}`);
    }
  }

  await rds.end();

  console.log('\n==========================================');
  console.log(`✓ Sync completado`);
  console.log(`  Insertadas: ${insertadas}`);
  console.log(`  Ya existían: ${saltadas}`);
  console.log('==========================================');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
