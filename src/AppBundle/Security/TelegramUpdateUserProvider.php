<?php

namespace AppBundle\Security;

use AppBundle\Entity\User;
use Bayne\Telegram\Bot\Object\Update;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class TelegramUpdateUserProvider implements UserProviderInterface
{
    /**
     * @var RequestStack
     */
    private $requestStack;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * TelegramUpdateUserProvider constructor.
     * @param RequestStack $requestStack
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(RequestStack $requestStack, EntityManagerInterface $entityManager)
    {
        $this->requestStack = $requestStack;
        $this->entityManager = $entityManager;
    }


    /**
     * Loads the user for the given username.
     *
     * This method must throw UsernameNotFoundException if the user is not
     * found.
     *
     * @param string $username The username
     *
     * @return UserInterface
     *
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByUsername($username)
    {
        /** @var User $from */
        $from = $this->requestStack->getCurrentRequest()->get('from');

        $user = $this->entityManager->find(User::class, $from->getId());

        if ($user === null) {
            $user = new User(
                $from->getId(),
                $from->getIsBot(),
                $from->getFirstName(),
                $from->getLastName(),
                $from->getUsername() ?: $from->getId()
            );
        } else {
            $user
                ->setUsername($from->getUsername() ?: $from->getId())
                ->setFirstName($from->getFirstName())
                ->setLastName($from->getLastName())
            ;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Refreshes the user.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param UserInterface $user
     *
     * @return UserInterface
     *
     * @throws UnsupportedUserException if the user is not supported
     */
    public function refreshUser(UserInterface $user)
    {
        return $user;
    }

    /**
     * Whether this provider supports the given user class.
     *
     * @param string $class
     *
     * @return bool
     */
    public function supportsClass($class)
    {
        return $class === User::class;
    }
}