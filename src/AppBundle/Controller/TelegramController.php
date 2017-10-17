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

        $botman->hears('/new', function (BotMan $bot) {
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
            $bot->say('Starting a new game! Other players, click this then press start to join: http://t.me/WittyWilmaBot?start='.$encoded, $bot->getMessage()->getRecipient());
        });
        
        $botman->hears('/end', function (BotMan $bot) {
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

        $botman->hears('/join', function (BotMan $botMan) {
            if ($botMan->getMessage()->getSender() === $botMan->getMessage()->getRecipient()) {
                return;
            }
            
            $message = $botMan->getMessage();

            $this->join($botMan, $message->getRecipient(), $message->getSender());
            
        });

        $botman->hears('/begin', function (BotMan $botMan) {
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
            
            if ($game->getPlayers()->count() < 2) {
                $botMan->reply('You need at least two players before the game can start');
                return;
            }

            if ($message->getSender() === $game->getHost()->getId()) {

                $players = $game->getPlayers()->toArray();
                shuffle($players);
                $players1 = array_chunk($players, 2);
                shuffle($players);
                $players2 = array_chunk($players, 2);
                
                $questions = $this
                    ->getDoctrine()
                    ->getRepository(Entity\Question::class)
                    ->generateQuestions(count($players1) + count($players2))
                ;

                foreach ($questions as $question) {
                    $game->getQuestions()->add($question);
                }

                $this->getDoctrine()->getManager()->persist($game);
                
                $questionsCollection = $game->getQuestions();
                
                foreach ($players1 as $i => list($player1, $player2)) {
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
                    
                    $botMan->say($question->getText(), $player1->getId());
                    $botMan->say($question->getText(), $player2->getId());
                }
                
                foreach ($players2 as $i => list($player1, $player2)) {
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
                }
                
                $game->setState(Entity\Game::GATHER_ANSWERS);
                $this->getDoctrine()->getManager()->persist($game);

                $botMan->say('Game has begun! I sent each of a prompt..', $botMan->getMessage()->getRecipient());
            } else {
                $botMan->say('Only the host can begin the game', $botMan->getMessage()->getRecipient());
            }

        });

        $botman->fallback(function (BotMan $botMan) {
            if ($botMan->getMessage()->getSender() !== $botMan->getMessage()->getRecipient()) {
                return $this->gatherVotes($botMan);
            }
            $message = $botMan->getMessage();
            if ($message->isFromBot()) {
                return;
            }

            $player = $this->getDoctrine()->getRepository(Entity\Player::class)->find($message->getSender());
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

        /** @var Response $response */
        $response = $botMan->sendRequest(
            'sendMessage',
            [
                'chat_id' => $game->getChatGroup(),
                'text' => $question->getText(),
                'reply_markup' => json_encode([
                    'one_time_keyboard' => true,
                    'keyboard' => array_values(array_map(
                        function (Entity\Answer $answer) use ($questionNumber) {
                            return [
                                $answer->getResponse()
                            ];
                        },
                        $answers
                    ))
                ])
            ]
        );
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
    
    private function gatherVotes(BotMan $botMan)
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
            'response' => $message->getText(),
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
            if ($questionNumber >= $game->getQuestions()->count() - 1) {
                $game->setState(Entity\Game::END);
                $this->getDoctrine()->getManager()->persist($game);
                $this->getDoctrine()->getManager()->flush();
                $scoreBoard = $game->getScoreBoard();
                $gameOver = 'Game Over! Winner: '.reset($scoreBoard)['player']->getName();
                foreach ($scoreBoard as $score) {
                    $gameOver .= "\n".$score['player']->getName().': '.$score['points']." pts";
                }

                $botMan->reply($gameOver);

                return;
            }

            $question = $game->getQuestions()->offsetGet($questionNumber);

            /** @var Entity\Answer[] $answers */
            $answers = $this->getDoctrine()->getRepository(Entity\Answer::class)->findBy(['question' => $question, 'game' => $game]);
            $roundResults = $question->getText();

            foreach ($answers as $answer) {
                $roundResults .= "\n".$answer->getResponse().' ('.$answer->getPlayer()->getName().' +'.count($answer->getVotes()).')';
            }

            $botMan->reply($roundResults);

            $this->vote($questionNumber+1, $game, $botMan);
        }
    }
}