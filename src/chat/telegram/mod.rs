use crate::chat::ChatClient;
use crate::chat::ChatError::{Deserialize, ServerError};
use crate::chat::Result;
use crate::game::{Answer, Callback, ChatGroup, Vote};
use crate::game::{Choice, FullUser};
use crate::http::client::Client;
use httparse::EMPTY_HEADER;
use log::error;
use serde_json::{json, Value};
use std::collections::HashMap;

pub mod update;

pub struct Telegram<'a> {
    client: Client<'a>,
    token: &'a str,
    hostname: &'a str,
    gamename: &'a str,
}

impl<'a> Telegram<'a> {
    pub fn new(hostname: &'a str, token: &'a str, gamename: &'a str) -> Result<Self> {
        let client = Client::new(hostname)?;
        Ok(Telegram {
            client,
            token,
            hostname,
            gamename,
        })
    }

    fn send_message(&self, ChatGroup(id): &ChatGroup, message: &str) -> Result<()> {
        let body = json!({
            "chat_id": id,
            "text": message
        });
        let _body = self.call_method("sendMessage", body)?;
        Ok(())
    }

    fn answer_callback_query(&self, Callback { id, .. }: &Callback, message: &str) -> Result<()> {
        let body = json!({
            "callback_query_id": id,
            "text": message
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }

    fn call_method(&self, method: &'static str, body: Value) -> Result<Value> {
        self.request("POST", method, body)
    }

    fn request(&self, verb: &'static str, path: &'a str, req_body: Value) -> Result<Value> {
        let uri = format!("https://{}/bot{}/{}", self.hostname, self.token, path);

        let req = http::Request::builder()
            .method(verb)
            .uri(&uri)
            .body(req_body.to_string())
            .unwrap_or_else(|_| {
                panic!(
                    "Failed to build request for telegram: {} {} {}",
                    verb,
                    &uri,
                    req_body.to_string()
                )
            });
        let mut header_buf = [EMPTY_HEADER; 100];
        let mut res = httparse::Response::new(&mut header_buf);
        let mut buf = Vec::new();
        // todo move content type header setting up here
        let (res, body) = self.client.send(req, &mut buf, &mut res)?;
        if res.code != Some(200) {
            error!(
                "Error response from telegram: {:?} {:?}, {:?}",
                res.code,
                res.reason,
                String::from_utf8(body.to_vec())
            );
            return Err(ServerError);
        }

        Ok(req_body)
    }
}

impl<'a> ChatClient for Telegram<'a> {
    fn already_in_game_error(&self, callback: &Callback) -> Result<()> {
        self.answer_callback_query(callback, "You are already in this game")
    }

    fn game_already_exists_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.send_message(
            chat_group,
            "Cannot start a new game, game already exists. Type /end to end current the game",
        )
    }

    fn game_does_not_exist_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.send_message(chat_group, "There is no game currently running")
    }

