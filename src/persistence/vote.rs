use crate::game::{User, Vote};
use crate::persistence::postgres::Db;
use crate::persistence::Result;

pub struct PqDao<'s> {
    db: Db<'s>,
}

impl<'s> PqDao<'s> {
    pub fn new(connection: &'s libpq::Connection) -> PqDao<'s> {
        PqDao {
            db: Db::new(connection),
        }
    }
}

pub trait Dao {
    fn find(&self, id: i64) -> Result<Vec<Vote>>;
}

impl Dao for PqDao<'_> {
    fn find(&self, id: i64) -> Result<Vec<Vote>> {
        let res = self.db.exec_params(
            "SELECT a.token, v.user_id \
        FROM vote v \
        INNER JOIN answer a ON (v.answer_id = a.id) \
        WHERE a.game_id = $1",
            &[Box::new(Some(id))],
        )?;

        let mut votes = vec![];
        for i in 0..res.ntuples() {
            votes.push(Vote {
                token: res.value_unchecked(i, 0)?,
                user: User {
                    id: res.value_unchecked(i, 1)?,
                },
            });
        }

        Ok(votes)
    }
}
