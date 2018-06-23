<?php
declare(strict_types=1);

namespace Callcenter;

use Evenement\EventEmitter;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Log\LoggerInterface;

final class WebsocketHandler extends EventEmitter implements MessageComponentInterface
{
    /**
     * @var \SplObjectStorage
     */
    private $clients;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * WebsocketHandler constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clients = new \SplObjectStorage;
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn) : void
    {
        $this->clients->attach($conn);
    }

    /**
     * @param ConnectionInterface $from
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $from, $msg = '') : void
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
    public function sendtoAll(string $msg) : void
    {
        foreach ($this->clients as $client) {
            $client->send($msg);
        }
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn) : void
    {
        $this->clients->detach($conn);
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e) : void
    {
        $conn->close();
    }
}
