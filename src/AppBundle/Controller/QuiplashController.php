<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Answer;
use AppBundle\Entity\Exception\AlreadyInTheGameException;
use AppBundle\Entity\Exception\GameException;
use AppBundle\Entity\Exception\NoAnswersForUserException;
use AppBundle\Entity\Exception\NotEnoughPlayersException;
use AppBundle\Entity\Game;
use AppBundle\Entity\Question;
use AppBundle\Entity\User;
use AppBundle\GameManager;
use AppBundle\Repository\GameRepository;
use Bayne\Telegram\Bot\Client;
use Bayne\Telegram\Bot\Object\InlineKeyboardButton;
use Bayne\Telegram\Bot\Object\InlineKeyboardMarkup;
use Bayne\Telegram\Bot\Object\Update;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

class QuiplashController extends Controller
{

    /**
     * @Route(
     *     name="new_game_command",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('text') matches '<^/new>'"
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function newGameAction(Update $update)
    {
        try {
            $game = $this->getGameManager()->newGame(
                $this->getUser(),
                $update->getMessage()->getChat()->getId()
            );

            $game = $this->getGameManager()->joinGame(
                $this->getUser(),
                $game->getChatGroup()
            );

            $this->getClient()->sendMessage(
                $game->getChatGroup(),
                $this->getJoinMessage($game),
                null,
                null,
                null,
                null,
                (new InlineKeyboardMarkup())
                    ->setInlineKeyboard(
                        [
                            [
                                (new InlineKeyboardButton())
                                    ->setText('Join')
                                    ->setCallbackData('/join_callback')
                            ]
                        ]
                    )
            );
        } catch (GameException $e) {
            $this->getLogger()->info($e->getMessage());
            $this->getClient()->sendMessage(
                $update->getMessage()->getChat()->getId(),
                'Cannot start a new game, game already exists. Type /end to end current the game'
            );
        }

        return new Response();
    }

    /**
     * @Route(
     *     name="join_game_callback",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('callback_data') matches '<^/join_callback>'"
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function joinGameCallbackAction(Update $update)
    {
        try {
            $game = $this->getGameManager()->joinGame($this->getUser(), $update->getCallbackQuery()->getMessage()->getChat()->getId());
            $this->getClient()->answerCallbackQuery(
                $update->getCallbackQuery()->getId(),
                'You have joined the game'
            );

            $this->getClient()->editMessageText(
                $update->getCallbackQuery()->getMessage()->getChat()->getId(),
                $update->getCallbackQuery()->getMessage()->getMessageId(),
                null,
                $this->getJoinMessage($game),
                null,
                null,
                (new InlineKeyboardMarkup())
                    ->setInlineKeyboard(
                        [
                            [
                                (new InlineKeyboardButton())
                                    ->setText('Join')
                                    ->setCallbackData('/join_callback')
                            ]
                        ]
                    )
            );

        } catch (AlreadyInTheGameException $e) {
            $this->getClient()->answerCallbackQuery(
                $update->getCallbackQuery()->getId(),
                'You are already in this game'
            );
        } catch (GameException $e) {
            $this->getClient()->answerCallbackQuery(
                $update->getCallbackQuery()->getId(),
                'There is no game currently running'
            );
            $this->getLogger()->info($e->getMessage());
        }

        return new Response();
    }

    /**
     * @Route(
     *     name="begin_game_command",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('text') matches '<^/begin>'"
     * )
     *
     * @param Update $update
     *
     * @return Response
     */
    public function beginGameAction(Update $update)
    {
        try {
            $game = $this->getGameManager()->beginGame(
                $update->getMessage()->getChat()->getId()
            );

            $this->getClient()->sendGame(
                $game->getChatGroup(),
                'quiplash',
                false,
                null,
                (new InlineKeyboardMarkup())
                    ->setInlineKeyboard(
                        [
                            [
                                (new InlineKeyboardButton())
                                    ->setText('Enter your prompts')
                                    ->setCallbackGame(true)
                            ]
                        ]
                    )
            );
        } catch (NotEnoughPlayersException $e) {
            $this->getClient()->sendMessage(
                $update->getMessage()->getChat()->getId(),
                'You need at least 3 players to start the game'
            );
        } catch (GameException $e) {
            $this->getLogger()->info($e->getMessage());
        }

        return new Response();
    }

