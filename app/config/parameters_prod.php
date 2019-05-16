<?php
$secrets = json_decode(file_get_contents($_SERVER['APP_SECRETS']), true);

$container->setParameter('telegram_token', $secrets['CUSTOM']['TELEGRAM_TOKEN']);
$container->setParameter('redis_password', $secrets['CUSTOM']['REDIS_PASSWORD']);
$container->setParameter('logdna_token', $secrets['CUSTOM']['LOGDNA_TOKEN']);
$container->setParameter('rollbar_token', $secrets['CUSTOM']['ROLLBAR_TOKEN']);
