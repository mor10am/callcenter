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

$options = [
    'member_template' => getenv('AMI_MEMBER_TEMPLATE'),
];

$logger = new Monolog\Logger('callcenter');
$logger->pushHandler(new Monolog\Handler\StreamHandler('log/callcenter.log'));

$loop = React\EventLoop\Factory::create();

$websockethandler = new \Callcenter\WebsocketHandler($logger);

$logger->debug("Starting websocket at ".getenv('WSSERVERADDRESS').":".getenv('WSSERVERPORT'));

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

try {
  $logger->debug("Connecting to Asterisk at " . getenv('ASTERISKSERVER'));

  $ami = new \React\Stream\DuplexResourceStream(
      stream_socket_client(getenv('ASTERISKSERVER')),
      $loop
  );
} catch (\Exception $e) {
  die($e->getMessage());
}

$asteriskmanager = new Callcenter\AsteriskManager(
    $ami,
    $options
);

$asteriskmanager->setLogger($logger);

$asteriskmanager->login(
    getenv('AMI_USERNAME'),
    getenv('AMI_PASSWORD')
);

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
$websockethandler->on('websocket.avail', [$callcenter, 'websocketSetAgentAvail']);
$websockethandler->on('websocket.pause', [$callcenter, 'websocketSetAgentPause']);

$asteriskmanager->on('agent.loggedin', [$callcenter, 'agentLoggedIn']);
$asteriskmanager->on('agent.loggedout', [$callcenter, 'agentLoggedOut']);

$asteriskmanager->on('caller.new', [$callcenter, 'callerNew']);
$asteriskmanager->on('caller.hangup', [$callcenter, 'callerHangup']);
$asteriskmanager->on('caller.queued', [$callcenter, 'callerQueued']);

$asteriskmanager->on('queue.connect', [$callcenter, 'callerAndAgentConnected']);

$logger->info("Callcenter started.");

$app->run();
