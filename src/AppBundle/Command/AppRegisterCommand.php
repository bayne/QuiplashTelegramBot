<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRegisterCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:register')
            ->setDescription('Registers the bot with Telegram')
            ->addArgument('target_url', InputArgument::REQUIRED, 'The URL for the webhook')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = 'https://api.telegram.org/bot'
                .$this->getContainer()->getParameter('telegram_token')
                .'/setWebhook?url='
                .$input->getArgument('target_url');

        $output->writeln('Using '.$url);

        $output->writeln('Pinging Telegram...');

        $response = json_decode(file_get_contents($url));

        if ($response->ok == true && $response->result == true) {
            $output->writeln('Your bot is now set up with Telegram\'s webhook!');
        }

        $output->writeln($response);
    }

}
