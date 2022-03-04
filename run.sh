RUST_LOG=debug TELEGRAM_TOKEN=$(cat /etc/quiplash/secrets/telegram_token) DB_DSN=postgres://postgres:$(cat /etc/quiplash/secrets/postgres_password)@192.168.1.5:5432/postgres BIND_ADDR=0.0.0.0:8000 APP_URL=https://quiplash.telegram.southroute.dev TELEGRAM_HOSTNAME=api.telegram.org TELEGRAM_GAMENAME=quiplash cargo run