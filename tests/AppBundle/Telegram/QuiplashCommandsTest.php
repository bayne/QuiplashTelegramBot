<?php

namespace AppBundle\Telegram;


use AppBundle\Entity\Game;
use AppBundle\Entity\Question;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Users\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

class QuiplashCommandsTest extends KernelTestCase
{
    /**
     * @var RegistryInterface
     */
    private $doctrine;
    /**
     * @var BotmanFacade
     */
    private $botMan;
    /**
     * @var QuiplashCommands
     */
    private $quiplashCommands;
    /**
     * @var int
     */
    private $botId = 999;

    public function getContainer()
    {
        return self::$kernel->getContainer();
    }
    
    protected function setUp()
    {
        self::bootKernel();
        $this->doctrine = self::$kernel->getContainer()->get('doctrine');
        $this->botMan = new BotmanFacade();
        $schemaTool = new SchemaTool($this->doctrine->getManager());
        $classMetadata = $this->doctrine->getManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($classMetadata);
        $schemaTool->createSchema($classMetadata);
        
        $file = file_get_contents($this->getContainer()->getParameter('kernel.root_dir').'/questions.json');
        $questions = json_decode($file);
        
        foreach ($questions as $questionText) {
            $question = new Question($questionText);
            $this->getContainer()->get('doctrine')->getManager()->persist($question);
        }
        $this->getContainer()->get('doctrine')->getManager()->flush();
        

        $this->quiplashCommands = new QuiplashCommands(
            $this->doctrine,
            self::$kernel->getContainer()->get('logger'),
            'token'
        );
    }

    protected function sayAsPlayer(User $player, $chatGroupId, $message, $isPM = true)
    {
        $this->botMan->setMessage(
            new IncomingMessage(
                $message,
                $player->getId(),
                $isPM ? $player->getId() : $chatGroupId
            )
        );
        
        $this->botMan->setUser($player);

        $this->doctrine->getConnection()->beginTransaction();
        switch ($message) {
            case '/new':
                $this->quiplashCommands->handleNew($this->botMan);
                break;
            case '/end':
                $this->quiplashCommands->handleEnd($this->botMan);
                break;
            case '/begin':
                $this->quiplashCommands->handleBegin($this->botMan);
                break;
            case '/status':
                $this->quiplashCommands->handleStatus($this->botMan);
                break;
            default:
                if (false !== strpos($message, '/vote')) {
                    $matches = [];
                    preg_match('/\/vote(.*)/', $message, $matches);
                    $this->quiplashCommands->handleVote($this->botMan, trim($matches[1]));
                } elseif (false !== strpos($message, '/start')) {
                    list($chatGroupJoin) = sscanf($message, '/start %s');
                    $this->quiplashCommands->handleStart($this->botMan, $chatGroupJoin);
                } else {
                    $this->quiplashCommands->handleFallback($this->botMan);
                }
        }
        $this->doctrine->getManager()->flush();
        $this->doctrine->getConnection()->commit();
    }
    
