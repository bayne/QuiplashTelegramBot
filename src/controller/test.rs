use crate::chat::ChatClient;
use crate::chat::Result;
use crate::controller::Controller;
use crate::game::{Answer, Callback, ChatGroup, Vote};
use crate::game::{Choice, FullUser};
use crate::handler::DefaultHandler;
use crate::http::server::Handler;
use crate::persistence::postgres::Db;
use crate::persistence::test::{clean_db, init_db};
use crate::router::Router;
use http::Uri;

use serde_json::{json, Value};
use std::cell::RefCell;

struct CaptureChatClient<'s>(RefCell<&'s mut Vec<(String, Vec<String>)>>);

impl<'s> CaptureChatClient<'s> {
    fn capture(&self, name: &str, args: Vec<String>) {
        let CaptureChatClient(history) = self;
        history.borrow_mut().push((name.to_string(), args))
    }
}

impl<'s> ChatClient for CaptureChatClient<'s> {
    fn already_in_game_error(&self, callback: &Callback) -> Result<()> {
        self.capture("already_in_game_error", vec![format!("{:?}", callback)]);
        Ok(())
    }

    fn game_already_exists_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.capture(
            "game_already_exists_error",
            vec![format!("{:?}", chat_group)],
        );
        Ok(())
    }

    fn game_does_not_exist_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.capture(
            "game_does_not_exist_error",
            vec![format!("{:?}", chat_group)],
        );
        Ok(())
    }

    fn require_at_least_three_error(&self, chat_group: &ChatGroup) -> Result<()> {
        self.capture(
            "require_at_least_three_error",
            vec![format!("{:?}", chat_group)],
        );
        Ok(())
    }

    fn start_message(&self, chat_group: &ChatGroup) -> Result<()> {
        self.capture("start_message", vec![format!("{:?}", chat_group)]);
        Ok(())
    }

    fn join_game_message(&self, chat_group: &ChatGroup, users: &[FullUser]) -> Result<()> {
        self.capture(
            "join_game_message",
            vec![format!("{:?}", chat_group), format!("{:?}", users)],
        );
        Ok(())
    }

    fn enter_prompts_message(&self, chat_group: &ChatGroup) -> Result<()> {
        self.capture("enter_prompts_message", vec![format!("{:?}", chat_group)]);
        Ok(())
    }

    fn remaining_voters_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()> {
        self.capture(
            "remaining_voters_message",
            vec![format!("{:?}", chat_group), format!("{:?}", users)],
        );
        Ok(())
    }

    fn remaining_answers_message(&self, chat_group: &ChatGroup, users: &[&FullUser]) -> Result<()> {
        self.capture(
            "remaining_answers_message",
            vec![format!("{:?}", chat_group), format!("{:?}", users)],
        );
        Ok(())
    }

    fn vote_message(&self, chat_group: &ChatGroup, answers: (&Answer, &Answer)) -> Result<()> {
        self.capture(
            "vote_message",
            vec![
                format!("{:?}", chat_group),
                format!("{:?}", answers),
                answers.0.token.clone(),
                answers.1.token.clone(),
            ],
        );
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
        self.capture(
            "round_results_message",
            vec![
                format!("{:?}", choice),
                format!("{:?}", chat_group),
                format!("{:?}", votes),
                format!("{:?}", answers),
                format!("{:?}", users),
            ],
        );
        Ok(())
    }

    fn game_over_message(
        &self,
        chat_group: &ChatGroup,
        votes: &[Vote],
        answers: &[Answer],
        users: &[FullUser],
    ) -> Result<()> {
        self.capture(
            "game_over_message",
            vec![
                format!("{:?}", chat_group),
                format!("{:?}", votes),
                format!("{:?}", answers),
                format!("{:?}", users),
            ],
        );
        Ok(())
    }

    fn join_game_callback(&self, callback: &Callback) -> Result<()> {
        self.capture("join_game_callback", vec![format!("{:?}", callback)]);
        Ok(())
    }

    fn update_join_message(
        &self,
        _chat_group: &ChatGroup,
        _users: &[FullUser],
        _callback: &Callback,
    ) -> Result<()> {
        Ok(())
    }

    fn game_does_not_exist_callback(&self, callback: &Callback) -> Result<()> {
        self.capture(
            "game_does_not_exist_callback",
            vec![format!("{:?}", callback)],
        );
        Ok(())
    }

    fn launch_game_callback(&self, token: &str, callback: &Callback) -> Result<()> {
        self.capture(
            "launch_game_callback",
            vec![token.to_string(), format!("{:?}", callback)],
        );
        Ok(())
    }

    fn cannot_vote_own_question_callback(&self, callback: &Callback) -> Result<()> {
        self.capture("cannot_vote_own_question", vec![format!("{:?}", callback)]);
        Ok(())
    }

    fn only_vote_once_callback(&self, callback: &Callback) -> Result<()> {
        self.capture("only_vote_once_callback", vec![format!("{:?}", callback)]);
        Ok(())
    }

    fn not_in_game_callback(&self, callback: &Callback) -> Result<()> {
        self.capture("not_in_game_callback", vec![format!("{:?}", callback)]);
        Ok(())
    }

    fn only_current_question_callback(&self, callback: &Callback) -> Result<()> {
        self.capture(
            "only_current_question_callback",
            vec![format!("{:?}", callback)],
        );
        Ok(())
    }
}

