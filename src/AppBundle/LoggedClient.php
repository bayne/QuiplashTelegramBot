<?php

namespace AppBundle;

use Bayne\Telegram\Bot\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

class LoggedClient extends Client
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ClientInterface $client,
        $token,
        CamelCaseToSnakeCaseNameConverter $converter,
        LoggerInterface $logger,
        $baseUrl = 'https://api.telegram.org'
    ) {
        parent::__construct($client, $token, $converter, $baseUrl);
        $this->logger = $logger;
    }

    protected function callMethod($methodName, $parameters)
    {
        $this->logger->info(
            'Calling Telegram Bot API',
            [
                'method' => $methodName,
                $this->getParameters($methodName, $parameters)
            ]
        );
        return parent::callMethod($methodName, $parameters);
    }

}