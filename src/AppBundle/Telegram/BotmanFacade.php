<?php

namespace AppBundle\Telegram;


use BotMan\BotMan\BotMan;
use BotMan\BotMan\Cache\ArrayCache;
use BotMan\BotMan\Drivers\NullDriver;
use BotMan\BotMan\Http\Curl;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Storages\Drivers\FileStorage;
use BotMan\BotMan\Users\User;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpFoundation\Request;

class BotmanFacade extends BotMan
{
    private $lastCall = [];
    /**
     * @var User
     */
    private $user;
    /**
     * @var ArrayCollection
     */
    private $sendRequestHistory;
    /**
     * @var ArrayCollection
     */
    private $sayHistory;
    /**
     * @var ArrayCollection
     */
    private $replyHistory;

    public function __construct() 
    {
        $cache = new ArrayCache();
        $request = Request::createFromGlobals();
        $storageDriver = new FileStorage(__DIR__);
        $this->sayHistory = new ArrayCollection();
        $this->replyHistory = new ArrayCollection();
        $this->sendRequestHistory = new ArrayCollection();
//
        parent::__construct(
            $cache,
            new NullDriver(
                $request,
                [],
                new Curl()
            ),
            [],
            $storageDriver
        );
    }
    
    public function getLastCall($methodName)
    {
        return $this->lastCall[$methodName];
    }
    
    public function setUser(User $user)
    {
        $this->user = $user;
        return $this;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function say($message, $recipient, $driver = null, $additionalParameters = [])
    {
        $this->sayHistory->add(func_get_args());
    }

    /**
     * @return ArrayCollection
     */
    public function sayHistory()
    {
        return $this->sayHistory;
    }

    /**
     * @return ArrayCollection
     */
    public function sendRequestHistory()
    {
        return $this->sendRequestHistory;
    }

    /**
     * @return ArrayCollection
     */
    public function replyHistory()
    {
        return $this->replyHistory;
    }
    

    public function sendRequest($endpoint, $additionalParameters = [])
    {
        $this->sendRequestHistory->add(func_get_args());
    }

    public function reply($message, $additionalParameters = [])
    {
        $this->replyHistory->add(func_get_args());
    }

    /**
     * @param IncomingMessage $message
     *
     * @return BotMan
     */
    public function setMessage($message)
    {
        $this->message = $message;
        return $this;
    }

    public function getMessage()
    {
        return $this->message;
    }


}