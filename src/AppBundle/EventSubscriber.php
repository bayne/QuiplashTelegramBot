<?php

namespace AppBundle;


use Bayne\Serializer\Normalizer\GetSetExcludeNormalizer;
use Bayne\Telegram\Bot\Object\Update;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

class EventSubscriber implements EventSubscriberInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param PropertyInfoExtractor $propertyInfoExtractor
     * @param LoggerInterface $logger
     */
    public function __construct(
        PropertyInfoExtractor $propertyInfoExtractor,
        LoggerInterface $logger
    ) {
        $this->serializer = new Serializer(
            [
                new GetSetExcludeNormalizer(
                    null,
                    new CamelCaseToSnakeCaseNameConverter(),
                    $propertyInfoExtractor
                )
            ],
            [
                new JsonEncoder()
            ]
        );;
        $this->logger = $logger;
    }


    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2')))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => [
                'onKernelRequest',
                100
            ]
        ];
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        /** @var Update $update */
        try {

            $update = $this->serializer->deserialize(
                $request->getContent(),
                Update::class,
                'json'
            );

            $this->logger->info(
                'Update',
                [
                    'update' => $this->serializer->serialize($update, 'json')
                ]
            );

            try {

                $request->attributes->set(
                    'update',
                    $update
                );

                if ($update->getMessage()) {
                    $request->attributes->set(
                        'text',
                        $update->getMessage()->getText()
                    );

                    $request->attributes->set(
                        'from',
                        $update->getMessage()->getFrom()
                    );

                }


                if ($update->getCallbackQuery()) {

                    $request->attributes->set(
                        'game_short_name',
                        $update->getCallbackQuery()->getGameShortName()
                    );

                    $request->attributes->set(
                        'callback_data',
                        $update->getCallbackQuery()->getData()
                    );
                    $request->attributes->set(
                        'from',
                        $update->getCallbackQuery()->getFrom()
                    );
                }
            } catch (UnexpectedValueException $e) {
                $this->logger->critical(
                    $e->getMessage(),
                    [
                        'trace' => $e->getTraceAsString(), 'content' => $request->getContent()
                    ]
                );
            }

        } catch (UnexpectedValueException $e) {

        }

    }
}