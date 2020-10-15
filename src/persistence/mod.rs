use crate::persistence::postgres::PostgresError;
use crate::persistence::DaoError::Postgres;

#[derive(Debug)]
pub enum DaoError {
    Postgres(PostgresError),
}

impl From<PostgresError> for DaoError {
    fn from(err: PostgresError) -> Self {
        Postgres(err)
    }
}

pub type Result<T> = std::result::Result<T, DaoError>;

pub mod answer;
pub mod game;
pub(crate) mod postgres;
pub mod question;
pub mod user;
pub mod vote;

#[cfg(test)]
pub mod test;
