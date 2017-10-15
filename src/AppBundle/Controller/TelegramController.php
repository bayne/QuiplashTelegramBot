<?php

namespace AppBundle\Controller;


use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Telegram\TelegramDriver;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
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
        $botman = BotManFactory::create([
            'telegram' => [
                'token' => $this->getParameter('telegram_token')
            ]
        ]);

        $botman->hears('test', function (BotMan $bot) {
            $bot->reply('Yay!');
        });

        $botman->listen();

        return new Response();
    }
}