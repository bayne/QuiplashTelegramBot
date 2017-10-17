<?php

namespace AppBundle\Repository;
use AppBundle\Entity\Player;

/**
 * PlayerRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PlayerRepository extends \Doctrine\ORM\EntityRepository
{
    public function findOrCreate($id, $name)
    {
        $player = $this->find($id);
        
        if (null === $player) {
            $player = new Player($id);
            $player->setName($name);
            $this->getEntityManager()->persist($player);
        }
        
        return $player;
    }
}