    public function testFullGame()
    {
        $quiplashCommands = new QuiplashCommands(
            $this->doctrine,
            self::$kernel->getContainer()->get('logger'),
            'token'
        );

        $host = new User(
            1,
            'Brian',
            'Payne'
        );
        
        $alice = new User(
            2,
            'Alice',
            'Player'
        );
        
        $bob = new User(
            3,
            'Bob',
            'Player'
        );

        $players = [
            $host,
            $alice,
            $bob
        ];
        
        $chatGroupId = 100;


        $this->sayAsPlayer($host, $chatGroupId, '/new', false);
        list($method, $options) = $this->botMan->sendRequestHistory()->last();
        $this->assertEquals('sendMessage', $method);
        $this->assertContains('Starting', $options['text']);

        $encoded = base64_encode($chatGroupId);
        $this->sayAsPlayer($host, $chatGroupId, "/start $encoded");

        list($message, $recipient) = $this->botMan->sayHistory()->first();
        $this->assertEquals('Brian has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($alice, $chatGroupId, "/start $encoded");

        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Alice has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($bob, $chatGroupId, "/start $encoded");
        
        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Bob has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);
        
        $this->sayAsPlayer($host, $chatGroupId, '/begin', false);
        $this->assertCount(10, $this->botMan->sayHistory());

        $recipients = [];
        foreach ($this->botMan->sayHistory() as list($message, $recipient)) {
            if (false !== strpos($message, 'Reply to')) {
                $recipients[] = $recipient;
            }
        }
        $this->assertCount(3, array_unique($recipients));
        
        $this->sayAsPlayer($host, $chatGroupId, 'Some answer 1a');
        $this->sayAsPlayer($alice, $chatGroupId, 'Some answer 2a');
        $this->sayAsPlayer($bob, $chatGroupId, 'Some answer 3a');

        $this->assertCount(13, $this->botMan->sayHistory());

        $this->sayAsPlayer($host, $chatGroupId, 'Some answer 1b');
        $this->assertEquals(['Answers submitted, waiting for other players', $host->getId()], $this->botMan->sayHistory()->last());
        $this->sayAsPlayer($alice, $chatGroupId, 'Some answer 2b');
        $this->assertEquals(['Answers submitted, waiting for other players', $alice->getId()], $this->botMan->sayHistory()->last());
        $this->sayAsPlayer($bob, $chatGroupId, 'Some answer 3b');

        $this->assertCount(18, $this->botMan->sayHistory());
        
        $this->vote($players, $chatGroupId);
        $this->vote($players, $chatGroupId);
        $this->vote($players, $chatGroupId);

        list($message, $recipient) = $this->botMan->sayHistory()->last();

        $this->assertContains('Game Over', $message);
    }
    
    public function testAnswerTimeout()
    {
        $quiplashCommands = new QuiplashCommands(
            $this->doctrine,
            self::$kernel->getContainer()->get('logger'),
            'token'
        );

        $host = new User(
            1,
            'Brian',
            'Payne'
        );
        
        $alice = new User(
            2,
            'Alice',
            'Player'
        );
        
        $bob = new User(
            3,
            'Bob',
            'Player'
        );

        $players = [
            $host,
            $alice,
            $bob
        ];
        
        $chatGroupId = 100;


        $this->sayAsPlayer($host, $chatGroupId, '/new', false);
        list($method, $options) = $this->botMan->sendRequestHistory()->last();
        $this->assertEquals('sendMessage', $method);
        $this->assertContains('Starting', $options['text']);

        $encoded = base64_encode($chatGroupId);
        $this->sayAsPlayer($host, $chatGroupId, "/start $encoded");

        list($message, $recipient) = $this->botMan->sayHistory()->first();
        $this->assertEquals('Brian has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($alice, $chatGroupId, "/start $encoded");

        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Alice has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($bob, $chatGroupId, "/start $encoded");
        
        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Bob has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);
        
        $this->sayAsPlayer($host, $chatGroupId, '/begin', false);
        $this->assertCount(10, $this->botMan->sayHistory());

        $recipients = [];
        foreach ($this->botMan->sayHistory() as list($message, $recipient)) {
            if (false !== strpos($message, 'Reply to')) {
                $recipients[] = $recipient;
            }
        }

        $this->assertCount(3, array_unique($recipients));
        
        $this->sayAsPlayer($host, $chatGroupId, 'Some answer 1a');
        $this->sayAsPlayer($alice, $chatGroupId, 'Some answer 2a');
        
        $currentTime = new \DateTime();
        $currentTime->modify('+20 seconds');
        $this->heartBeat($quiplashCommands, $this->botMan, $currentTime);

        $currentTime->modify('+11 seconds');
        $this->heartBeat($quiplashCommands, $this->botMan, $currentTime);

        $messages = array_column($this->botMan->sayHistory()->toArray(), 0);
        $this->assertContains('10 seconds', $messages);
        $this->assertContains('5 seconds', $messages);
        $this->assertContains('Now its time to vote on your favorite answers', $messages);

    }

