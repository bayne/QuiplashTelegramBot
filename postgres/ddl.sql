CREATE TABLE question
(
    id   BIGSERIAL PRIMARY KEY,
    text TEXT NOT NULL
);

CREATE TABLE "user"
(
    id         BIGINT PRIMARY KEY,
    is_bot     BOOLEAN NOT NULL,
    first_name TEXT    NULL,
    last_name  TEXT    NULL,
    username   TEXT    NULL
);

CREATE TABLE game
(
    id                          BIGSERIAL PRIMARY KEY,
    host_id                     BIGINT                   NULL REFERENCES "user",
    current_question_id         BIGINT                   NULL,
    chatgroup                   BIGINT                   NOT NULL,
    state                       TEXT                     NOT NULL,
    gathering_votes_started     TIMESTAMP WITH TIME ZONE NULL,
    gathering_users_started     TIMESTAMP WITH TIME ZONE NULL,
    gathering_answers_started   TIMESTAMP WITH TIME ZONE NULL,
    warning_state_state         TEXT                     NOT NULL,
    warning_state_warning_value INT                      NOT NULL,
    has_timer                   BOOLEAN DEFAULT TRUE     NOT NULL
);

CREATE INDEX fk_game_host ON game (host_id);
CREATE INDEX fk_game_current_question ON game (current_question_id);

CREATE TABLE answer
(
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NULL REFERENCES "user",
    question_id BIGINT NULL REFERENCES question,
    game_id     BIGINT NULL REFERENCES game,
    response    TEXT,
    token       TEXT   NOT NULL
);

CREATE INDEX fk_answer_user ON answer (user_id);
CREATE INDEX fk_answer_question ON answer (question_id);
CREATE INDEX fk_answer_game ON answer (game_id);
CREATE UNIQUE INDEX ix_answer_token ON answer (token);

CREATE TABLE game_user
(
    game_id BIGINT NOT NULL REFERENCES game ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES "user" ON DELETE CASCADE,
    PRIMARY KEY (game_id, user_id)
);

CREATE INDEX fk_game_user_user ON game_user (user_id);

CREATE INDEX fk_game_user_game ON game_user (game_id);

CREATE TABLE vote
(
    id          BIGSERIAL PRIMARY KEY,
    answer_id   BIGINT NOT NULL REFERENCES answer,
    question_id BIGINT NOT NULL REFERENCES question,
    user_id     BIGINT NOT NULL REFERENCES "user",
    game_id     BIGINT NOT NULL REFERENCES game
);

CREATE INDEX fk_vote_question ON vote (question_id);

CREATE INDEX fk_vote_user ON vote (user_id);

CREATE INDEX fk_vote_answer ON vote (answer_id);

CREATE INDEX fk_vote_game ON vote (game_id);

CREATE UNIQUE INDEX uq_vote ON vote(user_id, answer_id);
