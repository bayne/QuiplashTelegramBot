INSERT INTO question (text) VALUES
    ('q1'),
    ('q2'),
    ('q3');

INSERT INTO "user" (
    id,
    is_bot,
    first_name,
    last_name,
    username
)
VALUES
    (1, false, 'testfirst1', 'testlast1', 'test1'),
    (2, false, 'testfirst2', 'testlast2', 'test2'),
    (3, false, 'testfirst3', 'testlast3', 'test3');

INSERT INTO game (
    host_id,
    current_question_id,
    chatgroup,
    state,
    warning_state_state,
    warning_state_warning_value
) VALUES
    (1, null, 1, 'gather_answers', 'gather_answers', 1);

INSERT INTO game_user (game_id, user_id) VALUES
    (1, 1),
    (1, 2),
    (1, 3);

INSERT INTO answer (user_id, question_id, game_id, response, token) VALUES
    (1, 1, 1, null, 'u1q1'),
    (1, 2, 1, null, 'u1q2'),
    (2, 1, 1, null, 'u2q1'),
    (2, 3, 1, null, 'u2q3'),
    (3, 2, 1, null, 'u3q2'),
    (3, 3, 1, null, 'u3q3');