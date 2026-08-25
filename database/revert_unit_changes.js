const {Pool} = require('pg');
const pg = new Pool({host:'cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com',port:5432,database:'compras_db',user:'cadejo_admin',password:'Holamundo#3..',ssl:{rejectUnauthorized:false}});

// Revertir solo las 5 unidades que cambiaron - dejar sub_receta_id correcto
const fixes = [
  { id: 121823, unidad: 'porcion' },
  { id: 121904, unidad: 'porcion' },
  { id: 121958, unidad: 'porcion' },
  { id: 121959, unidad: 'porcion' },
  { id: 122970, unidad: 'oz'      },
];

Promise.all(fixes.map(f =>
  pg.query('UPDATE receta_ingredientes SET unidad = $1 WHERE id = $2', [f.unidad, f.id])
    .then(() => console.log(`ri=${f.id} → unidad revertida a '${f.unidad}'`))
))
.then(() => { console.log('\nListo.'); pg.end(); })
.catch(e => { console.error(e.message); process.exit(1); });