    public function testVoteTimeout()
    {
        $quiplashCommands = new QuiplashCommands(
            $this->doctrine,
            self::$kernel->getContainer()->get('logger'),
            'token'
        );

        $host = new User(
            1,
            'Brian',
            'Payne'
        );

        $alice = new User(
            2,
            'Alice',
            'Player'
        );

        $bob = new User(
            3,
            'Bob',
            'Player'
        );

        $players = [
            $host,
            $alice,
            $bob
        ];

        $chatGroupId = 100;


        $this->sayAsPlayer($host, $chatGroupId, '/new', false);
        list($method, $options) = $this->botMan->sendRequestHistory()->last();
        $this->assertEquals('sendMessage', $method);
        $this->assertContains('Starting', $options['text']);

        $encoded = base64_encode($chatGroupId);
        $this->sayAsPlayer($host, $chatGroupId, "/start $encoded");

        list($message, $recipient) = $this->botMan->sayHistory()->first();
        $this->assertEquals('Brian has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($alice, $chatGroupId, "/start $encoded");

        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Alice has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($bob, $chatGroupId, "/start $encoded");

        $history = $this->botMan->sayHistory()->toArray();
        end($history);
        list($message, $recipient) = prev($history);
        $this->assertEquals('Bob has joined the game!', $message);
        $this->assertEquals($chatGroupId, $recipient);

        $this->sayAsPlayer($host, $chatGroupId, '/begin', false);
        $this->assertCount(10, $this->botMan->sayHistory());

        $recipients = [];
        foreach ($this->botMan->sayHistory() as list($message, $recipient)) {
            if (false !== strpos($message, 'Reply to')) {
                $recipients[] = $recipient;
            }
        }
        $this->assertCount(3, array_unique($recipients));

        $this->sayAsPlayer($host, $chatGroupId, 'Some answer 1a');
        $this->sayAsPlayer($alice, $chatGroupId, 'Some answer 2a');
        $this->sayAsPlayer($bob, $chatGroupId, 'Some answer 3a');

        $this->assertCount(13, $this->botMan->sayHistory());

        $this->sayAsPlayer($host, $chatGroupId, 'Some answer 1b');
        $this->assertEquals(['Answers submitted, waiting for other players', $host->getId()], $this->botMan->sayHistory()->last());
        $this->sayAsPlayer($alice, $chatGroupId, 'Some answer 2b');
        $this->assertEquals(['Answers submitted, waiting for other players', $alice->getId()], $this->botMan->sayHistory()->last());
        $this->sayAsPlayer($bob, $chatGroupId, 'Some answer 3b');

        $this->assertCount(18, $this->botMan->sayHistory());

        $this->vote($players, $chatGroupId);
        $currentTime = new \DateTime();
        $currentTime->modify('+20 seconds');
        $this->heartBeat($quiplashCommands, $this->botMan, $currentTime);
        
        $currentTime->modify('+11 seconds');
        $this->heartBeat($quiplashCommands, $this->botMan, $currentTime);
        
        $currentTime->modify('+11 seconds');
        $this->heartBeat($quiplashCommands, $this->botMan, $currentTime);

        list($message, $recipient) = $this->botMan->sayHistory()->last();

        $this->assertContains('Game Over', $message);
    }

    
    private function vote($players, $chatGroupId)
    {
        list($question, $recipientId) = $this->botMan->sayHistory()->last();

        foreach ($players as $player) {
            if ($player->getId() === $recipientId) {
                $this->sayAsPlayer(
                    $player,
                    $chatGroupId,
                    $question->getButtons()[0]['value']
                );
            }
        }
    }
    
    private function heartBeat(QuiplashCommands $quiplashCommands, BotMan $botMan, \DateTime $currentTime)
    {
        $this->doctrine->getConnection()->beginTransaction();

        $activeGames = $this->doctrine->getRepository(Game::class)->getAllActiveGames();
        /** @var Game $game */
        foreach ($activeGames as $game) {
            $quiplashCommands->handleHeartbeat($botMan, $game, $currentTime);
        }
        $this->doctrine->getConnection()->commit();
    }

}
