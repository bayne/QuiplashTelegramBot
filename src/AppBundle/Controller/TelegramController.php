<?php

namespace AppBundle\Controller;

use AppBundle\MiddlewarePersister;
use AppBundle\Telegram\QuiplashCommands;
use BotMan\BotMan\Http\Curl;
use BotMan\BotMan\Messages\Incoming;
use BotMan\BotMan\Messages\Outgoing;
use AppBundle\Entity;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\SymfonyCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Telegram\TelegramDriver;
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TelegramController extends Controller
{
    private function getBot(Request $request)
    {
        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        /** @var LoggerInterface $logger */
        $logger = $this->get('logger');
        DriverManager::loadDriver(TelegramDriver::class);
        $cache = new SymfonyCache(new PdoAdapter($this->getDoctrine()->getConnection()));
        $middleware = new MiddlewarePersister(
            $em,
            $logger
        );
        
        $botman = BotManFactory::create(
            [
                'telegram' => [
                    'token' => $this->getParameter('telegram_token'),
                ],
            ], 
            $cache,
            $request
        );       
        
        $botman->middleware->sending($middleware);
        $botman->middleware->received($middleware);
        return $botman;
    }
    
    public function buildRequest(Entity\Game $game)
    {
        return new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            json_encode(
                [
                    "update_id" => -1,
                    "message" => [
                        "message_id" => -1,
                        "from" => [
                            "id" => -1,
                            "is_bot" => false,
                            "first_name" => "Heart",
                            "last_name" => "Beat",
                            "language_code" => "en-US"
                        ],
                        "chat" => [
                            "id" => $game->getChatGroup(),
                        ],
                        "text" => "/heartbeat@QuiplashModeratorBot",
                    ]
                ]
            )
        );
    }
    
    /**
     * @Route("/heartbeat", name="telegramHeartbeat")
     * 
     * @param Request $request
     */
    public function heartbeatAction(Request $request)
    {
        $this->getDoctrine()->getConnection()->beginTransaction();
        $quiplashCommands = new QuiplashCommands($this->getDoctrine(), $this->get('logger'), $this->getParameter('telegram_token'));

        $activeGames = $this->getDoctrine()->getRepository(Entity\Game::class)->getAllActiveGames();
        /** @var Entity\Game $game */
        foreach ($activeGames as $game) {
            $botMan = $this->getBot($this->buildRequest($game));
            $botMan->hears('/heartbeat(.*)', function (BotMan $botMan) use ($game, $quiplashCommands) {
                $quiplashCommands->handleHeartbeat($botMan, $game);
            });
            $botMan->listen();
        }
        $this->getDoctrine()->getConnection()->commit();
    }
    
    /**
     * @Route("/telegram", name="telegramListen")
     *
     * @param Request $request
     * @return Response
     */
    public function listenAction(Request $request)
    {
        $this->get('logger')->info('raw content', [
            'content' => $request->getContent()
        ]);
        
        $this->getDoctrine()->getConnection()->beginTransaction();
        $botman = $this->getBot($request);
        
        $quiplashCommands = new QuiplashCommands($this->getDoctrine(), $this->get('logger'), $this->getParameter('telegram_token'));

        $botman->hears('/start {chatGroup}', function (BotMan $botMan, $chatGroup) use ($quiplashCommands) {
            $quiplashCommands->handleStart($botMan, $chatGroup);
        });

        $botman->hears('/new(.*)', function (BotMan $bot) use ($quiplashCommands) {
            $quiplashCommands->handleNew($bot);
        });
        
        $botman->hears('/end(.*)', function (BotMan $bot) use ($quiplashCommands) {
            $quiplashCommands->handleEnd($bot);
        });

        $botman->hears('/begin(.*)', function (BotMan $botMan) use ($quiplashCommands) {
            $quiplashCommands->handleBegin($botMan);
        });
        
        $botman->hears('/status(.*)', function (BotMan $botMan) use ($quiplashCommands) {
            $quiplashCommands->handleStatus($botMan);
        });
        
        $botman->hears('/vote {response}', function (BotMan $botMan, $response) use ($quiplashCommands) {
            $quiplashCommands->handleVote($botMan, $response);
        });

        $botman->fallback(function (BotMan $botMan) use ($quiplashCommands) {
            $quiplashCommands->handleFallback($botMan);
        });

        $botman->listen();

        $this->getDoctrine()->getManager()->flush();
        $this->getDoctrine()->getConnection()->commit();

        return new Response();
    }
}