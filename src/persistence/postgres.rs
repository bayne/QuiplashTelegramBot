use crate::persistence::postgres::PostgresError::{MissingValue, Parse, Utf8};
use libpq::{Format, Status};
use log::error;
use std::string::FromUtf8Error;

type Result<T> = std::result::Result<T, PostgresError>;

#[derive(Debug)]
pub enum PostgresError {
    MissingValue,
    Parse,
    Utf8(FromUtf8Error),
    Status(Status),
}

pub struct Db<'s> {
    connection: &'s libpq::Connection,
}

impl<'s> Db<'s> {
    pub fn new(connection: &'s libpq::Connection) -> Self {
        Db { connection }
    }

    pub fn exec_params(&self, sql: &str, params: &[Box<dyn ToSql>]) -> Result<Res> {
        let mut param_types = vec![];
        let mut param_values = vec![];
        for param in params {
            param_values.push(param.to_sql());
            param_types.push(param.oid());
        }

        let result =
            self.connection
                .exec_params(sql, &param_types, &param_values, &[], Format::Text);

        match result.status() {
            Status::TupplesOk | Status::SingleTuble | Status::CommandOk => Ok(Res(result)),
            other => {
                error!(
                    "error with query, status: {:?}, {:?}",
                    other,
                    self.connection.error_message()
                );
                Err(PostgresError::Status(other))
            }
        }
    }
}

pub trait ToSql {
    fn to_sql(&self) -> Option<Vec<u8>>;
    fn oid(&self) -> libpq::Oid;
}

impl ToSql for Option<String> {
    fn to_sql(&self) -> Option<Vec<u8>> {
        self.clone().map(|u| {
            let mut bytes = u.into_bytes();
            bytes.push(0);
            bytes
        })
    }

    fn oid(&self) -> u32 {
        libpq::types::TEXT.oid
    }
}

impl ToSql for Option<bool> {
    fn to_sql(&self) -> Option<Vec<u8>> {
        self.clone().map(|u| {
            let mut bytes = u.to_string().into_bytes();
            bytes.push(0);
            bytes
        })
    }

    fn oid(&self) -> u32 {
        libpq::types::BOOL.oid
    }
}

impl ToSql for Option<i64> {
    fn to_sql(&self) -> Option<Vec<u8>> {
        self.map(|u| {
            let mut bytes = u.to_string().into_bytes();
            bytes.push(0);
            bytes
        })
    }

    fn oid(&self) -> u32 {
        libpq::types::INT8.oid
    }
}

pub struct Res(libpq::Result);

impl Res {
    pub fn value_unchecked<T: FromSql>(&self, row: usize, column: usize) -> Result<T> {
        let Res(result) = self;
        let value = match result.value(row, column) {
            None => {
                error!("value missing: (row {}, col {})", row, column);
                return Err(MissingValue);
            }
            Some(value) => String::from_utf8(value.to_vec()),
        };

        match value {
            Err(err) => {
                error!("utf8 error: {:?})", err);
                return Err(Utf8(err));
            }
            Ok(value) => T::from_sql(&value),
        }
        .map_err(|_err| {
            error!("parse error for unchecked: (row {}, col {})", row, column);
            Parse
        })
    }

    pub fn value<T: FromSql>(&self, row: usize, column: usize) -> Result<Option<T>> {
        let Res(result) = self;
        let value = match result.value(row, column) {
            None => {
                return Ok(None);
            }
            Some(value) => String::from_utf8(value.to_vec()),
        };

        let value = match value {
            Err(err) => {
                error!("utf8 error: {:?})", err);
                return Err(Utf8(err));
            }
            Ok(value) => T::from_sql(&value),
        };

        match value {
            Err(_err) => {
                error!("parse error: (row {}, col {})", row, column);
                Err(Parse)
            }
            Ok(value) => Ok(Some(value)),
        }
    }

    pub fn ntuples(&self) -> usize {
        let Res(result) = self;
        result.ntuples()
    }
}

pub trait FromSql: Sized {
    fn from_sql(str: &str) -> Result<Self>;
}

impl FromSql for bool {
    fn from_sql(str: &str) -> Result<bool> {
        match str {
            "f" => Ok(false),
            "t" => Ok(true),
            other => {
                error!("Parse error from sql: {}", other);
                Err(Parse)
            }
        }
    }
}

impl FromSql for String {
    fn from_sql(str: &str) -> Result<String> {
        Ok(str.to_string())
    }
}

impl FromSql for i64 {
    fn from_sql(str: &str) -> Result<i64> {
        str.parse().map_err(|err| {
            error!("Parse error from sql: {:?}", err);
            Parse
        })
    }
}

impl FromSql for usize {
    fn from_sql(str: &str) -> Result<usize> {
        str.parse().map_err(|err| {
            error!("Parse error from sql: {:?}", err);
            Parse
        })
    }
}
