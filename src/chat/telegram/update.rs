use crate::chat::ChatError::Deserialize;
use crate::chat::Result;
use crate::game::FullUser;
use crate::game::{Callback, ChatGroup, Choice};
use log::error;
use serde_json::value::Value::Number;
use serde_json::Value;

#[derive(Debug)]
pub struct Update(pub Value);

impl Update {
    pub fn message_text(&self) -> Result<Option<String>> {
        self.get_field("/message/text")
    }

    pub fn callback_data(&self) -> Result<Option<String>> {
        self.get_field("/callback_query/data")
    }

    pub fn game_short_name(&self) -> Result<Option<String>> {
        self.get_field("/callback_query/game_short_name")
    }

    pub fn chat_group(&self) -> Result<ChatGroup> {
        let chat_group =
            self.get_field_or("/message/chat/id", "/callback_query/message/chat/id")?;
        Ok(ChatGroup(chat_group))
    }

    pub fn callback(&self) -> Result<Callback> {
        let id = self.get_field("/callback_query/id")?;
        let message_id = self.get_field("/callback_query/message/message_id")?;
        Ok(Callback { id, message_id })
    }

    pub fn user(&self) -> Result<FullUser> {
        let id = self.get_field_or("/message/from/id", "/callback_query/from/id")?;
        let is_bot = self.get_field_or("/message/from/is_bot", "/callback_query/from/is_bot")?;
        let first_name = self.get_field_or(
            "/message/from/first_name",
            "/callback_query/from/first_name",
        )?;
        let last_name =
            self.get_field_or("/message/from/last_name", "/callback_query/from/last_name")?;
        let username =
            self.get_field_or("/message/from/username", "/callback_query/from/username")?;
        Ok(FullUser {
            id,
            is_bot,
            first_name,
            last_name,
            username,
        })
    }

    pub fn choice(&self) -> Result<Choice> {
        let data = self.callback_data()?;
        let data = match data {
            None => {
                error!("Choice request is missing callback data");
                return Err(Deserialize);
            }
            Some(choice) => choice,
        };
        let token: Vec<&str> = data.split(' ').collect();
        match token.get(1) {
            None => Err(Deserialize),
            Some(token) => Ok(Choice {
                token: token.to_string(),
            }),
        }
    }
}

trait Field<T> {
    fn get_field(&self, pointer: &str) -> Result<T>;
    fn get_field_or(&self, pointer: &str, or: &str) -> Result<T>;
}

impl Field<Value> for Update {
    fn get_field(&self, pointer: &str) -> Result<Value> {
        let Update(value) = self;
        let value = value.pointer(pointer);
        match value {
            None => {
                error!("Failed to get field: {}", pointer);
                Err(Deserialize)
            }
            Some(value) => Ok(value.clone()),
        }
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<Value> {
        let Update(value) = self;
        let value = value.pointer(pointer).or_else(|| value.pointer(or));
        match value {
            None => {
                error!("Failed to get field: {} or {}", pointer, or);
                Err(Deserialize)
            }
            Some(value) => Ok(value.clone()),
        }
    }
}

impl Field<Option<i64>> for Update {
    fn get_field(&self, pointer: &str) -> Result<Option<i64>> {
        let Update(value) = self;
        let result = value.pointer(pointer);
        let string = match result {
            None => return Ok(None),
            Some(Number(n)) => return Ok(n.as_i64()),
            Some(Value::String(s)) => s,
            Some(_) => {
                error!("Expected option int: {} {:?}", pointer, value);
                return Err(Deserialize);
            }
        };
        match string.parse() {
            Ok(value) => Ok(Some(value)),
            Err(err) => {
                error!("Expected option int: {} {:?}, {:?}", pointer, value, err);
                Err(Deserialize)
            }
        }
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<Option<i64>> {
        let Update(value) = self;
        let result = value
            .pointer(pointer)
            .or_else(|| value.pointer(or))
            .map(|value| value.as_i64())
            .flatten();
        Ok(result)
    }
}

impl Field<Option<String>> for Update {
    fn get_field(&self, pointer: &str) -> Result<Option<String>> {
        let Update(value) = self;
        let result = value
            .pointer(pointer)
            .map(|value| value.as_str())
            .flatten()
            .map(|value| value.to_string());
        Ok(result)
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<Option<String>> {
        let Update(value) = self;
        let result = value
            .pointer(pointer)
            .or_else(|| value.pointer(or))
            .map(|value| value.as_str())
            .flatten()
            .map(|value| value.to_string());
        Ok(result)
    }
}

impl Field<String> for Update {
    fn get_field(&self, pointer: &str) -> Result<String> {
        let value: Value = self.get_field(pointer)?;
        match value.as_str() {
            None => {
                error!("Expected string: {} {:?}", pointer, value);
                Err(Deserialize)
            }
            Some(value) => Ok(value.to_string()),
        }
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<String> {
        let value: Value = self.get_field_or(pointer, or)?;
        match value.as_str() {
            None => {
                error!("Expected string: {} {:?}", pointer, value);
                Err(Deserialize)
            }
            Some(value) => Ok(value.to_string()),
        }
    }
}

impl Field<i64> for Update {
    fn get_field(&self, pointer: &str) -> Result<i64> {
        let value: Value = self.get_field(pointer)?;
        match value.as_str().map(|value| value.parse::<i64>()) {
            Some(Ok(value)) => Ok(value),
            _ => {
                error!("Expected int: {} {:?}", pointer, value);
                Err(Deserialize)
            }
        }
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<i64> {
        let value: Value = self.get_field_or(pointer, or)?;
        match value.as_i64() {
            None => {
                error!("Expected int: {} {:?}", pointer, value);
                Err(Deserialize)
            }
            Some(value) => Ok(value),
        }
    }
}

impl Field<bool> for Update {
    fn get_field(&self, pointer: &str) -> Result<bool> {
        let value: Value = self.get_field(pointer)?;
        match value.as_bool() {
            None => {
                error!("Expected bool: {} {:?}", pointer, value);
                Err(Deserialize)
            }
            Some(value) => Ok(value),
        }
    }

    fn get_field_or(&self, pointer: &str, or: &str) -> Result<bool> {
        let value: Value = self.get_field_or(pointer, or)?;
        match value.as_bool() {
            None => {
                error!("Expected bool: {} {:?}", pointer, value);
                Err(Deserialize)
            }
            Some(value) => Ok(value),
        }
    }
}
