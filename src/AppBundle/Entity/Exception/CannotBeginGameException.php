<?php

namespace AppBundle\Entity\Exception;


class CannotBeginGameException extends GameException
{
    /**
     * @var
     */
    private $state;

    /**
     * CannotBeginGameException constructor.
     * @param $state
     */
    public function __construct($state)
    {
        parent::__construct();
        $this->state = $state;
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }


}