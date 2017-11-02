<?php

namespace AppBundle\Controller;


use AppBundle\AppBundle;
use AppBundle\Entity\Game;
use AppBundle\Entity\Question;
use AppBundle\GameManager;
use Bayne\Serializer\Normalizer\GetSetExcludeNormalizer;
use Bayne\Telegram\Bot\Client;
use Bayne\Telegram\Bot\Object\CallbackQuery;
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

    private function getUser($i)
    {
        return new Entity\User(
            $i,
            false,
            'Brian',
            'Payne',
            (string) $i
        );
    }

    private function getQuestion()
    {
        $question = new Question(
            'what'
        );

        return $question;
    }

    public function testVoteCallbackAction()
    {
        $serializer = new Serializer(
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
        $client = self::createClient();

        $em = $client->getContainer()->get('doctrine')->getManager();
        $schemaTool = new SchemaTool($em);
        $metaDatas = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metaDatas);
        $schemaTool->createSchema($metaDatas);

        $users = [
            $this->getUser(0),
            $this->getUser(1),
            $this->getUser(2),
//            $this->getUser(3),
        ];

        $questions = [
            (new Question('text')),
            (new Question('text')),
            (new Question('text')),
            (new Question('text')),
            (new Question('text')),
            (new Question('text')),
        ];

        foreach ($questions as $question) {
            $em->persist($question);
        }

        foreach ($users as $user) {
            $em->persist($user);
        }
        $em->flush();

        $gameManager = new GameManager(
            $em->getRepository(Game::class),
            $em->getRepository(Question::class)
        );

        $chatGroupId = '0';
        $gameManager->newGame(
            $users[0],
            $chatGroupId
        );

        foreach ($users as $user) {
            $gameManager->joinGame(
                $user,
                $chatGroupId
            );
        }

        $game = $gameManager->beginGame($chatGroupId);

        foreach ($game->getAnswers() as $answer) {
            $answer->setResponse('test');
            $em->persist($answer);
        }
        $em->flush();
        $game = $gameManager->beginVoting($game);



        $update = new Update();
        $update
            ->setUpdateId(1)
            ->setCallbackQuery(
                (new CallbackQuery())
                    ->setFrom(
                        (new User())
                            ->setFirstName('Brian')
                            ->setFirstName('Payne')
                            ->setIsBot(false)
                            ->setId(2)
                            ->setUsername('2')
                    )
                    ->setData('/vote_callback 1')
            )
        ;

        $telegramClient = \Mockery::mock(Client::class);
        $telegramClient->shouldReceive(
            'answerCallbackQuery'
        )->withArgs(function (...$args) {
            return true;
        });
        $client->getContainer()->get(QuiplashController::class)->setClient($telegramClient);

        $crawler = $client->request(
            'post',
            '/telegram/quiplash',
            [],
            [],
            [],
            $serializer->serialize(
                $update,
                'json'
            )
        );

//        $controller = new QuiplashController();
//
//        $request = new Request();
//        $request->attributes->set('callback_data', '/vote_callback 1');
//        $update = new Update();
//
//        $controller->voteCallbackAction(
//            $request,
//            $update
//        );
    }
}
