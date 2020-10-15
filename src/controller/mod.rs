use crate::chat::ChatClient;
use crate::chat::ChatError;
use crate::game::AnswerError::AlreadyAnswered;

use crate::game::{Callback, ChatGroup, Choice, DomainError, FullUser, State, User, VoteError};

use crate::persistence::DaoError;
use crate::{game, persistence};
use log::{error, info, warn};

#[cfg(test)]
mod test;

#[derive(Debug)]
pub enum ClientErrorReason {
    #[allow(dead_code)]
    AlreadyAnswered,
    #[allow(dead_code)]
    InvalidCommand,
    InvalidQueryParams,
}

#[derive(Debug)]
pub enum ControllerError {
    Telegram,
    Persistence,
    Domain(DomainError),
    #[allow(dead_code)]
    ClientError(ClientErrorReason),
}

pub type Result<T> = std::result::Result<T, ControllerError>;

impl From<ChatError> for ControllerError {
    fn from(_: ChatError) -> Self {
        error!("Failed due to telegram");
        ControllerError::Telegram
    }
}

impl From<DaoError> for ControllerError {
    fn from(_: DaoError) -> Self {
        error!("Failed due to persistence");
        ControllerError::Persistence
    }
}

impl From<DomainError> for ControllerError {
    fn from(err: DomainError) -> Self {
        info!("Failed due to domain error");
        ControllerError::Domain(err)
    }
}

pub struct Controller<'s> {
    game_dao: Box<dyn persistence::game::Dao + 's>,
    question_dao: Box<dyn persistence::question::Dao + 's>,
    answer_dao: Box<dyn persistence::answer::Dao + 's>,
    user_dao: Box<dyn persistence::user::Dao + 's>,
    vote_dao: Box<dyn persistence::vote::Dao + 's>,
    chat_client: Box<dyn ChatClient + 's>,
    app_url: String,
}

impl<'s> Controller<'s> {
    pub fn new(
        connection: &'s libpq::Connection,
        chat_client: Box<dyn ChatClient + 's>,
        app_url: &str,
    ) -> Self {
        // Maybe not box?
        let game_dao = Box::new(persistence::game::PqDao::new(connection));
        let question_dao = Box::new(persistence::question::PqDao::new(connection));
        let answer_dao = Box::new(persistence::answer::PqDao::new(connection));
        let user_dao = Box::new(persistence::user::PqDao::new(connection));
        let vote_dao = Box::new(persistence::vote::PqDao::new(connection));

        Controller {
            game_dao,
            question_dao,
            answer_dao,
            user_dao,
            chat_client,
            app_url: String::from(app_url),
            vote_dao,
        }
    }

    pub fn top_scores(&self) -> Result<()> {
        warn!("Top scores invoked however not implemented");
        Ok(())
    }

    pub fn status(&self, chat_group: ChatGroup) -> Result<()> {
        if let Some(state) = self.game_dao.find_running(&chat_group)? {
            match state {
                State::New { .. } => unreachable!(),
                State::GatherUsers { users, .. } => {
                    self.chat_client.join_game_message(&chat_group, &users)?;
                }
                State::GatherAnswers { .. } => {
                    self.chat_client.enter_prompts_message(&chat_group)?;
                    let users = state.remaining_answerers()?;
                    self.chat_client
                        .remaining_answers_message(&chat_group, &users)?;
                }
                State::GatherVotes {
                    current: (ref a, ref b),
                    ..
                } => {
                    let users = state.remaining_voters()?;
                    self.chat_client
                        .remaining_answers_message(&chat_group, &users)?;
                    self.chat_client.vote_message(&chat_group, (&a, &b))?;
                }
                State::End { id, .. } => {
                    let votes = self.vote_dao.find(id)?;
                    let answers = self.answer_dao.find(id)?;
                    let users = self.user_dao.find(id)?;
                    self.chat_client
                        .game_over_message(&chat_group, &votes, &answers, &users)?;
                }
            };
        } else {
            self.chat_client.game_does_not_exist_error(&chat_group)?;
        }

        Ok(())
    }

