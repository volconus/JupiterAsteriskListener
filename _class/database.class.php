<?php
class database {
    public $pdo;
    public $affectedRows, $driver, $ip, $port, $username, $password, $name, $defScheme;

    public function __construct() {

    }

    public function connect() {
        try {
            $this->pdo = new PDO($this->driver.':dbname='.$this->name.';host='.$this->ip.';user='.$this->username.';password='.$this->password.';');
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);     ## bu sql injection i�in �nemli bir �eymi�. gavurlar �yle yazm��. false kalmal�.
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return true;
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
    }


    public function select($table, $fields, $lastSection = '1=1') {
        $allFields = null;
        foreach ($fields as $field)
            $allFields .= "$field, ";
        $allFields = substr($allFields, 0, -2);
        return $this->fetchAll("SELECT $allFields FROM $table WHERE $lastSection");

    }

    public function selectOne($table, $fields, $lastSection = '1=1') {
        $allFields = null;
        foreach ($fields as $field)
            $allFields .= "$field, ";
        $allFields = substr($allFields, 0, -2);
        return $this->fetch("SELECT $allFields FROM $table WHERE $lastSection");
    }

    public function fetch($query) {
        try {
            //echo $query;
            $db = $this->query($query);
            $db = $db->fetch(PDO::FETCH_LAZY);
            return $db;
        } catch(PDOException $e) {
            echo "Fetch ERR:". $e->getMessage();
        }
    }

    public function fetchAll($query) {
        try {
            //echo $query;
            $db = $this->query($query);
            $db = $db->fetchAll(PDO::FETCH_CLASS);
            return $db;
        } catch(PDOException $e) {
            echo "FetchALL ERR:". $e->getMessage();
        }
    }

    public function query($query) {
        try {

            $db = $this->pdo->prepare($query);
            $db->execute();
            $this->affectedRows = $db->rowCount();
            return $db;
        } catch (PDOException $e) {
            if ($e->getCode() == '23505') { ## 23505 unique se�ilen alanlarla �eli�en bir duplicate yapmaya �al��t���n� s�yl�yor.

            }
            else
                echo "DBERR:". $e->getMessage(). '--'. $e->getCode();
        }
    }

    public function getId($schemaTable) {
        $id = $this->fetch("SELECT nextval('$schemaTable-sq');");
        return $id->nextval;
    }

    public function ifSuccess() {
        if ($this->affectedRows > 0)
            return true;
        else
            return false;
    }

    public function insert($schemaTable, $datas) {
        !isset($datas['id']) ? $datas['id'] = $this->getId($schemaTable) : '';

        foreach ($datas as $field => $value) {
            if ($value != '') {
                @$allFields .= "\"$field\",";
                @$allValues .= "'" . pg_escape_string($value) . "',";
            }
        }

        $allFields = rtrim($allFields, ',');
        $allValues = rtrim($allValues, ',');

        $query = "INSERT INTO $schemaTable ($allFields) VALUES ($allValues);";
        $ins = $this->query($query);

        // echo $query;

        if ($ins == true) {
            //return $this->pdo->lastInsertId($schemaTable.'-sq'); # ben gene burda sorun ya�adm�m abi ya. id'yi ben �nceden rezerve edip g�nderi�im zaman hata veriyor buras�
            return $datas['id'];
        }
        else
            return false;
    }

    public function update($schemaTable, $datas, $lastSection){
        foreach ($datas as $field => $value)
            @$set .= "\"$field\" = '".pg_escape_string($value)."',";

        $query = "UPDATE $schemaTable SET ".rtrim(@$set, ',')." WHERE $lastSection";
        $up = $this->query($query);

        if ($up != false) {
            return $up;
        }

        else
            return false;
    }

    public function delete($schemaTable, $lastSection) {
        $query = "DELETE FROM $schemaTable WHERE $lastSection";
        return $this->query($query);
    }

    public function isExist($schemaTable, $lastSection) {
        $res = $this->selectOne($schemaTable, array('id'), $lastSection);
        if (empty($res->id)) // false d�n sat�r yok
            return false;
        else // true d�n sat�r bulundu
            return true;
    }
}
?>