<?php

namespace AppBundle\Conversation;

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer;

class Game extends Conversation
{
    private $secret = '';
    /**
     * @return mixed
     */
    public function run()
    {
        $this->secret = hash('sha256', mt_rand());
        $this->askGuess();
    }

    public function askGuess()
    {
        $sha = hash('sha256', $this->secret);
        $this->ask('SHA(SECRET) = '.$sha.' which might it be?', function (Answer $answer) {
            $text = strtolower($answer->getText());
            $this->say($text.':'. $this->secret[0]);
        });
    }
}