    pub fn start(&self, chat_group: ChatGroup) -> Result<()> {
        self.chat_client.start_message(&chat_group)?;
        Ok(())
    }

    pub fn new_game(&self, user: FullUser, chat_group: ChatGroup) -> Result<()> {
        self.user_dao.save(&user)?;

        if self.game_dao.find_running(&chat_group)?.is_some() {
            warn!(
                "Attempted to create a game that already exists: user {} group {:?}",
                user.id, chat_group
            );
            self.chat_client.game_already_exists_error(&chat_group)?;
            return Ok(());
        }

        let game_state = game::State::new(User { id: user.id }, &chat_group, false);

        self.game_dao.save(&game_state)?;

        info!("Game started {:?}", chat_group);
        self.chat_client.join_game_message(&chat_group, &[user])?;

        Ok(())
    }

    pub fn join_game(
        &self,
        user: FullUser,
        chat_group: ChatGroup,
        callback: Callback,
    ) -> Result<()> {
        self.user_dao.save(&user)?;

        let user_id = user.id;
        let mut game_state = match self.game_dao.find_running(&chat_group)? {
            None => {
                warn!(
                    "Attempted to join a non-existent game: user {} group {:?}",
                    user_id, chat_group
                );
                self.chat_client.game_does_not_exist_callback(&callback)?;
                return Ok(());
            }
            Some(game) => game,
        };

        info!("Joining game: {:?} {}", &user, game_state.id());
        match game_state.join_game(user) {
            Err(DomainError::AlreadyInGame) => {
                self.chat_client.already_in_game_error(&callback)?;
                Ok(())
            }
            Err(DomainError::InvalidTransition) => {
                warn!(
                    "Attempted to join a game in an invalid state: user {} group {:?}",
                    user_id, chat_group
                );
                self.chat_client.game_does_not_exist_callback(&callback)?;
                Ok(())
            }
            Err(_) => Ok(()),
            Ok(()) => {
                self.game_dao.save(&game_state)?;
                self.chat_client.join_game_callback(&callback)?;
                let users = self.user_dao.find(game_state.id())?;
                self.chat_client
                    .update_join_message(&chat_group, &users, &callback)?;
                Ok(())
            }
        }
    }

    pub fn begin_game(&self, chat_group: ChatGroup) -> Result<()> {
        let state = match self.game_dao.find_running(&chat_group)? {
            None => {
                warn!(
                    "Attempted to begin a non-existent game: group {:?}",
                    chat_group
                );
                self.chat_client.game_does_not_exist_error(&chat_group)?;
                return Ok(());
            }
            Some(state) => state,
        };

        let questions = self.question_dao.find_random(&state).map_err(|err| {
            warn!("Failed to get new set of questions: {:?}", chat_group);
            err
        })?;

        match state.begin_game(&questions) {
            Ok(state) => {
                self.game_dao.save(&state)?;
                self.chat_client.enter_prompts_message(&chat_group)?;
                Ok(())
            }
            Err(DomainError::AtLeastThreePlayers) => {
                self.chat_client.require_at_least_three_error(&chat_group)?;
                Ok(())
            }
            Err(err) => {
                error!("Unexpected error when beginning game: {:?}", err);
                Ok(())
            }
        }
    }

    pub fn launch_game(&self, user: User, chat_group: ChatGroup, callback: Callback) -> Result<()> {
        match self.answer_dao.find_token(&user, &chat_group)? {
            None => {
                warn!(
                    "Attempted to begin launch game in invalid state: user {:?} group {:?}",
                    user, &chat_group
                );
                self.chat_client.game_does_not_exist_callback(&callback)?;
                Ok(())
            }
            Some(token) => {
                let url = self.generate_url(&chat_group, &token);
                self.chat_client.launch_game_callback(&url, &callback)?;
                Ok(())
            }
        }
    }

    pub fn get_prompt(&self, token: String) -> Result<String> {
        match self.question_dao.find_with_token(&token)? {
            None => Err(ControllerError::Domain(DomainError::AnswerError(
                AlreadyAnswered,
            ))),
            Some(question) => Ok(question.text),
        }
    }

