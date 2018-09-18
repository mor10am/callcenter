<?php declare(strict_types=1);

require_once __DIR__."/vendor/autoload.php";

use Symfony\Component\Dotenv\Dotenv;

try {
  $dotenv = new Dotenv();
  $dotenv->load(__DIR__.'/.env');
} catch (\Symfony\Component\Dotenv\Exception\PathException $e) {
  die(".env file is missing!\n");
}

$env = getenv('ENV');

if ($env == 'production') {
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }
}

$logger = new Monolog\Logger('callcenter');
$logger->pushHandler(new Monolog\Handler\StreamHandler('log/callcenter.log'));

$loop = React\EventLoop\Factory::create();

$websockethandler = new \Callcenter\WebsocketHandler($logger);

$app = new Ratchet\App(
  getenv('WSSERVERADDRESS'),
  getenv('WSSERVERPORT'),
  '0.0.0.0',
  $loop
);

$app->route(
    '/callcenter',
    $websockethandler,
    ['*']
);

$logger->debug("Started websocket at ".getenv('WSSERVERADDRESS').":".getenv('WSSERVERPORT'));

try {
    $ami = new \React\Stream\DuplexResourceStream(
        stream_socket_client(getenv('ASTERISKSERVER')),
        $loop
    );
} catch (\Exception $e) {
    die($e->getMessage());
}

$logger->debug("Connected to Asterisk at " . getenv('ASTERISKSERVER'));

$asteriskmanager = new Callcenter\AsteriskManager($ami);

$asteriskmanager->setLogger($logger);

$reportwriter = new \Callcenter\Report\File(__DIR__."/report.csv");

$redis = new \Redis();
$redis->connect(
    getenv('REDIS_SERVER')
);

$callcenter = new Callcenter\Callcenter(
    $websockethandler,
    $asteriskmanager,
    $reportwriter,
    $redis,
    $logger
);

$websockethandler->on('websocket.hello', [$callcenter, 'websocketHello']);
$websockethandler->on('websocket.avail', [$callcenter, 'websocketSetAgentAvail']);
$websockethandler->on('websocket.pause', [$callcenter, 'websocketSetAgentPause']);

$asteriskmanager->on('agent.loggedin', [$callcenter, 'agentLoggedIn']);
$asteriskmanager->on('agent.loggedout', [$callcenter, 'agentLoggedOut']);
$asteriskmanager->on('agent.paused', [$callcenter, 'agentPaused']);
$asteriskmanager->on('agent.avail', [$callcenter, 'agentAvail']);

$asteriskmanager->on('caller.new', [$callcenter, 'callNew']);
$asteriskmanager->on('caller.hangup', [$callcenter, 'callHangup']);
$asteriskmanager->on('caller.queued', [$callcenter, 'callQueued']);

$asteriskmanager->on('queue.connect', [$callcenter, 'callAndAgentConnected']);

$asteriskmanager->login(
    getenv('AMI_USERNAME'),
    getenv('AMI_PASSWORD')
);

$logger->info("Callcenter server started [{$env}].");

$app->run();
