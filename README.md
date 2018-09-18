# Simple Asterisk callcenter with PHP and Vue.js

## Server process and frontend

```
$> php server.php
```

It will listen to Asterisk AMI on ```tcp://callcenter.local:5038```
and create a Websocket server on ```tcp://callcenter.local:8080/callcenter```

The webpage dashboard is served by builtin Asterisk HTTP server
at ```http://callcenter.local:8088/static/index.html```

```callcenter.local``` is added to ```/etc/hosts```

The settings for the server is defined in ```.env``` as:

```bash
WSSERVERADDRESS=callcenter.local
WSSERVERPORT=8080
ASTERISKSERVER=tcp://localhost:5038
AMI_USERNAME=admin
AMI_PASSWORD=password
REDIS_SERVER=127.0.0.1
ENV=development
```

The webpage will connect to the websocket server and on connection
get the current state of the the backend server process.

## Changes from default configuration files in Asterisk:

* sip.conf

```
tcpenable=yes
transport=tcp,udp
autocreatepeer=yes

[phones]
type=friend
host=dynamic
port=5060
sipreinvite=no
transport=tcp,udp
secret=password
callevents=yes
directmedia=no
insecure=invite,port
```

* manager.d/admin.conf

```
[admin]
secret = password
read = call,agent,user,security
write = call,command,agent,user,originate
```

* http.conf

```
enabled=yes
bindaddr=0.0.0.0
bindport=8088
enablestatic=yes
redirect= / /static/index.html
```

* queues.conf

```
[q-callcenter]
strategy=random
joinempty=yes
leavewhenempty=no
ringinuse=no

setinterfacevar=yes
setqueueentryvar=yes
setqueuevar=yes

timeout = 60
retry = 10
wrapuptime=15

autopause=yes

reportholdtime=yes
announce-position=yes
announce-holdtime=yes
queue-callswaiting = queue-callswaiting
queue-thankyou = queue-thankyou
queue-youarenext = queue-youarenext
periodic-announce = queue-periodic-announce
periodic-announce-frequency=30
announce-frequency=45
```

* extensions.ael

Empty

* extension.lua

Empty
