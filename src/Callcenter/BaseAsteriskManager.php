<?php
declare(strict_types=1);

namespace Callcenter;

use PAMI\Message\Event\UnknownEvent;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Action\LoginAction;
use PAMI\Message\Action\QueuePauseAction;
use PAMI\Message\Action\QueueUnpauseAction;
use PAMI\Message\Response\ResponseMessage;
use PAMI\Message\IncomingMessage;
use PAMI\Message\Message;
use PAMI\Message\Event\Factory\Impl\EventFactoryImpl;
use Psr\Log\NullLogger;
use Evenement\EventEmitter;


abstract class BaseAsteriskManager extends EventEmitter implements \PAMI\Client\IClient,
    \PAMI\Listener\IEventListener
{
    /**
     * PSR-3 logger.
     * @var \Psr\Log\LoggerInterface
     */
    public $logger;

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
    public $eventFactory;

    /**
     * Our event listeners
     * @var \PAMI\Listener\IEventListener[]
     */
    public $eventListeners;

    /**
     * The receiving queue.
     * @var \PAMI\Message\IncomingMessage[]
     */
    public $incomingQueue;

    /**
     * This should not happen. Asterisk may send responses without a
     * corresponding ActionId.
     * @var string
     */
    public $lastActionId;

    /**
     * Event mask to apply on login action.
     * @var string|null
     */
    public $eventMask;

    /**
     * AsteriskManager constructor.
     * @param \React\Stream\DuplexResourceStream $stream
     * @param array $options
     */
    public function __construct(
        \React\Stream\DuplexResourceStream $stream,
        array $options = []
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

        $this->registerEventListener($this);

        $that = $this;

        $this->stream->on('data', function ($data) use ($that) {
            $that->messageDispatcher($data);
        });
    }

    /**
     * Log in to Asterisk Manager Interface
     *
     * @param string $username
     * @param string $password
     */
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
     * Send action to Asterisk to unpause the agent
     * @param string $member
     */
    public function unpauseAgent($member)
    {
        $this->send(
            new QueueUnpauseAction($member)
        );

        $this->logger->debug("Unpause $member");
    }

    /**
     * Send action to Asterisk to pause the agent
     * @param string $member
     */
    public function pauseAgent($member)
    {
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