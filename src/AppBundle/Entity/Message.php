<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Message
 *
 * @ORM\Table(name="message", indexes={@ORM\Index(name="chksum", columns={"chksum", "recipient"})})
 * @ORM\Entity(repositoryClass="AppBundle\Repository\MessageRepository")
 */
class Message
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="message", type="text", nullable=true)
     */
    private $message;

    /**
     * @var string
     *
     * @ORM\Column(name="chksum", type="string", length=255)
     */
    private $chksum;

    /**
     * @var string
     *
     * @ORM\Column(name="recipient", type="string", length=255)
     */
    private $recipient;

    /**
     * @var \DateTime
     * 
     * @ORM\Column(type="datetime")
     */
    private $currentTime;

    /**
     * Message constructor.
     */
    public function __construct()
    {
        $this->currentTime = new \DateTime();
    }

    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set message
     *
     * @param string $message
     *
     * @return Message
     */
    public function setMessage($message)
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Set recipient
     *
     * @param string $recipient
     *
     * @return Message
     */
    public function setRecipient($recipient)
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Get recipient
     *
     * @return string
     */
    public function getRecipient()
    {
        return $this->recipient;
    }

    /**
     * @return string
     */
    public function getChksum()
    {
        return $this->chksum;
    }

    /**
     * @param string $chksum
     *
     * @return Message
     */
    public function setChksum($chksum)
    {
        $this->chksum = $chksum;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getCurrentTime()
    {
        return $this->currentTime;
    }

    /**
     * @param \DateTime $currentTime
     *
     * @return Message
     */
    public function setCurrentTime($currentTime)
    {
        $this->currentTime = $currentTime;
        return $this;
    }
    
}

