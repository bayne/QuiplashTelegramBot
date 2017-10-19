<?php

namespace AppBundle;


use AppBundle\Entity\Message;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Interfaces\MiddlewareInterface;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use Doctrine\ORM\EntityManager;

class MiddlewarePersister implements MiddlewareInterface
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * MiddlewarePersister constructor.
     * @param EntityManager $entityManager
     */
    public function __construct(
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
    }


    /**
     * Handle a captured message.
     *
     * @param IncomingMessage $message
     * @param callable $next
     * @param BotMan $bot
     *
     * @return mixed
     */
    public function captured(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }

    /**
     * Handle an incoming message.
     *
     * @param IncomingMessage $message
     * @param callable $next
     * @param BotMan $bot
     *
     * @return mixed
     */
    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }

    /**
     * @param IncomingMessage $message
     * @param string $pattern
     * @param bool $regexMatched Indicator if the regular expression was matched too
     * @return bool
     */
    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return true;
    }

    /**
     * Handle a message that was successfully heard, but not processed yet.
     *
     * @param IncomingMessage $message
     * @param callable $next
     * @param BotMan $bot
     *
     * @return mixed
     */
    public function heard(IncomingMessage $message, $next, BotMan $bot)
    {
        return $next($message);
    }

    /**
     * Handle an outgoing message payload before/after it
     * hits the message service.
     *
     * @param mixed $payload
     * @param callable $next
     * @param BotMan $bot
     *
     * @return mixed
     */
    public function sending($payload, $next, BotMan $bot)
    {
        $text = $payload['text'];
        
        $messages = $this->entityManager->getRepository(Message::class)->findBy(
            [
                'chksum' => md5($text),
                'recipient' =>  $payload['chat_id']
            ],
            ['currentTime' => 'desc']
        );
        
        $message = new Message();
        $message->setChksum(md5($text));
        $message->setMessage($text);
        $message->setRecipient($payload['chat_id']);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        if (count($messages) > 0) {
            /** @var Message $message */
            $message = reset($messages);
            if (time() - $message->getCurrentTime()->getTimestamp() > 30) {
                return $next($payload);
            }
            
        }
        
    }
}