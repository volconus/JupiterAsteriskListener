#!/usr/bin/php
<?php
    /**
     * Created by PhpStorm.
     * User: volkan
     * Date: 26.11.2015
     * Time: 21:15
     */
    ini_set('display_errors', 'On');
    error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
    PHP_OS == 'Linux' ? $dir = '/home/voip1' : $dir = "D:\\Projects\\PHP\\voip1";
    include "$dir/_class/writer.class.php";
    include "$dir/_class/database.class.php";
    $w = new writer();
    $w->db = new database();


    ########  configurations
    $w->dir = $dir;

    $w->memcacheIp = '192.168.11.14';
    $w->memcachePort = 11211;

    $w->db->driver = 'pgsql';
    $w->db->ip = '192.168.11.14';
    $w->db->port = 5432;
    $w->db->username = 'postgres';
    $w->db->password = null;
    $w->db->name = 'jupiter';
    $w->db->defScheme = 'voip1';
    ########  end of configurations

    $w->createLog(null, "Jupiter Writer starting for {$w->memcacheIp}:{$w->memcachePort}");
    $memcache = $w->memcacheConnect() ? $w->createLog(null, "Connected Memcache: {$w->memcacheIp}:{$w->memcachePort}.") : $w->terminate('memcacheConnectionError');
    $w->db->connect() ? $w->createLog(null, "Connected DB: {$w->db->driver}:{$w->db->ip}:{$w->db->port}/{$w->db->name}.") : $w->terminate('dbConnectionError');
    $w->lastEventId = $w->getLastEventId();
    $w->lastEventId > 0 ? $w->createLog(null, "Last Event ID: {$w->lastEventId}") : $w->terminate('lastEventIdError');

    $w->stationLength = $w->getStationLength();
    $w->stationLength > 0 ? $w->createLog(null, "Station Extension Length: {$w->stationLength} char.") : $w->terminate('stationExtensionLengthError');

    $w->domainPrefixLength = $w->getDomainPrefixLength();
    $w->domainPrefixLength > 0 ? $w->createLog(null, "Domain Prefix Length: {$w->domainPrefixLength} char.") : $w->terminate('domainPrefixLengthError');

    $w->getStates() == true ? $w->createLog(null, "Agent States loaded.") : $w->terminate('agentStatesError');

    while (1) {
        //$w->createLog(null, "{$w->lastEventId} checking...");
        $w->checkLastEventId();
        usleep(100000);
    }

?>