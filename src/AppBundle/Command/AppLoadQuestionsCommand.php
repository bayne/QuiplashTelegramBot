<?php

namespace AppBundle\Command;

use AppBundle\Entity\Question;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppLoadQuestionsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:load_questions')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = file_get_contents($this->getContainer()->getParameter('kernel.root_dir').'/questions.json');
        $questions = json_decode($file);
        
        foreach ($questions as $questionText) {
            $question = new Question($questionText);
            $this->getContainer()->get('doctrine')->getManager()->persist($question);
        }
        $this->getContainer()->get('doctrine')->getManager()->flush();
    }

}
