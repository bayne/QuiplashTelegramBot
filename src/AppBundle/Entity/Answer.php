<?php

namespace AppBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Answer
 *
 * @ORM\Table(name="answer")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\AnswerRepository")
 */
class Answer
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Player
     * 
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Player")
     */
    private $player;

    /**
     * @var Question
     * 
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Question")
     */
    private $question;

    /**
     * @var Game
     * 
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Game", inversedBy="answers")
     */
    private $game;

    /**
     * @var Vote[]
     * 
     * @ORM\OneToMany(targetEntity="AppBundle\Entity\Vote", cascade={"all"}, mappedBy="answer")
     */
    private $votes;

    /**
     * @var string
     * 
     * @ORM\Column(type="text")
     */
    private $response = '';

    /**
     * Answer constructor.
     * @param Player $player
     * @param Question $question
     * @param Game $game
     */
    public function __construct(Player $player, Question $question, Game $game)
    {
        $this->player = $player;
        $this->question = $question;
        $this->game = $game;
        $this->votes = new ArrayCollection();
    }


    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getPlayer()
    {
        return $this->player;
    }

    /**
     * @param string $player
     *
     * @return Answer
     */
    public function setPlayer($player)
    {
        $this->player = $player;
        return $this;
    }

    /**
     * @return Question
     */
    public function getQuestion()
    {
        return $this->question;
    }

    /**
     * @param Question $question
     *
     * @return Answer
     */
    public function setQuestion($question)
    {
        $this->question = $question;
        return $this;
    }

    /**
     * @return Game
     */
    public function getGame()
    {
        return $this->game;
    }

    /**
     * @param Game $game
     *
     * @return Answer
     */
    public function setGame($game)
    {
        $this->game = $game;
        return $this;
    }

    /**
     * @return Vote[]
     */
    public function getVotes()
    {
        return $this->votes;
    }

    /**
     * @param Vote[] $votes
     *
     * @return Answer
     */
    public function setVotes($votes)
    {
        $this->votes = $votes;
        return $this;
    }

    /**
     * @return string
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param string $response
     *
     * @return Answer
     */
    public function setResponse($response)
    {
        $this->response = $response;
        return $this;
    }

    public function isPending()
    {
        return $this->response == '';
    }

}

