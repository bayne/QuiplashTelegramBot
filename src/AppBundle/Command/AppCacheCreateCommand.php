<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppCacheCreateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:cache:create')
            ->setDescription('Creates the cache tables')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $adapter = new PdoAdapter($this->getContainer()->get('doctrine')->getConnection());
        $adapter->createTable();
    }

}
