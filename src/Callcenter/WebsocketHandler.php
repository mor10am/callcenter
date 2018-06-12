<?php

namespace Callcenter;

use Evenement\EventEmitter;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Monolog\Logger;

final class WebsocketHandler extends EventEmitter implements MessageComponentInterface
{
    private $clients;
    private $logger;

    /**
     * WebsocketHandler constructor.
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->clients = new \SplObjectStorage;
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
    }

    /**
     * @param ConnectionInterface $from
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->logger->debug("WS: " . $msg);

        $parts = explode(":", $msg);
        $cmd = $parts[0];

        switch ($cmd) {
            case 'HELLO':
                $this->emit('websocket.hello', [$from]);
                break;
            case 'TOGGLE':
                $agentid = $parts[1];

                $this->emit('websocket.toggle', [$from, $agentid]);
                break;
            case 'AVAIL':
                $agentid = $parts[1];

                $this->emit('websocket.avail', [$from, $agentid]);
                break;
            default:
                echo "Unknown msg: {$msg}\n";
        }
    }

    /**
     * @param $msg
     */
    public function sendtoAll($msg)
    {
        foreach ($this->clients as $client) {
            $client->send($msg);
        }
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
    }
}