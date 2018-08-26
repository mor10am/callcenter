<?php
declare(strict_types=1);

namespace Callcenter;

use Callcenter\Model\Agent;
use Callcenter\Model\Call;
use Callcenter\Model\Connection;
use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;

class Callcenter
{
    /**
     * @var \Callcenter\WebsocketHandler $websocket
     */
    private $websocket;

    /**
     * @var \Callcenter\AsteriskManager $ami
     */
    private $ami;

    /**
     * @var \Psr\Log\LoggerInterface $logger
     */
    private $logger;

    /**
     * @var array $settings
     */
    private $settings = [
        'report' => false
    ];

    /**
     * @var array
     */
    private $agents = [];

    /**
     * @var array
     */
    private $calls = [];

    /**
     * Connections of agent and call
     * @var array
     */
    private $connections = [];

    /**
     * Callcenter constructor.
     * @param WebsocketHandler $websocket
     * @param AsteriskManager $ami
     * @param LoggerInterface $logger
     */
    public function __construct(
        \Callcenter\WebsocketHandler $websocket,
        \Callcenter\AsteriskManager $ami,
        LoggerInterface $logger,
        array $settings = []
    )
    {
        $this->websocket = $websocket;
        $this->ami = $ami;
        $this->logger = $logger;
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function websocketHello(ConnectionInterface $conn) : void
    {
        $str = "";

        foreach ($this->agents as $agent) {
            $str .= "AGENT|".json_encode($agent)."\n";
        }

        foreach ($this->calls as $call) {
            $str .= "CALL|".json_encode($call)."\n";
        }

        foreach ($this->connections as $connection) {
            $str .= "CONNECT|".json_encode($connection)."\n";
        }

        $this->logger->debug("TO UI: ".$str);

        $conn->send($str);
    }

    /**
     * @param ConnectionInterface $conn
     * @param string $agentid
     */
    public function websocketSetAgentAvail(ConnectionInterface $conn, string $agentid) : void
    {
        if (!isset($this->agents[$agentid])) {
            $this->agents[$agentid] = new \Callcenter\Model\Agent($agentid);
        }

        $agent = $this->agents[$agentid];

        $this->ami->unpauseAgent($agentid);
        $this->setAgentStatus($agent, 'AVAIL');
    }

    /**
     * @param ConnectionInterface $conn
     * @param string $agentid
     * @param string $force
     */
    public function websocketSetAgentPause(ConnectionInterface $conn, string $agentid) : void
    {
        if (!isset($this->agents[$agentid])) {
            $this->agents[$agentid] = new \Callcenter\Model\Agent($agentid);
        }

        $agent = $this->agents[$agentid];

        $this->ami->pauseAgent($agentid);
        $this->setAgentStatus($agent, 'PAUSED');
    }

    /**
     * @param string $agentid
     * @return Agent
     */
    private function getOrCreateAgent(string $agentid) : Agent
    {
        if (!isset($this->agents[$agentid])) {
            $this->agents[$agentid] = new Agent($agentid);
        }

        return $this->agents[$agentid];
    }

    /**
     * @param string $callerid
     * @param string $uid
     * @return Call
     */
    public function getOrCreateCall(string $callerid, string $uid) : Call
    {
        if (!isset($this->calls[$uid])) {
            $this->calls[$uid] = new Call($callerid, $uid);
        }

        return $this->calls[$uid];
    }

    /**
     * @param string $agentid
     */
    public function agentLoggedIn(string $agentid) : void
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'LOGGEDIN'
        );
    }

    /**
     * @param string $agentid
     */
    public function agentLoggedOut(string $agentid) : void
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'LOGGEDOUT'
        );
    }

    /**
     * @param string $agentid
     */
    public function agentPaused(string $agentid) : void
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'PAUSED'
        );
    }

    /**
     * @param string $agentid
     */
    public function agentAvail(string $agentid) : void
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'AVAIL'
        );
    }

    /**
     * @param string $callerid
     * @param string $uid
     */
    public function callNew(string $callerid, string $uid) : void
    {
        $call = $this->getOrCreateCall($callerid, $uid);

        $this->websocket->sendtoAll("CALL|".json_encode($call));

        $this->logger->info("Call {$call} is in the IVR");
    }

    /**
     * @param string $callerid
     * @param string $uid
     */
    public function callHangup(string $callerid, string $uid) : void
    {
        $call = $this->getOrCreateCall($callerid, $uid);

        $this->setCallStatus($call, 'HANGUP');

        unset($this->calls[$call->uid]);

        $agent = null;

        if (isset($this->connections[$call->uid])) {
            $agent = $this->connections[$call->uid]->agent;
            $this->ami->unpauseAgent($agent->getAgentId());
            $this->setAgentStatus($agent, 'AVAIL');
            unset($this->connections[$call->uid]);
        }

        $str = "CALL|".json_encode($call);

        if ($agent) {
            $str .= "\nAGENT|".json_encode($agent);
        }

        $this->websocket->sendtoAll($str);

        $this->logger->info("Call {$call} hung up");
    }

    /**
     * @param string $callerid
     * @param string $uid
     * @param string $queue
     */
    public function callQueued(string $callerid, string $uid, string $queue) : void
    {
        $call = $this->getOrCreateCall($callerid, $uid);

        $call->setQueue($queue);
        $this->setCallStatus($call, 'QUEUED');

        $this->websocket->sendtoAll("CALL|".json_encode($call));

        $this->logger->info("Call {$call} was queued in queue {$call->queue}");
    }

    /**
     * @param string $agentid
     * @param string $callerid
     * @param string $uid
     */
    public function callAndAgentConnected(string $agentid, string $callerid, string $uid) : void
    {
        if (!isset($this->agents[$agentid]) or !isset($this->calls[$uid])) {
            return;
        }

        $agent = $this->agents[$agentid];
        $call = $this->calls[$uid];

        if (!isset($this->connections[$uid])) {
            $agent->setQueue($call->getQueue());
            $this->setAgentStatus($agent, 'INCALL');
            $this->setCallStatus($call, 'INCALL');

            $conn = new Connection($call, $agent);

            $this->websocket->sendtoAll(
                "AGENT|".json_encode($agent)."\n".
                "CALL|".json_encode($call)."\n".
                "CONNECT|".json_encode($conn)
            );

            $this->connections[$uid] = $conn;

            $this->logger->info("Call {$call} was connected to agent {$agent}");
        }
    }

    /**
     * Set status on Call and notify UI
     *
     * @param Call $call
     * @param string $status
     */
    private function setCallStatus(Call $call, string $status) : void
    {
        $report = $call->getReportLine();

        if ($call->setStatus($status)) {
            file_put_contents(
                $this->settings['report'],
                $report."\n",
                FILE_APPEND
            );
        }
    }

    /**
     * Set status on Agent and notify UI
     *
     * @param Agent $agent
     * @param string $status
     */
    private function setAgentStatus(Agent $agent, string $status) : void
    {
        $report = $agent->getReportLine();

        if ($agent->setStatus($status)) {
            file_put_contents(
                $this->settings['report'],
                $report."\n",
                FILE_APPEND
            );
        }

        $this->websocket->sendtoAll("AGENT|".json_encode($agent));

        $this->logger->info("Agent {$agent}");
    }
}
