<?php

namespace AppBundle;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class ExceptionListener
{
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();
        $response = new Response('', 404);
        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }

}