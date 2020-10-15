#/bin/sh
SECRETS_PATH=/etc/quiplash/secrets docker-compose --context pi logs -t --tail 100 --follow app nginx
