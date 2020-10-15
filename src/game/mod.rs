use crate::game::DomainError::{AlreadyInGame, AtLeastThreePlayers, InvalidTransition};
use crate::game::VoteError::{Current, NotInGame, OnlyOnce, OwnQuestion};
use log::error;
use uuid::Uuid;

use crate::game::AnswerError::{AlreadyAnswered, NoneWithToken};
use std::collections::{HashMap, HashSet};
use std::fmt::{Display, Formatter};

#[derive(Debug)]
pub enum VoteError {
    NotInGame,
    OnlyOnce,
    OwnQuestion,
    Current,
}

#[derive(Debug)]
pub enum AnswerError {
    AlreadyAnswered,
    NoneWithToken,
}

#[derive(Debug)]
pub enum DomainError {
    AtLeastThreePlayers,
    InvalidTransition,
    AlreadyInGame,
    AnswerError(AnswerError),
    VoteError(VoteError),
}

pub type Result<T> = std::result::Result<T, DomainError>;

pub enum State {
    New {
        host: User,
        chat_group: i64,
        timer: bool,
    },
    GatherUsers {
        id: i64,
        users: Vec<FullUser>,
    },
    GatherAnswers {
        id: i64,
        answers: Vec<Answer>,
        users: Vec<FullUser>,
    },
    GatherVotes {
        id: i64,
        answers: Vec<Answer>,
        current: (Answer, Answer),
        votes: Vec<Vote>,
        users: Vec<FullUser>,
    },
    End {
        id: i64,
        votes: Vec<Vote>,
    },
}

#[derive(Clone, Debug)]
pub struct Question {
    pub id: i64,
    pub text: String,
}

#[derive(Clone, Debug)]
pub struct Answer {
    pub user: User,
    pub question: Question,
    pub token: String,
    pub response: Option<String>,
}

#[derive(Clone, Debug)]
pub struct Choice {
    pub token: String,
}

#[derive(Clone, Debug)]
pub struct Vote {
    pub token: String,
    pub user: User,
}

#[derive(Debug)]
pub struct ChatGroup(pub i64);

#[derive(Debug)]
pub struct Callback {
    pub id: i64,
    pub message_id: Option<i64>,
}

#[derive(Debug, Clone)]
pub struct User {
    pub id: i64,
}

impl PartialEq for User {
    fn eq(&self, other: &Self) -> bool {
        self.id.eq(&other.id)
    }
}

impl PartialEq for FullUser {
    fn eq(&self, other: &Self) -> bool {
        self.id.eq(&other.id)
    }
}

#[derive(Debug, Clone)]
pub struct FullUser {
    pub id: i64,
    pub is_bot: bool,
    pub first_name: Option<String>,
    pub last_name: Option<String>,
    pub username: Option<String>,
}

impl From<FullUser> for User {
    fn from(user: FullUser) -> Self {
        User { id: user.id }
    }
}

impl From<&FullUser> for User {
    fn from(user: &FullUser) -> Self {
        User { id: user.id }
    }
}

impl Display for FullUser {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        let id = &self.id.to_string();
        f.write_str(
            &self
                .first_name
                .as_ref()
                .unwrap_or_else(|| self.username.as_ref().unwrap_or(id)),
        )
    }
}

impl State {
    pub fn new(host: User, ChatGroup(chat_group): &ChatGroup, timer: bool) -> State {
        State::New {
            host,
            chat_group: *chat_group,
            timer,
        }
    }

    pub fn begin_game(&self, questions: &[Question]) -> Result<State> {
        let (id, users) = match self {
            State::GatherUsers { id, users } => (id, users),
            _ => return Err(InvalidTransition),
        };

        if users.len() < 3 {
            return Err(AtLeastThreePlayers);
        }

        let mut answers = vec![];
        for (i, question) in questions.iter().enumerate() {
            let user = users.get(i).unwrap();
            answers.push(Answer {
                user: user.into(),
                question: question.clone(),
                token: generate_token(),
                response: None,
            });
            let user = match users.get(i + 1) {
                None => users.get(0).unwrap(),
                Some(user) => user,
            };
            answers.push(Answer {
                user: user.into(),
                question: question.clone(),
                token: generate_token(),
                response: None,
            });
        }

        let state = State::GatherAnswers {
            id: *id,
            answers,
            users: users.to_owned(),
        };
        Ok(state)
    }

