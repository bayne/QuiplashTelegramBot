use crate::game::{Answer, Callback, ChatGroup, Vote};
use crate::game::{Choice, FullUser};
use crate::http::client::ClientError;

pub mod telegram;

#[derive(Debug)]
pub enum ChatError {
    ClientError(ClientError),
    ServerError,
    Deserialize,
}

impl From<ClientError> for ChatError {
    fn from(error: ClientError) -> Self {
        ChatError::ClientError(error)
    }
}

pub type Result<T> = std::result::Result<T, ChatError>;

pub trait ChatClient {
    fn already_in_game_error(&self, callback: &Callback) -> Result<()>;
    fn game_already_exists_error(&self, chat_group: &ChatGroup) -> Result<()>;
    fn game_does_not_exist_error(&self, chat_group: &ChatGroup) -> Result<()>;
    fn require_at_least_three_error(&self, chat_group: &ChatGroup) -> Result<()>;
    fn start_message(&self, chat_group: &ChatGroup) -> Result<()>;
    fn join_game_message(&self, chat_group: &ChatGroup, users: &[FullUser]) -> Result<()>;
    fn enter_prompts_message(&self, chat_group: &ChatGroup) -> Result<()>;
    fn remaining_voters_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()>;
    fn remaining_answers_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()>;
    fn vote_message(&self, chat_group: &ChatGroup, answers: (&Answer, &Answer)) -> Result<()>;
    fn round_results_message(
        &self,
        choice: &Choice,
        chat_group: &ChatGroup,
        votes: &[Vote],
        answers: &[Answer],
        users: &[FullUser],
    ) -> Result<()>;
    fn game_over_message(
        &self,
        chat_group: &ChatGroup,
        votes: &[Vote],
        answers: &[Answer],
        users: &[FullUser],
    ) -> Result<()>;
    fn join_game_callback(&self, callback: &Callback) -> Result<()>;
    fn update_join_message(
        &self,
        chat_group: &ChatGroup,
        users: &[FullUser],
        callback: &Callback,
    ) -> Result<()>;
    fn game_does_not_exist_callback(&self, callback: &Callback) -> Result<()>;
    fn launch_game_callback(&self, token: &str, callback: &Callback) -> Result<()>;
    fn cannot_vote_own_question_callback(&self, callback: &Callback) -> Result<()>;
    fn only_vote_once_callback(&self, callback: &Callback) -> Result<()>;
    fn not_in_game_callback(&self, callback: &Callback) -> Result<()>;
    fn only_current_question_callback(&self, callback: &Callback) -> Result<()>;
}
