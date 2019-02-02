<?php

namespace AppBundle;

use AppBundle\Entity\Answer;
use AppBundle\Entity\Exception\AlreadyInTheGameException;
use AppBundle\Entity\Exception\CannotBeginGameException;
use AppBundle\Entity\Exception\GameAlreadyExistsException;
use AppBundle\Entity\Exception\GameAlreadyRunningException;
use AppBundle\Entity\Exception\GameException;
use AppBundle\Entity\Exception\NoAnswersForUserException;
use AppBundle\Entity\Exception\NoGameRunningException;
use AppBundle\Entity\Exception\NotEnoughPlayersException;
use AppBundle\Entity\Game;
use AppBundle\Entity\User;
use AppBundle\Entity\ValueObject\WarningState;
use AppBundle\Entity\Vote;
use AppBundle\Repository\AnswerRepository;
use AppBundle\Repository\GameRepository;
use AppBundle\Repository\QuestionRepository;
use Psr\Log\LoggerInterface;

class GameManager
{
    /**
     * @var GameRepository
     */
    private $gameRepository;
    /**
     * @var QuestionRepository
     */
    private $questionRepository;
    /**
     * @var AnswerRepository
     */
    private $answerRepository;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * GameManager constructor.
     * @param GameRepository $gameRepository
     * @param QuestionRepository $questionRepository
     * @param AnswerRepository $answerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        GameRepository $gameRepository,
        QuestionRepository $questionRepository,
        AnswerRepository $answerRepository,
        LoggerInterface $logger
    ) {
        $this->gameRepository = $gameRepository;
        $this->questionRepository = $questionRepository;
        $this->lock();
        $this->answerRepository = $answerRepository;
        $this->logger = $logger;
    }

    public function __destruct()
    {
        $this->unlock();
    }


    public function lock()
    {
        $this->gameRepository->beginTransaction();
    }

    public function unlock()
    {
        $this->gameRepository->commit();
    }

    public function newGame(User $host, $chatGroupId, $hasTimer = true)
    {
        $games =
            array_filter(
                array_merge(
                    $this->gameRepository->findRunningGames($chatGroupId)
                )
            )
        ;

        if (count($games) > 0) {
            throw new GameAlreadyExistsException();
        }

        $game = new Game(
            $host,
            $chatGroupId,
            $hasTimer
        );

        $this->gameRepository->updateGame($game);

        return $game;
    }

    public function endGameForGroup($chatGroupId)
    {
        $games = $this->gameRepository->findRunningGames($chatGroupId);

        if (count($games) === 0) {
            throw new NoGameRunningException();
        }

        $game = reset($games);

        $game->setState(Game::END);

        $this->gameRepository->updateGame($game);

        return $game;
    }

    public function joinGame(User $user, $chatGroupId)
    {
        $games = $this->gameRepository->findRunningGames($chatGroupId);

        if (count($games) === 0) {
            throw new NoGameRunningException();
        }

        /** @var Game $game */
        $game = reset($games);

        if (false === $game->getUsers()->contains($user)) {
            $game->getUsers()->add($user);
            $this->gameRepository->updateGame($game);
        } else {
            throw new AlreadyInTheGameException();
        }

        if ($game->getState() !== Game::GATHER_USERS) {
            throw new GameAlreadyRunningException();
        }

        return $game;
    }

    public function beginGame($chatGroupId)
    {
        $games = $this->gameRepository->findRunningGames($chatGroupId);

        if (count($games) === 0) {
            throw new NoGameRunningException();
        }

        /** @var Game $game */
        $game = reset($games);

        if ($game->getState() !== Game::GATHER_USERS) {
            throw new CannotBeginGameException($game->getState());
        }

        if (false === $game->hasEnoughUsers()) {
            throw new NotEnoughPlayersException();
        }

        $game->setState(Game::GATHER_ANSWERS);

        $questions = $this->questionRepository->generateQuestions(
            $game->getUsers()->count()
        );

        foreach ($questions as $question) {
            $game->getQuestions()->add($question);
        }

        $answers = [];
        foreach ($game->getQuestions() as $i => $question) {
            $answers[] = new Answer(
                $game->getUsers()->offsetGet($i),
                $question,
                $game,
                base64_encode(random_bytes(16))
            );
        }

        $shiftedQuestions = $game->getQuestions()->toArray();
        $question = array_pop($shiftedQuestions);
        array_unshift($shiftedQuestions, $question);

        foreach (array_values($shiftedQuestions) as $i => $question) {
            $answers[] = new Answer(
                $game->getUsers()->offsetGet($i),
                $question,
                $game,
                base64_encode(random_bytes(16))
            );
        }

        $this->logger->debug(
            'Generated tokens for users',
            [
                'tokens' => array_map(function (Answer $answer) {
                    return [
                        'user' => $answer->getUser()->getUsername(),
                        'token' => $answer->getToken()
                    ];
                }, $answers)
            ]
        );

        foreach ($answers as $answer) {
            $game->getAnswers()->add($answer);
        }

        $this->gameRepository->updateGame($game);

        return $game;
    }

    public function getTokenForUser(User $user, $chatGroupId)
    {
        $games = $this->gameRepository->findRunningGames($chatGroupId);
        /** @var Game $game */
        foreach ($games as $game) {
            foreach ($game->getAnswers() as $answer) {
                if ($answer->getUser()->getId() === $user->getId() && $answer->isPending()) {
                    return $answer->getToken();
                }
            }
        }
        throw new NoAnswersForUserException();
    }

    public function beginVoting(Game $game)
    {
        if ($game->getState() !== Game::GATHER_ANSWERS) {
            throw new GameException();
        }

        $game->setState(Game::GATHER_VOTES);
        $game->setCurrentQuestion($game->getQuestions()->first());

        $this->gameRepository->updateGame($game);

        return $game;
    }

    public function recordVote(User $user, Answer $answer)
    {
        $vote = new Vote();
        $vote->setAnswer($answer);
        $vote->setGame($answer->getGame());
        $vote->setUser($user);
        $vote->setQuestion($answer->getQuestion());

        $this->gameRepository->recordVote($vote);
    }

    public function advanceGame(Game $game)
    {
        $nextQuestionIndex = 0;
        foreach ($game->getQuestions() as $i => $question) {
            if ($question->getId() === $game->getCurrentQuestion()->getId()) {
                $nextQuestionIndex = $i + 1;
                break;
            }
        }

        if ($game->getQuestions()->count() <= $nextQuestionIndex) {
            $game->setWarningState(new WarningState(Game::END, Game::TIME_TO_VOTE));
            $game->setState(Game::END);
        } else {
            $game->setWarningState(new WarningState(Game::GATHER_VOTES, Game::TIME_TO_VOTE));
            $game->setCurrentQuestion($game->getQuestions()->offsetGet($nextQuestionIndex));
        }

        $this->gameRepository->updateGame($game);
    }

    public function getCurrentGame($chatGroupId)
    {
        $games = $this->gameRepository->findRunningGames($chatGroupId);

        if (count($games) === 0) {
            throw new NoGameRunningException();
        }

        $game = reset($games);

        return $game;
    }

    public function heartbeatExpiredGames()
    {
        $games = $this->gameRepository->getAllActiveGames();
        $currentTime = new \DateTime();
        $expiredGames = [];
        /** @var Game $game */
        foreach ($games as $game) {
            if ($game->isExpired($currentTime)) {
                if ($game->getState() === Game::GATHER_USERS) {
                    try {
                        $this->beginGame($game->getChatGroup());
                    } catch (NotEnoughPlayersException $e) {
                        $this->endGameForGroup($game->getChatGroup());
                    }
                } elseif ($game->getState() === Game::GATHER_ANSWERS) {
                    /** @var Answer $pendingAnswer */
                    foreach ($game->getPendingAnswers() as $pendingAnswer) {
                        $pendingAnswer->setResponse('(No Answer)');
                        $this->answerRepository->updateAnswer($pendingAnswer);
                    }
                    $this->beginVoting($game);
                } elseif ($game->getState() === Game::GATHER_VOTES) {
                    $this->advanceGame($game);
                } elseif ($game->getState() === Game::END) {
                    throw new \RuntimeException('Heartbeat should not be called on ended games');
                } else {
                    throw new \RuntimeException('Unknown state');
                }
                $this->gameRepository->updateGame($game);
                $expiredGames[] = $game;
            }
        }

        return $expiredGames;
    }

    public function heartbeatWarningStates()
    {
        $games = $this->gameRepository->getAllActiveGames();
        $currentTime = new \DateTime();
        $gamesToAnnounce = [];
        /** @var Game $game */
        foreach ($games as $game) {
            $warningState = $game->getWarningState();
            $warningStateToAnnounce = $game->warningStateToAnnounce($currentTime);
            if (!$warningState->equals($warningStateToAnnounce) && false === $game->isExpired($currentTime)) {
                $gamesToAnnounce[] = $game;
                $game
                    ->setWarningState($warningStateToAnnounce);
                $this->gameRepository->updateGame($game);
            } else {
                // do nothing
            }
        }

        return $gamesToAnnounce;
    }

    public function getTopScores($chatId)
    {
        $games = $this->gameRepository->findBy(
            [
                'chatGroup' => $chatId,
                'state' => Game::END
            ]
        );

        $topScores = [];
        /** @var Game $game */
        foreach ($games as $game) {
            $scoreBoard = $game->getScoreBoard();
            foreach ($scoreBoard as $userId => $score) {
                $points = 0;
                if (isset($topScores[$userId])) {
                    $points = $topScores[$userId]['points'];
                }
                $topScores[$userId] = [
                    'user' => $score['user'],
                    'points' => $score['points'] + $points
                ];
            }
        }

        uasort($topScores, function ($a, $b) {
            return $b['points'] - $a['points'];
        });

        return [$topScores, count($games)];
    }

}