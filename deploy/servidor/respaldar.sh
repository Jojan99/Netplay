#!/bin/bash
# Respaldo diario de Netplay/Netvula: MySQL, GenieACS (Mongo), archivos subidos y configuración.
# Guarda 14 días en /root/respaldos/diarios. Log en /root/respaldos/respaldo.log.
set -euo pipefail
DIA=$(date +%F_%H%M)
DEST=/root/respaldos/diarios/$DIA
mkdir -p "$DEST"
BASES=$(mysql -N -e "show databases" | grep -vE "^(information_schema|performance_schema|mysql|sys)$")
for b in $BASES; do
  # Una vista rota (apunta a tablas o columnas que ya no existen) corta mysqldump:
  # se salta y queda anotada. Hoy pasa con pruebaBd1.v_clients_company.
  SALTAR=""
  for v in $(mysql -N -e "select table_name from information_schema.tables where table_schema=\"$b\" and table_type=\"VIEW\" and table_comment like \"%invalid%\""); do
    SALTAR="$SALTAR --ignore-table=$b.$v"; echo "$(date "+%F %T") aviso: vista rota $b.$v (no se respalda)"
  done
  mysqldump --single-transaction --quick --routines --triggers --events $SALTAR "$b" | gzip -6 > "$DEST/mysql-$b.sql.gz"
  gunzip -c "$DEST/mysql-$b.sql.gz" | tail -1 | grep -q "Dump completed" || { echo "$(date "+%F %T") ERROR: volcado incompleto de $b"; exit 1; }
done
mongodump --quiet --db genieacs --archive="$DEST/mongo-genieacs.archive.gz" --gzip
tar -czf "$DEST/archivos-storage.tar.gz" -C /var/www/netplay/storage app
tar -czf "$DEST/config.tar.gz" --ignore-failed-read \
  /var/www/netplay/.env /var/www/whatsapp-service/.env /var/www/whatsapp-service/ecosystem.config.js \
  /etc/nginx/sites-available /etc/nginx/snippets /etc/ssl/cloudflare /etc/letsencrypt \
  /etc/wireguard /opt/genieacs/genieacs.env /etc/crontab /var/spool/cron/crontabs 2>/dev/null || true
tar -czf "$DEST/whatsapp-sesiones.tar.gz" --ignore-failed-read -C /var/www/whatsapp-service $(cd /var/www/whatsapp-service && ls -d auth_inst_* 2>/dev/null) 2>/dev/null || true
chmod -R go-rwx "$DEST"
find /root/respaldos/diarios -mindepth 1 -maxdepth 1 -type d -mtime +13 -exec rm -rf {} +
echo "$(date "+%F %T") ok $DEST $(du -sh "$DEST" | cut -f1)"
