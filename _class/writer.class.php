<?php
/**
 * Created by PhpStorm.
 * User: volkan
 * Date: 25.11.2015
 * Time: 10:23
 */
class writer {
    public $dir, $db, $lastEventId, $memcache, $memcacheIp, $memcachePort, $stationLength, $domainPrefixLength, $loggedInId, $availableId, $ringingId, $inCallId, $holdId;

    public function __construct() {
    }

    # bu metot servisi terminate eder. boot esnasinda eventid, callid gibi verilere ulasilamazsa terminate olur.
    public function terminate($reason) {
        // TODO: buradan bir yere log atanmali.
        $this->createLog(null, 'WRITER TERMINATING... Reason: '.$reason);
        die();
    }

    # bu metot memcache e connect eder. bu serviste kullanilmiyor.
    public function memcacheConnect() {
        $this->memcache = new Memcache;
        $this->createLog(null, 'Connecting Memcache...');
        if ($this->memcache->connect($this->memcacheIp, $this->memcachePort))
            return true;
        else
            return false;
    }

    # bu metot bu serviste kullanilmiyor. lazim olur diye biraktim.
    public function setMemCache($key, $value) {
        if ($this->memcache->getserverstatus($this->memcacheIp, $this->memcachePort)) {
            $this->createLog(null, 'Memcached Connected.');
        }
    }

    # son event ID'yi alir. Bunun amaci, listener ve writer arasindaki sync.
    public function getLastEventId() {
        # listener da ayni sekilde acilista gider event ID yi alir.
        $a = $this->db->selectOne("\"{$this->db->defScheme}\".settings", array('value'), "name = 'lastEventId'");
        return $a->value;
    }

    # bu metot default olarak sistemdeki dahililerin uzunlugunu belirler. 4 veya 5 karakter gibi.
    public function getStationLength() {
        # bu sayede cagrilarin yonunu algilayabiliriz.
        $a = $this->db->selectOne('pbx.setting', array('value'), "name = 'internalStationLength'");
        return $a->value;
    }

    # bu metot default olarak sistemdeki domain prefixlerinin uzunlugunu belirler. 4 veya 5 karakter gibi.
    public function getDomainPrefixLength() {
        # bu sayede cagrilarin domainini algilayabiliriz.
        $a = $this->db->selectOne('pbx.setting', array('value'), "name = 'domainPrefixLength'");
        return $a->value;
    }

    # bu metot mevcut sisteme ait default stateleri alir. bu statelerin adlari degisemez, ancak ID leri istenirse degisebilir.
    public function getStates() {
        # ID degisirse eger writer restart edilmelidir.
        $states = $this->db->select('pbx.agent_state_list', array('id', 'name'), "status = 'A' and (state_type = 'system' or state_type = 'mixed')");
        foreach ($states as $state) {
            if ($state->name == 'Available')
                $this->availableId = $state->id;
            else if ($state->name == 'InCall')
                $this->inCallId = $state->id;
            else if ($state->name == 'Hold')
                $this->holdId = $state->id;
            else if ($state->name == 'Ringing')
                $this->ringingId = $state->id;
            else if ($state->name == 'Logged In')
                $this->loggedInId = $state->id;
        }
        if ($this->loggedInId > 0)
            return true;
    }

    # bu fonksiyon devamli tetiklenir arka planda. son event id yi memcache den kontrol eder.
    public function checkLastEventId() {

        # memcache e yeni bir event gelmis mi ona bakar. 
        # geldiyse processResponse metotunu cagirir. 
        $response = $this->memcache->get($this->lastEventId);
        if ($response == true) {
            $this->createLog(null, "Founded #{$this->lastEventId}.");
            $this->processResponse($response);
        } else {
            //$this->createLog(null, "Not found #{$this->lastEventId}.", 'f');
        }
    }

    # cagri basladiginda bu metot tetiklenir.
    public function processStarting($response) {
        # herhangi bir sey yapilmazsa bile squence setval ile arttirilir.
        # bu sayede listener ve writer arasindaki CallID'ye ait sync patlamaz.
        # kaldigi yerden devam eder.
        $squenceUpdate = $this->db->query("SELECT setval('pbx.calls-sq', $response[id])");

        if ($squenceUpdate == true) // squence update basariliysa
            $this->createLog(null, "1 call started now. Current squence: $response[id]");
        else // değilse de at.
            $this->createLog(null, "ERROR: 1 one call doesnt started. Current squence: $response[id].", 'f');

    }

