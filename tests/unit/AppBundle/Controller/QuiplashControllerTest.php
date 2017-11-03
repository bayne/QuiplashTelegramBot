<?php

namespace AppBundle\Controller;


use AppBundle\AppBundle;
use AppBundle\Entity\Game;
use AppBundle\Entity\Question;
use AppBundle\GameManager;
use Bayne\Serializer\Normalizer\GetSetExcludeNormalizer;
use Bayne\Telegram\Bot\Client;
use Bayne\Telegram\Bot\Object\CallbackQuery;
use Bayne\Telegram\Bot\Object\Chat;
use Bayne\Telegram\Bot\Object\Message;
use Bayne\Telegram\Bot\Object\Update;
use Bayne\Telegram\Bot\Object\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Serializer;
use AppBundle\Entity;

class QuiplashControllerTest extends WebTestCase
{

    /**
     * @var Serializer
     */
    private $serializer;
    /**
     * @var \Symfony\Bundle\FrameworkBundle\Client
     */
    private $client;
    /**
     * @var Client
     */
    private $telegramClient;
    private $updateId = 0;
    private $users = [];
    private $em;

    private function getQuestion()
    {
        $question = new Question(
            'what'
        );

        return $question;
    }

    protected function setUp()
    {
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
        $this->client = self::createClient();

        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $schemaTool = new SchemaTool($this->em);
        $metaDatas = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metaDatas);
        $schemaTool->createSchema($metaDatas);


        for ($i = 0; $i < 1000; $i++) {
            $this->em->persist((new Question('text')));
        }
        $this->em->flush();


    }

    public function getUser($name)
    {
        $user = $this->users[$name] ?? (new User())
            ->setFirstName($name)
            ->setLastName($name)
            ->setIsBot(false)
            ->setId(count($this->users)+1)
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

    public function userResponds($name)
    {
        $this->em->clear();
        $answerRepository = $this->client->getContainer()->get('doctrine')->getRepository(Entity\Answer::class);
        $answers = $answerRepository->findBy([
            'user'  => $this->getUser($name)->getId()
        ]);
        /** @var Entity\Answer $answer */
        foreach ($answers as $answer) {
            $this->client->request(
                'get',
                '/telegram/quiplash/prompts/'.urlencode(urlencode($answer->getToken())),
                    [
                        'form' => [
                            'submit' => 1,
                            'response' => 'text',
                        ]
                    ]
            );
        }
    }

    public function testFullGame()
    {
        $this->userSays('alice', '/new');
        $this->userSays('bob', '/join_callback', true);
        $this->userSays('charlie', '/join_callback', true);
        $this->userSays('alice', '/begin');

        $this->userResponds('alice');
        $this->userResponds('bob');
        $this->userResponds('charlie');
//
        $this->userSays('alice', '/vote_callback 1', true);
        $this->userSays('bob', '/vote_callback 1', true);
        $this->userSays('charlie', '/vote_callback 1', true);

        $this->userSays('alice', '/vote_callback 1', true);
        $this->userSays('bob', '/vote_callback 1', true);
        $this->userSays('charlie', '/vote_callback 1', true);
    }

    public function testBigGame()
    {
        $playerCount = 10;
        $this->userSays('0', '/new');
        for ($i = 1; $i < $playerCount; $i++) {
            $this->userSays((string) $i, '/join_callback', true);
        }
        $this->userSays('0', '/begin');

        for ($i = 0; $i < $playerCount; $i++) {
            $this->userResponds((string) $i);
        }

        for ($i = 0; $i < $playerCount; $i++) {
            for ($j = 0; $j < $playerCount; $j++) {
                $this->userSays((string) $j, '/vote_callback 1', true);
            }
        }
    }

}
