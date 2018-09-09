<?php
declare(strict_types=1);

namespace Callcenter;

use Callcenter\Model\Agent;
use Callcenter\Model\Call;
use Callcenter\Model\Connection;
use Psr\Log\LoggerInterface;

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
    private $agentcallconnections = [];

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
     * @param CallCenterEvent $event
     */
    public function websocketHello(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.hello') {
            throw new \InvalidArgumentException("This method expects a websocket.hello event. [".$event->getType()."]");
        }

        $str = "";

        foreach ($this->agents as $agent) {
            $str .= json_encode($agent)."\n";
        }

        foreach ($this->calls as $call) {
            $str .= json_encode($call)."\n";
        }

        foreach ($this->agentcallconnections as $connection) {
            $str .= json_encode($connection)."\n";
        }

        if ($str) {
            $this->logger->debug("TO UI: ".$str);

            $event->wsconnection->send($str);
        }
    }

    /**
     * @param CallCenterEvent $event
     */
    public function websocketSetAgentAvail(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.avail') {
            throw new \InvalidArgumentException("This method expects a websocket.avail event. [".$event->getType()."]");
        }

        if (!isset($this->agents[$event->agentid])) {
            $this->agents[$event->agentid] = new \Callcenter\Model\Agent($event->agentid);
        }

        $this->ami->unpauseAgent($event->agentid);
    }

    /**
     * @param CallCenterEvent $event
     */
    public function websocketSetAgentPause(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.pause') {
            throw new \InvalidArgumentException("This method expects a websocket.pause event. [".$event->getType()."]");
        }

        if (!isset($this->agents[$event->agentid])) {
            $this->agents[$event->agentid] = new \Callcenter\Model\Agent($event->agentid);
        }

        $this->ami->pauseAgent($event->agentid);
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
     * @param CallCenterEvent $event
     */
    public function agentLoggedIn(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'agent.loggedin') {
            throw new \InvalidArgumentException("This method expects a agent.loggedin event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent($event->agentid),
            'LOGGEDIN'
        );
    }

    /**
     * @param CallCenterEvent $event
     */
    public function agentLoggedOut(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'agent.loggedout') {
            throw new \InvalidArgumentException("This method expects a agent.loggedout event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent($event->agentid),
            'LOGGEDOUT'
        );
    }

    /**
     * @param CallCenterEvent $event
     */
    public function agentPaused(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'agent.paused') {
            throw new \InvalidArgumentException("This method expects a agent.paused event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent($event->agentid),
            'PAUSED'
        );
    }

    /**
     * @param CallCenterEvent $event
     */
    public function agentAvail(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'agent.avail') {
            throw new \InvalidArgumentException("This method expects a agent.avail event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent($event->agentid),
            'AVAIL'
        );
    }

    /**
     * @param CallCenterEvent $event
     */
    public function callNew(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'caller.new') {
            throw new \InvalidArgumentException("This method expects a caller.new event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->callerid, $event->uid);

        $this->websocket->sendtoAll(json_encode($call));

        $this->logger->info("Call {$call} is in the IVR");
    }

    /**
     * @param CallCenterEvent $event
     */
    public function callHangup(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'caller.hangup') {
            throw new \InvalidArgumentException("This method expects a caller.hangup event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->callerid, $event->uid);

        $this->setCallStatus($call, 'HANGUP');

        unset($this->calls[$call->uid]);

        $agent = null;

        if (isset($this->agentcallconnections[$call->uid])) {
            $agent = $this->agentcallconnections[$call->uid]->agent;
            $this->ami->unpauseAgent($agent->getAgentId());
            unset($this->agentcallconnections[$call->uid]);
        }

        $str = json_encode($call);

        if ($agent) {
            $str .= "\n".json_encode($agent);
        }

        $this->websocket->sendtoAll($str);

        $this->logger->info("Call {$call} hung up");
    }

    /**
     * @param CallCenterEvent $event
     */
    public function callQueued(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'caller.queued') {
            throw new \InvalidArgumentException("This method expects a caller.queued event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->callerid, $event->uid);

        $call->setQueue($event->queue);
        $this->setCallStatus($call, 'QUEUED');

        $this->websocket->sendtoAll(json_encode($call));

        $this->logger->info("Call {$call} was queued in queue {$call->queue}");
    }

    /**
     * @param CallCenterEvent $event
     */
    public function callAndAgentConnected(CallCenterEvent $event) : void
    {
        if ($event->getType() != 'queue.connect') {
            throw new \InvalidArgumentException("This method expects a queue.connect event. [".$event->getType()."]");
        }

        if (!isset($this->agents[$event->agentid]) or !isset($this->calls[$event->calleruid])) {
            return;
        }

        $agent = $this->agents[$event->agentid];
        $call = $this->calls[$event->calleruid];

        if (!isset($this->agentcallconnections[$event->calleruid])) {
            $this->setCallStatus($call, 'INCALL');

            $agent->setQueue($call->getQueue());
            $this->setAgentStatus($agent, 'INCALL');

            $conn = new Connection($call, $agent);

            $this->websocket->sendtoAll(
                json_encode($agent)."\n".
                json_encode($call)."\n".
                json_encode($conn)
            );

            $this->agentcallconnections[$conn->id] = $conn;

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

        $this->websocket->sendtoAll(json_encode($agent));

        $this->logger->info("Agent {$agent}");
    }
}
