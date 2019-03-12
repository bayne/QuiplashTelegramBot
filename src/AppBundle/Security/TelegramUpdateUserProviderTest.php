<?php

namespace AppBundle\Security;

use Bayne\Telegram\Bot\Object\User;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

class TelegramUpdateUserProviderTest extends TestCase
{
    /** @var EntityManager */
    private $entityManager;
    /** @var RequestStack */
    private $requestStack;
    /** @var TelegramUpdateUserProvider */
    private $telegramUpdateUserProvider;

    protected function setUp()
    {
        parent::setUp();
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->telegramUpdateUserProvider = new TelegramUpdateUserProvider(
            $this->requestStack,
            $this->entityManager
        );
    }

    public function testLoadUserByUsername_shouldAllowNullLastAndFirstNames_whenNullAndExists()
    {
        $userId = 1000;
        $this->from((new User())->setId($userId));
        $this->entityManager->method("find")->willReturn(new \AppBundle\Entity\User($userId, false, "blah", "blah", "blah"));
        /** @var \AppBundle\Entity\User $user */
        $user = $this->telegramUpdateUserProvider->loadUserByUsername("test");

        $this->assertNull($user->getFirstName());
        $this->assertNull($user->getLastName());
        $this->assertEquals($userId, $user->getId());
    }

    private function from($from)
    {
        $this->requestStack->method("getCurrentRequest")->willReturn(new class($from) {
            private $from;
            public function __construct($from)
            {
                $this->from = $from;
            }

            public function get($string) {
                return $this->from;
            }
        });
    }
}
