use crate::game;
use crate::game::{Answer, ChatGroup, Question, State, Vote};
use crate::game::{FullUser, User};
use crate::persistence::answer::Dao as AnswerDao;
use crate::persistence::postgres::Db;
use crate::persistence::user::Dao as UserDao;
use crate::persistence::vote::Dao as VoteDao;
use crate::persistence::{answer, user, vote, Result};
use log::{error, warn};

pub struct PqDao<'s> {
    db: Db<'s>,
    vote_dao: vote::PqDao<'s>,
    answer_dao: answer::PqDao<'s>,
    user_dao: user::PqDao<'s>,
}

impl<'s> PqDao<'s> {
    pub fn new(connection: &'s libpq::Connection) -> Self {
        let db = Db::new(connection);
        PqDao {
            db,
            vote_dao: vote::PqDao::new(connection),
            answer_dao: answer::PqDao::new(connection),
            user_dao: user::PqDao::new(connection),
        }
    }

    fn gather_votes(&self, id: i64) -> Result<game::State> {
        let answers = self.answer_dao.find(id)?;
        let votes = self.vote_dao.find(id)?;
        let users = self.user_dao.find(id)?;

        let res = self.db.exec_params(
            "SELECT a.user_id, q.id, q.text, a.token, a.response \
            FROM answer a \
            INNER JOIN game g ON (g.id = a.game_id) \
            INNER JOIN question q ON (a.question_id = q.id AND q.id = g.current_question_id) \
            WHERE g.id = $1",
            &[Box::new(Some(id))],
        )?;

        let state = game::State::GatherVotes {
            id,
            answers,
            users,
            current: (
                Answer {
                    user: User {
                        id: res.value_unchecked(0, 0)?,
                    },
                    question: Question {
                        id: res.value_unchecked(0, 1)?,
                        text: res.value_unchecked(0, 2)?,
                    },
                    token: res.value_unchecked(0, 3)?,
                    response: res.value(0, 4)?,
                },
                Answer {
                    user: User {
                        id: res.value_unchecked(1, 0)?,
                    },
                    question: Question {
                        id: res.value_unchecked(1, 1)?,
                        text: res.value_unchecked(1, 2)?,
                    },
                    token: res.value_unchecked(1, 3)?,
                    response: res.value(1, 4)?,
                },
            ),
            votes,
        };

        Ok(state)
    }

    fn gather_answers(&self, id: i64) -> Result<game::State> {
        let answers = self.answer_dao.find(id)?;
        let users = self.user_dao.find(id)?;

        let state = game::State::GatherAnswers { id, answers, users };
        Ok(state)
    }

