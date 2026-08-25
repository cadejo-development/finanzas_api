#!/bin/bash
# Correr esto en AWS CloudShell
# Compara departamento_id entre instancia backup (temp) y producción
# y genera los UPDATE statements

TEMP="cadejo-finanzas-db-temp.c7u6secoqxcn.us-east-2.rds.amazonaws.com"
PROD="cadejo-finanzas-db.c7u6secoqxcn.us-east-2.rds.amazonaws.com"
USER="cadejo_admin"
PASS="Holamundo#3.."
DB="core_db"

export PGPASSWORD="$PASS"

echo "Extrayendo departamentos del BACKUP (antes del script)..."
psql -h $TEMP -U $USER -d $DB -t -A -F'|' \
  -c "SELECT codigo, COALESCE(departamento_id::text, 'NULL') FROM empleados ORDER BY codigo" \
  > /tmp/backup_depts.txt

echo "Extrayendo departamentos de PRODUCCIÓN (actual)..."
psql -h $PROD -U $USER -d $DB -t -A -F'|' \
  -c "SELECT codigo, COALESCE(departamento_id::text, 'NULL') FROM empleados ORDER BY codigo" \
  > /tmp/prod_depts.txt

echo "Generando SQL de restauración..."
python3 - <<'PYEOF'
backup = {}
with open('/tmp/backup_depts.txt') as f:
    for line in f:
        line = line.strip()
        if '|' in line:
            parts = line.split('|')
            backup[parts[0]] = parts[1]

prod = {}
with open('/tmp/prod_depts.txt') as f:
    for line in f:
        line = line.strip()
        if '|' in line:
            parts = line.split('|')
            prod[parts[0]] = parts[1]

diffs = []
for codigo, dept_backup in backup.items():
    dept_prod = prod.get(codigo, 'NULL')
    if dept_backup != dept_prod:
        diffs.append((codigo, dept_backup, dept_prod))

print(f"-- Diferencias encontradas: {len(diffs)}")
print("BEGIN;")
for codigo, dept_backup, dept_prod in diffs:
    val = dept_backup if dept_backup != 'NULL' else 'NULL'
    print(f"-- {codigo}: backup={dept_backup} prod_actual={dept_prod}")
    print(f"UPDATE empleados SET departamento_id = {val}, updated_at = NOW(), aud_usuario = 'restauracion_backup_21jul' WHERE codigo = '{codigo}';")
print("COMMIT;")
print(f"-- Total: {len(diffs)} empleados a restaurar")
PYEOF
