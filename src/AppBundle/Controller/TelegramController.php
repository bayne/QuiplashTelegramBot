<?php

namespace AppBundle\Controller;

use BotMan\BotMan\Messages\Incoming;
use BotMan\BotMan\Messages\Outgoing;
use AppBundle\Entity;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\SymfonyCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Telegram\TelegramDriver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TelegramController extends Controller
{
    /**
     * @Route("/telegram", name="telegramListen")
     *
     * @param Request $request
     * @return Response
     */
    public function listenAction(Request $request)
    {

        DriverManager::loadDriver(TelegramDriver::class);
        $cache = new SymfonyCache(new PdoAdapter($this->getDoctrine()->getConnection()));
        $botman = BotManFactory::create(
            [
                'telegram' => [
                    'token' => $this->getParameter('telegram_token'),
                ],
            ], 
            $cache,
            $request
        );

        $botman->hears('/start {chatGroup}', function (BotMan $botMan, $chatGroup) {
            $this->getLogger()->info('joined');
            $chatGroup = base64_decode($chatGroup);
            if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
                return;
            }
            
            $botMan->say('Hello! I will send you private messages here related to the game you are in. Keep a look out.', $botMan->getMessage()->getRecipient());
            
            $this->join($botMan, $chatGroup, $botMan->getMessage()->getSender());
        });

        $botman->hears('/new(.*)', function (BotMan $bot) {
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
                [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES)]
            );
            
            if (count($games) > 0) {
                $this->getLogger()->info('no games');
                return;
            }

            $game = new Entity\Game($player, $bot->getMessage()->getRecipient());
            
            $this->getDoctrine()->getManager()->persist($game);
            $bot->say('Starting a new game! Other players, click this then press start to join: '.$this->getJoinLink($bot->getMessage()->getRecipient()), $bot->getMessage()->getRecipient());
            $bot->reply('Once everyone has joined, the host must type /begin to start the game');

            $this->join($bot, $bot->getMessage()->getRecipient(), $senderId);
        });
        
        $botman->hears('/end(.*)', function (BotMan $bot) {
            
            $senderId = $bot->getMessage()->getSender();
            /** @var Entity\Player $player */
            $player = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($senderId, $bot->getUser()->getFirstName());
            /** @var Entity\Game $game */
            $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player);
            if ($game === null) {
                $this->getLogger()->info('missing game to end');
                return;
            }
            
            $game->setState(Entity\Game::END);

            $this->getDoctrine()->getManager()->persist($game);

            $bot->say('Ending the game!', $game->getChatGroup());                          
            
        });

        $botman->hears('/begin(.*)', function (BotMan $botMan) {
            if ($botMan->getMessage()->getSender() === $botMan->getMessage()->getRecipient()) {
                $this->getLogger()->info('game cannot begin from here');
                return;
            }
            
            $message = $botMan->getMessage();
            $player = $this
                ->getDoctrine()
                ->getRepository(Entity\Player::class)
                ->findOrCreate($message->getSender(), $botMan->getUser()->getFirstName())
            ;
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
            
            if ($game->getPlayers()->count() < 3) {
                $botMan->reply('You need at least three players before the game can start');
                return;
            }

            if ($message->getSender() === $game->getHost()->getId()) {

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
                    ->generateQuestions(count($playerPairs))
                ;

                foreach ($questions as $question) {
                    $game->getQuestions()->add($question);
                }

                $this->getDoctrine()->getManager()->persist($game);
                
                $questionsCollection = $game->getQuestions();
                
                $alreadyQuestioned = [];
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
                    

                    $prompt = "Reply to the following prompt with something witty: \n".$question->getText();
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

                $botMan->say('Game has begun! I sent each of you a prompt..', $botMan->getMessage()->getRecipient());
            } else {
                $botMan->say('Only the host can begin the game', $botMan->getMessage()->getRecipient());
            }

        });
        
        $botman->hears('/status(.*)', function (BotMan $botMan) {
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
                $message = 'The game is currently gathering players. Click the following link to join: '.$this->getJoinLink($chatGroup);
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
                $message .= "\n".implode("\n", $players);
            }
            
            if ($game->getState() === Entity\Game::GATHER_VOTES) {
                $message = 'The game is currently gathering votes. The following players need to vote on which answer they like the best:';
                foreach ($game->getMissingPlayerVotes() as $player) {
                    $message .= "\n".$player->getName();
                }
            }
            
            if ($game->getState() === Entity\Game::END) {
                $scoreBoard = $game->getScoreBoard();
                $message = "The game has ended:\n";
                $message .= 'Winner: '.reset($scoreBoard)['player']->getName();
                foreach ($scoreBoard as $score) {
                    $message .= "\n".$score['player']->getName().': '.$score['points']." pts";
                }
            }

            $botMan->say($message, $chatGroup);
        });
        
        $botman->hears('/vote {response}', function (BotMan $botMan, $response) {
            $this->gatherVotes($botMan, $response);
        });

        $botman->fallback(function (BotMan $botMan) {
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

            $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->getNextForUser($player,$game);
            
            if ($answer === null) {
                return;
            }
            
            $answer->setResponse($message->getText());
            $this->getDoctrine()->getManager()->persist($answer);
            $this->getDoctrine()->getManager()->flush();
            $this->getDoctrine()->getManager()->clear();

            $game = $this->getDoctrine()->getRepository(Entity\Game::class)->find($game->getId());
           
            if ($game->allAnswersAreIn()) {
                $game->setState(Entity\Game::GATHER_VOTES);
                $this->getDoctrine()->getManager()->persist($game);

                $botMan->say('Now its time to vote on your favorite answers', $game->getChatGroup());

                $this->vote(0, $game, $botMan);
                return;
            }
            $this->getLogger()->info('misisng answer to respond');

            /** @var Entity\Answer $answer */
            $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->getNextForUser($player, $game);
            
            $botMan->say($answer->getQuestion()->getText(), $player->getId());
        });

        $botman->listen();

        $this->getDoctrine()->getManager()->flush();

        return new Response();
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
        
        $existingGames = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_ANSWERS);
        $existingGames = array_merge(
            [$existingGames],
            [$this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES)]
        );
        
        if (count($existingGames) > 0) {
            $botMan->reply('You are already in a different game!');
        }

        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findOneBy(['chatGroup' => $chatGroup, 'state' => Entity\Game::GATHER_PLAYERS]);

        if ($game === null) {
            $this->getLogger()->info('cannot join missing game');
            return;
        }

        if (false === $game->getPlayers()->contains($player)) {
            $game->getPlayers()->add($player);
            $this->getDoctrine()->getManager()->persist($game);
            $botMan->say($botMan->getUser()->getFirstName() . ' has joined the game!', $chatGroup);
        }
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

        $questionNumber = 0;
        foreach ($game->getQuestions() as $i => $question) {
            if ($question->getId() === $answer->getQuestion()->getId()) {
                $questionNumber = $i;
                break;
            }
        }

        if ($game->votesAreTallied($questionNumber)) {

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
        return $this->get('logger');
    }
}