    # cagri ringing oldugunda bu metot tetiklenir.
    public function processRinging($response) {
        # eger from veya to jupiter e ait bir station ise
        # statusu ringing olarak guncellenir.
        # buradaki this->ringingId boot esnasinda load edilmisti.
        if (strlen($response['from']) == $this->stationLength) { // from bir jupiter station'�
            return $this->changeStationState($response['from'], $this->ringingId);
        }
        if (strlen($response['to']) == $this->stationLength) { // to bir jupiter station'�
            return $this->changeStationState($response['to'], $this->ringingId);
        }
    }

    # cagri karsi taraf tarafindan cevaplandiginda, yani talking basladiginda bu metot tetiklenir.
    public function processInCall($response) {
        # statusu incall olarak guncellenir.
        # buradaki this->inCallId boot esnasinda load edilmisti.
        if (strlen($response['from']) == $this->stationLength) { // from bir jupiter station'�
            return $this->changeStationState($response['from'], $this->inCallId);
        }
        if (strlen($response['to']) == $this->stationLength) { // to bir jupiter station'�
            return $this->changeStationState($response['to'], $this->inCallId);
        }
    }

    # cagri hold edildiginde bu metot tetiklenir.
    public function processHold($response) {

        # statusu hold olarak guncellenir.
        # burada suan holdlar tek tek eklenmiyor. cagri bittiginde ekleniyor. suan amaci yok. sadece statu change.
        if (strlen($response['from']) == $this->stationLength) { // from bir jupiter station'ı
            return $this->changeStationState($response['from'], $this->holdId);
        }
        if (strlen($response['to']) == $this->stationLength) { // to bir jupiter station'ı
            return $this->changeStationState($response['to'], $this->holdId);
        }
    }

    # cagri unhold edildiginde bu metot tetiklenir.
    public function processHoldStop($response) {
        # statusu incall olarka guncellenir.
        if (strlen($response['from']) == $this->stationLength) { // from bir jupiter station'�
            return $this->changeStationState($response['from'], $this->inCallId);
        }
        if (strlen($response['to']) == $this->stationLength) { // to bir jupiter station'�
            return $this->changeStationState($response['to'], $this->inCallId);
        }
    }

