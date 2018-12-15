<?php

    class listener
    {
        var $data, $channels = array();
        var $startCount = 0;
        var $ringingCount = 0;
        var $upCount = 0;
        var $hangupCount = 0;
        public $dir;
        public $memcache, $memcacheIp, $memcachePort;
        public $astId, $astName, $astIp, $astPort, $astUserName, $astPassword;
        public $db, $lastEventId, $lastCallId;

        public function __construct()
        {

        }

        # bu metot servisi terminate eder. boot esnasinda eventid, callid gibi verilere ulasilamazsa terminate olur.
        public function terminate($reason)
        {
            // TODO: buradan bir yere log atanmali.
            $this->createLog(null, 'LISTENER TERMINATING... Reason: ' . $reason, 'f');
            die();
        }

        # son event ID'yi alir. Bunun amaci, listener ve writer arasindaki sync. listener bu event id den devam eder memcache e event atmaya.
        public function getLastEventId()
        {
            $a = $this->db->selectOne("\"{$this->db->defScheme}\".settings", array('value'), "name = 'lastEventId'");
            return $a->value;
        }

        # bu metot memcache e verileri set eder. bu sayede writer ile listener konusmus olurlar.
        public function setMemCache($key, $value = array())
        {
            $value['serverId'] = $this->astId; // server id'sini markl�yoruz. insert into calls i�in laz�m.
            if ($this->memcache->getserverstatus($this->memcacheIp, $this->memcachePort)) {
                if ($this->memcache->set($key, $value, 0, 604800)) {
                    $this->lastEventId++;
                    return true;
                }
            }
        }

        public function var_dump_ret($mixed = null)
        {
            ob_start();
            print_r($mixed);
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        }

        # ana metot. her bir event pbxden patladiginda bu event onu bir array e cevirerek duzenler. ve ilgili metotlari tetikler.
        public function processEvents($response)
        {
            $lines = explode("\r\n", $response);
            //echo $response;
            foreach ($lines as $key => $line) {
                $satirKodu = ord($line);
                // echo "Satir ($satirKodu) : $line\r\n";
                @list($key, $value) = explode(":", $line);
                if ($value != '')
                    $recv[$key] = trim($value);


                if ($satirKodu == 0) {
                    if ($recv['Event'] != 'VarSet' and $recv['Event'] != 'RTCPReceived' and $recv['Event'] != 'RTCPSent' and $recv['Event'] != 'AGIExec') {
                        $this->astLogonEvent($recv);
                        $this->deviceStatus($recv);
                        $this->peerStatus($recv);
                        $this->dialBegin($recv);
                        $this->detectSkill($recv);
                        $this->conferenceJoin($recv);
                        $this->conferenceLeave($recv);
                        $this->conferenceActiveted($recv);
                        $this->conferenceSuspended($recv);
                        $this->callRinging($recv);
                        $this->callUp($recv);
                        $this->callTransfer($recv);
                        $this->startHold($recv);
                        $this->stopHold($recv);
                        $this->callSoundFile($recv);
                        $this->callHangup($recv);
                        $this->getKeys($recv);
                        //print_r($recv);
                    }
                    unset($recv);
                }
            }
        }

        # bu metot memcache e baglanti acar. memcache e baglanti boot esnasinda bir kere acilir ve bir daha kapanmaz.
        public function memcacheConnect()
        {
            $this->memcache = new Memcache;
            $this->createLog(null, 'Connecting Memcache...');
            if ($this->memcache->connect($this->memcacheIp, $this->memcachePort))
                return true;
            else
                return false;
        }

        # bu metot pbxe  baglanti acar. boot esnasinda bir kere acilir ve kapanmaz. kapanirsa reconnect edilir saniyede 1 kez denenir.
        public function astConnect()
        {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                $this->createLog(null, "Socket_create() error!!! " . socket_strerror(socket_last_error()));
            }

            $this->createLog(null, "Connectiong Mainframe {$this->astIp}:{$this->astPort}...");
            $result = socket_connect($socket, $this->astIp, $this->astPort);
            if ($result === false) {
                $this->createLog(null, "No connection. Reason: ($result) " . socket_strerror(socket_last_error($socket)), 'f');
            } else {
                $in = "Action: Login" . PHP_EOL;
                $in .= "UserName: {$this->astUserName}" . PHP_EOL;
                $in .= "Secret: {$this->astPassword}" . PHP_EOL . PHP_EOL;

                $this->createLog(null, "Received hand shake from mainframe. Sending Login request...");
                socket_write($socket, $in, strlen($in));
            }
            return $socket;
        }

        # bu metot database e baglanti acar. boot esnasinda bir kere acilir ve kapanmaz.
        public function dbConnect()
        {
            $this->createLog(null, 'Connecting DB...');
            try {
                $this->pdo = new PDO("{$this->dbDriver}:host={$this->dbIp} port={$this->dbPort}dbname={$this->dbName} user={$this->dbUserName} password={$this->dbPassword}");
                $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);     ## bu sql injection i�in �nemli bir �eymi�. gavurlar �yle yazm��. false kalmal�.
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, "2");
                return true;
            } catch (PDOException $e) {
                echo $e->getMessage();
            }
        }

        # bu metot tuslanan (ivr gibi) tuslari algilar ve buna uygun metotlari tetikler.
        public function getKeys($recv)
        {
            if ($recv['Event'] == 'DTMF' and $recv['Direction'] == 'Received' and $recv['Begin'] == 'Yes') {
                $this->data[$recv['Uniqueid']]['pressedKey'] = $recv['Digit'];
                $this->createLog($recv, "Key Pressed -> $recv[Digit]", 'f');

            }
        }

        # bu metot asterisk ile basariyla bir baglanti saglandiginda gelen event i read eder. boot eventi da diyebiliriz.
        public function astLogonEvent($recv)
        {
            if ($recv['Event'] == 'FullyBooted') {
                $this->createLog(null, 'Mainframe Connected. ');
                $this->createLog(null, 'Waiting for events... ' . date("d.m.Y H:i:s"), 'f');

            } else if ($recv['Response'] == 'Error' and $recv['Message'] == 'Authentication failed') {
                $this->createLog(null, "Error: AMI username or password incorrect.", 'f');
                die();
            }
        }

        # anlik tarih metotu
        public function getDate()
        {
            return date('Y-m-d H:i:s');
        }

        # bu metot bir SIP clientin anlik statusune bakiyor. henuz bununla tam olarak ne yapacagima karar vermedim.
        public function deviceStatus($recv)
        {
            if ($recv['Event'] == 'DeviceStateChange') {
                $stationExt = explode('SIP/', $recv['Device']);
                if (is_numeric($stationExt[1])) {
                    $this->createLog(null, "New SIP Station Status: $stationExt[1] = $recv[State]", 'f');
                }
            }
        }

        # bu metot bir SIP client in anlik reg durumuna bakiyor. timeout sebebiyle logout isleri bu event sayesinde yapiliyor.
        public function peerStatus($recv)
        {
            if ($recv['Event'] == 'PeerStatus') {
                $stationExt = explode('SIP/', $recv['Peer']);
                if (is_numeric($stationExt[1])) {
                    $this->createLog(null, "SIP Station: $stationExt[1] = $recv[PeerStatus]", 'f');
                    $this->setMemCache($this->lastEventId, array(
                        'eventType' => 'peerStatus',
                        'stationExt' => $stationExt[1],
                        'peerStatus' => $recv[PeerStatus]
                    ));
                }
            }
        }

        # konferansa giris eventi
        public function conferenceJoin($recv)
        {
            if ($recv['Event'] == 'ConfbridgeJoin') {
                preg_match("/^SIP\/(.*)-.(.*)$/", $recv['Channel'], $stationExt);
                if (is_numeric($stationExt[1])) {
                    $this->createLog($recv, "Conference Join: $stationExt[1] = $recv[Conference]", 'f');
                }
            }
        }

        # konferanstan cikis eventi
        public function conferenceLeave($recv)
        {
            if ($recv['Event'] == 'ConfbridgeLeave') {
                preg_match("/^SIP\/(.*)-.(.*)$/", $recv['Channel'], $stationExt);
                if (is_numeric($stationExt[1])) {
                    $this->createLog($recv, "Conference Leave: $stationExt[1] = $recv[Conference]", 'f');
                }
            }
        }

        # konferenas salonu aktif edildigindeki event
        public function conferenceActiveted($recv)
        {
            if ($recv['Event'] == 'ConfbridgeStart' and isset($recv['Conference'])) {
                $this->createLog($recv, "Conference Room Activeted : $recv[Conference]");
            }
        }

        # konferans salonu bosaldigindaki event
        public function conferenceSuspended($recv)
        {
            if ($recv['Event'] == 'ConfbridgeEnd' and isset($recv['Conference'])) {
                $this->createLog($recv, "Conference Room Suspended : $recv[Conference]");
            }
        }

        # cagri (dial daha dogrusu) baslama eventi
        public function dialBegin($recv)
        {
            if ($recv['Event'] == 'DialBegin' and $recv['DestUniqueid'] != $recv['DestLinkedid']) {
                $this->channels["$recv[DestUniqueid]"] = $recv['DestLinkedid'];
                if (is_numeric($this->data[$recv['Uniqueid']]['from'])) {
                    print_r($recv);
                    $this->createLog($recv['Uniqueid'], "Call Starting oldu overwrite edecekti, engellendi. Bu bir transfer baslangici olabilir.", 'f');
                    $this->data[$recv['DestUniqueid']]['id'] = $this->lastCallId;
                    $this->data[$recv['DestUniqueid']]['dateDial'] = $this->getDate();
                    $this->data[$recv['DestUniqueid']]['from'] = $recv['CallerIDNum'];
                    $this->data[$recv['DestUniqueid']]['fromChannel'] = $recv['Channel']; // buradaki data SIP/77771002-0000000e ornegindeki gibi. writer bunu parcalayip domain prefixini algiliyor.
                    $this->data[$recv['DestUniqueid']]['to'] = $recv['DestCallerIDNum'];
                    $this->data[$recv['DestUniqueid']]['toChannel'] = $recv['DestChannel']; // buradaki data SIP/77771002-0000000e ornegindeki gibi. writer bunu parcalayip domain prefixini algiliyor.
                    $this->data[$recv['DestUniqueid']]['eventType'] = 'CallStarting';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['DestUniqueid']]);
                    $this->auditEvent($recv['DestUniqueid'], "Call Starting -> $recv[CallerIDNum] -> $recv[DestCallerIDNum]");
                    $this->lastCallId++; // onemli.
                } else {
                    print_r($recv);
                    $this->data[$recv['Uniqueid']]['id'] = $this->lastCallId;
                    $this->data[$recv['Uniqueid']]['dateDial'] = $this->getDate();
                    $this->data[$recv['Uniqueid']]['from'] = $recv['CallerIDNum'];
                    $this->data[$recv['Uniqueid']]['fromChannel'] = $recv['Channel']; // buradaki data SIP/77771002-0000000e ornegindeki gibi. writer bunu parcalayip domain prefixini algiliyor.
                    $this->data[$recv['Uniqueid']]['to'] = $recv['DestCallerIDNum'];
                    $this->data[$recv['Uniqueid']]['toChannel'] = $recv['DestChannel']; // buradaki data SIP/77771002-0000000e ornegindeki gibi. writer bunu parcalayip domain prefixini algiliyor.
                    $this->data[$recv['Uniqueid']]['eventType'] = 'CallStarting';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['Uniqueid']]);
                    $this->auditEvent($recv['Uniqueid'], "Call Starting -> $recv[CallerIDNum] -> $recv[DestCallerIDNum]"." Call ID: #".$this->data[$recv['Uniqueid']]['id']);
                    $this->startCount++;
                    $this->lastCallId++; // onemli.
                }
            }
        }

        # skill algilama metotu
        public function detectSkill($recv)
        {
            if ($recv['Event'] == 'Newexten' and $recv['Application'] == 'Set' and $recv['Value']) {
                print_r($recv);
                $this->data[$this->channels["$recv[Uniqueid]"]]['dateJoinQueue'] = $this->getDate();
                $this->data[$this->channels["$recv[Uniqueid]"]]['skillName'] = $recv['Value'];
                $this->data[$this->channels["$recv[Uniqueid]"]]['eventType'] = 'CallJoinQueue';
                $this->setMemCache($this->lastEventId, $this->data[$this->channels["$recv[Uniqueid]"]]);
                $this->auditEvent($this->channels["$recv[Uniqueid]"], "Call Join To Skill -> " . $this->data[$this->channels["$recv[Uniqueid]"]]['from'] . ' -> ' . $this->data[$this->channels["$recv[Uniqueid]"]]['to'] . " [$recv[Value]]");
            }
        }

        # cagri ringing metotu
        public function callRinging($recv)
        {
            if ($recv['Event'] == 'Newstate' and $recv['ChannelStateDesc'] == 'Ringing') {
                if (isset($this->data[$this->channels["$recv[Uniqueid]"]]['dateRinging'])) { // transfer
                    $this->createLog($recv['Uniqueid'], "Call Ringing oldu overwrite edecekti, engellendi. Bu bir transfer ringingi olabilir.", 'f');
                    $this->data[$recv['Uniqueid']]['dateRinging'] = $this->getDate();
                    $this->data[$recv['Uniqueid']]['eventType'] = 'CallRinging';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['Uniqueid']]);
                    $this->auditEvent($recv['Uniqueid'], "Call Ringing -> " . $this->data[$recv['Uniqueid']]['from'] . ' -> ' . $this->data[$recv['Uniqueid']]['to']);
                    //print_r($recv);
                } else { // rutin
                    $this->data[$this->channels["$recv[Uniqueid]"]]['dateRinging'] = $this->getDate();
                    $this->data[$this->channels["$recv[Uniqueid]"]]['eventType'] = 'CallRinging';
                    $this->setMemCache($this->lastEventId, $this->data[$this->channels["$recv[Uniqueid]"]]);
                    $this->auditEvent($this->channels["$recv[Uniqueid]"], "Call Ringing -> " . $this->data[$this->channels["$recv[Uniqueid]"]]['from'] . ' -> ' . $this->data[$this->channels["$recv[Uniqueid]"]]['to']);
                    $this->ringingCount++;
                    //print_r($recv);
                }
            
            }
        }

        #cagri up metotu (diger deyisle incall agent acisindan)
        public function callUp($recv)
        {
            if ($recv['Event'] == 'Newstate' and $recv['ChannelStateDesc'] == 'Up' and !empty($this->data[$this->channels["$recv[Uniqueid]"]]['from'])) {
                if ($this->data[$this->channels["$recv[Uniqueid]"]]['dateRinging'] == 0) { // ringing olmadan nas�l oluyor bu i�ler hac� ?
                    $this->createLog($this->channels["$recv[Uniqueid]"], "�ift kanal�n UP olmas�yla ilgili bir durum olu�tu.", 'f');
                    //exec("asterisk -rx \"channel request hangup $recv[Channel]\"");
                } else if (isset($this->data[$this->channels["$recv[Uniqueid]"]]['dateUp'])) {
                    $this->createLog($recv['Uniqueid'], "Call Up oldu overwrite edecekti, engellendi. Bu bir transfer UP`i olabilir.", 'f');
                    $this->data[$recv['Uniqueid']]['dateUp'] = $this->getDate();
                    $this->data[$recv['Uniqueid']]['dateUp']['eventType'] = 'CallUp';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['Uniqueid']]);
                    $this->auditEvent($recv['Uniqueid'], "Call Up -> " . $this->data[$recv['Uniqueid']]['from'] . ' -> ' . $this->data[$recv['Uniqueid']]['to']);
                    //print_r($recv);
                } else {
                    $this->data[$this->channels["$recv[Uniqueid]"]]['dateUp'] = $this->getDate();
                    $this->data[$this->channels["$recv[Uniqueid]"]]['eventType'] = 'CallUp';
                    $this->setMemCache($this->lastEventId, $this->data[$this->channels["$recv[Uniqueid]"]]);
                    $this->upCount++;
                    $this->auditEvent($this->channels["$recv[Uniqueid]"], "Call Up -> " . $this->data[$this->channels["$recv[Uniqueid]"]]['from'] . ' -> ' . $this->data[$this->channels["$recv[Uniqueid]"]]['to']);
                }
            }
        }

        # cagri start hold metotu. multiple olarak array sekilnde depolanir.
        public function startHold($recv)
        {
            if ($recv['Event'] == 'MusicOnHoldStart' and $recv['ChannelStateDesc'] == 'Up') {
        
                if (isset($this->data[$recv['Linkedid']]['from'])) {
                    $this->data[$recv['Linkedid']]['holds'][$recv['ConnectedLineNum']]['start'][] = $this->getDate();
                    $this->data[$recv['Linkedid']]['eventType'] = 'CallHoldStart';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['Linkedid']]);
                    $this->auditEvent($recv['Linkedid'], "Hold Start -> " . $this->data[$recv['Linkedid']]['from'] . ' -> ' . $this->data[$recv['Linkedid']]['to'] . " [Start By: $recv[ConnectedLineNum]]");
                } else {
                    $this->auditEvent($recv['Linkedid'], "Hold Start -> Bilinmeyen.", 'f');
                }
            }
        }

        # cagri stop hold metotu. multiple olarak array sekilnde depolanir.
        public function stopHold($recv)
        {
            if ($recv['Event'] == 'MusicOnHoldStop' and $recv['ChannelStateDesc'] == 'Up') {
          
                if (isset($this->data[$recv['Linkedid']]['from'])) {
                    $this->data[$recv['Linkedid']]['holds'][$recv['ConnectedLineNum']]['stop'][] = $this->getDate();
                    $this->data[$recv['Linkedid']]['eventType'] = 'CallHoldStop';
                    $this->setMemCache($this->lastEventId, $this->data[$recv['Linkedid']]);
                    $this->auditEvent($recv['Linkedid'], "Hold Stop -> " . $this->data[$recv['Linkedid']]['from'] . ' -> ' . $this->data[$recv['Linkedid']]['to'] . " [Stop By: $recv[ConnectedLineNum]]");
                } else {
                    $this->auditEvent($recv['Linkedid'], "Hold Stop -> Bilinmeyen.", 'f');
                }
            }
        }

        # cagri transfer eventi. cagriyi transfer olarak bu marklar.
        public function callTransfer($recv)
        {
            if ($recv['Event'] == 'BlindTransfer') {
                print_r($recv);

                if (isset($this->data[$this->channels["$recv[TransfereeUniqueid]"]])) {
                    $this->data[$recv['TransfereeUniqueid']]['dateTransfer'] = $this->getDate();
                    $this->data[$recv['TransfereeUniqueid']]['transferTo'] = $recv['Extension'];
                    $this->data[$recv['TransfereeUniqueid']]['transferBy'] = "test";
                    $this->auditEvent($recv['TransfereeUniqueid'], "Transfer Label -> " . $this->data[$recv['TransfereeUniqueid']]['from'] . " -> " . $this->data[$recv['TransfereeUniqueid']]['to'] . " [Transfered By: " . $this->data[$recv['TransfereeUniqueid']]['transferedBy'] . "]");
                } else {
                    $this->data[$recv['TransfereeLinkedid']]['dateTransfer'] = $this->getDate();
                    $this->data[$recv['TransfereeLinkedid']]['transferTo'] = $recv['Extension'];
                    $this->data[$recv['TransfereeLinkedid']]['transferBy'] = "test";
                    $this->auditEvent($recv['TransfereeLinkedid'], "Transfer Label -> " . $this->data[$recv['TransfereeLinkedid']]['from'] . " -> " . $this->data[$recv['TransfereeLinkedid']]['to'] . " [Transfered By: " . $this->data[$recv['TransfereeLinkedid']]['transferedBy'] . "]");
                }
                //exec("asterisk -rx \"channel request hangup $recv[TransfererChannel]\"");
            }
        }

        # cagri dial result eventi. henuz bitmedi. bu aramanin nasil sonuclandigini belirler, mesgul, cevaplandi, ulasilamiyor v.b.
        public function dialResult($recv, $totalDuration, $totalRinging)
        {
            // TODO: bakilmasi lazim buraya. operatore gore durum degisiyor. daha kolay bir sekli olmali.
            $startEnd = time() - $this->data[$recv['Uniqueid']]['timeStart'];

            echo $this->data[$recv['Uniqueid']]['hangupCode'] . "-$startEnd\r\n";

            if ($this->data[$recv['Uniqueid']]['hangupCode'] == '17' and $this->data[$recv['Uniqueid']]['timeRinging'] > 0) // reddedilen
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Reddedildi';
            else if ($this->data[$recv['Uniqueid']]['hangupCode'] == '28' or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $this->data[$recv['Uniqueid']]['timeRinging'] > 0 and $totalRinging != 6)) // me�gul
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Mesgul';
            else if ((($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 29) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 25) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 30) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 29) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 23) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 24) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 25) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 30) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 31)) and $this->data[$recv['Uniqueid']]['timeRinging'] > 0 and $this->data[$recv['Uniqueid']]['timeUp'] == 0) // cevap yok
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Cevap yok';
            else if ((($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 1) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 2) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 3) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 4) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 5) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 6) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 7) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 8) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 21) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 22) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 23) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 24) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 30)) and $this->data[$recv['Uniqueid']]['timeRinging'] == 0 and $this->data[$recv['Uniqueid']]['timeUp'] == 0) // kullan�lmayan numara
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Kullanilmayan Numara';
            else if ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $this->data[$recv['Uniqueid']]['timeRinging'] > 0 and ($startEnd == 6 or $startEnd == 7)) // yeni kullanilmayan nymara 18.10.2015
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Kullanilmayan Numara';
            else if ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $this->data[$recv['Uniqueid']]['timeRinging'] > 0 and $this->data[$recv['Uniqueid']]['timeUp'] > 0 and $totalDuration > 0) // cevapland�
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Cevaplandi';
            else if ((($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 10) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 10) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 11) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 12) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 13) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 14) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 15) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 16) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 17) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 18) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 19) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 20) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 28) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 29) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 30) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '19' and $startEnd == 31) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 16) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 20) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 21) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 22) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 23) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 24) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 25) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 27) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 28) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 29) or ($this->data[$recv['Uniqueid']]['hangupCode'] == '16' and $startEnd == 30)) and $this->data[$recv['Uniqueid']]['timeRinging'] == 0) // kapal�
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Kapali';
            else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Network out of order') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Destination out of order') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Circuit/channel congestion') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Call Rejected') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Bearer capability not available') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Number changed') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Number changed';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Recover on timer expiry') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Subscriber absent') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Unallocated (unassigned) number') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupReason'] == 'Unknown') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'System Error';
            } else if ($this->data[$recv['Uniqueid']]['hangupCode'] == '17') {
                $this->data[$recv['Uniqueid']]['dialResult'] = 'Mesgul';
            }
        }

        # cagri ses kaydini algilar. henuz bitmedi.
        public function callSoundFile($recv)
        {
            // TODO: bakilmali buna da
            if ($recv['Event'] == 'Newexten' and $recv['Application'] == 'NoOp') {
                $soundFile = explode('MONITOR_FILENAME=/var/spool/asterisk/monitor/', $recv['AppData']);
                if (!empty($soundFile[1])) {
                    $this->data[$recv['Uniqueid']]['soundFile'] = $soundFile[1];
                    $this->createLog($recv, "Call Sound File -> " . $this->data[$recv['Uniqueid']]['phone']);
                }
            }
        }

        # cagri bitisinde bu calisir ve tum cagri sureci biter.
        public function callHangup($recv)
        {
            //
            if ($recv['Event'] == 'Hangup') {
                if (isset($this->data[$this->channels["$recv[Uniqueid]"]]['from'])) {
                    if (isset($this->data[$this->channels["$recv[Uniqueid]"]]['dateHangup'])) {
                        $this->createLog($recv['Linkedid'], "Call Hangup oldu overwrite edecekti, engellendi. Bu bir transfer sonrasi hangup olabilir.", 'f');
                        $this->data[$recv['Uniqueid']]['hangupCode'] = $recv['Cause'];
                        $this->data[$recv['Uniqueid']]['hangupReason'] = $recv['Cause-txt'];
                        $this->data[$recv['Uniqueid']]['dateHangup'] = $this->getDate();
                        $this->data[$recv['Uniqueid']]['eventType'] = 'CallHangup';
                        $this->setMemCache($this->lastEventId, $this->data[$recv['Uniqueid']]);
                        $this->auditEvent($recv['Uniqueid'], "Call Hangup Overwrite -> " . $this->data[$recv['Uniqueid']]['from'] . ' -> ' . $this->data[$recv['Uniqueid']]['to']);
                        //print_r($this->data[$recv['Uniqueid']]);
                        unset($this->data[$recv['Uniqueid']]);
                        //unset($this->data[$this->channels["$recv[Uniqueid]"]]);
                    } else {
                        $this->data[$recv['Linkedid']]['hangupCode'] = $recv['Cause'];
                        $this->data[$recv['Linkedid']]['hangupReason'] = $recv['Cause-txt'];
                        $this->data[$recv['Linkedid']]['dateHangup'] = $this->getDate();
                        $this->data[$recv['Linkedid']]['eventType'] = 'CallHangup';
                        $this->setMemCache($this->lastEventId, $this->data[$recv['Linkedid']]);
                        $this->hangupCount++;
                        $this->auditEvent($recv['Linkedid'], "Call Hangup Rutin -> " . $this->data[$recv['Linkedid']]['from'] . ' -> ' . $this->data[$recv['Linkedid']]['to']);
                        //print_r($this->data[$recv['Linkedid']]);
                        unset($this->data[$recv['Linkedid']]);

                        //unset($this->data[$this->channels["$recv[Linkedid]"]]);
                    }
                } else {
                    $this->createLog($recv['Uniqueid'], "Call Hangup -> NULL");
                }
                //print_r($this->channels);

                $this->dialResult($recv, $totalDuration, $totalRinging);

                if ($this->data[$recv['Uniqueid']]['dialResult'] == 'Cevaplandi' and is_numeric($this->data[$recv['Uniqueid']]['queueExtension']) and empty($this->data[$recv['Uniqueid']]['agent'])) // abandon
                    $this->data[$recv['Uniqueid']]['dialResult'] = 'Abandon';


            }
        }

        # bu metot ekrana log basar.
        public function createLog($uniqueId, $log, $status = 's')
        {
            if ($status == 's' and PHP_OS == 'Linux')
                $log = chr(27) . "[44m $log" . chr(27) . '[0m';
            else if ($status == 'f' and PHP_OS == 'Linux')
                $log = chr(27) . "[41m $log" . chr(27) . '[0m';


            if ($uniqueId == '' and PHP_OS == 'Linux') {
                $uniqueId = 'SYSACT';
            }


            echo "{$this->astName} -> $uniqueId $log\r\n";
        }

        # bu metot erkana log basilmadan onceki olaylar icin.
        public function auditEvent($uniqueId, $log, $status = 's')
        {
            $log .= " Event ID: #{$this->lastEventId}";
            $this->createLog($uniqueId, $log, $status);
        }
    }

?>
