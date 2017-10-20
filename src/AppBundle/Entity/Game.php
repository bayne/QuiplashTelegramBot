<?php

namespace AppBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Game
 *
 * @ORM\Table(name="game")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\GameRepository")
 */
class Game
{
    const GATHER_PLAYERS = 'gather_players';
    const GATHER_ANSWERS = 'gather_answers';
    const GATHER_VOTES = 'gather_votes';
    const END = 'end';
    
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="chatGroup", type="string", length=255)
     */
    private $chatGroup;

    /**
     * @var string
     *
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Player")
     */
    private $host;

    /**
     * @var Answer[]
     * 
     * @ORM\OneToMany(targetEntity="AppBundle\Entity\Answer", cascade={"all"}, mappedBy="game")
     */
    private $answers;

    /**
     * @var Player[]
     * 
     * @ORM\ManyToMany(targetEntity="AppBundle\Entity\Player")
     */
    private $players;

    /**
     * @var Question[]|ArrayCollection
     * 
     * @ORM\ManyToMany(targetEntity="AppBundle\Entity\Question", cascade={"persist"})
     */
    private $questions;

    /**
     * @var string
     * 
     * @ORM\Column(type="string")
     */
    private $state;

    /**
     * @var Question
     * 
     * @ORM\OneToOne(targetEntity="AppBundle\Entity\Question")
     * @ORM\JoinColumn(nullable=true)
     */
    private $currentQuestion;
    
    /**
     * Game constructor.
     * @param Player $host
     * @param $chatGroup
     */
    public function __construct(Player $host, $chatGroup)
    {
        $this->answers = new ArrayCollection();
        $this->players = new ArrayCollection();
        $this->host = $host;
        $this->chatGroup = $chatGroup;
        $this->questions = new ArrayCollection();
        $this->state = self::GATHER_PLAYERS;
    }

    /**
     * @return Question
     */
    public function getCurrentQuestion()
    {
        return $this->currentQuestion;
    }

    /**
     * @param Question $currentQuestion
     *
     * @return Game
     */
    public function setCurrentQuestion($currentQuestion)
    {
        $this->currentQuestion = $currentQuestion;
        return $this;
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
     * Set chatGroup
     *
     * @param string $chatGroup
     *
     * @return Game
     */
    public function setChatGroup($chatGroup)
    {
        $this->chatGroup = $chatGroup;

        return $this;
    }

    /**
     * Get chatGroup
     *
     * @return string
     */
    public function getChatGroup()
    {
        return $this->chatGroup;
    }

    /**
     * Set host
     *
     * @param string $host
     *
     * @return Game
     */
    public function setHost($host)
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Get host
     *
     * @return Player
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return Answer[]
     */
    public function getAnswers()
    {
        return $this->answers;
    }

    /**
     * @param Answer[] $answers
     *
     * @return Game
     */
    public function setAnswers($answers)
    {
        $this->answers = $answers;
        return $this;
    }

    /**
     * @return Player[]|ArrayCollection
     */
    public function getPlayers()
    {
        return $this->players;
    }

    /**
     * @param Player[] $players
     *
     * @return Game
     */
    public function setPlayers($players)
    {
        $this->players = $players;
        return $this;
    }

    public function allAnswersAreIn()
    {
        if ($this->state === Game::GATHER_ANSWERS) {
            $validAnswers = $this->answers->filter(function (Answer $answer) {
                return $answer->getResponse();
            });
            return $validAnswers->count() === $this->getPlayers()->count() * 2;
        } 
        return false;
    }

    /**
     * @return Question[]|ArrayCollection
     */
    public function getQuestions()
    {
        return $this->questions;
    }

    /**
     * @param Question[]|ArrayCollection $questions
     *
     * @return Game
     */
    public function setQuestions($questions)
    {
        $this->questions = $questions;
        return $this;
    }
    
    public function getMissingPlayerVotes()
    {
        $votedPlayers = [];
        foreach ($this->getAnswers() as $answer) {
            if ($this->currentQuestion->getId() === $answer->getQuestion()->getId()) {
                foreach ($answer->getVotes() as $vote) {
                    $votedPlayers[] = $vote->getPlayer();
                }
            }
        }
        
        $votedPlayersIds = array_map(function (Player $player) {
            return $player->getId();
        }, $votedPlayers);
        
        $notVotedPlayers = [];
        $notVotedPlayersIds = [];
        foreach ($this->getVotersForCurrentQuestion() as $player) {
            if (!in_array($player->getId(), $votedPlayersIds) && !in_array($player->getId(), $notVotedPlayersIds)) {
                $notVotedPlayers[] = $player;
                $notVotedPlayersIds[] = $player->getId();
            }
        }
            
        return $notVotedPlayers;
    }

    public function votesAreTallied($questionNumber)
    {
        $question = $this->questions->offsetGet($questionNumber);
        $totalVotesForQuestion = 0;
        
        foreach ($this->answers as $answer) {
            if ($answer->getQuestion()->getId() === $question->getId()) {
                $totalVotesForQuestion += $answer->getVotes()->count();
            }
        }
        
        return $totalVotesForQuestion >= count($this->getVotersForCurrentQuestion());
    }

    public function isStillRunning()
    {
        $totalVotes = 0;
        foreach ($this->answers as $answer) {
            $totalVotes += $answer->getVotes()->count();
        }
        
        return $totalVotes >= $this->getQuestions()->count()*$this->getPlayers()->count();
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param string $state
     *
     * @return Game
     */
    public function setState($state)
    {
        $this->state = $state;
        return $this;
    }

    public function getScoreBoard()
    {
        $points = [];
        
        foreach ($this->players as $player) {
            $points[$player->getId()] = [
                'player' => $player,
                'points' => 0
            ];
        }
        
        foreach ($this->answers as $answer) {
            $points[$answer->getPlayer()->getId()]['points'] += count($answer->getVotes());
        }
        
        usort($points, function ($a, $b) {
            return $b['points'] - $a['points'];
        });
        
        return $points;
    }

    public function getVotersForCurrentQuestion()
    {
        $allPlayers = $this->players->map(function (Player $player) {
            return $player->getId();
        });
        
        $questionAnswers = $this->answers->filter(function (Answer $answer) {
            return $answer->getQuestion()->getId() === $this->currentQuestion->getId();
        });
        
        $answerers = $questionAnswers->map(function (Answer $answer) {
            return $answer->getPlayer()->getId();
        });
        
        $voters = [];
        foreach (array_diff($allPlayers, $answerers) as $id) {
            foreach ($this->players as $player) {
                if ($player->getId() === $id) {
                    $voters[] = $player;
                }
            }
        }
        
        return $voters;
    }

}