    /**
     * @Route(
     *     name="launch_game_command",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('game_short_name') matches '<^quiplash$>'"
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function launchGameCallbackAction(Update $update)
    {
        try {
            $token = $this->getGameManager()->getTokenForUser(
                $this->getUser(),
                $update->getCallbackQuery()->getMessage()->getChat()->getId()
            );
        } catch (NoAnswersForUserException $e) {
            $this->getClient()->answerCallbackQuery(
                $update->getCallbackQuery()->getId(),
                'You have already answered your prompts'
            );
            return new Response;
        }

        $url = $this->generateUrl(
            'enter_prompts',
            [
                'token' => urlencode($token),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->getClient()->answerCallbackQuery(
            $update->getCallbackQuery()->getId(),
            'test',
            null,
            $url
        );
        return new Response();
    }

    /**
     * @Route(
     *     name="enter_prompts",
     *     path="/telegram/quiplash/prompts/{token}"
     * )
     *
     * @param Request $request
     * @return Response
     */
    public function enterPromptsAction(Request $request, $token)
    {
        /** @var Answer $answer */
        $answer = $this->getEntityManager()->getRepository(Answer::class)->findOneBy(
            [
                'token' => urldecode($token),
            ]
        );

        if (false === $answer->isPending()) {
            return $this->render('quiplash/done.html.twig');
        }

        $form = $this->createFormBuilder($answer)
            ->setMethod('get')
            ->add(
                'response',
                TextType::class,
                [
                    'label' => $answer->getQuestion()->getText()
                ]
            )
            ->add('submit', SubmitType::class)
        ;

        $form = $form->getForm();

        $form->handleRequest($request);

        if ($form->get('submit')->isClicked()) {
            if ($form->isValid()) {
                $this->getDoctrine()->getManager()->persist($answer);
                $this->getDoctrine()->getManager()->flush();
                try {
                    $token = $this->getGameManager()->getTokenForUser(
                        $answer->getUser(),
                        $answer->getGame()->getChatGroup()
                    );

                    return $this->redirectToRoute(
                        'enter_prompts',
                        [
                            'token' => urlencode($token)
                        ]
                    );

                } catch (NoAnswersForUserException $e) {
                    $game = $answer->getGame();

                    if ($game->allAnswersAreIn()) {
                        $this->getGameManager()->beginVoting($game);
                        $this->askToVoteOnCurrentQuestion($game);
                    }

                    return $this->render(
                        'quiplash/done.html.twig'
                    );
                }

            }
        }

        return $this->render(
            'quiplash/prompts.html.twig',
            [
                'form' => $form->createView()
            ]
        );
    }

    /**
     * @Route(
     *     name="vote_callback",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('callback_data') matches '<^/vote_callback>'"
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function voteCallbackAction(Request $request, Update $update)
    {
        list($command, $choice) = explode(' ', $request->attributes->get('callback_data'));

        /** @var Game $game */
        $game = $this->getGameManager()->getCurrentGame($update->getCallbackQuery()->getMessage()->getChat()->getId());
        $answers = $game->getAnswersForCurrentQuestion();
        $answer = $answers[$choice];

