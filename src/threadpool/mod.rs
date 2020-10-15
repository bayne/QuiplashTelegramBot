use log::error;
use log::info;

use crate::chat::telegram::Telegram;
use crate::config::Config;
use crate::controller::Controller;
use crate::handler::DefaultHandler;
use crate::http::server::Server;
use crate::router::Router;
use std::net::TcpStream;
use std::sync::mpsc;
use std::sync::Arc;
use std::sync::Mutex;
use std::thread;

pub struct ThreadPool {
    workers: Vec<Worker>,
    sender: mpsc::Sender<Message>,
}

#[derive(Debug)]
pub enum ThreadPoolError {
    Dispatch,
}

pub type Result<T> = std::result::Result<T, ThreadPoolError>;

enum Message {
    NewJob(TcpStream),
    Terminate,
}

impl ThreadPool {
    /// Create a new ThreadPool.
    ///
    /// The size is the number of threads in the pool.
    ///
    /// # Panics
    ///
    /// The `new` function will panic if the size is zero.
    pub fn new(size: usize, config: Config) -> ThreadPool {
        assert!(size > 0);

        let (sender, receiver) = mpsc::channel();

        let receiver = Arc::new(Mutex::new(receiver));

        let mut workers = Vec::with_capacity(size);

        for id in 0..size {
            let worker = Worker::new(id, Arc::clone(&receiver), config.clone());
            workers.push(worker);
        }

        ThreadPool { workers, sender }
    }

    pub fn handle(&self, stream: TcpStream) -> Result<()> {
        self.sender.send(Message::NewJob(stream)).map_err(|err| {
            error!("Error handling stream: {}", err);
            ThreadPoolError::Dispatch
        })
    }
}

impl Drop for ThreadPool {
    fn drop(&mut self) {
        info!("Sending terminate message to all workers.");

        for _ in &self.workers {
            self.sender.send(Message::Terminate).unwrap();
        }

        info!("Shutting down all workers.");

        for worker in &mut self.workers {
            info!("Shutting down worker {}", worker.id);

            if let Some(thread) = worker.thread.take() {
                thread.join().unwrap();
            }
        }
    }
}

struct Worker {
    id: usize,
    thread: Option<thread::JoinHandle<()>>,
}

impl Worker {
    fn new(id: usize, receiver: Arc<Mutex<mpsc::Receiver<Message>>>, config: Config) -> Worker {
        let thread = thread::spawn(move || {
            let connection = libpq::Connection::new(&config.db_dsn)
                .map_err(|err| {
                    error!("Database connection error: {}", err);
                    err
                })
                .expect("Failed to start due to db connection error");

            let chat_client = Telegram::new(
                &config.telegram_hostname,
                &config.telegram_token,
                &config.telegram_gamename,
            )
            .expect("Failed to create client");

            let controller = Controller::new(&connection, Box::new(chat_client), &config.app_url);
            let router = Router::default();
            let handler = DefaultHandler::new(controller, router);

            let server = Server::new(&handler);

            loop {
                let message = receiver
                    .lock()
                    .unwrap()
                    .recv()
                    .map_err(|err| {
                        error!("Worker {} failed to receive message: {}", id, err);
                    })
                    .unwrap();

                match message {
                    Message::NewJob(stream) => server.handle_connection(stream).unwrap(),
                    Message::Terminate => {
                        info!("Worker {} was told to terminate.", id);

                        break;
                    }
                }
            }
        });

        Worker {
            id,
            thread: Some(thread),
        }
    }
}
