use crate::game::{Answer, Question, User};

use crate::game::ChatGroup;
use crate::persistence::postgres::Db;
use crate::persistence::Result;

pub trait Dao {
    fn find_token(&self, user: &User, chat_group: &ChatGroup) -> Result<Option<String>>;
    fn find(&self, id: i64) -> Result<Vec<Answer>>;
    fn save_all(&self, game_id: i64, answers: &[Answer]) -> Result<()>;
}

pub struct PqDao<'s> {
    db: Db<'s>,
}

impl<'s> PqDao<'s> {
    pub fn new(connection: &'s libpq::Connection) -> Self {
        let db = Db::new(connection);
        PqDao { db }
    }
}

impl Dao for PqDao<'_> {
    fn find_token(&self, user: &User, ChatGroup(chat_group): &ChatGroup) -> Result<Option<String>> {
        let res = self.db.exec_params(
            "SELECT a.token \
            FROM answer a \
            INNER JOIN game g ON (g.id = a.game_id) \
            WHERE a.user_id = $1 \
            AND g.chatgroup = $2 \
            AND g.state != 'end' \
            LIMIT 1",
            &[Box::new(Some(user.id)), Box::new(Some(*chat_group))],
        )?;
        Ok(res.value(0, 0)?)
    }

    fn find(&self, id: i64) -> Result<Vec<Answer>> {
        let res = self.db.exec_params(
            "SELECT a.user_id, q.id, q.text, a.token, a.response \
            FROM answer a \
            INNER JOIN question q ON (a.question_id = q.id) \
            WHERE a.game_id = $1 \
            ORDER BY a.id",
            &[Box::new(Some(id))],
        )?;

        let mut answers = vec![];
        for i in 0..res.ntuples() {
            answers.push(Answer {
                user: User {
                    id: res.value_unchecked(i, 0)?,
                },
                question: Question {
                    id: res.value_unchecked(i, 1)?,
                    text: res.value_unchecked(i, 2)?,
                },
                token: res.value_unchecked(i, 3)?,
                response: res.value(i, 4)?,
            });
        }

        Ok(answers)
    }

    fn save_all(&self, game_id: i64, answers: &[Answer]) -> Result<()> {
        for Answer {
            user,
            question,
            token,
            response,
        } in answers
        {
            self.db.exec_params(
                "INSERT INTO answer (user_id, question_id, game_id, response, token) \
                VALUES ($1, $2, $3, $4, $5) ON CONFLICT (token) DO UPDATE SET response = $4",
                &[
                    Box::new(Some(user.id)),
                    Box::new(Some(question.id)),
                    Box::new(Some(game_id)),
                    Box::new(response.clone()),
                    Box::new(Some(token.clone())),
                ],
            )?;
        }

        Ok(())
    }
}
