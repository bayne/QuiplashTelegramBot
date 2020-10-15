#!/bin/bash
RUST_BACKTRACE=1 TELEGRAM_TOKEN=$(cat /run/secrets/telegram_token) DB_DSN=postgres://postgres:$(cat /run/secrets/postgres_password)@postgres:5432/postgres app
