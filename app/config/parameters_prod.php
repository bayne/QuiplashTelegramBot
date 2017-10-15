<?php
$secrets = json_decode(file_get_contents($_SERVER['APP_SECRETS']), true);

$container->setParameter('telegram_token', $secrets['CUSTOM']['TELEGRAM_TOKEN']);
$container->setParameter('redis_password', $secrets['CUSTOM']['REDIS_PASSWORD']);
