use crate::game::{Question, State};
use crate::persistence::postgres::Db;
use crate::persistence::Result;
use rand::distributions::Uniform;
use rand::Rng;

pub struct PqDao<'s> {
    db: Db<'s>,
}

impl<'s> PqDao<'s> {
    pub fn new(connection: &'s libpq::Connection) -> PqDao<'s> {
        let db = Db::new(connection);
        PqDao { db }
    }
}

pub trait Dao {
    fn find_random(&self, state: &State) -> Result<Vec<Question>>;
    fn find_with_token(&self, token: &str) -> Result<Option<Question>>;
}

impl Dao for PqDao<'_> {
    fn find_random(&self, state: &State) -> Result<Vec<Question>> {
        let res = self.db.exec_params(
            "SELECT count(*) FROM game_user WHERE game_id = $1",
            &[Box::new(Some(state.id()))],
        )?;
        let count = res.value_unchecked::<usize>(0, 0)?;

        let res = self.db.exec_params("SELECT count(*) FROM question", &[])?;
        let question_count = res.value_unchecked::<i64>(0, 0)?;

        let dist = Uniform::from(1..question_count - 1);
        let ids: Vec<i64> = rand::thread_rng().sample_iter(&dist).take(count).collect();

        let mut questions = vec![];

        for id in ids {
            let res = self.db.exec_params(
                "SELECT q.id, q.text \
                FROM (SELECT ROW_NUMBER() OVER (ORDER BY id) AS rn, id FROM question) AS r \
                INNER JOIN question q ON (q.id = r.id) \
                WHERE r.rn = $1",
                &[Box::new(Some(id))],
            )?;
            questions.push(Question {
                id: res.value_unchecked(0, 0)?,
                text: res.value_unchecked(0, 1)?,
            });
        }

        Ok(questions)
    }

    fn find_with_token(&self, token: &str) -> Result<Option<Question>> {
        let res = self.db.exec_params(
            "SELECT q.id, q.text \
            FROM question q \
            INNER JOIN answer a ON (q.id = a.question_id) \
            INNER JOIN game g ON (a.game_id = g.id) \
            WHERE a.user_id IN (SELECT user_id FROM answer WHERE token = $1) \
            AND a.response IS NULL \
            AND g.state = 'gather_answers' \
            ORDER BY a.id",
            &[Box::new(Some(token.to_string()))],
        )?;

        if res.ntuples() == 0 {
            return Ok(None);
        }

        Ok(Some(Question {
            id: res.value_unchecked(0, 0)?,
            text: res.value_unchecked(0, 1)?,
        }))
    }
}

#[cfg(test)]
mod test {

    use crate::persistence::question::{Dao, PqDao};
    use crate::persistence::test::{clean_db, create_gather_answers_game, init_db};

    #[test]
    fn test_find_with_token() {
        clean_db();
        init_db();
        create_gather_answers_game();

        let dsn = "postgres://postgres:example@localhost:5433/testdb";
        let connection = libpq::Connection::new(dsn).unwrap();

        let dao = PqDao::new(&connection);
        let result = dao.find_with_token("u1q1").unwrap();

        assert!(result.is_some(), "Should have found question");

        connection.exec("UPDATE answer SET response = 'a' WHERE token = 'u1q1");

        let result = dao.find_with_token("u1q1").unwrap();

        assert!(result.is_some(), "Should have found question");
    }
}
