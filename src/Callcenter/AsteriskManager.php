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
use PAMI\Message\Event\UserEventEvent;
use PAMI\Message\Event\QueueMemberPausedEvent;
use PAMI\Message\Event\QueueCallerJoinEvent;

final class AsteriskManager extends BaseAsteriskManager
{
    /**
     * @param EventMessage $event
     */
    public function handle(EventMessage $event) : void
    {
        switch ($event->getName()) {
            case "UserEvent":
                $this->handleUserEvent($event);
                break;
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

    /**
     * @param UserEventEvent $event
     */
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
                new CallcenterEvent(
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
            new CallcenterEvent(
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
        $agentstatus = strtolower(($event->getPaused())?"PAUSED":"AVAIL");

        $type = "agent.{$agentstatus}";

        $this->emit($type, [
            new CallcenterEvent(
                $type,
                [
                    'agentid' => $event->getMemberName(),
                    'member' => $event->getKey('interface'),
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
            new CallcenterEvent(
                'agent.loggedout',
                [
                    'agentid' => $event->getKey('agentid'),
                    'member' => $event->getKey('member'),
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
            new CallcenterEvent(
                'agent.loggedin',
                [
                    'agentid' => $event->getKey('agentid'),
                    'member' => $event->getKey('member'),
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
            new CallcenterEvent(
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
            new CallcenterEvent(
                'caller.new',
                [
                    'callerid' => $event->getKey('calleridnum'),
                    'uid' => $event->getKey('uniqueid')
                ]
            )
        ]);        
    }
}
