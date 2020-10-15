#!/bin/bash

SSH_TUNNEL_ENABLED=true
SSH_TUNNEL_USER=symfony-ockm
SSH_TUNNEL_HOST=tunnel.us1.frbit.com
SSH_TUNNEL_MYSQL_HOST=symfony-ockm.mysql.us1.frbit.com

MYSQL_HOST=127.0.0.1
MYSQL_PORT=13306
MYSQL_USER=symfony-ockm
MYSQL_DATABASE=symfony-ockm
MYSQL_EXPORT_DIR=mysql/export

#POSTGRES_HOST=127.0.0.1
POSTGRES_HOST=192.168.1.5
POSTGRES_PORT=5432
POSTGRES_USER=postgres
POSTGRES_DATABASE=postgres
POSTGRES_DDL=postgres/ddl.sql

TABLE_NAMES=(question user game answer game_user vote)
TABLE_NAMES_WITH_AUTO_INCREMENT_IDS=(question game answer vote)

MYSQL_PWD=`cat /etc/quiplash/secrets/mysql_pwd`
PGPASSWORD=`cat /etc/quiplash/secrets/postgres_password`
TELEGRAM_TOKEN=`cat /etc/quiplash/secrets/telegram_token`
export MYSQL_PWD
export PGPASSWORD
export TELEGRAM_TOKEN

echo "Creating TSV output directory"
mkdir -p $MYSQL_EXPORT_DIR;

# Lock and shutdown application
# extract mysql
## start tunnel
if [ $SSH_TUNNEL_ENABLED = true ]; then
  #echo "Testing SSH tunnel connection"
  #ssh "${SSH_TUNNEL_USER}@${SSH_TUNNEL_HOST}" "echo 'Connected successfully'" || { echo 'failed to test tunnel connection' ; exit 1; }
  #echo "Starting SSH tunnel"
  ssh -N -L 13306:${SSH_TUNNEL_MYSQL_HOST}:3306 ${SSH_TUNNEL_USER}@"${SSH_TUNNEL_HOST}" &
  SSH_TUNNEL_PID=$!;
  sleep 5;
  trap 'kill ${SSH_TUNNEL_PID}' EXIT
else
  echo "Skipped SSH Tunnel"
fi

## mysqldump to csv?
echo "Dumping MySQL database";
for table in "${TABLE_NAMES[@]}"; do
  echo "Dumping ${table}";
  echo "SELECT * FROM ${table}" | mysql --default-character-set=utf8mb4 --ssl-mode=DISABLED --protocol tcp -u ${MYSQL_USER} -h ${MYSQL_HOST} -P ${MYSQL_PORT} -D ${MYSQL_DATABASE} --raw > "${MYSQL_EXPORT_DIR}/${table}".tsv || exit 1;
  iconv -f iso88591 -t utf8 "${MYSQL_EXPORT_DIR}/${table}".tsv > "${MYSQL_EXPORT_DIR}/${table}-utf8".tsv
done

echo "Dropping and creating database"
echo "Creating database"
PSQL_COMMAND="psql --port ${POSTGRES_PORT} -h ${POSTGRES_HOST} ${POSTGRES_DATABASE} ${POSTGRES_USER}"
KILL_ALL="SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = '${POSTGRES_DATABASE}' AND pid <> pg_backend_pid();";
# Using the postgres db instead since we are deleting the target db
echo "$KILL_ALL; DROP SCHEMA public CASCADE; DROP DATABASE ${POSTGRES_DATABASE}; CREATE DATABASE ${POSTGRES_DATABASE}; CREATE SCHEMA public;" | psql --port ${POSTGRES_PORT} -h ${POSTGRES_HOST} postgres ${POSTGRES_USER} || exit 1
${PSQL_COMMAND} < ${POSTGRES_DDL} || exit 1

echo "Loading into PostgreSQL"
for table in "${TABLE_NAMES[@]}"; do
  echo "Loading ${table}";
  printf "\copy \"%s\" FROM '%s/%s-utf8.tsv' WITH DELIMITER '\t' CSV HEADER NULL 'NULL' QUOTE E'\b';" "${table}" "${MYSQL_EXPORT_DIR}" "${table}" | ${PSQL_COMMAND} || exit 1
done

for table in "${TABLE_NAMES_WITH_AUTO_INCREMENT_IDS[@]}"; do
  echo "Setting sequence ${table}";
  echo "SELECT setval('${table}_id_seq', (SELECT MAX(id) from ${table})+1);" | ${PSQL_COMMAND} || exit 1
done

SECRETS_PATH=/etc/quiplash/secrets docker-compose --context pi restart app

curl -X POST --location "https://api.telegram.org/bot${TELEGRAM_TOKEN}/deleteWebhook" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "drop_pending_updates=true"

curl -X POST --location "https://api.telegram.org/bot${TELEGRAM_TOKEN}/setWebhook" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "url=https://quiplash.telegram.southroute.dev/webhook"