    # cagri sonlandiginda bu metot tetiklenir.
    public function processHangup($response) {
        # hold insertleri dahil cagriya ait hemen tum insertler buradadir.
        # hangup oldugunda available olarak guncellenir agent.
        // TODO: hangup oldugunda kisi available mi olmalidir ? bunun uzerinde calisilmali. custom olmali bence skill based.

        # agent session detection
        $fromAgent = $this->db->selectOne('"pbx".agent_session sess, "pbx".station s', array('agent_id'), "s.extension = '$response[from]' and s.id = sess.station_id and sess.session_end IS NULL");
        $toAgent = $this->db->selectOne('"pbx".agent_session sess, "pbx".station s', array('agent_id'), "s.extension = '$response[to]' and s.id = sess.station_id and sess.session_end IS NULL");

        if ($fromAgent->agent_id) {
            $team = $this->db->selectOne("main.team_index", array('member_id'), "member_id = '{$fromAgent->agent_id}'");
        } else if ($toAgent->agent_id) {
            $team = $this->db->selectOne("main.team_index", array('member_id'), "member_id = '{$toAgent->agent_id}'");
        }

        # from domain id detection
        preg_match("/^SIP\/(.*)-.(.*)$/", $response['fromChannel'], $domainWithStationExt); // return 77771001
        $domainPrefix = substr($domainWithStationExt[1], 0, $this->domainPrefixLength);
        if (strlen($domainPrefix) == $this->domainPrefixLength) {
            $this->createLog(null, "Domain detected: $domainPrefix", 'f');
            $domain = $this->db->selectOne("main.domain", array('id'), "prefix = '$domainPrefix'");
        }

        # to domain id detection
        preg_match("/^SIP\/(.*)-.(.*)$/", $response['toChannel'], $domainWithStationExt); // return 77771001
        $domainPrefix = substr($domainWithStationExt[1], 0, $this->domainPrefixLength);
        if (strlen($domainPrefix) == $this->domainPrefixLength) {
            $this->createLog(null, "Domain detected: $domainPrefix", 'f');
            $domain = $this->db->selectOne("main.domain", array('id'), "prefix = '$domainPrefix'");
        }

        // TODO: Acaba 1 domaini 2 domainini aradiginda cift kayit mi eklemek gerekir ? suan tek ekliyor biribrilerini aradiklarinda. dolayisiyla iclerinden birisi kaydi hic gormeyecek domain id yuzunden

        # insert call
        $ins = $this->db->insert('pbx.calls',
            array(
                'id' => $response['id'],
                '_from' => $response['from'],
                'from_agent_id' => $fromAgent->agent_id,
                '_to' => $response['to'],
                'to_agent_id' => $toAgent->agent_id,
                'direction' => $this->returnDirection($response['from'], $response['to']),
                'server_id' => $response['serverId'],
                'date_dial' => $response['dateDial'],
                'date_ringing' => $response['dateRinging'],
                'date_up' => $response['dateUp'],
                'date_hangup' => $response['dateHangup'],
                'date_transfer' => $response['dateTransfer'],
                'transfer_to' => $response['transferTo'],
                'transfer_by' => $response['transferBy'],
                'skill_id' => $skillId, // TODO: hani nerede ?
                'team_id' => $team->member_id,
                'domain_id' => $domain->id,
            )
        );

        if ($ins > 0) {
            $this->createLog(null, "Call insert id: $ins", 'f');
            # insert holds
            foreach($response['holds'] as $holderStationExt => $holdL1) {
                if ($holderStationExt == $response['from'])
                    $holderAgentId = $fromAgent->agent_id;
                else if ($holderStationExt == $response['to'])
                    $holderAgentId = $toAgent->agent_id;
                
                for ($i=0; $i<count($holdL1['start']); $i++) {
                    $holdIns[$i] = $this->db->insert('pbx.holds', array(
                        'call_id' => $response['id'],
                        'start_date' => $holdL1[start][$i],
                        'end_date' => $holdL1[stop][$i],
                        'holder_agent_id' => $holderAgentId
                    ));
                    if ($holdIns[$i] == true) // ekleme başarılıysa log at
                        $this->createLog(null, "$response[id] hold added.");
                    else // değilse de at.
                        $this->createLog(null, "ERROR: $response[id] hold doesnt added!.", 'f');
                }
            }
        }


        # change agent state
        if (strlen($response['from']) == $this->stationLength) { // from bir jupiter station'�
            return $this->changeStationState($response['from'], $this->availableId);
        }
        if (strlen($response['to']) == $this->stationLength) { // to bir jupiter station'�
            return $this->changeStationState($response['to'], $this->availableId);
        }
    }

    # bu metot cagrinin yonunu belirler ve bunu return eder
    public function returnDirection($from, $to) {
        if (strlen($from) == $this->stationLength and strlen($to) == $this->stationLength)
            return 'internal';
                else if (strlen($from) == $this->stationLength)
            return 'outbound';
        else if (strlen($to) == $this->stationLength)
            return 'inbound';

    }

    # bu metot server tarafindan agent'in statusunu degistirir.
    public function changeStationState($stationExt, $newStateId) {

        # buradaki amac incall, hold gibi hayati statulerin server tarafindan algilanarak guvence altina alinmasidir.
        $this->db->pdo->beginTransaction();
        $newState = $this->db->selectOne('pbx.agent_state_list', array('id', 'name', 'state_type', 'accept_queue_call'), "id = '$newStateId' and status = 'A'");
        $newState->accept_queue_call == 'F' ? $paused = 1 : $paused = 0;

        # hub daki kuyruklari pause et.
        $upd1 = $this->db->update('hub.queue_members', array(
            'paused' => $paused
        ), "membername = '$stationExt'");

        # bu bir pgsql function. icerisindeki pek cok update ve insert var. biz sadece stationExtension ve yeni statu id sini gonderiyoruz.
        $query = $this->db->query("SELECT pbx.\"addstatesession(int4, int4)\"($stationExt, $newStateId)");


        if ($upd1 == true and $query == true) {// updateler başarılıysa log at
            $this->db->pdo->commit();
            $this->createLog(null, "$stationExt state changed to :$newStateId");
        }
        else { // değilse de at.
            $this->db->pdo->rollBack();
            $this->createLog(null, "$stationExt do not state change to:$newStateId", 'f');
        }
    }

