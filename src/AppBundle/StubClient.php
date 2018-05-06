<?php

namespace AppBundle;

use Bayne\Telegram\Bot\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

class StubClient extends Client
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        parent::__construct(new \GuzzleHttp\Client(), '', new CamelCaseToSnakeCaseNameConverter(), 'https://telegram.example.com');
        $this->logger = $logger;
    }

    protected function callMethod($methodName, $parameters)
    {
        $this->logger->info(
            'Would have called Telegram Bot API',
            [
                'method' => $methodName,
                $this->getParameters($methodName, $parameters)
            ]
        );
    }
}