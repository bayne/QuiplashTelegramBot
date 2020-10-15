use libpq::Status;
use log::{error, warn};

fn seed_questions() {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let connection = libpq::Connection::new(dsn).unwrap();

    for _i in 0..10 {
        connection.exec("INSERT INTO question (text) VALUES ('')");
    }
}

pub fn init_db() {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let connection = libpq::Connection::new(dsn).unwrap();

    let result = connection.exec("CREATE SCHEMA public");
    let ddl = include_str!("../../../postgres/ddl.sql");
    let result = connection.exec(ddl);
    match result.status() {
        Status::TupplesOk | Status::SingleTuble | Status::CommandOk => (),
        other => {
            error!(
                "error with query, status: {:?}, {:?}",
                other,
                connection.error_message()
            );
        }
    }

    seed_questions();
}

pub fn clean_db() {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let connection = match libpq::Connection::new(dsn) {
        Ok(connection) => connection,
        Err(_err) => {
            warn!("Could not connect to testdb, recreating");
            let connection =
                libpq::Connection::new("postgres://postgres:example@localhost:5433/postgres")
                    .expect(
                        "Should be able to connect to default database for creating test database",
                    );
            connection.exec("CREATE DATABASE testdb");
            libpq::Connection::new(dsn).expect("testdb should now be created")
        }
    };

    let _result = connection.exec("DROP SCHEMA public CASCADE");
}

pub fn create_gather_answers_game() {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let connection = libpq::Connection::new(dsn).unwrap();

    let ddl = include_str!("./gather_answers_game.sql");
    let _result = connection.exec(ddl);
}