    pub fn join_game(&mut self, user: FullUser) -> Result<()> {
        match self {
            State::GatherUsers { users, .. } => {
                if users.contains(&user) {
                    return Err(AlreadyInGame);
                }
                users.push(user);
                Ok(())
            }
            _ => Err(InvalidTransition),
        }
    }

    pub fn answer_prompt(&mut self, token: &str, answer: &str) -> Result<()> {
        let (id, answers, users) = match self {
            State::GatherAnswers { id, answers, users } => (id, answers, users),
            _ => return Err(InvalidTransition),
        };

        answer_prompt(token, answer, answers)?;

        if all_answers_are_in(&answers) {
            *self = State::GatherVotes {
                id: *id,
                answers: answers.clone(),
                users: users.to_owned(),
                votes: vec![],
                current: next(answers, &[]),
            };
            Ok(())
        } else {
            *self = State::GatherAnswers {
                id: *id,
                answers: answers.clone(),
                users: users.to_owned(),
            };
            Ok(())
        }
    }

    pub fn vote(&mut self, user: &User, choice: &Choice) -> Result<()> {
        let (id, answers, current, votes, users) = match self {
            State::GatherVotes {
                id,
                answers,
                current,
                votes,
                users,
            } => (id, answers, current, votes, users),
            _ => return Err(InvalidTransition),
        };

        let (answer_a, answer_b) = current;

        if answer_a.token != choice.token && answer_b.token != choice.token {
            return Err(DomainError::VoteError(Current));
        }
        if already_voted(user, (&answer_a, &answer_b), votes) {
            return Err(DomainError::VoteError(OnlyOnce));
        }
        if not_in_game(user, answers) {
            return Err(DomainError::VoteError(NotInGame));
        }
        if own_question(user, (&answer_a, &answer_b)) {
            return Err(DomainError::VoteError(OwnQuestion));
        }

        vote(user, choice, votes);

        if all_votes_are_in(answers, votes) {
            *self = State::End {
                id: *id,
                votes: votes.to_owned(),
            };
            return Ok(());
        }

        if current_votes_are_in(&(answer_a, answer_b), answers, votes) {
            *self = State::GatherVotes {
                id: *id,
                answers: answers.to_owned(),
                current: next(answers, votes),
                votes: votes.to_owned(),
                users: users.to_owned(),
            };
            return Ok(());
        }

        Ok(())
    }

    pub fn end(&mut self) -> Result<()> {
        *self = State::End {
            id: self.id(),
            votes: vec![],
        };
        Ok(())
    }

    pub fn id(&self) -> i64 {
        match self {
            State::New { .. } => panic!("New game does not have an id"),
            State::GatherUsers { id, .. } => *id,
            State::GatherAnswers { id, .. } => *id,
            State::GatherVotes { id, .. } => *id,
            State::End { id, .. } => *id,
        }
    }

    pub fn remaining_voters(&self) -> Result<Vec<&FullUser>> {
        match self {
            State::GatherVotes {
                current: (answer_a, answer_b),
                answers,
                votes,
                users,
                ..
            } => {
                let user_ids = remaining_votes(&(answer_a, answer_b), answers, votes);
                let users = user_ids
                    .iter()
                    .map(|user_id| users.iter().find(|user| user.id.eq(user_id)))
                    .map(|user| user.unwrap())
                    .collect();
                Ok(users)
            }
            _ => {
                error!("Unexpected state when counting remaining voters");
                Err(InvalidTransition)
            }
        }
    }

    pub fn remaining_answerers(&self) -> Result<Vec<&FullUser>> {
        match self {
            State::GatherAnswers { answers, users, .. } => {
                let user_ids: HashSet<i64> = answers
                    .iter()
                    .filter(|answer| answer.response.is_none())
                    .map(|answer| answer.user.id)
                    .collect();
                let users = user_ids
                    .iter()
                    .map(|user_id| users.iter().find(|user| user.id.eq(user_id)))
                    .map(|user| user.unwrap())
                    .collect();
                Ok(users)
            }
            _ => {
                error!("Unexpected state when counting remaining answers");
                Err(InvalidTransition)
            }
        }
    }
}

