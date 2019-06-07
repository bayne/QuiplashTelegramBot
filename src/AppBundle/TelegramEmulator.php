<?php

namespace AppBundle;

use Bayne\Serializer\Normalizer\GetSetExcludeNormalizer;
use Bayne\Telegram\Bot\Type\CallbackQuery;
use Bayne\Telegram\Bot\Type\Chat;
use Bayne\Telegram\Bot\Type\Message;
use Bayne\Telegram\Bot\Type\Update;
use Bayne\Telegram\Bot\Type\User;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Serializer;

class TelegramEmulator
{
    private $updateId = 0;
    private $users = [];
    /**
     * @var EntityManager
     */
    private $em;
    /**
     * @var Serializer
     */
    private $serializer;
    /**
     * @var Client
     */
    private $client;

    /**
     * TelegramEmulator constructor.
     * @param EntityManager $em
     * @param Client $client
     */
    public function __construct(EntityManager $em, Client $client)
    {
        $this->em = $em;
        $this->serializer = new Serializer(
            [
                new GetSetExcludeNormalizer(
                    null,
                    new CamelCaseToSnakeCaseNameConverter()
                )
            ],
            [
                new JsonEncoder()
            ]
        );
        $this->client = $client;
    }

    public function getUser($name)
    {
        $user = $this->users[$name] ?? (new User())
            ->setFirstName($name)
            ->setLastName($name)
            ->setIsBot(false)
            ->setId(crc32($name))
            ->setUsername($name)
        ;

        $this->users[$name] = $user;
        return $user;
    }

    public function userSays($name, $text, $isCallback = false)
    {

        $user = $this->getUser($name);

        $message = (new Message())
            ->setFrom($user)
            ->setText($text)
            ->setChat((new Chat())->setId(1));

        $update = new Update();
        $update->setUpdateId($this->updateId++);
        if ($isCallback) {
            $update
                ->setCallbackQuery(
                    (new CallbackQuery())
                        ->setFrom($user)
                        ->setData($text)
                        ->setMessage($message)
                )
            ;
        } else {
            $update
                ->setMessage($message)
            ;
        }

        $crawler = $this->client->request(
            'post',
            '/telegram/quiplash',
            [],
            [],
            [],
            $this->serializer->serialize(
                $update,
                'json'
            )
        );

        $this->em->clear();
        $this->em->flush();
    }



}