    fn gather_users(&self, id: i64) -> Result<game::State> {
        let res = self.db.exec_params(
            "SELECT u.id, u.is_bot, u.first_name, u.last_name, u.username \
            FROM game_user gu \
            INNER JOIN \"user\" u ON (u.id=gu.user_id) \
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

        let state = game::State::GatherUsers { id, users };
        Ok(state)
    }

    fn end(&self, id: i64) -> Result<game::State> {
        Ok(game::State::End { id, votes: vec![] })
    }

    fn persist_new(&self, host_id: i64, chat_group: i64) -> Result<()> {
        let res = self.db.exec_params(
            "INSERT INTO game (host_id, chatgroup, state, gathering_users_started, warning_state_state, warning_state_warning_value) \
            VALUES ($1, $2, 'gather_users', now(), '', 0) RETURNING id",
            &[Box::new(Some(host_id)), Box::new(Some(chat_group))]
        )?;

        let game_id = res.value_unchecked::<i64>(0, 0)?;

        self.db.exec_params(
            "INSERT INTO game_user (game_id, user_id) VALUES ($1, $2)",
            &[Box::new(Some(game_id)), Box::new(Some(host_id))],
        )?;

        Ok(())
    }

    fn persist_gather_users(&self, id: i64, users: &[FullUser]) -> Result<()> {
        self.db.exec_params(
            "UPDATE game SET state = 'gather_users' WHERE id = $1",
            &[Box::new(Some(id))],
        )?;
        self.db.exec_params("UPDATE game SET gathering_users_started = now() WHERE id = $1 AND gathering_users_started IS NULL", &[Box::new(Some(id))])?;

        for user in users {
            self.db.exec_params(
                "INSERT INTO game_user (game_id, user_id) VALUES ($1, $2) ON CONFLICT (game_id, user_id) DO NOTHING",
                &[Box::new(Some(id)), Box::new(Some(user.id))],
            )?;
        }
        Ok(())
    }

    fn persist_gather_answers(&self, id: i64, answers: &[Answer]) -> Result<()> {
        self.db.exec_params(
            "UPDATE game SET state = 'gather_answers' WHERE id = $1",
            &[Box::new(Some(id))],
        )?;
        self.db.exec_params(
            "UPDATE game \
            SET gathering_answers_started = now() \
            WHERE id = $1 \
            AND gathering_answers_started IS NULL",
            &[Box::new(Some(id))],
        )?;

        self.answer_dao.save_all(id, answers)?;

        Ok(())
    }

    fn persist_gather_votes(
        &self,
        id: i64,
        votes: &[Vote],
        current: &(Answer, Answer),
    ) -> Result<()> {
        let (
            Answer {
                question: Question {
                    id: question_id, ..
                },
                ..
            },
            _,
        ) = current;

        self.db.exec_params(
            "UPDATE game SET state = 'gather_votes', current_question_id = $1 WHERE id = $2",
            &[Box::new(Some(*question_id)), Box::new(Some(id))],
        )?;
        self.db.exec_params(
            "UPDATE game \
            SET gathering_votes_started = now() \
            WHERE id = $1 \
            AND gathering_votes_started IS NULL",
            &[Box::new(Some(id))],
        )?;

        for Vote { token, user } in votes {
            self.db.exec_params("INSERT INTO vote (answer_id, question_id, user_id, game_id) \
                SELECT a.id as answer_id, a.question_id AS question_id, $1 as user_id, a.game_id AS game_id \
                FROM answer a \
                WHERE token = $2 \
                ON CONFLICT (user_id, answer_id) DO NOTHING", &[Box::new(Some(user.id)), Box::new(Some(token.clone()))])?;
        }

        Ok(())
    }

    fn persist_end(&self, id: i64) -> Result<()> {
        self.db.exec_params(
            "UPDATE game SET state = 'end' WHERE id = $1",
            &[Box::new(Some(id))],
        )?;
        Ok(())
    }
}

pub trait Dao {
    fn find_running(&self, chat_group: &ChatGroup) -> Result<Option<game::State>>;
    fn save(&self, game: &game::State) -> Result<()>;
}

impl Dao for PqDao<'_> {
    fn find_running(&self, ChatGroup(chat_group): &ChatGroup) -> Result<Option<game::State>> {
        let res = self.db.exec_params(
            "SELECT id, state FROM game WHERE chatgroup = $1 AND state != 'end' LIMIT 1",
            &[Box::new(Some(*chat_group))],
        )?;

        if res.ntuples() == 0 {
            warn!("No game found for group: {:?}", chat_group);
            return Ok(None);
        }

        let id = res.value_unchecked::<i64>(0, 0)?;
        let state = res.value_unchecked::<String>(0, 1)?;

        let state = match state.as_str() {
            "gather_users" => self.gather_users(id)?,
            "gather_answers" => self.gather_answers(id)?,
            "gather_votes" => self.gather_votes(id)?,
            "end" => self.end(id)?,
            other => {
                error!("Invalid state: {}", other);
                self.end(id)?
            }
        };

        Ok(Some(state))
    }

    fn save(&self, game: &game::State) -> Result<()> {
        match game {
            State::New {
                host: User { id: host_id },
                chat_group,
                ..
            } => {
                self.persist_new(*host_id, *chat_group)?;
            }
            State::GatherUsers { id, users, .. } => {
                self.persist_gather_users(*id, users)?;
            }
            State::GatherAnswers { id, answers, .. } => {
                self.persist_gather_answers(*id, answers)?;
            }
            State::GatherVotes {
                id, current, votes, ..
            } => {
                self.persist_gather_votes(*id, votes, current)?;
            }
            State::End { id, .. } => {
                self.persist_end(*id)?;
            }
        };
        Ok(())
    }
}
