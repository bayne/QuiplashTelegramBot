<?php

namespace AppBundle\Entity\ValueObject;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;


/**
 * Class WarningState
 * @package AppBundle\Entity\ValueObject
 * @Embeddable()
 */
class WarningState
{
    /**
     * @var string
     * @Column(type="string")
     */
    protected $state;
    /**
     * @var int
     * @Column(type="integer")
     */
    protected $warningValue;

    /**
     * WarningState constructor.
     * @param string $state
     * @param int $warningValue
     */
    public function __construct($state, $warningValue)
    {
        $this->state = $state;
        $this->warningValue = $warningValue;
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param string $state
     *
     * @return WarningState
     */
    public function setState($state)
    {
        $this->state = $state;
        return $this;
    }

    /**
     * @return int
     */
    public function getWarningValue()
    {
        return $this->warningValue;
    }

    /**
     * @param int $warningValue
     *
     * @return WarningState
     */
    public function setWarningValue($warningValue)
    {
        $this->warningValue = $warningValue;
        return $this;
    }
    
    public function equals(WarningState $warningState) 
    {
        return $warningState->getState() === $this->state && $warningState->getWarningValue() === $this->warningValue;
    }

}