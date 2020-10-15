#!/bin/sh
SECRETS_PATH=/etc/quiplash/secrets docker-compose --context pi up -d --build