    # bir SIP client register veya unregister oldugu zaman bu metot tetiklenir.
    public function processPeerStatus($response) {

        # bu sayede logout/login islemlerinin bir kisminin server tarafindan yapilmasi saglanir.
        #
        if (strlen($response['stationExt']) == $this->stationLength and $response['peerStatus'] == 'Registered') {
            $this->db->query("UPDATE pbx.agent_session SET state_id = '{$this->loggedInId}', session_last_move = NOW(), session_state = 'online' FROM pbx.station WHERE agent_session.station_id = station.id and station.extension = '$response[stationExt]' and session_state = 'pending' and session_end IS NULL");

            ## skill ler yukleniyor
            foreach($this->db->select(
                'pbx.skill_index si,
                 pbx.skill skill,
                 pbx.agent_session sess,
                 pbx.station station,
                 pbx.server server',

                array('skill.skill_name', 'server.name as server_name'),
                "si.skill_id = skill.id
                AND si.agent_id = sess.agent_id
                AND sess.station_id = station.id
                AND station.server_id = server.id
                AND station.extension = '$response[stationExt]'
                AND sess.session_end IS NULL
                AND skill.status = 'A'") as $skills) {

                $isExist = $this->db->selectOne('hub.queue_members', array('queue_name'),
                    "interface = 'SIP/$response[stationExt]@{$skills->server_name}' and membername = '$response[stationExt]' and queue_name = '{$skills->skill_name}'"); // member eklenmiş mi daha önce

                # skill ekleniyor
                if (!$isExist->queue_name) { # eklenmediyse ekle
                    $ins = $this->db->insert('hub.queue_members', array(
                        'queue_name' => $skills->skill_name,
                        'interface' => 'SIP/' . $response['stationExt'] . '@' . $skills->server_name, // Example: SIP/1000@voip1
                        'membername' => $response['stationExt'],
                        'paused' => 1 # giriste paused olsun ki hemen cagri gelmesin.
                    ));

                    if ($ins == true) # ekleme basariliysa log at
                        $this->createLog(null, "$response[stationExt]@{$skills->server_name} added to {$skills->skill_name}.");
                    else # degilse de at
                        $this->createLog(null, "ERROR: $response[stationExt]@{$skills->server_name} -> {$skills->skill_name} was can't added.", 'f');
                }
            }
        }
        else if (strlen($response['stationExt']) == $this->stationLength and ($response['peerStatus'] == 'Unregistered' or $response['peerStatus'] == 'Unreachable' )) {
            $q1 = $this->db->query("UPDATE pbx.agent_state astate SET end_date = NOW() FROM  pbx.agent_session sess, pbx.station s
                                       WHERE
                                            s.id = sess.station_id AND
                                            astate.agent_id = sess.agent_id and
                                            astate.end_date IS NULL and
                                                sess.session_end IS NULL and
                                                s.extension = $response[stationExt]");

            $del = $this->db->delete('hub.queue_members', "membername = '$response[stationExt]'");
            $q2 = $this->db->query("UPDATE pbx.agent_session SET state_id = 0, session_last_move = NOW(), session_end = NOW(), session_state = 'completed' FROM pbx.station WHERE agent_session.station_id = station.id and station.extension = '$response[stationExt]' and session_end IS NULL");

            if ($q1 == true and $q2 == true and $del == true) # ekleme basariliysa log at
                $this->createLog(null, "$response[stationExt] offline success.");
            else # degilse de at.
                $this->createLog(null, "$response[stationExt] offline FAIL!", 'f');

        }
    }

    # bu metot ana yonlendirici.
    public function processResponse($response) {
        # her bir event ta bu metot cagrilir ve bu metot gelen eventin tipine gore ilgili metotu tetikler.
        $this->createLog(null, "Processing #{$this->lastEventId}.");
        if ($response['eventType'] == 'CallStarting')
            $this->processStarting($response);
        else if ($response['eventType'] == 'CallRinging')
            $this->processRinging($response);
        else if ($response['eventType'] == 'CallUp')
            $this->processInCall($response);
        else if ($response['eventType'] == 'CallHoldStart')
            $this->processHold($response);
        else if ($response['eventType'] == 'CallHoldStop')
            $this->processHoldStop($response);
        else if ($response['eventType'] == 'CallHangup')
            $this->processHangup($response);
        else if ($response['eventType'] == 'peerStatus')
            $this->processPeerStatus($response);

        $this->lastEventId++;
        $this->db->update("\"{$this->db->defScheme}\".settings", array('value' => $this->lastEventId), "name = 'lastEventId'");
        return true;
    }

    # log metotu.
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
}