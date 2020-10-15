use crate::game::FullUser;
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
    fn save(&self, user: &FullUser) -> Result<()>;
    fn find(&self, id: i64) -> Result<Vec<FullUser>>;
}

impl Dao for PqDao<'_> {
    fn save(&self, user: &FullUser) -> Result<()> {
        self.db.exec_params(
            "INSERT INTO \"user\" (id, is_bot, first_name, last_name, username) \
            VALUES ($1, $2, $3, $4, $5) \
            ON CONFLICT (id) DO UPDATE \
            SET first_name = $3, last_name = $4, username = $5",
            &[
                Box::new(Some(user.id)),
                Box::new(Some(user.is_bot)),
                Box::new(user.first_name.clone()),
                Box::new(user.last_name.clone()),
                Box::new(user.username.clone()),
            ],
        )?;
        Ok(())
    }

    fn find(&self, id: i64) -> Result<Vec<FullUser>> {
        let res = self.db.exec_params(
            "SELECT u.id, u.is_bot, u.first_name, u.last_name, u.username \
            FROM \"user\" u \
            INNER JOIN game_user gu ON (gu.user_id = u.id) \
            WHERE gu.game_id = $1",
            &[Box::new(Some(id))],
        )?;

        let mut users = vec![];
        for i in 0..res.ntuples() {
            users.push(FullUser {
                id: res.value_unchecked(i, 0)?,
                is_bot: res.value_unchecked(i, 1)?,
                first_name: res.value(i, 2)?,
                last_name: res.value(i, 3)?,
                username: res.value(i, 4)?,
            });
        }

        Ok(users)
    }
}