fn send(captor: &mut Vec<(String, Vec<String>)>, body: Value) {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let host_url = "http://localhost";
    let connection = libpq::Connection::new(dsn).unwrap();
    let client = CaptureChatClient(RefCell::new(captor));
    let controller = Controller::new(&connection, Box::new(client), host_url.to_string());
    let router = Router::default();
    let handler = DefaultHandler::new(controller, router);
    handler
        .handle(
            "POST".to_string(),
            "/webhook".parse::<Uri>().unwrap(),
            vec![],
            body,
        )
        .unwrap();
}

fn send_new(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64) {
    send(
        captor,
        json!({
            "message": {
                "text": "/new",
                "from": {
                    "id": user_id,
                    "is_bot": false,
                },
                "chat": {
                    "id": chat_id
                }
            }
        }),
    );
}

fn send_join(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64) {
    send(
        captor,
        json!({
            "callback_query": {
                "id": 1,
                "data": "/join_callback",
                "message": {
                    "message_id": 1,
                    "chat": {
                        "id": chat_id
                    }
                },
                "from": {
                    "id": user_id,
                    "is_bot": false,
                },
            }
        }),
    );
}

fn send_begin(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64) {
    send(
        captor,
        json!({
            "message": {
                "id": 1,
                "text": "/begin",
                "chat": {
                    "id": chat_id
                },
                "from": {
                    "id": user_id,
                    "is_bot": false,
                },
            }
        }),
    );
}

fn send_launch_game(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64) {
    send(
        captor,
        json!({
            "callback_query": {
                "id": 1,
                "game_short_name": "quiplash",
                "message": {
                    "message_id": 1,
                    "chat": {
                        "id": chat_id
                    }
                },
                "from": {
                    "id": user_id,
                    "is_bot": false,
                },
            }
        }),
    );
}

fn send_post_prompt(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64) {
    let dsn = "postgres://postgres:example@localhost:5433/testdb";
    let host_url = "http://localhost";
    let connection = libpq::Connection::new(dsn).unwrap();
    let db = Db::new(&connection);
    let client = CaptureChatClient(RefCell::new(captor));
    let controller = Controller::new(&connection, Box::new(client), host_url.to_string());
    let res = db
        .exec_params(
            "SELECT a.token FROM answer a WHERE a.user_id=$1",
            &[Box::new(Some(user_id))],
        )
        .unwrap();
    assert_eq!(
        res.ntuples(),
        2,
        "Expecting only two results for tokens per user"
    );
    controller
        .post_prompt(
            res.value_unchecked(0, 0).unwrap(),
            format!("answer{}-{}", user_id, 0),
            ChatGroup(chat_id),
        )
        .unwrap();

    controller
        .post_prompt(
            res.value_unchecked(1, 0).unwrap(),
            format!("answer{}-{}", user_id, 1),
            ChatGroup(chat_id),
        )
        .unwrap();
}

fn send_vote(captor: &mut Vec<(String, Vec<String>)>, user_id: i64, chat_id: i64, token: String) {
    send(
        captor,
        json!({
            "callback_query": {
                "id": 1,
                "data": format!("/vote_callback {}", token),
                "message": {
                    "chat": {
                        "id": chat_id
                    }
                },
                "from": {
                    "id": user_id,
                    "is_bot": false,
                },
            }
        }),
    );
}

fn next_tokens(captor: &[(String, Vec<String>)]) -> (String, String) {
    captor
        .iter()
        .find(|(method, _)| method == "vote_message")
        .map(|(_, args)| match args.as_slice() {
            [_, _, token_a, token_b] => (token_a.clone(), token_b.clone()),
            _ => unreachable!(),
        })
        .unwrap()
}

#[test]
fn full_game() {
    env_logger::init();
    clean_db();
    init_db();

    let mut captor = vec![];

    send_new(&mut captor, 1, 1);
    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "join_game_message");

    send_join(&mut captor, 2, 1);
    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "join_game_callback");

    send_join(&mut captor, 3, 1);
    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "join_game_callback");

    send_begin(&mut captor, 1, 1);
    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "enter_prompts_message");

    send_launch_game(&mut captor, 1, 1);
    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "launch_game_callback");

    send_post_prompt(&mut captor, 1, 1);
    send_post_prompt(&mut captor, 2, 1);
    send_post_prompt(&mut captor, 3, 1);

    let (token_a, token_b) = next_tokens(&captor);
    captor.pop().unwrap();

    send_vote(&mut captor, 1, 1, token_a.clone());
    send_vote(&mut captor, 1, 1, token_b.clone());

    send_vote(&mut captor, 2, 1, token_a.clone());
    send_vote(&mut captor, 2, 1, token_b.clone());

    send_vote(&mut captor, 3, 1, token_a);
    send_vote(&mut captor, 3, 1, token_b);

    let (token_a, token_b) = next_tokens(&captor);
    captor.pop().unwrap();
    captor.pop().unwrap();
    send_vote(&mut captor, 1, 1, token_a.clone());
    send_vote(&mut captor, 1, 1, token_b.clone());

    send_vote(&mut captor, 2, 1, token_a.clone());
    send_vote(&mut captor, 2, 1, token_b.clone());

    send_vote(&mut captor, 3, 1, token_a);
    send_vote(&mut captor, 3, 1, token_b);

    let (token_a, token_b) = next_tokens(&captor);
    captor.pop().unwrap();
    captor.pop().unwrap();
    send_vote(&mut captor, 1, 1, token_a.clone());
    send_vote(&mut captor, 1, 1, token_b.clone());

    send_vote(&mut captor, 2, 1, token_a.clone());
    send_vote(&mut captor, 2, 1, token_b.clone());

    send_vote(&mut captor, 3, 1, token_a);
    send_vote(&mut captor, 3, 1, token_b);

    let (actual, _) = captor.pop().unwrap();
    assert_eq!(actual, "game_over_message");
}
