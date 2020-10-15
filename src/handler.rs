use crate::controller::ControllerError::Domain;
use crate::controller::{Controller, ControllerError};
use crate::game::{AnswerError, DomainError};
use crate::http::server;
use crate::http::server::{Handler, ServerError};
use crate::router::Router;
use http::Uri;

use serde_json::Value;

pub struct DefaultHandler<'s> {
    controller: Controller<'s>,
    router: Router,
}

impl<'s> DefaultHandler<'s> {
    pub fn new(controller: Controller<'s>, router: Router) -> Self {
        DefaultHandler { controller, router }
    }
}

impl From<ControllerError> for ServerError {
    fn from(err: ControllerError) -> Self {
        match err {
            Domain(DomainError::AnswerError(AnswerError::AlreadyAnswered)) => {
                ServerError::Client("ALREADY_ANSWERED")
            }
            Domain(DomainError::AnswerError(AnswerError::NoneWithToken)) => {
                ServerError::Client("INVALID_TOKEN")
            }
            _ => ServerError::Internal,
        }
    }
}

impl Handler for DefaultHandler<'_> {
    fn handle(
        &self,
        method: String,
        path: Uri,
        headers: Vec<(String, String)>,
        body: Value,
    ) -> server::Result<Option<Value>> {
        Ok(self
            .router
            .route(&self.controller, method, path, headers, body)?)
    }
}
