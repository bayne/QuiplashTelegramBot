use log::error;
use log::info;
use std::process;

mod chat;
mod config;
mod controller;
mod game;
mod handler;
mod http;
mod persistence;
mod router;
mod threadpool;

use crate::config::{Config, ConfigError};
use crate::threadpool::ThreadPool;
use core::fmt;
use std::fmt::Formatter;
use std::net::TcpListener;

impl std::fmt::Display for ConfigError {
    fn fmt(&self, f: &mut Formatter<'_>) -> fmt::Result {
        match self {
            ConfigError::MissingEnv(name) => {
                f.write_fmt(format_args!("Missing environment variable {}", name))
            }
            ConfigError::InvalidEnvValue(name) => {
                f.write_fmt(format_args!("Invalid environment variable {}", name))
            }
        }
    }
}

fn main() {
    env_logger::init();

    let config = Config::from_env().unwrap_or_else(|err| {
        error!("Configuration error: {}", err);
        process::exit(1);
    });

    let listener = TcpListener::bind(&config.bind_addr).unwrap_or_else(|err| {
        error!("Failed to start server: {}", err);
        process::exit(1);
    });
    let pool = ThreadPool::new(4, config.clone());

    info!("Server started: {}", config.bind_addr);

    for stream in listener.incoming() {
        let stream = match stream {
            Ok(stream) => stream,
            Err(err) => {
                error!("Unknown error with TCP stream {:?}", err);
                continue;
            }
        };

        if let Err(err) = pool.handle(stream) {
            error!("Failed to handle stream: {:?}", err);
        }
    }
}
