<?php

namespace AppBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Answer
 *
 * @ORM\Table(name="answer", indexes={@ORM\Index(name="token",columns={"token"})})
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
     * @var User
     * 
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User")
     */
    private $user;

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
     * @ORM\OneToMany(
     *     targetEntity="AppBundle\Entity\Vote",
     *     cascade={"all"},
     *     mappedBy="answer",
     *     fetch="EAGER"
     * )
     */
    private $votes;

    /**
     * @var string
     * 
     * @ORM\Column(type="text")
     */
    private $response = '';

    /**
     * @var string
     *
     * @ORM\Column(type="string")
     */
    private $token;

    /**
     * Answer constructor.
     * @param User $user
     * @param Question $question
     * @param Game $game
     * @param string $token
     */
    public function __construct(User $user, Question $question, Game $game, $token)
    {
        $this->user = $user;
        $this->question = $question;
        $this->game = $game;
        $this->votes = new ArrayCollection();
        $this->token = $token;
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
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param string $user
     *
     * @return Answer
     */
    public function setUser($user)
    {
        $this->user = $user;
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

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    public function alreadyVoted(User $user)
    {
        foreach ($this->votes as $vote) {
            if ($user->getId() === $vote->getUser()->getId()) {
                return true;
            }
        }
        return false;
    }

}