    fn require_at_least_three_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.send_message(chat_group, "You need at least 3 players to start the game")
    }

    fn start_message(&self, chat_group: &ChatGroup) -> Result<()> {
        self.send_message(chat_group, "Add this bot to a group chat and send /new@QuiplashModeratorBot to start a new game in the chat!")
    }

    fn join_game_message(&self, chat_group: &ChatGroup, users: &[FullUser]) -> Result<()> {
        let user_list: String = users
            .iter()
            .map(|user| format!("{}", user))
            .collect::<Vec<String>>()
            .join("\n");
        let message = format!(
            "Click the Join button below \n\
        Once everyone has joined, then type /begin to start the game\n\
        Players:\n\
        {}",
            user_list
        );

        let body = json!({
            "chat_id": chat_group.0,
            "text": message,
            "reply_markup": {
                "inline_keyboard": [
                    [
                        {
                            "text": "Join",
                            "callback_data": "/join_callback"
                        }
                    ]
                ]
            }
        });
        self.call_method("sendMessage", body)?;
        Ok(())
    }

    fn enter_prompts_message(&self, ChatGroup(id): &ChatGroup) -> Result<()> {
        let body = json!({
            "chat_id": id,
            "game_short_name": self.gamename,
            "disable_notification": false,
            "reply_markup": {
                "inline_keyboard": [
                    [
                        {
                            "text": "Enter your prompts",
                            "callback_game": true
                        }
                    ]
                ]
            }
        });
        self.call_method("sendGame", body)?;
        Ok(())
    }

    fn remaining_voters_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()> {
        let message = format!(
            "The following people still need to vote:\n{}",
            users
                .iter()
                .map(|user| format!("{}", user))
                .collect::<Vec<String>>()
                .join("\n")
        );
        self.send_message(chat_group, &message)?;
        Ok(())
    }

    fn remaining_answers_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()> {
        let message = format!(
            "The following people still need to answer their prompts:\n{}",
            users
                .iter()
                .map(|user| format!("{}", user))
                .collect::<Vec<String>>()
                .join("\n")
        );
        self.send_message(chat_group, &message)?;
        Ok(())
    }

    fn vote_message(&self, ChatGroup(id): &ChatGroup, answers: (&Answer, &Answer)) -> Result<()> {
        let (answer_a, answer_b) = answers;
        let fallback_response = "??".to_string();
        let response_a = &answer_a.response.as_ref().unwrap_or_else(|| {
            error!("Missing response for answer [token: {}]", answer_a.token);
            &fallback_response
        });
        let response_b = answer_b.response.as_ref().unwrap_or_else(|| {
            error!("Missing response for answer [token: {}]", answer_b.token);
            &fallback_response
        });

        let body = json!({
            "chat_id": id,
            "text": format!("{}:\nA: {}\nB: {}", answer_a.question.text, response_a, response_b),
            "reply_markup": {
                "inline_keyboard": [
                    [
                        {
                            "text": "A",
                            "callback_data": format!("/vote_callback {}", answer_a.token)
                        },
                        {
                            "text": "B",
                            "callback_data": format!("/vote_callback {}", answer_b.token)
                        }
                    ]
                ]
            }
        });
        self.call_method("sendMessage", body)?;
        Ok(())
    }

    fn round_results_message(
        &self,
        choice: &Choice,
        chat_group: &ChatGroup,
        votes: &[Vote],
        answers: &[Answer],
        users: &[FullUser],
    ) -> Result<()> {
        let answer_a = answers.iter().find(|answer| answer.token.eq(&choice.token));
        let answer_a = match answer_a {
            None => {
                error!("answer_a not found for choice: {:?}, {:?}", answers, choice);
                return Ok(());
            }
            Some(answer) => answer,
        };
        let answer_b = answers.iter().find(|answer| {
            answer.question.id.eq(&answer_a.question.id) && !answer.token.eq(&answer_a.token)
        });
        let answer_b = match answer_b {
            None => {
                error!(
                    "answer_b not found for question: {:?}, {:?}",
                    answers, answer_a.question
                );
                return Ok(());
            }
            Some(answer) => answer,
        };

        let mut sums: HashMap<&String, usize> = HashMap::new();
        for vote in votes.iter() {
            sums.insert(&vote.token, sums.get(&vote.token).unwrap_or(&0) + 1);
        }

        let users: HashMap<i64, &FullUser> = users.iter().map(|user| (user.id, user)).collect();

        let results = format!(
            "{} ({} +{})\n{} ({} +{})",
            answer_a.response.as_ref().unwrap(),
            users.get(&answer_a.user.id).unwrap(),
            sums.get(&answer_a.token).unwrap_or(&0),
            answer_b.response.as_ref().unwrap(),
            users.get(&answer_b.user.id).unwrap(),
            sums.get(&answer_b.token).unwrap_or(&0)
        );

        self.send_message(chat_group, &results)?;

        Ok(())
    }

    fn game_over_message(
        &self,
        chat_group: &ChatGroup,
        votes: &[Vote],
        answers: &[Answer],
        users: &[FullUser],
    ) -> Result<()> {
        let answers: HashMap<&String, i64> = answers
            .iter()
            .map(|answer| (&answer.token, answer.user.id))
            .collect();
        let votes: Vec<i64> = votes
            .iter()
            .map(|vote| *answers.get(&vote.token).unwrap())
            .collect();
        let mut summary: Vec<(&FullUser, usize)> = vec![];
        for user in users.iter() {
            let points = votes.iter().filter(|id| **id == user.id).count();
            summary.push((user, points));
        }

        summary.sort_by(|(_, points_a), (_, points_b)| points_a.cmp(points_b).reverse());

        let (winner, _) = summary.get(0).unwrap();

        let mut summary: Vec<String> = summary
            .iter()
            .map(|(user, points)| format!("{}: {} pts", user, points))
            .collect();

        summary.insert(0, format!("Game Over! Winner: {}", winner));
        let summary = summary.join("\n");

        self.send_message(chat_group, &summary)?;

        Ok(())
    }

    fn join_game_callback(&self, callback: &Callback) -> Result<()> {
        self.answer_callback_query(callback, "You have joined the game")
    }

    fn update_join_message(
        &self,
        ChatGroup(id): &ChatGroup,
        users: &[FullUser],
        Callback { message_id, .. }: &Callback,
    ) -> Result<()> {
        let user_list: String = users
            .iter()
            .map(|user| format!("{}", user))
            .collect::<Vec<String>>()
            .join("\n");
        let message = format!(
            "Click the Join button below \n\
        Once everyone has joined, then type /begin to start the game\n\
        Players:\n\
        {}",
            user_list
        );

        let message_id = match message_id {
            None => {
                error!("Missing message id in callback");
                return Err(Deserialize);
            }
            Some(message_id) => message_id,
        };

        let body = json!({
            "chat_id": id,
            "message_id": message_id,
            "text": message,
            "reply_markup": {
                "inline_keyboard": [
                    [
                        {
                            "text": "Join",
                            "callback_data": "/join_callback"
                        }
                    ]
                ]
            }
        });
        let _body = self.call_method("editMessageText", body)?;
        Ok(())
    }

    fn game_does_not_exist_callback(&self, callback: &Callback) -> Result<()> {
        self.answer_callback_query(callback, "This game is no longer valid")
    }

    fn launch_game_callback(&self, url: &str, Callback { id, .. }: &Callback) -> Result<()> {
        let message = "";
        let body = json!({
            "callback_query_id": id,
            "text": message,
            "url": url
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }

    fn cannot_vote_own_question_callback(&self, Callback { id, .. }: &Callback) -> Result<()> {
        let message = "You cannot vote for your own question";
        let body = json!({
            "callback_query_id": id,
            "text": message,
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }

    fn only_vote_once_callback(&self, Callback { id, .. }: &Callback) -> Result<()> {
        let message = "You can only vote once per question";
        let body = json!({
            "callback_query_id": id,
            "text": message,
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }

    fn not_in_game_callback(&self, Callback { id, .. }: &Callback) -> Result<()> {
        let message = "You must be a player in the current game to vote";
        let body = json!({
            "callback_query_id": id,
            "text": message,
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }

    fn only_current_question_callback(&self, Callback { id, .. }: &Callback) -> Result<()> {
        let message = "You can only vote for the current question";
        let body = json!({
            "callback_query_id": id,
            "text": message,
        });
        let _body = self.call_method("answerCallbackQuery", body)?;
        Ok(())
    }
}
