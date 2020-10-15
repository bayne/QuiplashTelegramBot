use std::env;
use std::env::VarError;

#[derive(Clone)]
pub struct Config {
    pub db_dsn: String,
    pub bind_addr: String,
    pub telegram_hostname: String,
    pub telegram_gamename: String,
    pub telegram_token: String,
    pub app_url: String,
}

pub enum ConfigError {
    MissingEnv(&'static str),
    InvalidEnvValue(&'static str),
}

impl Config {
    pub fn from_env() -> Result<Config, ConfigError> {
        Ok(Config {
            db_dsn: env_var("DB_DSN")?,
            bind_addr: env_var("BIND_ADDR")?,
            telegram_hostname: env_var("TELEGRAM_HOSTNAME")?,
            telegram_gamename: env_var("TELEGRAM_GAMENAME")?,
            telegram_token: env_var("TELEGRAM_TOKEN")?,
            app_url: env_var("APP_URL")?,
        })
    }
}

fn env_var(key: &'static str) -> Result<String, ConfigError> {
    env::var(key).map_err(|err| match err {
        VarError::NotPresent => ConfigError::MissingEnv(key),
        VarError::NotUnicode(_) => ConfigError::InvalidEnvValue(key),
    })
}
