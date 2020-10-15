use crate::http::server::ServerError::Internal;
use httparse::{Status, EMPTY_HEADER};
use log::{error, info};

use http::Uri;

use serde_json::{json, Value};
use std::io::{Read, Write};
use std::net::TcpStream;

#[derive(Debug)]
pub enum ServerError {
    Internal,
    Client(&'static str),
}

pub type Result<T> = std::result::Result<T, ServerError>;

pub trait Handler {
    fn handle(
        &self,
        method: String,
        path: Uri,
        headers: Vec<(String, String)>,
        body: Value,
    ) -> Result<Option<Value>>;
}

pub struct Server<'s> {
    handler: &'s dyn Handler,
}

impl<'s> Server<'s> {
    pub fn new(handler: &'s dyn Handler) -> Self {
        Server { handler }
    }

    pub fn handle_connection(&self, mut stream: TcpStream) -> Result<()> {
        let Response {
            method,
            path,
            headers,
            body,
        } = read_stream(&mut stream)?;

        let result = self.handler.handle(method, path, headers, body);
        match result {
            Ok(None) => send_response_empty(&mut stream),
            Ok(Some(body)) => send_response(&mut stream, body),
            Err(ServerError::Client(reason)) => send_error_response(&mut stream, reason),
            Err(err) => {
                error!("Internal server error: {:?}", err);
                send_response_empty(&mut stream)
            }
        }
    }
}

fn send_error_response(stream: &mut TcpStream, reason: &'static str) -> Result<()> {
    let body = json!({ "error": reason }).to_string();
    let result = stream.write_fmt(format_args!(
        "HTTP/1.0 400 Bad Request\r\n\
            Content-Length: {length}\r\n\
            Content-Type: application/json\r\n\r\n\
            {body}\r\n",
        length = body.len(),
        body = body
    ));

    if let Err(err) = result {
        error!("Failed to write to stream for response: {:?}", err);
        return Ok(());
    }

    let result = stream.flush();

    if let Err(err) = result {
        error!("Failed to flush response: {:?}", err);
        return Ok(());
    }

    Ok(())
}

fn send_response_empty(stream: &mut TcpStream) -> Result<()> {
    let result = stream.write(b"HTTP/1.1 200 OK\r\n\r\n");
    if let Err(err) = result {
        error!("Failed to write to stream for response: {:?}", err);
        return Ok(());
    }

    let result = stream.flush();

    if let Err(err) = result {
        error!("Failed to flush response: {:?}", err);
        return Ok(());
    }

    Ok(())
}

fn send_response<T: ToString>(stream: &mut TcpStream, body: T) -> Result<()> {
    let body = body.to_string();
    let result = stream.write_fmt(format_args!(
        "HTTP/1.0 200 OK\r\n\
            Content-Length: {length}\r\n\
            Content-Type: application/json\r\n\r\n\
            {body}\r\n",
        length = body.len(),
        body = body
    ));

    if let Err(err) = result {
        error!("Failed to write to stream for response: {:?}", err);
        return Ok(());
    }

    let result = stream.flush();

    if let Err(err) = result {
        error!("Failed to flush response: {:?}", err);
        return Ok(());
    }

    Ok(())
}

struct Response {
    method: String,
    path: Uri,
    headers: Vec<(String, String)>,
    body: Value,
}

fn read_stream(stream: &mut TcpStream) -> Result<Response> {
    let mut buf = [0; 1_000_000];
    stream.read(&mut buf).map_err(|err| {
        error!("Failed reading request {}", err);
        ServerError::Internal
    })?;
    let mut header_buf = [EMPTY_HEADER; 64];
    let mut req = httparse::Request::new(&mut header_buf);
    let body = req.parse(&buf).map_err(|err| {
        error!("Failed parsing request {}", err);
        ServerError::Internal
    })?;
    let method = match req.method {
        None => {
            error!("Expecting method for request, none found");
            Err(ServerError::Internal)
        }
        Some(method) => Ok(method),
    }?;
    let path = match req.path {
        None => {
            error!("Expecting path for request, none found");
            Err(ServerError::Internal)
        }
        Some(path) => Ok(path),
    }?;
    let path = path.parse::<Uri>().map_err(|err| {
        error!("Could not parse path: {} {}", path, err);
        ServerError::Internal
    })?;

    let headers: Vec<(String, String)> = req
        .headers
        .iter()
        .map(|header| match String::from_utf8(header.value.to_vec()) {
            Ok(value) => Ok((String::from(header.name), value)),
            Err(_) => Err(Internal),
        })
        .collect::<Result<Vec<(String, String)>>>()?;

    let body = get_body(&headers, &buf, body)?;

    Ok(Response {
        method: method.to_string(),
        path,
        headers,
        body,
    })
}

fn get_body(headers: &[(String, String)], buf: &[u8], body_start: Status<usize>) -> Result<Value> {
    let content_length = headers
        .iter()
        .find(|(name, _)| name.to_ascii_lowercase() == "content-length");
    let content_length = match content_length {
        None => {
            info!("Empty body");
            return Ok(Value::Null);
        }
        Some((_, value)) => value,
    };
    let content_length = content_length.parse::<usize>().map_err(|err| {
        error!("Content-length could not be parsed as int: {}", err);
        ServerError::Internal
    })?;

    let body = match body_start {
        Status::Complete(body) => &buf[body..(body + content_length)],
        Status::Partial => {
            error!("Only partially parsed request");
            return Err(Internal);
        }
    };

    let body = serde_json::from_slice(body).map_err(|err| {
        error!("Could not parse body as utf8: {}", err);
        ServerError::Internal
    })?;

    Ok(body)
}