fn next(answers: &[Answer], votes: &[Vote]) -> (Answer, Answer) {
    let mut voted = HashMap::new();
    for answer in answers {
        voted.insert(&answer.token, answer.question.id);
    }
    let voted: HashSet<i64> = votes
        .iter()
        .map(|vote| {
            *voted
                .get(&vote.token)
                .expect("vote should be mapped to answer")
        })
        .collect();

    let answer_a = answers
        .iter()
        .find(|answer| !voted.contains(&answer.question.id))
        .expect("There should be an answer that hasn't been voted yet");
    let answer_b = answers
        .iter()
        .find(|answer| answer.question.id == answer_a.question.id && answer.token != answer_a.token)
        .expect("Every answer should have an answer for the other player");

    (answer_a.clone(), answer_b.clone())
}

fn remaining_votes(current: &(&Answer, &Answer), answers: &[Answer], votes: &[Vote]) -> Vec<i64> {
    let (
        Answer {
            token: token_a,
            user: User { id: user_id_a },
            ..
        },
        Answer {
            token: token_b,
            user: User { id: user_id_b },
            ..
        },
    ) = current;
    let users: HashSet<i64> = answers.iter().map(|answer| answer.user.id).collect();
    let voters: HashSet<i64> = votes
        .iter()
        .filter(|vote| vote.token.eq(token_a) || vote.token.eq(token_b))
        .map(|vote| vote.user.id)
        .collect();

    users
        .difference(&voters)
        .filter(|user_id| !user_id_a.eq(*user_id) && !user_id_b.eq(*user_id))
        .map(|user_id| user_id.to_owned())
        .collect()
}

fn current_votes_are_in(current: &(&Answer, &Answer), answers: &[Answer], votes: &[Vote]) -> bool {
    remaining_votes(current, answers, votes).is_empty()
}

fn all_votes_are_in(answers: &[Answer], votes: &[Vote]) -> bool {
    let mut map = HashMap::new();
    for answer in answers {
        map.insert(&answer.token, answer.question.id);
    }

    let voted_questions: HashSet<i64> = votes
        .iter()
        .map(|vote| {
            let message = &format!("vote found that does not match any answers: {:?}", &vote);
            *map.get(&vote.token).expect(message)
        })
        .collect();
    let all_questions: HashSet<i64> = answers.iter().map(|answer| answer.question.id).collect();

    voted_questions.len() == all_questions.len()
}

fn own_question(user: &User, current: (&Answer, &Answer)) -> bool {
    let (
        Answer {
            user: User { id: user_id_a },
            ..
        },
        Answer {
            user: User { id: user_id_b },
            ..
        },
    ) = current;
    user.id.eq(user_id_a) || user.id.eq(user_id_b)
}

fn not_in_game(user: &User, answers: &[Answer]) -> bool {
    for answer in answers {
        if answer.user.id == user.id {
            return false;
        }
    }
    true
}

fn already_voted(user: &User, current: (&Answer, &Answer), votes: &[Vote]) -> bool {
    let (Answer { token: token_a, .. }, Answer { token: token_b, .. }) = current;
    votes
        .iter()
        .any(|vote| user.id == vote.user.id && (vote.token.eq(token_a) || vote.token.eq(token_b)))
}

fn all_answers_are_in(answers: &[Answer]) -> bool {
    answers.iter().all(|answer| answer.response.is_some())
}

fn answer_prompt(token: &str, response: &str, answers: &mut Vec<Answer>) -> Result<()> {
    let answer = answers.iter_mut().find(|answer| answer.token.eq(token));

    let user_id = match answer {
        None => {
            error!("No answer found for token: {}", token);
            return Err(DomainError::AnswerError(NoneWithToken));
        }
        Some(answer @ Answer { response: None, .. }) => {
            answer.response = Some(response.to_string());
            return Ok(());
        }
        Some(Answer {
            response: Some(_),
            user,
            ..
        }) => user.id,
    };

    let answer = answers
        .iter_mut()
        .find(|answer| user_id.eq(&answer.user.id) && answer.response.is_none());

    match answer {
        None => {
            error!("No unanswered question found for token: {}", token);
            Err(DomainError::AnswerError(AlreadyAnswered))
        }
        Some(answer) => {
            answer.response = Some(response.to_string());
            Ok(())
        }
    }
}

fn vote(user: &User, choice: &Choice, votes: &mut Vec<Vote>) {
    let vote = Vote {
        token: choice.token.clone(),
        user: User { id: user.id },
    };

    votes.push(vote);
}

fn generate_token() -> String {
    Uuid::new_v4().to_string()
}
