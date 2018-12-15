#!/usr/bin/php
<?php
    /**
     * Created by PhpStorm.
     * User: volkan
     * Date: 26.10.2015
     * Time: 21:15
     */
    ini_set('display_errors', 'On');
    error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
    PHP_OS == 'Linux' ? $dir = '/home/voip1' : $dir = "D:\\Projects\\PHP\\voip1";
    include "$dir/_class/listener.class.php";
    include "$dir/_class/database.class.php";
    $l = new listener();
    $l->db = new database();


    ########  configurations
    $l->dir = $dir;
    $l->astId = '1';
    $l->astName = 'voip1';
    $l->astIp = '192.168.10.96';
    $l->astPort = 5038;
    $l->astUserName = 'admin';
    $l->astPassword = null;

    $l->memcacheIp = '192.168.11.14';
    $l->memcachePort = 11211;

    $l->db->driver = 'pgsql';
    $l->db->ip = '192.168.11.14';
    $l->db->port = 5432;
    $l->db->username = 'postgres';
    $l->db->password = null;
    $l->db->name = 'jupiter';
    $l->db->defScheme = 'voip1';
    ########  end of configurations


    $l->createLog(null, "Jupiter Listener starting for {$l->astName}...");

    ######## connection process
    $l->db->connect() ? $l->createLog(null, "Connected DB: {$l->db->driver}:{$l->db->ip}:{$l->db->port}/{$l->db->name}.") : $l->terminate('dbConnectionError');
    $l->lastEventId = $l->getLastEventId();
    $l->lastEventId > 0 ? $l->createLog(null, "Last Event ID: {$l->lastEventId}") : $l->terminate('lastEventIdError');
    $l->lastCallId = $l->db->getId('pbx.calls');
    $l->lastCallId > 0 ? $l->createLog(null, "Last Call ID: {$l->lastCallId}") : $l->terminate('lastCallIdError');
    $memcache = $l->memcacheConnect() ? $l->createLog(null, "Connected Memcache: {$l->memcacheIp}:{$l->memcachePort}.") : $l->terminate('memcacheConnectionError');
    $astSocket = $l->astConnect();

    //socket_set_nonblock($astSocket);
    while (1) {
        $response = socket_read($astSocket, 327680);
        //$bytes_read = @socket_recv($astSocket, $response, 327680, MSG_OOB);
        if ($response != '') {
            $l->processEvents($response);
        } else {
            $l->createLog(null, "AMI Connection refused or dropped. Reconnectiong now...", 'f');
            sleep(1);
            $l->createLog(null, "Connecting AMI...", 'f');
            $astSocket = $l->astConnect();
        }
    }
    socket_close($astSocket);

    echo date("d.m.Y H:i:s") . "End.\n\n";
?>
