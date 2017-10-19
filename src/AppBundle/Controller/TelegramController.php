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
            $chatGroup = base64_decode($chatGroup);
            if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
                return;
            }
            
            $botMan->say('Hello! I will send you private messages here related to the games you are in. Keep a look out.', $botMan->getMessage()->getRecipient());
            
            $this->join($botMan, $chatGroup, $botMan->getMessage()->getSender());
        });

        $botman->hears('/new(.*)', function (BotMan $bot) {
            if ($bot->getMessage()->getSender() === $bot->getMessage()->getRecipient()) {
                return;
            }
            
            $senderId = $bot->getMessage()->getSender();
            /** @var Entity\Player $host */
            $host = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($senderId, $bot->getUser()->getFirstName());
            /** @var Entity\Game $game */
            $games = $this->getDoctrine()->getRepository(Entity\Game::class)->findRunningGames($bot->getMessage()->getRecipient());
            if (count($games) > 0) {
                return;
            }

            $game = new Entity\Game($host, $bot->getMessage()->getRecipient());
            
            $this->getDoctrine()->getManager()->persist($game);
            $encoded = base64_encode($bot->getMessage()->getRecipient());
            $bot->say('Starting a new game! Other players, click this then press start to join: http://t.me/QuiplashModeratorBot?start='.$encoded, $bot->getMessage()->getRecipient());
            $bot->reply('Once everyone has joined, the host must type /begin to start the game');

            $this->join($bot, $bot->getMessage()->getRecipient(), $senderId);
        });
        
        $botman->hears('/end(.*)', function (BotMan $bot) {
            if ($bot->getMessage()->getSender() === $bot->getMessage()->getRecipient()) {
                return;
            }
            
            $senderId = $bot->getMessage()->getSender();
            /** @var Entity\Player $host */
            $host = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($senderId, $bot->getUser()->getFirstName());
            /** @var Entity\Game $game */
            $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($host);
            if ($game === null) {
                return;
            }
            
            if ($game->getHost()->getId() === $senderId) {
                $game->setState(Entity\Game::END);

                $this->getDoctrine()->getManager()->persist($game);

                $bot->say('Ending the game!', $bot->getMessage()->getRecipient());                          
            } else {
                $bot->say('Only the host can end the game', $bot->getMessage()->getRecipient());
            }
            
        });

        $botman->hears('/begin(.*)', function (BotMan $botMan) {
            if ($botMan->getMessage()->getSender() === $botMan->getMessage()->getRecipient()) {
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
                    

                    if (!in_array($player1->getId(), $alreadyQuestioned)) {
                        $botMan->say('Reply to the following prompt with something witty: ', $player1->getId());
                        $botMan->say($question->getText(), $player1->getId());
                    }
                    if (!in_array($player2->getId(), $alreadyQuestioned)) {
                        $botMan->say('Reply to the following prompt with something witty: ', $player1->getId());
                        $botMan->say($question->getText(), $player2->getId());
                    }
                    
                    $alreadyQuestioned[] = $player1->getId();
                    $alreadyQuestioned[] = $player2->getId();
                }
                
                $game->setState(Entity\Game::GATHER_ANSWERS);
                $this->getDoctrine()->getManager()->persist($game);

                $botMan->say('Game has begun! I sent each of you a prompt..', $botMan->getMessage()->getRecipient());
            } else {
                $botMan->say('Only the host can begin the game', $botMan->getMessage()->getRecipient());
            }

        });
        
        $botman->hears('/vote {response}', function (BotMan $botMan, $response) {
            $this->gatherVotes($botMan, $response);
        });

        $botman->fallback(function (BotMan $botMan) {
            $message = $botMan->getMessage();
            
            if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
                return;
            }
            
            if ($message->isFromBot()) {
                return;
            }

            $player = $this->getDoctrine()->getRepository(Entity\Player::class)->findOrCreate($message->getSender(), $botMan->getUser()->getFirstName());
            /** @var Entity\Game $game */
            $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_ANSWERS);
            if ($game === null) {
                return;
            }

            $answer = $this->getDoctrine()->getRepository(Entity\Answer::class)->getNextForUser($player,$game);
            
            if ($answer === null) {
                if ($game->allAnswersAreIn()) {

                    $game->setState(Entity\Game::GATHER_VOTES);
                    $this->getDoctrine()->getManager()->persist($game);
                    
                    $botMan->say('Now its time to vote on your favorite answers', $game->getChatGroup());

                    $this->vote(0, $game, $botMan);
                }
                return;
            }
            
            $answer->setResponse($message->getText());
            $this->getDoctrine()->getManager()->persist($answer);
            
            $this->getDoctrine()->getManager()->flush();

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
        $game->setCurrentQuestion($question);
        $this->getDoctrine()->getManager()->persist($game);
        $answers = $this->getDoctrine()->getRepository(Entity\Answer::class)->findBy(['question' => $question, 'game' => $game]);

        $botMan->say("The prompt: ".'"'.$question->getText().'"', $game->getChatGroup());
        $botMan->say("Look at your private messages and choose the best answer", $game->getChatGroup());

        foreach ($game->getPlayers() as $player) {
            
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

        /** @var Entity\Game $game */
        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findOneBy(['chatGroup' => $chatGroup, 'state' => Entity\Game::GATHER_PLAYERS]);

        if ($game === null) {
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
            return;
        }

        if ($game->getState() !== Entity\Game::GATHER_VOTES) {
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
        
        $botMan->say($player->getName().' voted', $game->getChatGroup());

        $game = $this->getDoctrine()->getRepository(Entity\Game::class)->findCurrentGameForPlayer($player, Entity\Game::GATHER_VOTES);

        $questionNumber = 0;
        foreach ($game->getQuestions() as $i => $question) {
            if ($question->getId() === $answer->getQuestion()->getId()) {
                $questionNumber = $i;
                break;
            }
        }

        if ($game->votesAreTallied($questionNumber)) {
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

            $question = $game->getQuestions()->offsetGet($questionNumber);

            /** @var Entity\Answer[] $answers */
            $answers = $this->getDoctrine()->getRepository(Entity\Answer::class)->findBy(['question' => $question, 'game' => $game]);
            $roundResults = $question->getText();

            foreach ($answers as $answer) {
                $roundResults .= "\n".$answer->getResponse().' ('.$answer->getPlayer()->getName().' +'.count($answer->getVotes()).')';
            }

            $botMan->say($roundResults, $game->getChatGroup());

            $this->vote($questionNumber+1, $game, $botMan);
        }
    }
}