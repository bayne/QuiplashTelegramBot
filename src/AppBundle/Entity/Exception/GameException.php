<?php

namespace AppBundle\Entity\Exception;


class GameException extends \RuntimeException
{

    /**
     * GameException constructor.
     */
    public function __construct()
    {
        $this->message = get_class($this);
    }
}