    pub fn post_prompt(&self, token: String, answer: String, chat_group: ChatGroup) -> Result<()> {
        let state = match self.game_dao.find_running(&chat_group)? {
            None => {
                return Ok(());
            }
            Some(mut state) => {
                state.answer_prompt(&token, &answer)?;
                state
            }
        };

        self.game_dao.save(&state)?;

        if let State::GatherVotes {
            id,
            current: (answer_a, answer_b),
            answers,
            ..
        } = &state
        {
            self.answer_dao.save_all(*id, answers)?;
            self.chat_client
                .vote_message(&chat_group, (answer_a, answer_b))?;
        }
        Ok(())
    }

    pub fn vote(
        &self,
        user: User,
        choice: Choice,
        chat_group: ChatGroup,
        callback: Callback,
    ) -> Result<()> {
        let state = match self.game_dao.find_running(&chat_group)? {
            None => return Ok(()),
            Some(mut state) => state.vote(&user, &choice).map(|_| state),
        };

        let state = match state {
            Ok(state) => state,
            Err(err @ DomainError::VoteError(VoteError::NotInGame)) => {
                info!(
                    "User not in game (user {:?}, chat_group {:?})",
                    &user, &chat_group
                );
                self.chat_client.not_in_game_callback(&callback)?;
                return Err(ControllerError::Domain(err));
            }
            Err(err @ DomainError::VoteError(VoteError::OnlyOnce)) => {
                info!(
                    "Can only vote once (user {:?}, chat_group {:?})",
                    &user, &chat_group
                );
                self.chat_client.only_vote_once_callback(&callback)?;
                return Err(ControllerError::Domain(err));
            }
            Err(err @ DomainError::VoteError(VoteError::Current)) => {
                info!(
                    "Can only vote for current question (user {:?}, chat_group {:?})",
                    &user, &chat_group
                );
                self.chat_client.only_current_question_callback(&callback)?;
                return Err(ControllerError::Domain(err));
            }
            Err(err @ DomainError::VoteError(VoteError::OwnQuestion)) => {
                info!(
                    "Cannot vote for own question (user {:?}, chat_group {:?})",
                    &user, &chat_group
                );
                self.chat_client
                    .cannot_vote_own_question_callback(&callback)?;
                return Err(ControllerError::Domain(err));
            }
            Err(err) => return Err(ControllerError::Domain(err)),
        };

        match &state {
            State::GatherVotes {
                id,
                current: current_vote_options,
                votes,
                answers,
                ..
            } => {
                let users = self.user_dao.find(*id).map_err(|err| {
                    error!(
                        "Could not get users for game {}. [reason={:?}]",
                        state.id(),
                        err
                    );
                    err
                })?;
                let (answer_a, answer_b) = current_vote_options;
                if answer_a.token != choice.token {
                    self.chat_client.round_results_message(
                        &choice,
                        &chat_group,
                        &votes,
                        &answers,
                        &users,
                    )?;
                    self.chat_client
                        .vote_message(&chat_group, (answer_a, answer_b))?;
                }
            }
            State::End { id, votes, .. } => {
                let answers = self.answer_dao.find(*id)?;
                let users = self.user_dao.find(*id)?;
                self.chat_client.round_results_message(
                    &choice,
                    &chat_group,
                    &votes,
                    &answers,
                    &users,
                )?;
                self.chat_client
                    .game_over_message(&chat_group, &votes, &answers, &users)?;
            }
            _ => {}
        }

        self.game_dao.save(&state)?;

        Ok(())
    }

    pub fn end(&self, chat_group: ChatGroup) -> Result<()> {
        let state = match self.game_dao.find_running(&chat_group)? {
            None => return Ok(()),
            Some(mut state) => {
                state.end()?;
                state
            }
        };

        self.game_dao.save(&state)?;
        Ok(())
    }

    pub fn generate_url(&self, ChatGroup(chat_group): &ChatGroup, token: &str) -> String {
        format!("{}/?group_id={}&token={}", self.app_url, chat_group, token)
    }
}
