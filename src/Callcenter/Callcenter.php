<?php

namespace Callcenter;

use Callcenter\Model\Agent;
use Callcenter\Model\Caller;
use Callcenter\Model\Bridge;

use Psr\Log\LoggerInterface;
use PAMI\Message\Event\EventMessage;

use Ratchet\ConnectionInterface;

class Callcenter
{
    /* @var \Callcenter\WebsocketHandler $websocket */
    private $websocket;

    /* @var \Callcenter\AsteriskManager $ami */
    private $ami;

    /* @var LoggerInterface $logger */
    private $logger;

    /* @var array $settings */
    private $settings = [
        'report' => false
    ];

    /* @var array $agents */
    private $agents = [];

    /* @var array $callers */
    private $callers = [];

    /* @var array $bridges */
    private $bridges = [];

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
    public function websocketHello(ConnectionInterface $conn)
    {
        $str = "";

        foreach ($this->agents as $agent) {
            $str .= "AGENT:{$agent}\n";
        }

        $this->logger->debug("TO UI: ".$str);

        $conn->send($str);
    }

    /**
     * @param ConnectionInterface $conn
     * @param $agentid
     * @param string $force
     */
    public function websocketToggleAvail(ConnectionInterface $conn, $agentid, $force = "")
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
    private function getOrCreateAgent($agentid) : Agent
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
     * @param $agentid
     */
    public function websocketSetAgentAvail(ConnectionInterface $conn, $agentid)
    {
        if (!isset($this->agents[$agentid])) {
            $this->agents[$agentid] = new \Callcenter\Model\Agent($agentid);
        }

        $agent = $this->agents[$agentid];

        $this->ami->unpauseAgent($agentid);
        $this->setAgentStatus($agent, 'AVAIL');
    }

    /**
     * @param $agentid
     */
    public function agentLoggedIn($agentid)
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'LOGGEDIN'
        );
    }

    /**
     * @param $agentid
     */
    public function agentLoggedOut($agentid)
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'LOGGEDOUT'
        );
    }

    /**
     * @param $agentid
     */
    public function agentPaused($agentid)
    {
        $this->setAgentStatus(
            $this->getOrCreateAgent($agentid),
            'PAUSED'
        );
    }

    /**
     * @param $agentid
     */
    public function agentAvail($agentid)
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
    public function callerNew(string $callerid, string $uid)
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $this->websocket->sendtoAll("CALLER:{$caller}");

        $this->logger->info("Caller {$caller} is in the IVR");
    }

    /**
     * @param string $callerid
     * @param string $uid
     */
    public function callerHangup(string $callerid, string $uid)
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $this->setCallerStatus($caller, 'HANGUP');

        unset($this->callers[$caller->uid]);

        if (isset($this->bridges[$caller->uid])) {
            $agent = $this->bridges[$caller->uid]->agent;
            $this->setAgentStatus($agent, 'AVAIL');
            unset($this->bridges[$caller->uid]);
        }


        $this->websocket->sendtoAll("CALLERHANGUP:{$caller}");

        $this->logger->info("Caller {$caller} hung up");
    }

    /**
     * @param string $callerid
     * @param string $uid
     * @param string $queue
     */
    public function callerQueued(string $callerid, string $uid, string $queue)
    {
        $caller = $this->getOrCreateCaller($callerid, $uid);

        $caller->setQueue($queue);
        $this->setCallerStatus($caller, 'QUEUED');

        $this->websocket->sendtoAll("CALLERJOIN:{$caller}|{$caller->queue}");

        $this->logger->info("Caller {$caller} was queued in queue {$caller->queue}");
    }

    /**
     * @param string $agentid
     * @param string $callerid
     * @param string $uid
     */
    public function callerAndAgentConnected(string $agentid, string $callerid, string $uid)
    {
        if (!isset($this->agents[$agentid]) or !isset($this->callers[$uid])) {
            return;
        }

        $agent = $this->agents[$agentid];
        $caller = $this->callers[$uid];

        if (!isset($this->bridges[$uid])) {
            $agent->setQueue($caller->getQueue());
            $this->setAgentStatus($agent, 'INCALL');
            $this->setCallerStatus($caller, 'INCALL');

            $this->bridges[$uid] = new Bridge($caller, $agent);
            $this->websocket->sendtoAll("CONNECT:{$agent}:{$caller}");

            $this->logger->info("Caller {$caller} was connected to agent {$agent}");
        }
    }

    /**
     * @param Caller $caller
     * @param $status
     */
    private function setCallerStatus(Caller $caller, $status)
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
     * @param Agent $agent
     * @param $status
     */
    private function setAgentStatus(Agent $agent, $status)
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
