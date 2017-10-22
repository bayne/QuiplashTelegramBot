<?php

namespace AppBundle\Telegram;


use BotMan\BotMan\Messages\Incoming;
use BotMan\BotMan\Messages\Outgoing;
use AppBundle\Entity;
use BotMan\BotMan\BotMan;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class QuiplashCommands
{
    /**
     * @var RegistryInterface
     */
    private $doctrine;
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $telegramToken;


    /**
     * QuiplashCommands constructor.
     * @param RegistryInterface $doctrine
     * @param LoggerInterface $logger
     * @param $telegramToken
     */
    public function __construct(
        RegistryInterface $doctrine,
        LoggerInterface $logger,
        $telegramToken
    ) {
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->telegramToken = $telegramToken;
    }

    public function handleStart(BotMan $botMan, $chatGroup)
    {
        $this->getLogger()->info('joined');
        $chatGroup = base64_decode($chatGroup);
        if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
            return;
        }

        if ($this->join($botMan, $chatGroup, $botMan->getMessage()->getSender())) {
            $botMan->say('Hello! I will send you private messages here related to the game you are in. Keep a look out.', $botMan->getMessage()->getRecipient());
        }
    }

    public function handleNew(BotMan $bot)
    {
        if ($bot->getMessage()->getSender() === $bot->getMessage()->getRecipient()) {
            $this->getLogger()->info('sent to wrong group');
            return;
        }

        $senderId = $bot->getMessage()->getSender();
        /** @var Entity\Player $player */
        $player = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($senderId, $bot->getUser()->getFirstName());
        /** @var Entity\Game $game */
        $games = array_merge(
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_ANSWERS)],
            $this->getDoctrine()->getRepository(Entity\Game::class)->findRunningGames($bot->getMessage()->getRecipient()),
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES)],
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_PLAYERS)]
        );
        $games = array_filter($games);

        if (count($games) > 0) {
            $this->getLogger()->info('multiple games');
            return;
        }

        $game = new Entity\Game($player, $bot->getMessage()->getRecipient());

        $this->getDoctrine()->getManager()->persist($game);
        $this->getDoctrine()->getManager()->flush();

        $bot->sendRequest(
            'sendMessage',
            [
                'chat_id' => $game->getChatGroup(),
                'text' => "Starting a new game! Click the Join button below then click start to join.\nOnce everyone has joined, the host can type /begin to start the game",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'Join',
                                'url' => $this->getJoinLink($game->getChatGroup())
                            ]
                        ]
                    ]
                ])
            ]
        );
    }

    public function handleEnd(BotMan $bot)
    {

        $senderId = $bot->getMessage()->getSender();
        /** @var Entity\Player $player */
        $player = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($senderId, $bot->getUser()->getFirstName());
        /** @var Entity\Game $game */
        $games = $this->getDoctrine()->getRepository(Entity\Game::class)->findRunningGames($bot->getMessage()->getRecipient());
        if (count($games) === 0) {
            $this->getLogger()->info('missing game to end');
            return;
        }
        $game = reset($games);
        
        if ($player->getId() !== $game->getHost()->getId()) {
            $bot->say('Only the host can end the game', $game->getChatGroup());
            return;
        }

        $game->setState(Entity\Game::END);

        $this->getDoctrine()->getManager()->persist($game);

        $bot->say('Ending the game!', $game->getChatGroup());

    }

    public function handleBegin(BotMan $botMan)
    {
        if ($botMan->getMessage()->getSender() === $botMan->getMessage()->getRecipient()) {
            $this->getLogger()->info('game cannot begin from here');
            return;
        }

        $message = $botMan->getMessage();
        $player = $this
            ->getDoctrine()
            ->getRepository(Entity\Player::class)
            ->findOrCreate($message->getSender(), $botMan->getUser()->getFirstName());
        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findOneBy(
            [
                'state' => Entity\Game::GATHER_PLAYERS,
                'chatGroup' => $message->getRecipient(),
            ]
        );
        if ($game === null) {
            $this->getLogger()->info('no game to begin');
            return;
        }

        if ($message->getSender() === $game->getHost()->getId()) {
            if (!$game->getPlayers()->contains($game->getHost())) {
                $botMan->say('You need to click the Join button', $botMan->getMessage()->getRecipient());
            } else {
                if ($game->hasEnoughPlayers()) {
                    $this->beginGame($game, $botMan);
                } else {
                    $botMan->reply('You need at least three players before the game can start');
                }
            }
        } else {
            $botMan->say('Only the host can begin the game', $botMan->getMessage()->getRecipient());
        }

    }

    public function handleStatus(BotMan $botMan)
    {
        $chatGroup = $botMan->getMessage()->getRecipient();
        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findBy(['chatGroup' => $chatGroup], ['id' => 'desc']);
        if (count($game) > 0) {
            $game = reset($game);
        } else {
            $botMan->say('There has been no games started in this chat', $chatGroup);
        }

        $message = '';

        if ($game->getState() === Entity\Game::GATHER_PLAYERS) {
            $botMan->sendRequest(
                'sendMessage',
                [
                    'chat_id' => $game->getChatGroup(),
                    'text' => "Gathering players for a new game.\nClick the Join button below then click start to join.\nOnce everyone has joined, the host can type /begin to start the game",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Join',
                                    'url' => $this->getJoinLink($game->getChatGroup())
                                ]
                            ]
                        ]
                    ])
                ]
            );
            return;
        }

        if ($game->getState() === Entity\Game::GATHER_ANSWERS) {
            $message = 'The game is currently gathering answers. The following players need to provides answers to their prompts:';
            $players = [];
            foreach ($game->getAnswers() as $answer) {
                if ($answer->isPending()) {
                    $players[] = $answer->getPlayer()->getName();
                }
            }

            $players = array_unique($players);
            $message .= "\n" . implode("\n", $players);
        }

        if ($game->getState() === Entity\Game::GATHER_VOTES) {
            $message = 'The game is currently gathering votes. The following players need to vote on which answer they like the best:';
            foreach ($game->getMissingPlayerVotes() as $player) {
                $message .= "\n" . $player->getName();
            }
        }

        if ($game->getState() === Entity\Game::END) {
            $scoreBoard = $game->getScoreBoard();
            $message = "The game has ended:\n";
            $message .= 'Winner: ' . reset($scoreBoard)['player']->getName();
            foreach ($scoreBoard as $score) {
                $message .= "\n" . $score['player']->getName() . ': ' . $score['points'] . " pts";
            }
        }

        $botMan->say($message, $chatGroup);
    }

    public function handleVote(BotMan $botMan, $response)
    {
        $this->gatherVotes($botMan, $response);
    }

    public function handleFallback(BotMan $botMan)
    {
        $message = $botMan->getMessage();

        if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
            $this->getLogger()->info('freeform only in private');
            return;
        }

        if ($message->isFromBot()) {
            $this->getLogger()->info('ignore from bot');
            return;
        }

        $player = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($message->getSender(), $botMan->getUser()->getFirstName());
        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_ANSWERS);
        if ($game === null) {
            $this->getLogger()->info('misisng current game for freeform');
            return;
        }

        $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->getNextForUser($player, $game);

        if ($answer === null) {
            return;
        }

        $answer->setResponse($message->getText());
        $this->getDoctrine()->getManager()->persist($answer);
        $this->getDoctrine()->getManager()->flush();
        $this->getDoctrine()->getManager()->clear();

        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findOneBy(['id' => $game->getId()]);

        if ($game->allAnswersAreIn()) {
            $game->setState(Entity\Game::GATHER_VOTES);
            $this->getDoctrine()->getManager()->persist($game);

            $botMan->say('Now its time to vote on your favorite answers', $game->getChatGroup());

            $this->vote(0, $game, $botMan);
            return;
        }

        /** @var Entity\Answer $answer */
        $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->getNextForUser($player, $game);
        if ($answer === null) {
            $botMan->say('Answers submitted, waiting for other players', $player->getId());
            return;
        }

        $botMan->say($answer->getQuestion()->getText(), $player->getId());
    }
    
    protected function getDoctrine()
    {
        return $this->doctrine;
    }
    
    private function vote($questionNumber, Entity\Game $game, BotMan $botMan)
    {
        $question = $game->getQuestions()->offsetGet($questionNumber);

        $answers = $this->getDoctrine()->getRepository(Entity\Answer::class)->findBy(['question' => $question, 'game' => $game]);
        $prompt = '';
        if ($game->getCurrentQuestion() === null) {
            $prompt .= 'Time for the next question! ';
        }
        $prompt .= "Look at your private messages and choose the best answer\n";
        $prompt .= "The prompt: ".'"'.$question->getText().'"';
        $botMan->say($prompt, $game->getChatGroup());

        $game->setCurrentQuestion($question);
        $this->getDoctrine()->getManager()->persist($game);

        foreach ($game->getVotersForCurrentQuestion() as $player) {

            $prompt = Outgoing\Question::create("Choose the best answer to the following prompt: ".'"'.$question->getText().'"');
            $prompt->addButtons(
                array_map(
                    function (Entity\Answer $answer) use ($questionNumber) {
                        return Outgoing\Actions\Button::create($answer->getResponse())->value("/vote {$answer->getResponse()}");
                    },
                    $answers
                )
            );
            $botMan->say($prompt, $player->getId());
        }
    }

    private function join(BotMan $botMan, $chatGroup, $senderId)
    {
        $player = $this
            ->getDoctrine()
            ->getRepository(Entity\Player::class)
            ->findOrCreate($senderId, $botMan->getUser()->getFirstName());

        $existingGames = array_merge(
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_ANSWERS)],
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES)],
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_PLAYERS)]
        );

        $existingGames = array_filter($existingGames);

        if (count($existingGames) > 0) {
            $botMan->reply('You are already in a different game!');
            return false;
        }

        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findOneBy(['chatGroup' => $chatGroup, 'state' => Entity\Game::GATHER_PLAYERS]);

        if ($game === null) {
            $this->getLogger()->info('cannot join missing game');
            return false;
        }

        if (false === $game->getPlayers()->contains($player)) {
            $game->getPlayers()->add($player);
            $this->getDoctrine()->getManager()->persist($game);
            $botMan->say($botMan->getUser()->getFirstName() . ' has joined the game!', $chatGroup);
        }
        return true;
    }

    private function gatherVotes(BotMan $botMan, $response)
    {
        $message = $botMan->getMessage();
        $player = $this->getDoctrine()->getRepository(Entity\Player::class)->find($message->getSender());
        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES);
        if ($game === null) {
            $this->getLogger()->info('no game to gather votes');
            return;
        }

        if ($game->getState() !== Entity\Game::GATHER_VOTES) {
            $this->getLogger()->info('game not in gather votes');
            return;
        }

        $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->findOneBy([
            'response' => $response,
            'game' => $game,
            'question' => $game->getCurrentQuestion()
        ]);

        $votes = $this->getDoctrine()->getRepository(Entity\Vote::class)->findBy([
            'player' => $player,
            'question' => $answer->getQuestion(),
            'game' => $game
        ]);

        if (count($votes) > 0) {
            $botMan->say('You cannot vote more than once!', $player->getId());
            return;
        }

        $vote = new Entity\Vote();
        $vote->setAnswer($answer);
        $vote->setPlayer($player);
        $vote->setGame($game);
        $vote->setQuestion($answer->getQuestion());

        $this->getDoctrine()->getManager()->persist($vote);
        $this->getDoctrine()->getManager()->flush();
        $this->getDoctrine()->getManager()->clear();

        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES);

        $this->nextQuestion($game, $botMan);
    }

    protected function nextQuestion(Entity\Game $game, BotMan $botMan, $forceNextQuestion = false)
    {
        $questionNumber = 0;
        foreach ($game->getQuestions() as $i => $question) {
            if ($question->getId() === $game->getCurrentQuestion()->getId()) {
                $questionNumber = $i;
                break;
            }
        }

        if ($game->votesAreTallied($questionNumber) || $forceNextQuestion) {

            $question = $game->getQuestions()->offsetGet($questionNumber);

            /** @var Entity\Answer[] $answers */
            $answers = $this->getDoctrine()->getRepository(Entity\Answer::class)->findBy(['question' => $question, 'game' => $game]);
            $roundResults = '';

            foreach ($answers as $answer) {
                $roundResults .= "\n".$answer->getResponse().' ('.$answer->getPlayer()->getName().' +'.count($answer->getVotes()).')';
            }
            $botMan->say($roundResults, $game->getChatGroup());

            if ($questionNumber >= $game->getQuestions()->count() - 1) {
                $game->setState(Entity\Game::END);
                $this->getDoctrine()->getManager()->persist($game);
                $this->getDoctrine()->getManager()->flush();
                $scoreBoard = $game->getScoreBoard();
                $gameOver = 'Game Over! Winner: '.reset($scoreBoard)['player']->getName();
                foreach ($scoreBoard as $score) {
                    $gameOver .= "\n".$score['player']->getName().': '.$score['points']." pts";
                }

                $botMan->say($gameOver, $game->getChatGroup());

                return;
            }

            $this->vote($questionNumber+1, $game, $botMan);
        }
    }

    protected function beginGame(Entity\Game $game, BotMan $botMan)
    {
        $pairPlayers = function (Entity\Game $game) {
            $players1 = [];
            $counts = [];

            foreach ($game->getPlayers() as $player) {
                $counts[$player->getId()] = 0;
            }

            unset($player);

            foreach ($game->getPlayers() as $currentPlayer) {
                $otherPlayers = $game->getPlayers()->filter(function (Entity\Player $player) use ($currentPlayer) {
                    return $player->getId() !== $currentPlayer->getId();
                });

                $otherPlayers = $otherPlayers->toArray();

                shuffle($otherPlayers);

                while (!empty($otherPlayers)) {
                    $otherPlayer = array_pop($otherPlayers);
                    if ($counts[$currentPlayer->getId()] >= 2) {
                        continue;
                    }
                    if ($counts[$otherPlayer->getId()] >= 2) {
                        continue;
                    }

                    $keys = [
                        $currentPlayer->getId(),
                        $otherPlayer->getId()
                    ];

                    sort($keys);

                    $keyHash = implode('-', $keys);

                    $players1[$keyHash] = [$currentPlayer, $otherPlayer];
                    $counts[$currentPlayer->getId()] += 1;
                    $counts[$otherPlayer->getId()] += 1;
                }
            }

            return $players1;
        };

        $playerPairs = $pairPlayers($game);

        $questions = $this
            ->getDoctrine()
            ->getRepository(Entity\Question::class)
            ->generateQuestions(count($playerPairs));

        foreach ($questions as $question) {
            $game->getQuestions()->add($question);
        }

        $this->getDoctrine()->getManager()->persist($game);

        $questionsCollection = $game->getQuestions();

        $alreadyQuestioned = [];
        /**
         * @var int $i
         * @var Entity\Player $player1
         * @var Entity\Player $player2
         */
        foreach ($playerPairs as $i => list($player1, $player2)) {
            $question = $questionsCollection->current();
            $questionsCollection->next();
            $answer = new Entity\Answer(
                $player1,
                $question,
                $game
            );

            $this->getDoctrine()->getManager()->persist($answer);

            $answer = new Entity\Answer(
                $player2,
                $question,
                $game
            );

            $this->getDoctrine()->getManager()->persist($answer);


            $prompt = "Reply to the following prompt with something witty: \n" . $question->getText();
            if (!in_array($player1->getId(), $alreadyQuestioned)) {
                $botMan->say($prompt, $player1->getId());
                $alreadyQuestioned[] = $player1->getId();
            }
            if (!in_array($player2->getId(), $alreadyQuestioned)) {
                $botMan->say($prompt, $player2->getId());
                $alreadyQuestioned[] = $player2->getId();
            }
        }

        $game->setState(Entity\Game::GATHER_ANSWERS);
        $this->getDoctrine()->getManager()->persist($game);

        $botMan->say('Game has begun! I sent each of you a prompt..', $game->getChatGroup());
    }
    
    public function handleHeartbeat(BotMan $botMan, Entity\Game $game)
    {
        $warningState = $game->warningStateToAnnounce(new \DateTime());
        if (false === $warningState->equals($game->getWarningState())) {
            $this->sendMessage(
                $botMan,
                sprintf('%s seconds', $warningState->getWarningValue()),
                $game->getChatGroup()
            );
            $game->setWarningState($warningState);
            $this->getDoctrine()->getManager()->persist($game);
        }

        if ($game->isExpired()) {
            if ($game->getState() === Entity\Game::GATHER_PLAYERS) {
                if (false === $game->getPlayers()->contains($game->getHost())) {
                    $botMan->say('The host needs to join the game. Ending the game!', $game->getChatGroup());
                } elseif ($game->hasEnoughPlayers()) {
                    $game->setState(Entity\Game::GATHER_ANSWERS);
                    $this->beginGame($game, $botMan);
                } else {
                    $this->sendMessage(
                        $botMan,
                        'You need at least three players to start a game. Ending the game!',
                        $game->getChatGroup()
                    );

                    $game->setState(Entity\Game::END);
                    $this->getDoctrine()->getManager()->persist($game);
                }
            } elseif ($game->getState() === Entity\Game::GATHER_ANSWERS) {
                foreach ($game->getAnswers() as $answer) {
                    if ($answer->isPending()) {
                        $answer->setResponse('No answer');
                        $this->getDoctrine()->getManager()->persist($answer);
                    }
                }
                $game->setState(Entity\Game::GATHER_VOTES);
                $this->getDoctrine()->getManager()->persist($game);
            } elseif ($game->getState() === Entity\Game::GATHER_VOTES) {
                $this->nextQuestion($game, $botMan, true);
            } else {
                // do nothing
            }
        }
        $this->getDoctrine()->getManager()->flush();
    }

    public function getJoinLink($chatGroup)
    {
        $encoded = base64_encode($chatGroup);
        return 'http://t.me/QuiplashModeratorBot?start='.$encoded;
    }

    /**
     * @return LoggerInterface
     */
    private function getLogger()
    {
        return $this->logger;
    }

    private function sendMessage(BotMan $botMan, $text, $chatId)
    {
        $botMan->say(
            $text,
            $chatId
        );
    }
}