<?php

namespace AppBundle\Entity;

use AppBundle\Entity\ValueObject\WarningState;
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
    const TIME_TO_JOIN = 360;
    const TIME_TO_ANSWER = 360;
    const TIME_TO_VOTE = 120;
    
    const GATHER_USERS = 'gather_users';
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
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\User")
     */
    private $host;

    /**
     * @var Answer[]
     * 
     * @ORM\OneToMany(targetEntity="AppBundle\Entity\Answer", cascade={"all"}, mappedBy="game")
     */
    private $answers;

    /**
     * @var User[]
     * 
     * @ORM\ManyToMany(targetEntity="AppBundle\Entity\User")
     */
    private $users;

    /**
     * @var Question[]|ArrayCollection
     * 
     * @ORM\ManyToMany(targetEntity="AppBundle\Entity\Question", cascade={"persist"})
     * @ORM\OrderBy(value={"id" = "ASC"})
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
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Question")
     * @ORM\JoinColumn(nullable=true)
     */
    private $currentQuestion;

    /**
     * @var \DateTime
     * 
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $gatheringVotesStarted;
    
    /**
     * @var \DateTime
     * 
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $gatheringUsersStarted;
    
    /**
     * @var \DateTime
     * 
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $gatheringAnswersStarted;

    /**
     * @var WarningState
     *
     * @ORM\Embedded(class="AppBundle\Entity\ValueObject\WarningState")
     */
    private $warningState;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $hasTimer = true;

    /**
     * Game constructor.
     * @param User $host
     * @param $chatGroup
     * @param bool $hasTimer
     */
    public function __construct(User $host, $chatGroup, $hasTimer = true)
    {
        $this->lastUpdated = new \DateTime();
        $this->answers = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->host = $host;
        $this->chatGroup = $chatGroup;
        $this->questions = new ArrayCollection();
        $this->state = self::GATHER_USERS;
        $this->gatheringAnswersStarted = null;
        $this->gatheringUsersStarted = new \DateTime();
        $this->gatheringVotesStarted = null;
        $this->warningState = new WarningState(
            $this->state,
            60
        );
        $this->hasTimer = $hasTimer;
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
        $this->gatheringVotesStarted = new \DateTime();
        $this->currentQuestion = $currentQuestion;
        return $this;
    }

    public function getPreviousQuestion()
    {
        $prevQuestionIndex = -1;
        foreach ($this->getQuestions() as $i => $question) {
            if ($question->getId() === $this->getCurrentQuestion()->getId()) {
                $prevQuestionIndex = $i - 1;
                break;
            }
        }

        if ($prevQuestionIndex < 0) {
            return null;
        } else {
            return $this->getQuestions()->offsetGet($prevQuestionIndex);
        }

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
     * @return User
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
     * @return User[]|ArrayCollection
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @param User[] $users
     *
     * @return Game
     */
    public function setUsers($users)
    {
        $this->users = $users;
        return $this;
    }

    public function allAnswersAreIn()
    {
        if ($this->state === Game::GATHER_ANSWERS) {
            $validAnswers = $this->answers->filter(function (Answer $answer) {
                return $answer->getResponse();
            });
            return $validAnswers->count() === $this->getUsers()->count() * 2;
        } 
        return false;
    }

    public function getPendingAnswers()
    {
        return $this->answers->filter(function (Answer $answer) {
            return $answer->isPending();
        })->toArray();
    }

    public function getAnswersForQuestion(Question $question)
    {
        $answers = [];
        foreach ($this->answers as $answer) {
            if ($answer->getQuestion()->getId() === $question->getId()) {
                $answers[$answer->getId()] = $answer;
            }
        }

        uasort($answers, function (Answer $a, Answer $b) {
            return $a->getId() - $b->getId();
        });

        return $answers;
    }

    public function getAnswersForCurrentQuestion()
    {
        return $this->getAnswersForQuestion($this->currentQuestion);
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
    
    public function getMissingUserVotes()
    {
        $votedUsers = [];
        foreach ($this->getAnswers() as $answer) {
            if (
                $this->currentQuestion->getId() === $answer->getQuestion()->getId()
            ) {
                foreach ($answer->getVotes() as $vote) {
                    $votedUsers[] = $vote->getUser();
                }
            }
        }

        $votedUsersIds = array_map(function (User $user) {
            return $user->getId();
        }, $votedUsers);
        
        $notVotedUsers = [];
        $notVotedUsersIds = [];
        foreach ($this->getVotersForCurrentQuestion() as $user) {
            if (
                !in_array($user->getId(), $votedUsersIds) &&
                !in_array($user->getId(), $notVotedUsersIds)
            ) {
                $notVotedUsers[] = $user;
                $notVotedUsersIds[] = $user->getId();
            }
        }
            
        return $notVotedUsers;
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
        
        return $totalVotes >= $this->getQuestions()->count()*$this->getUsers()->count();
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
        if ($state == self::GATHER_ANSWERS) {
            $this->gatheringAnswersStarted = new \DateTime();
            $this->setWarningState(
                new WarningState(
                    self::GATHER_ANSWERS,
                    self::TIME_TO_ANSWER
                )
            );
        }
        
        if ($state == self::GATHER_USERS) {
            $this->gatheringUsersStarted = new \DateTime();
            $this->setWarningState(
                new WarningState(
                    self::GATHER_USERS,
                    self::TIME_TO_JOIN
                )
            );
        }
        
        $this->state = $state;
        return $this;
    }

    public function getScoreBoard()
    {
        $points = [];
        
        foreach ($this->users as $user) {
            $points[$user->getId()] = [
                'user' => $user,
                'points' => 0
            ];
        }
        
        foreach ($this->answers as $answer) {
            $points[$answer->getUser()->getId()]['points'] += count($answer->getVotes());
        }
        
        uasort($points, function ($a, $b) {
            return $b['points'] - $a['points'];
        });
        
        return $points;
    }

    public function getVotersForCurrentQuestion()
    {
        $allUsers = $this->users->map(function (User $user) {
            return $user->getId();
        });
        
        $questionAnswers = $this->answers->filter(function (Answer $answer) {
            return $answer->getQuestion()->getId() === $this->currentQuestion->getId();
        });
        
        $answerers = $questionAnswers->map(function (Answer $answer) {
            return $answer->getUser()->getId();
        });
        
        $voters = [];
        foreach (array_diff($allUsers->toArray(), $answerers->toArray()) as $id) {
            foreach ($this->users as $user) {
                if ($user->getId() === $id) {
                    $voters[] = $user;
                }
            }
        }
        
        return $voters;
    }
    
    public function isExpired(\DateTime $current)
    {
        if (false === $this->hasTimer()) {
            return false;
        }

        $current = $current->getTimestamp();
        
        if (!$this->gatheringVotesStarted) {
            $gatheringVotesStarted = $current;
        } else {
            $gatheringVotesStarted = $this->gatheringVotesStarted->getTimestamp();
        }
        
        if (!$this->gatheringUsersStarted) {
            $gatheringUsersStarted = $current;
        } else {
            $gatheringUsersStarted = $this->gatheringUsersStarted->getTimestamp();
        }

        if (!$this->gatheringAnswersStarted) {
            $gatheringAnswersStarted = $current;
        } else {
            $gatheringAnswersStarted = $this->gatheringAnswersStarted->getTimestamp();
        }
        
        return 
            (
                $this->state === self::GATHER_VOTES &&
                $current - $gatheringVotesStarted > self::TIME_TO_VOTE
            ) ||
            (
                $this->state === self::GATHER_USERS &&
                $current - $gatheringUsersStarted > self::TIME_TO_JOIN
            ) ||
            (
                $this->state === self::GATHER_ANSWERS && 
                $current - $gatheringAnswersStarted > self::TIME_TO_ANSWER
            )
        ;
    }
    
    public function getSecondsRemaining(\DateTime $current)
    {
        if (false === $this->hasTimer()) {
            return INF;
        }

        $current = $current->getTimestamp();
        if ($this->state === self::GATHER_ANSWERS) {
            return self::TIME_TO_ANSWER - ($current - $this->gatheringAnswersStarted->getTimestamp());
        }
        
        if ($this->state === self::GATHER_VOTES) {
            return self::TIME_TO_VOTE - ($current - $this->gatheringVotesStarted->getTimestamp());
        }
        
        if ($this->state === self::GATHER_USERS) {
            return self::TIME_TO_JOIN - ($current - $this->gatheringUsersStarted->getTimestamp());
        }
        
        return INF;
    }
    
    public function expire()
    {
        
    }

    /**
     * @return WarningState
     */
    public function getWarningState()
    {
        return $this->warningState;
    }

    /**
     * @param WarningState $warningState
     *
     * @return Game
     */
    public function setWarningState($warningState)
    {
        $this->warningState = $warningState;
        return $this;
    }

    public function warningStateToAnnounce(\DateTime $currentTime)
    {
        if (false === $this->hasTimer()) {
            throw new \UnexpectedValueException('Should not announce warning state if the game has no timer');
        }

        $timestamp = $currentTime->getTimestamp();
        $warningState = $this->getWarningState();
        if ($warningState->getState() === self::GATHER_ANSWERS) {
            $startTime = $this->gatheringAnswersStarted;
            $maxTime = self::TIME_TO_ANSWER;
        } elseif ($warningState->getState() === self::GATHER_VOTES) {
            $startTime = $this->gatheringVotesStarted;
            $maxTime = self::TIME_TO_VOTE;
        } elseif ($warningState->getState() === self::GATHER_USERS) {
            $startTime = $this->gatheringUsersStarted;
            $maxTime = self::TIME_TO_JOIN;
        } else {
            throw new \UnexpectedValueException();
        }
        
        $remainingTime = $maxTime - ($timestamp - $startTime->getTimestamp());

        if ($this->inRange(10, 30, $remainingTime)) {
            return new WarningState(
                $this->state,
                30
            );
        } elseif ($this->inRange(5, 10, $remainingTime)) {
            return new WarningState(
                $this->state,
                10
            );
        } elseif ($this->inRange(-INF, 5, $remainingTime)) {
            return new WarningState(
                $this->state,
                5
            );
        }
        
        return new WarningState(
            $this->state,
            $maxTime
        );
    }
    
    
    private function inRange($lower, $upper, $number)
    {
        return $number > $lower && $number <= $upper;
    }

    public function hasEnoughUsers()
    {
        return $this->getUsers()->count() >= 3;
    }

    public function alreadyVoted(User $user)
    {
        /** @var Answer $answer */
        foreach ($this->getAnswersForCurrentQuestion() as $answer) {
            foreach ($answer->getVotes() as $vote) {
                if ($vote->getUser()->getId() === $user->getId()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public function hasTimer(): bool
    {
        return $this->hasTimer;
    }

}

