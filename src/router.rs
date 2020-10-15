use crate::chat::telegram::update::Update;
use crate::controller::ClientErrorReason::InvalidQueryParams;
use crate::controller::Result;
use crate::controller::{Controller, ControllerError};
use crate::game::ChatGroup;
use http::Uri;
use log::{error, info};
use regex::Regex;
use serde_json::{json, Value};

pub struct Router {
    command_pattern: Regex,
}

impl Default for Router {
    fn default() -> Self {
        let command_pattern = Regex::new("/[A-Za-z_]+").unwrap();
        Router { command_pattern }
    }
}

impl Router {
    pub fn route(
        &self,
        controller: &Controller,
        method: String,
        path: Uri,
        _headers: Vec<(String, String)>,
        body: Value,
    ) -> Result<Option<Value>> {
        let update = Update(body.clone());

        let route = (
            method.as_str(),
            path.path(),
            update.message_text(),
            update.callback_data(),
            update.game_short_name(),
        );

        match route {
            ("POST", "/webhook", _, _, Ok(Some(_))) => {
                let result = Self::handle_launch_game(controller, &update);
                if let Err(err) = result {
                    error!("Error launching game: {:?}", err);
                }
                Ok(None)
            }
            ("POST", "/webhook", Ok(Some(message_text)), _, _) => {
                let result = self.handle_command(controller, &update, &message_text);
                if let Err(err) = result {
                    error!("Error handling command: {:?}", err);
                }
                Ok(None)
            }
            ("POST", "/webhook", _, Ok(Some(callback_data)), _) => {
                let result = self.handle_callback(controller, &update, &callback_data);
                if let Err(err) = result {
                    error!("Error handling callback: {:?}", err);
                }
                Ok(None)
            }
            ("GET", "/", _, _, _) => Ok(None),
            ("GET", "/app", _, _, _) => self.handle_get_prompt(controller, path),
            ("POST", "/app", _, _, _) => self.handle_post_prompt(controller, path, &body),
            (_, _, Err(err), _, _) | (_, _, _, Err(err), _) | (_, _, _, _, Err(err)) => {
                error!("Error parsing update: {:?}", err);
                Ok(None)
            }
            (_, _, _, _, _) => Ok(None),
        }
    }

    fn handle_post_prompt(
        &self,
        controller: &Controller,
        path: Uri,
        body: &Value,
    ) -> Result<Option<Value>> {
        let path = match path.query() {
            None => {
                error!("Query params not valid");
                Err(ControllerError::ClientError(InvalidQueryParams))
            }
            Some(path) => Ok(path),
        }?;
        let params: Vec<(&str, &str)> = path
            .split('&')
            .map(|element| {
                let res: Vec<_> = element.split('=').collect();
                (res[0], res[1])
            })
            .collect();
        let token = params.iter().find(|(name, _)| name.eq(&"token"));
        let token = match token {
            Some((_, token)) => Ok(token),
            None => {
                error!("Missing query param, token: {}", path);
                Err(ControllerError::ClientError(InvalidQueryParams))
            }
        }?;

        let group_id = params.iter().find(|(name, _)| name.eq(&"group_id"));
        let group_id = match group_id {
            Some((_, group_id)) => Ok(group_id),
            None => {
                error!("Missing query param, group_id: {}", path);
                Err(ControllerError::ClientError(InvalidQueryParams))
            }
        }?;

        let group_id = match group_id.parse::<i64>() {
            Ok(group_id) => group_id,
            Err(err) => {
                error!("Invalid query param, group_id: {}, {}", path, err);
                return Err(ControllerError::ClientError(InvalidQueryParams));
            }
        };

        let answer = match body.get("answer") {
            None => Some(""),
            Some(value) => value.as_str(),
        };

        let answer = match answer {
            None => {
                error!("Unexpected structure for body: {}", body);
                ""
            }
            Some(value) => value,
        };

        controller.post_prompt(token.to_string(), answer.to_string(), ChatGroup(group_id))?;
        Ok(None)
    }

    fn handle_get_prompt(&self, controller: &Controller, path: Uri) -> Result<Option<Value>> {
        let path = match path.query() {
            None => {
                error!("Query params not valid");
                Err(ControllerError::ClientError(InvalidQueryParams))
            }
            Some(path) => Ok(path),
        }?;
        let mut params = path.split('&').map(|element| {
            let res: Vec<_> = element.split('=').collect();
            (res[0].to_string(), res[1].to_string())
        });
        let token = params.find(|(name, _)| name.eq("token"));
        let token = match token {
            Some((_, token)) => Ok(token),
            None => {
                error!("Missing query param, token: {}", path);
                Err(ControllerError::ClientError(InvalidQueryParams))
            }
        }?;

        let question = controller.get_prompt(token)?;
        Ok(Some(json!({ "question": question })))
    }

    fn handle_command(
        &self,
        controller: &Controller,
        update: &Update,
        message_text: &str,
    ) -> Result<()> {
        let command = self.command_pattern.find(message_text);
        if let Some(command) = command {
            info!("command: {:?}", command.as_str());
            self.route_command(controller, &update, command.as_str())
        } else {
            error!("Failed to match command: {}", message_text);
            Ok(())
        }
    }

    fn route_command(&self, controller: &Controller, update: &Update, command: &str) -> Result<()> {
        match command {
            "/top" => controller.top_scores(),
            "/start" => controller.start(update.chat_group()?),
            "/new" => controller.new_game(update.user()?, update.chat_group()?),
            "/begin" => controller.begin_game(update.chat_group()?),
            "/status" => controller.status(update.chat_group()?),
            "/end" => controller.end(update.chat_group()?),
            _ => {
                error!("Unexpected command: {}", command);
                Ok(())
            }
        }?;
        Ok(())
    }

    fn handle_callback(
        &self,
        controller: &Controller,
        update: &Update,
        callback_data: &str,
    ) -> Result<()> {
        let command = self.command_pattern.find(&callback_data);
        if let Some(command) = command {
            info!("callback: {:?}", command.as_str());
            self.route_callback(controller, &update, command.as_str())
        } else {
            error!("Failed to match callback: {}", callback_data);
            Ok(())
        }
    }

    fn route_callback(
        &self,
        controller: &Controller,
        update: &Update,
        command: &str,
    ) -> Result<()> {
        match command {
            "/join_callback" => {
                controller.join_game(update.user()?, update.chat_group()?, update.callback()?)
            }
            "/vote_callback" => controller.vote(
                update.user()?.into(),
                update.choice()?,
                update.chat_group()?,
                update.callback()?,
            ),
            _ => {
                error!("Unexpected callback: {}", command);
                Ok(())
            }
        }?;
        Ok(())
    }

    fn handle_launch_game(controller: &Controller, update: &Update) -> Result<()> {
        controller.launch_game(
            update.user()?.into(),
            update.chat_group()?,
            update.callback()?,
        )
    }
}
