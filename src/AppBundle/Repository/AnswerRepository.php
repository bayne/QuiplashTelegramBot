<?php

namespace AppBundle\Repository;
use AppBundle\Entity\Game;
use AppBundle\Entity\User;
use Doctrine\DBAL\LockMode;

/**
 * AnswerRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class AnswerRepository extends \Doctrine\ORM\EntityRepository
{
    public function getNextForUser(User $user, Game $game)
    {
        return $this->createQueryBuilder('a')
            ->where("a.response = ''")
            ->andWhere('a.user = :user')
            ->andWhere('a.game = :game')
            ->orderBy('a.id', 'asc')
            ->setParameters([
                'user' => $user,
                'game' => $game
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult()
        ;
    }

    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        $persister = $this->_em->getUnitOfWork()->getEntityPersister($this->_entityName);
        $persister->lock($criteria, LockMode::PESSIMISTIC_WRITE);

        return $persister->loadAll($criteria, $orderBy, $limit, $offset);
    }


}
