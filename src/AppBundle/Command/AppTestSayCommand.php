<?php
/**
 * Created by PhpStorm.
 * User: bayne
 * Date: 5/5/18
 * Time: 6:04 PM
 */

namespace AppBundle\Command;


use AppBundle\TelegramEmulator;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppTestSayCommand extends ContainerAwareCommand
{
    /**
     * @var TelegramEmulator
     */
    private $telegramEmulator;
    /**
     * @var EntityManager
     */
    private $em;

    protected function configure()
    {
        $this
            ->setName('app:test:say')
            ->addArgument("name", InputArgument::REQUIRED, "The name of the user")
            ->addArgument("text", InputArgument::REQUIRED, "The text that the user said to the bot")
            ->addOption("callback", "c", InputOption::VALUE_NONE, "If this message was a callback query")
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $client = new Client(
            $this->getApplication()->getKernel()
        );
        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $this->telegramEmulator = new TelegramEmulator(
            $this->em,
            $client
        );
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->telegramEmulator->userSays($input->getArgument('name'), $input->getArgument('text'), $input->getOption('callback'));
    }

}