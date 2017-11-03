<?php

namespace AppBundle;

use AppBundle\Entity\Answer;
use AppBundle\Entity\Exception\AlreadyInTheGameException;
use AppBundle\Entity\Exception\CannotBeginGameException;
use AppBundle\Entity\Exception\GameAlreadyExistsException;
use AppBundle\Entity\Exception\GameException;
use AppBundle\Entity\Exception\NoAnswersForUserException;
use AppBundle\Entity\Exception\NoGameRunningException;
use AppBundle\Entity\Exception\NotEnoughPlayersException;
use AppBundle\Entity\Game;
use AppBundle\Entity\User;
use AppBundle\Entity\Vote;
use AppBundle\Repository\GameRepository;
use AppBundle\Repository\QuestionRepository;

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
     * GameManager constructor.
     * @param GameRepository $gameRepository
     * @param QuestionRepository $questionRepository
     */
    public function __construct(
        GameRepository $gameRepository,
        QuestionRepository $questionRepository
    ) {
        $this->gameRepository = $gameRepository;
        $this->questionRepository = $questionRepository;
        $this->lock();
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

    public function newGame(User $host, $chatGroupId)
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
            $chatGroupId
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
            $game->setState(Game::END);
        } else {
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
}