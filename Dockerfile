FROM rust:1.40 AS build
RUN apt-get install libpq-dev
WORKDIR /usr/src/app

COPY Cargo.lock .
COPY Cargo.toml .
COPY ./postgres/ddl.sql postgres/ddl.sql
RUN mkdir .cargo
COPY ./src src

FROM build AS prod

COPY ./src src
RUN cargo build --release

FROM debian:stable-slim
RUN apt-get update && apt-get install -y libpq-dev

COPY --from=prod /usr/src/app/target/release/app /bin/app
COPY entrypoint.sh .

CMD ["app"]
