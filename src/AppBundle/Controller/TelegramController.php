<?php

namespace AppBundle\Controller;


use AppBundle\Conversation\Game;
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
        $cache = new SymfonyCache(
            new PdoAdapter($this->getDoctrine()->getConnection())
        );
        $botman = BotManFactory::create([
            'telegram' => [
                'token' => $this->getParameter('telegram_token')
            ],
            $cache
        ]);

        $botman->hears('play', function (BotMan $bot) {
            $bot->startConversation(new Game());
        });

        $botman->listen();

        return new Response();
    }
}