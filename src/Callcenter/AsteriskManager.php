<?php
declare(strict_types=1);

/**
 * This is based on the PAMI\Client\Impl\ClientImpl class
 * by Marcelo Gornstein
 *
 * @link http://marcelog.github.com/PAMI/
 */

namespace Callcenter;

use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event\BridgeEnterEvent;
use PAMI\Message\Event\UnknownEvent;
use PAMI\Message\Event\UserEventEvent;
use PAMI\Message\Event\QueueMemberPausedEvent;
use PAMI\Message\Event\QueueCallerJoinEvent;

use PAMI\Message\Action\LoginAction;
use PAMI\Message\Action\QueuePauseAction;
use PAMI\Message\Action\QueueUnpauseAction;
use PAMI\Message\Response\ResponseMessage;
use PAMI\Message\IncomingMessage;
use PAMI\Message\Message;
use PAMI\Message\Event\Factory\Impl\EventFactoryImpl;
use Psr\Log\NullLogger;
use Evenement\EventEmitter;

class AsteriskManager extends EventEmitter implements \PAMI\Client\IClient,
    \PAMI\Listener\IEventListener
{

    /**
     * PSR-3 logger.
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \React\Stream\DuplexResourceStream
     */
    public $stream;

    /**
     * @var array
     */
    public $options;

    /**
     * Event factory.
     * @var \PAMI\Message\Event\Factory\Impl\EventFactoryImpl
     */
    private $eventFactory;

    /**
     * Our event listeners
     * @var \PAMI\Listener\IEventListener[]
     */
    private $eventListeners;

    /**
     * The receiving queue.
     * @var \PAMI\Message\IncomingMessage[]
     */
    private $incomingQueue;

    /**
     * This should not happen. Asterisk may send responses without a
     * corresponding ActionId.
     * @var string
     */
    private $lastActionId;

    /**
     * Event mask to apply on login action.
     * @var string|null
     */
    private $eventMask;

    /**
     * Template for the queue member channel (ex. local/{{agentid}}@context)
     * The {{agentid}} part will be replaced
     * @var string|null
     */
    private $memberTemplate;

    /**
     * AsteriskManager constructor.
     * @param \React\Stream\DuplexResourceStream $stream
     * @param array $options
     */
    public function __construct(
        \React\Stream\DuplexResourceStream $stream,
        array $options
    )
    {
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->stream = $stream;

        $this->eventMask = isset($options['event_mask']) ? $options['event_mask'] : null;
        $this->eventListeners = array();
        $this->eventFactory = new EventFactoryImpl();
        $this->incomingQueue = array();
        $this->lastActionId = "";
        $this->memberTemplate = isset($options['member_template']) ? $options['member_template'] : null;

        $this->registerEventListener($this);

        $that = $this;

        $this->stream->on('data', function ($data) use ($that) {
            $that->messageDispatcher($data);
        });
    }

    public function login($username, $password)
    {
        $this->send(
            (new LoginAction(
                $username,
                $password
            ))
        );
    }

    /**
     *
     */
    public function open()
    {
    }

    /**
     * @param EventMessage $event
     */
    public function handle(EventMessage $event) : void
    {
        switch ($event->getName()) {
            case "UserEvent":
                $this->handleUserEvent($event);
                break;
            case "QueueMemberPause":
            case "QueueMemberPaused":
                $this->handleAgentPause($event);
                break;
            case "QueueCallerJoin":
                $this->handleCallerEnterQueue($event);
                break;
            case "BridgeEnter":
                $this->handleConnectCallerAndAgent($event);
                break;
            default:
                break;
        }
    }

    protected function handleUserEvent(UserEventEvent $event)
    {
        switch ($event->getUserEventName()) {
            case 'CALLER':
                $this->handleNewCaller($event);
                break;
            case 'CALLERHANGUP':
                $this->handleHangupCaller($event);
                break;
            case 'LOGGEDIN':
                $this->handleAgentLogin($event);
                break;
            case 'LOGGEDOUT':
                $this->handleAgentLogout($event);
                break;
        }
    }

    /**
     * @param BridgeEnterEvent $event
     */
    protected function handleConnectCallerAndAgent(BridgeEnterEvent $event) :  void
    {
        $agentid = $event->getKey('calleridnum');
        $callerid = $event->getKey('connectedlinenum');
        $uid = $event->getKey('linkedid');

        if ($agentid and $callerid) {
            $this->emit('queue.connect', [
                new CallCenterEvent(
                    'queue.connect',
                    [
                        'agentid' => $agentid,
                        'callerid' => $callerid,
                        'calleruid' => $uid,
                    ]
                )
            ]);
        }
    }

    /**
     * @param QueueCallerJoinEvent $event
     */
    protected function handleCallerEnterQueue(QueueCallerJoinEvent $event) : void
    {
        $this->emit('caller.queued', [
            new CallCenterEvent(
                'caller.queued',
                [
                    'callerid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid'),
                    'queue' => $event->getKey('queue'),
                ]
            )
        ]);
    }

    /**
     * @param QueueMemberPausedEvent $event
     */
    protected function handleAgentPause(QueueMemberPausedEvent $event) : void
    {
        $agentstatus = strtolower(($event->getKey("paused") == 1)?"PAUSED":"AVAIL");

        $type = "agent.{$agentstatus}";

        $this->emit($type, [
            new CallCenterEvent(
                $type,
                [
                    'agentid' => $event->getKey('membername'),
                    'uid' => $event->getKey('uniqueid'),
                ]
            )
        ]);
    }

    /**
     * @param UserEventEvent $event
     */
    protected function handleAgentLogout(UserEventEvent $event) : void
    {
        $this->emit('agent.loggedout', [
            new CallCenterEvent(
                'agent.loggedout',
                [
                    'agentid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid'),
                ]
            )
        ]);
    }

    /**
     * @param UserEventEvent $event
     */
    protected function handleAgentLogin(UserEventEvent $event) : void
    {
        $this->emit('agent.loggedin', [
            new CallCenterEvent(
                'agent.loggedin',
                [
                    'agentid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid'),
                ]
            )
        ]);
    }

    /**
     * @param UserEventEvent $event
     */
    protected function handleHangupCaller(UserEventEvent $event) : void
    {
        $this->emit('caller.hangup', [
            new CallCenterEvent(
                'caller.hangup',
                [
                    'callerid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid')
                ]
            )
        ]);
    }

    /**
     * @param UserEventEvent $event
     */
    protected function handleNewCaller(UserEventEvent $event) : void
    {
        $this->emit('caller.new', [
            new CallCenterEvent(
                'caller.new',
                [
                    'callerid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid')
                ]
            )
        ]);        
    }

    /**
     * Send action to Asterisk to unpause the agent
     * @param string $agentid
     */
    public function unpauseAgent($agentid)
    {
        $member = str_replace("{{agentid}}", $agentid, $this->memberTemplate);

        $this->send(
            new QueueUnpauseAction($member)
        );

        $this->logger->debug("Unpause $member");
    }

    /**
     * Send action to Asterisk to pause the agent
     * @param string $agentid
     */
    public function pauseAgent($agentid)
    {
        $member = str_replace("{{agentid}}", $agentid, $this->memberTemplate);

        $this->send(
            new QueuePauseAction($member)
        );

        $this->logger->debug("Pause $member");
    }

    /**
     * @param string $data
     */
    public function messageDispatcher($data)
    {
        $msgs = [];

        while (($marker = strpos($data, Message::EOM))) {
            $msg = substr($data, 0, $marker);

            $data = substr(
                $data,
                $marker + strlen(Message::EOM)
            );

            $msgs[] = $msg;
        }

        foreach ($msgs as $aMsg) {
            $resPos = strpos($aMsg, 'Response:');
            $evePos = strpos($aMsg, 'Event:');

            if (($resPos !== false) &&
                (($resPos < $evePos) || $evePos === false)
            ) {
                $response = $this->messageToResponse($aMsg);
                $this->incomingQueue[$response->getActionId()] = $response;
            } elseif ($evePos !== false) {
                $event = $this->messageToEvent($aMsg);
                $response = $this->findResponse($event);
                if (!$response || $response->isComplete()) {
                    $this->dispatch($event);
                } else {
                    $response->addEvent($event);
                }
            } else {
                $bMsg = 'Event: ResponseEvent' . "\r\n";
                $bMsg .= 'ActionId: ' . $this->lastActionId . "\r\n" . $aMsg;
                $event = $this->messageToEvent($bMsg);
                if ($response = $this->findResponse($event)) {
                    $response->addEvent($event);
                }
            }
        }
    }

    /**
     * @param IncomingMessage $message
     */
    protected function dispatch(IncomingMessage $message)
    {
        foreach ($this->eventListeners as $data) {
            $listener = $data[0];
            $predicate = $data[1];

            if (is_callable($predicate) && !call_user_func($predicate, $message)) {
                continue;
            }
            if ($listener instanceof \Closure) {
                $listener($message);
            } elseif (is_array($listener)) {
                $listener[0]->{$listener[1]}($message);
            } else {
                $listener->handle($message);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function process()
    {
        throw new \Exception("Not implemented. See dispatch() and handle()");
    }

    /**
     * @param mixed $listener
     * @param null $predicate
     * @return string
     */
    public function registerEventListener($listener, $predicate = null) : string
    {
        $listenerId = uniqid('PamiListener');
        $this->eventListeners[$listenerId] = array($listener, $predicate);
        return $listenerId;
    }

    /**
     * @param string $listenerId
     */
    public function unregisterEventListener($listenerId)
    {
        if (isset($this->eventListeners[$listenerId])) {
            unset($this->eventListeners[$listenerId]);
        }
    }

    /**
     * @throws \Exception
     */
    public function close()
    {
        throw new \Exception("Not implemented.");
    }

    /**
     * @param \PAMI\Message\OutgoingMessage $message
     */
    public function send(\PAMI\Message\OutgoingMessage $message)
    {
        $msg = $message->serialize();
        $this->logger->debug($msg);
        $this->stream->write($msg);
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Tries to find an associated response for the given message.
     *
     * @param IncomingMessage $message Message sent by asterisk.
     *
     * @return \PAMI\Message\Response\ResponseMessage
     */
    protected function findResponse(IncomingMessage $message)
    {
        $actionId = $message->getActionId();

        if (isset($this->incomingQueue[$actionId])) {
            return $this->incomingQueue[$actionId];
        }

        return null;
    }

    /**
     * Returns a ResponseMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Response\ResponseMessage
     */
    private function messageToResponse($msg)
    {
        $response = new ResponseMessage($msg);
        $actionId = $response->getActionId();
        if (is_null($actionId)) {
            $actionId = $this->lastActionId;
            $response->setActionId($this->lastActionId);
        }
        return $response;
    }

    /**
     * Returns a EventMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Event\EventMessage
     */
    private function messageToEvent($msg) : EventMessage
    {
        $event = $this->eventFactory->createFromRaw($msg);

        if (!$event instanceof UnknownEvent) {
            return $event;
        }

        // https://github.com/marcelog/PAMI/issues/139
        $msg = str_replace('QueueMemberPause', 'QueueMemberPaused', $msg);
        return $this->eventFactory->createFromRaw($msg);
    }
}
