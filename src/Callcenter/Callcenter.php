<?php
declare(strict_types=1);

namespace Callcenter;

use Callcenter\Model\Agent;
use Callcenter\Model\Call;
use Callcenter\Model\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Callcenter
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
     * @var array
     */
    private $agents = [];

    /**
     * @var array
     */
    private $calls = [];

    /**
     * @var \Callcenter\Model\Statistics
     */
    private $stats;

    /**
     * Connections of agent and call
     * @var array
     */
    private $agentcallconnections = [];

    /**
     * @var ReportInterface
     */
    private $reportwriter;

    /**
     * Callcenter constructor.
     * @param WebsocketHandler $websocket
     * @param AsteriskManager $ami
     * @param ReportInterface|null $reportwriter
     * @param \Redis $redis
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        WebsocketHandler $websocket,
        AsteriskManager $ami,
        ReportInterface $reportwriter = null,
        \Redis $redis,
        LoggerInterface $logger = null
    )
    {
        $this->redis = $redis;
        $this->websocket = $websocket;
        $this->ami = $ami;

        if ($logger == null) {
            $logger = new NullLogger();
        }

        $this->logger = $logger;
        $this->reportwriter = $reportwriter;
        $this->stats = new \Callcenter\Model\Statistics();

        $this->loadFromRedis();
    }

    /**
     * @return string
     */
    private function calcAndSerializeStats()
    {
        $this->stats->agents_online = count(
            array_filter(
                $this->agents,
                function($agent) {
                    /**
                     * @var \Callcenter\Model\Agent $agent
                     */
                    return $agent->status != 'LOGGEDOUT';
                }
            )
        );

        $this->stats->calls_online = count($this->calls);
        $this->stats->connections_online = count($this->agentcallconnections);

        $this->saveToRedis();

        return json_encode($this->stats);
    }

    /**
     *
     */
    private function loadFromRedis()
    {
        $storage = (array) unserialize((string) $this->redis->get('callcenter.storage'));

        if (isset($storage['stats'])) {
            $this->stats->init((array) $storage['stats']);
        }

        if (isset($storage['calls'])) {
            $this->calls = (array) $storage['calls'];
        }

        if (isset($storage['agents'])) {
            $this->agents = (array) $storage['agents'];
        }

        if (isset($storage['agentcallconnections'])) {
            $this->agentcallconnections = (array) $storage['agentcallconnections'];
        }
    }

    /**
     *
     */
    private function saveToRedis()
    {
        $storage = [
            'stats' => (array) $this->stats,
            'calls' => (array) $this->calls,
            'agents' => (array) $this->agents,
            'agentcallconnections' => (array) $this->agentcallconnections,
        ];

        $this->redis->set('callcenter.storage', serialize($storage));
    }

    /**
     * @param CallcenterEvent $event
     */
    public function websocketHello(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.hello') {
            throw new \InvalidArgumentException("This method expects a websocket.hello event. [".$event->getType()."]");
        }

        $str = $this->calcAndSerializeStats()."\n";

        foreach ($this->agents as $agent) {
            $str .= json_encode($agent)."\n";
        }

        foreach ($this->calls as $call) {
            $str .= json_encode($call)."\n";
        }

        foreach ($this->agentcallconnections as $connection) {
            $str .= json_encode($connection)."\n";
        }

        $event->wsconnection->send($str);

        $this->logger->debug("TO UI: ".$str);
    }

    /**
     * @param CallcenterEvent $event
     */
    public function websocketSetAgentAvail(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.avail') {
            throw new \InvalidArgumentException("This method expects a websocket.avail event. [".$event->getType()."]");
        }

        $agent = $this->getOrCreateAgent(
            $event->get('agentid'),
            $event->get('member', null)
        );

        $this->ami->unpauseAgent($agent->getMember());
    }

    /**
     * @param CallcenterEvent $event
     */
    public function websocketSetAgentPause(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'websocket.pause') {
            throw new \InvalidArgumentException("This method expects a websocket.pause event. [".$event->getType()."]");
        }

        $agent = $this->getOrCreateAgent(
            $event->get('agentid'),
            $event->get('member', null)
        );

        $this->ami->pauseAgent($agent->getMember());
    }

    /**
     * @param string $agentid
     * @param string $member
     * @return Agent
     */
    private function getOrCreateAgent(string $agentid, string $member = null) : Agent
    {
        if (!isset($this->agents[$agentid])) {
            $this->agents[$agentid] = new Agent($agentid, $member);
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
     * @param CallcenterEvent $event
     */
    public function agentLoggedIn(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'agent.loggedin') {
            throw new \InvalidArgumentException("This method expects a agent.loggedin event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent(
                $event->get('agentid'),
                $event->get('member', null)
            ),
            'LOGGEDIN'
        );
    }

    /**
     * @param CallcenterEvent $event
     */
    public function agentLoggedOut(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'agent.loggedout') {
            throw new \InvalidArgumentException("This method expects a agent.loggedout event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent(
                $event->get('agentid'),
                $event->get('member', null)
            ),
            'LOGGEDOUT'
        );
    }

    /**
     * @param CallcenterEvent $event
     */
    public function agentPaused(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'agent.paused') {
            throw new \InvalidArgumentException("This method expects a agent.paused event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent(
                $event->get('agentid'),
                $event->get('member', null)
            ),
            'PAUSED'
        );
    }

    /**
     * @param CallcenterEvent $event
     */
    public function agentAvail(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'agent.avail') {
            throw new \InvalidArgumentException("This method expects a agent.avail event. [".$event->getType()."]");
        }

        $this->setAgentStatus(
            $this->getOrCreateAgent(
                $event->get('agentid'),
                $event->get('member', null)
            ),
            'AVAIL'
        );
    }

    /**
     * @param CallcenterEvent $event
     */
    public function callNew(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'caller.new') {
            throw new \InvalidArgumentException("This method expects a caller.new event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->get('callerid'), $event->get('uid'));

        $this->websocket->sendtoAll(
            json_encode($call)."\n".
            $this->calcAndSerializeStats()."\n"
        );

        $this->logger->info("Call {$call} is in the IVR");
    }

    /**
     * @param CallcenterEvent $event
     */
    public function callHangup(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'caller.hangup') {
            throw new \InvalidArgumentException("This method expects a caller.hangup event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->get('callerid'), $event->get('uid'));

        $call_duration = $call->getDuration();

        $this->setCallStatus($call, 'HANGUP');

        unset($this->calls[$call->uid]);

        $agent = null;

        if (isset($this->agentcallconnections[$call->uid])) {
            $agent = $this->agentcallconnections[$call->uid]->agent;
            $this->ami->unpauseAgent($agent->getMember());
            unset($this->agentcallconnections[$call->uid]);

            $this->stats->addHandledCall($call_duration);
        } else {
            $this->stats->addAbandonedCall($call_duration);
        }

        $str = json_encode($call);

        if ($agent) {
            $str .= "\n".json_encode($agent);
        }

        $str .= "\n".$this->calcAndSerializeStats();

        $this->websocket->sendtoAll($str);

        $this->logger->info("Call {$call} hung up");

        $this->setCallStatus($call, 'NA');
    }

    /**
     * @param CallcenterEvent $event
     */
    public function callQueued(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'caller.queued') {
            throw new \InvalidArgumentException("This method expects a caller.queued event. [".$event->getType()."]");
        }

        $call = $this->getOrCreateCall($event->get('callerid'), $event->get('uid'));

        $call->setQueue($event->get('queue'));
        $this->setCallStatus($call, 'QUEUED');

        $this->stats->addCallReceived();

        $this->websocket->sendtoAll(
            json_encode($call)."\n".
            $this->calcAndSerializeStats()."\n"
        );

        $this->logger->info("Call {$call} was queued in queue {$call->queue}");
    }

    /**
     * @param CallcenterEvent $event
     */
    public function callAndAgentConnected(CallcenterEvent $event) : void
    {
        if ($event->getType() != 'queue.connect') {
            throw new \InvalidArgumentException("This method expects a queue.connect event. [".$event->getType()."]");
        }

        $agentid = $event->get('agentid');
        $calleruid = $event->get('calleruid');

        if (!isset($this->agents[$agentid]) or !isset($this->calls[$calleruid])) {
            return;
        }

        /**
         * @var \Callcenter\Model\Agent
         */
        $agent = $this->agents[$agentid];

        /**
         * @var \Callcenter\Model\Call
         */
        $call = $this->calls[$calleruid];

        if (!isset($this->agentcallconnections[$calleruid])) {
            $call_duration = $call->getDuration();

            $this->setCallStatus($call, 'INCALL');

            $agent->setQueue($call->getQueue());
            $this->setAgentStatus($agent, 'INCALL');

            $conn = new Connection($call, $agent);

            $this->agentcallconnections[$conn->id] = $conn;

            $this->stats->addAnsweredCall($call_duration);

            $this->websocket->sendtoAll(
                json_encode($agent)."\n".
                json_encode($call)."\n".
                json_encode($conn)."\n".
                $this->calcAndSerializeStats()."\n"
            );

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
        /**
         * @var Report
         */
        $report = $call->getReport();

        if ($call->setStatus($status)) {
            if ($this->reportwriter) {
                $this->reportwriter->write($report);
            }
        }

        $this->websocket->sendtoAll(
            $this->calcAndSerializeStats()."\n"
        );

    }

    /**
     * Set status on Agent and notify UI
     *
     * @param Agent $agent
     * @param string $status
     */
    private function setAgentStatus(Agent $agent, string $status) : void
    {
        /**
         * @var Report
         */
        $report = $agent->getReport();

        if ($agent->setStatus($status)) {
            if ($this->reportwriter) {
                $this->reportwriter->write($report);
            }
        }

        $this->websocket->sendtoAll(
            json_encode($agent)."\n".
            $this->calcAndSerializeStats()."\n"
        );

        $this->logger->info("Agent {$agent}");
    }
}
