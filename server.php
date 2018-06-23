<?php
declare(strict_types=1);

require_once __DIR__."/vendor/autoload.php";

$env = 'production';

if ($env == 'production') {
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }
}

$options = [
    'username' => 'admin',
    'password' => 'password',

];

$logger = new Monolog\Logger('callcenter');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://output'));

$loop = React\EventLoop\Factory::create();

$websockethandler = new \Callcenter\WebsocketHandler($logger);

$app = new Ratchet\App('127.0.0.1', 8080, '0.0.0.0', $loop);
$app->route(
    '/callcenter',
    $websockethandler,
    array('*')
);

$ami = new \React\Stream\DuplexResourceStream(
    stream_socket_client('tcp://127.0.0.1:5038'),
    $loop
);

$asteriskmanager = new Callcenter\AsteriskManager(
    $ami,
    $options
);

$asteriskmanager->setLogger($logger);

$settings = [
    'report' => __DIR__."/report.csv",
];

$callcenter = new Callcenter\Callcenter(
    $websockethandler,
    $asteriskmanager,
    $logger,
    $settings
);


$websockethandler->on('websocket.hello', [$callcenter, 'websocketHello']);
$websockethandler->on('websocket.toggle', [$callcenter, 'websocketToggleAvail']);
$websockethandler->on('websocket.avail', [$callcenter, 'websocketSetAgentAvail']);

$asteriskmanager->on('agent.loggedin', [$callcenter, 'agentLoggedIn']);
$asteriskmanager->on('agent.loggedout', [$callcenter, 'agentLoggedOut']);

$asteriskmanager->on('caller.new', [$callcenter, 'callerNew']);
$asteriskmanager->on('caller.hangup', [$callcenter, 'callerHangup']);
$asteriskmanager->on('caller.queued', [$callcenter, 'callerQueued']);

$asteriskmanager->on('queue.connect', [$callcenter, 'callerAndAgentConnected']);

$app->run();
