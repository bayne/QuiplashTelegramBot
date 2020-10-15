use http::Request;
use httparse::Status;
use log::error;
use rustls::{ClientConfig, ClientSession, Stream};
use std::io::{ErrorKind, Read, Write};
use std::net::{SocketAddr, TcpStream, ToSocketAddrs};
use std::sync::Arc;
use webpki::DNSNameRef;

pub struct Client<'a> {
    dns_name: DNSNameRef<'a>,
    socket_addr: SocketAddr,
    client_config: Arc<ClientConfig>,
}

#[derive(Debug, PartialEq)]
pub enum ClientError {
    Hostname,
    Connect,
    Write,
    Tls,
    Response,
}

impl<'a> Client<'a> {
    pub fn new(hostname: &'a str) -> Result<Self, ClientError> {
        let dns_name = webpki::DNSNameRef::try_from_ascii_str(hostname).map_err(|err| {
            error!("Invalid dns name: {}", err);
            ClientError::Hostname
        })?;
        let mut client_config = rustls::ClientConfig::new();
        client_config
            .root_store
            .add_server_trust_anchors(&webpki_roots::TLS_SERVER_ROOTS);

        let socket_addr = (hostname, 443)
            .to_socket_addrs()
            .map_err(|err| {
                error!("Parsing host name error: {}", err);
                ClientError::Hostname
            })?
            .next();

        match socket_addr {
            None => {
                error!("Failed lookup: {}", hostname);
                Err(ClientError::Hostname)
            }
            Some(socket_addr) => Ok(Client {
                dns_name,
                socket_addr,
                client_config: Arc::new(client_config),
            }),
        }
    }

    pub fn send<'h, 'b>(
        &self,
        req: Request<String>,
        buf: &'b mut Vec<u8>,
        dst: &'b mut httparse::Response<'h, 'b>,
    ) -> Result<(&'b httparse::Response<'h, 'b>, &'b [u8]), ClientError> {
        let sess = &mut self.session()?;
        let sock = &mut self.socket()?;
        let mut tls = self.tls(sess, sock)?;

        let host: &str = DNSNameRef::into(self.dns_name);
        let path = Self::path(&req)?;
        let body = req.body();

        tls.write_fmt(format_args!(
            "{method} https://{host}{path} HTTP/1.1\r\n\
            Host: {host}\r\n\
            Connection: close\r\n\
            Content-Type: application/json\r\n\
            Content-Length: {length}\r\n\
            Accept-Encoding: identity\r\n\r\n\
            {body}\r\n",
            method = req.method(),
            path = path,
            host = host,
            length = body.as_bytes().len(),
            body = body,
        ))
        .map_err(|err| {
            error!("Write error: {}", err);
            ClientError::Write
        })?;

        Self::read_response(&mut tls, buf, dst)
    }

    fn read_response<'s, 'h, 'b>(
        tls: &'s mut Stream<ClientSession, TcpStream>,
        buf: &'b mut Vec<u8>,
        dst: &'b mut httparse::Response<'h, 'b>,
    ) -> Result<(&'b httparse::Response<'h, 'b>, &'b [u8]), ClientError> {
        let result = tls.read_to_end(buf);
        if let Err(err) = result {
            if err.kind() != ErrorKind::ConnectionAborted
                && !err.to_string().contains("CloseNotify")
            {
                return Err(ClientError::Tls);
            }
        }
        let size = dst.parse(buf).map_err(|err| {
            error!("Response parsing error: {}", err);
            ClientError::Response
        })?;

        let headers: Vec<(String, String)> = dst
            .headers
            .iter()
            .map(|header| match String::from_utf8(header.value.to_vec()) {
                Ok(value) => Ok((String::from(header.name), value)),
                Err(_) => Err(ClientError::Response),
            })
            .collect::<Result<Vec<(String, String)>, ClientError>>()?;

        let content_length = headers
            .iter()
            .find(|(name, _)| name.to_ascii_lowercase() == "content-length");
        let content_length = match content_length {
            None => {
                error!("Missing content-length header");
                Err(ClientError::Response)
            }
            Some((_, value)) => Ok(value),
        }?;
        let content_length = content_length.parse::<usize>().map_err(|err| {
            error!("Content-length could not be parsed as int: {}", err);
            ClientError::Response
        })?;

        let body = match size {
            Status::Complete(size) => &buf[size..(size + content_length)],
            Status::Partial => {
                error!("Response parsing error: Partially parsed");
                return Err(ClientError::Response);
            }
        };

        Ok((dst, body))
    }

    fn path(req: &'_ Request<String>) -> Result<&'_ str, ClientError> {
        match req.uri().path_and_query() {
            None => Err(ClientError::Write),
            Some(path) => Ok(path.as_str()),
        }
    }

    fn tls<'s>(
        &self,
        sess: &'s mut ClientSession,
        sock: &'s mut TcpStream,
    ) -> Result<Stream<'s, ClientSession, TcpStream>, ClientError> {
        Ok(rustls::Stream::new(sess, sock))
    }

    fn session(&self) -> Result<ClientSession, ClientError> {
        Ok(rustls::ClientSession::new(
            &self.client_config,
            self.dns_name,
        ))
    }

    fn socket(&self) -> Result<TcpStream, ClientError> {
        TcpStream::connect(self.socket_addr).map_err(|err| {
            error!("Connect error: {}", err);
            ClientError::Connect
        })
    }
}
