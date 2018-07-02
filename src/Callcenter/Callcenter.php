<?php
declare(strict_types=1);

namespace Callcenter;

use Callcenter\Model\Agent;
use Callcenter\Model\Caller;
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
    private $callers = [];

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
            $str .= "AGENT:{$agent}\n";
        }

        foreach ($this->callers as $caller) {
            $str .= "CALLER:{$caller}\n";
        }

        foreach ($this->connections as $conn) {
            $str .= "CONNECT:{$conn}\n";
        }

        $this->logger->debug("TO UI: ".$str);

        $conn->send($str);
    }

    /**
     * @param ConnectionInterface $conn
     * @param string $agentid
     * @param string $force
     */
    public function websocketToggleAvail(ConnectionInterface $conn, string $agentid, string $force = "") : void
    {
        if (!isset($this->agents[$agentid])) {
            return;
        }

        $agent = $this->agents[$agentid];
        $current_status = $agent->status;

        if ($current_status == 'PAUSED' or $force == 'AVAIL') {
            $this->ami->unpauseAgent($agentid);
            $this->setAgentStatus($agent, 'AVAIL');
        } elseif ($current_status == 'AVAIL' or $force == 'PAUSED') {
            $this->ami->pauseAgent($agentid);
            $this->setAgentStatus($agent, 'PAUSED');
        }
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
     * @return Caller
     */
    public function getOrCreateCaller(string $callerid, string $uid) : Caller
    {
        if (!isset($this->callers[$uid])) {
            $this->callers[$uid] = new Caller($callerid, $uid);
        }

        return $this->callers[$uid];
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
    public function callerNew(string $callerid, string $uid) : void
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $this->websocket->sendtoAll("CALLER:{$caller}");

        $this->logger->info("Caller {$caller} is in the IVR");
    }

    /**
     * @param string $callerid
     * @param string $uid
     */
    public function callerHangup(string $callerid, string $uid) : void
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $this->setCallerStatus($caller, 'HANGUP');

        unset($this->callers[$caller->uid]);

        if (isset($this->connections[$caller->uid])) {
            $agent = $this->connections[$caller->uid]->agent;
            $this->setAgentStatus($agent, 'AVAIL');
            unset($this->connections[$caller->uid]);
        }


        $this->websocket->sendtoAll("CALLERHANGUP:{$caller}");

        $this->logger->info("Caller {$caller} hung up");
    }

    /**
     * @param string $callerid
     * @param string $uid
     * @param string $queue
     */
    public function callerQueued(string $callerid, string $uid, string $queue) : void
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $caller->setQueue($queue);
        $this->setCallerStatus($caller, 'QUEUED');

        $this->websocket->sendtoAll("CALLERJOIN:{$caller}");

        $this->logger->info("Caller {$caller} was queued in queue {$caller->queue}");
    }

    /**
     * @param string $agentid
     * @param string $callerid
     * @param string $uid
     */
    public function callerAndAgentConnected(string $agentid, string $callerid, string $uid) : void
    {
        if (!isset($this->agents[$agentid]) or !isset($this->callers[$uid])) {
            return;
        }

        $agent = $this->agents[$agentid];
        $caller = $this->callers[$uid];

        if (!isset($this->connections[$uid])) {
            $agent->setQueue($caller->getQueue());
            $this->setAgentStatus($agent, 'INCALL');
            $this->setCallerStatus($caller, 'INCALL');

            $conn = new Connection($caller, $agent);
            $this->websocket->sendtoAll("CONNECT:{$conn}");
            $this->connections[$uid] = $conn;

            $this->logger->info("Caller {$caller} was connected to agent {$agent}");
        }
    }

    /**
     * Set status on Caller and notify UI
     *
     * @param Caller $caller
     * @param string $status
     */
    private function setCallerStatus(Caller $caller, string $status) : void
    {
        $report = $caller->getReportLine();

        if ($caller->setStatus($status)) {
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

        $this->websocket->sendtoAll("AGENT:{$agent}");

        $this->logger->info("Agent {$agent}");
    }
}