        if ($game->alreadyVoted($this->getUser())) {
            $this->getClient()->answerCallbackQuery(
                $update->getCallbackQuery()->getId(),
                'You can\'t vote more than once'
            );
        } else {
            $voters = $game->getVotersForCurrentQuestion();
            if (!in_array($this->getUser(), $voters)) {
                $this->getClient()->answerCallbackQuery(
                    $update->getCallbackQuery()->getId(),
                    'You can\'t vote for your own question'
                );
            } else {
                $this->getGameManager()->recordVote($this->getUser(), $answer);
                $this->getClient()->answerCallbackQuery(
                    $update->getCallbackQuery()->getId(),
                    'Vote recorded'
                );
            }

            $this->getDoctrine()->getManager()->clear();
            $game = $this->getDoctrine()->getManager()->find(Game::class, $game->getId());

            if (count($game->getMissingUserVotes()) === 0) {
                $roundResults = '';

                foreach ($game->getAnswersForCurrentQuestion() as $answer) {
                    $roundResults .= "\n" . $answer->getResponse() . ' (' . $answer->getUser()->getFirstName() . ' +' . count($answer->getVotes()) . ')';
                }

                $this->getClient()->sendMessage(
                    $game->getChatGroup(),
                    $roundResults
                );

                $this->getDoctrine()->getManager()->clear();
                $game = $this->getDoctrine()->getManager()->find(Game::class, $game->getId());
                $this->getGameManager()->advanceGame($game);

                if ($game->getState() === Game::END) {
                    $scoreBoard = $game->getScoreBoard();
                    $gameOver = 'Game Over! Winner: ' . reset($scoreBoard)['user']->getFirstName();
                    foreach ($scoreBoard as $score) {
                        $gameOver .= "\n" . $score['user']->getFirstName() . ': ' . $score['points'] . " pts";
                    }

                    $this->getClient()->sendMessage(
                        $game->getChatGroup(),
                        $gameOver
                    );
                } else {
                    $this->askToVoteOnCurrentQuestion($game);
                }

            }
        }

        return new Response();
    }

    /**
     * @Route(
     *     name="end_game_command",
     *     path="/telegram/quiplash",
     *     methods={"POST"},
     *     condition="request.attributes.get('text') matches '<^/end>'"
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function endGameAction(Update $update)
    {
        try {
            $game = $this->getGameManager()->endGameForGroup($update->getMessage()->getChat()->getId());
            $this->getClient()->sendMessage(
                $game->getChatGroup(),
                'Ending the game'
            );
        } catch (GameException $e) {
            $this->getLogger()->info($e->getMessage());
        }

        return new Response();
    }

    /**
     * @Route(
     *     name="missing_command",
     *     path="/telegram/quiplash",
     *     methods={"POST"}
     * )
     *
     * @param Update $update
     * @return Response
     */
    public function missingCommandAction(Update $update)
    {
        return new Response();
    }

    /**
     * @return GameRepository
     */
    protected function getGameRepository()
    {
        return $this->getDoctrine()->getRepository(Game::class);
    }

    protected function getEntityManager()
    {
        return $this->getDoctrine()->getManager();
    }

    protected function getClient()
    {
        return $this->get('telegram_client');
    }

    /**
     * @return GameManager
     */
    protected function getGameManager()
    {
        return new GameManager(
            $this->getDoctrine()->getRepository(Game::class),
            $this->getDoctrine()->getRepository(Question::class)
        );
    }

    private function getLogger()
    {
        return $this->get('logger');
    }

    private function askToVoteOnCurrentQuestion(Game $game)
    {
        $answers = $game->getAnswersForCurrentQuestion();
        $this->getClient()->sendMessage(
            $game->getChatGroup(),
            $game->getCurrentQuestion()->getText(),
            null,
            null,
            null,
            null,
            (new InlineKeyboardMarkup())
                ->setInlineKeyboard(
                    [
                        [
                            (new InlineKeyboardButton())
                                ->setText($answers[0]->getResponse())
                                ->setCallbackData('/vote_callback 0'),
                        ],
                        [
                            (new InlineKeyboardButton())
                                ->setText($answers[1]->getResponse())
                                ->setCallbackData('/vote_callback 1'),
                        ]
                    ]
                )
        );
    }

    private function getJoinMessage(Game $game)
    {
        return "Click the Join button below \nOnce everyone has joined, then type /begin to start the game" .
            "\nPlayers:\n" .
            implode("\n",
                array_map(
                    function (User $user) {
                        return $user->getFirstName();
                    },
                    $game->getUsers()->toArray()
                )
            )
        ;
    }
}