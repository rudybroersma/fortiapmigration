<?php
# FortiAP SSH Migratie script
# Rudy Broersma <r.broersma@ctnet.nl>

$host = "https://a.b.c.d:8443";
$uri = "/api/v2/cmdb/switch-controller/managed-switch";
$token = "";

$readonly = FALSE;

$trigger_description = "ACCESS";
$trigger_vlan = "VLAN_160";
$fortiap_vlan = "VLAN_600";
$fortiap_allowed_vlans = [ "quarantine", "VLAN_114", "VLAN_130", "VLAN_161", "VLAN_162" ];

$whitelist_switches = [ ];

class SQLiteConnection {
  const SQLITE_FILE = '/tmp/ssh.sqlite3';

  private $pdo;

  public function __construct() {
    $this->connect();
    $this->initialize();
  }

  private function connect() {
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($this->pdo == null) {
      $this->pdo = new \PDO("sqlite:" . self::SQLITE_FILE, null, null, $options);
    }
    return $this->pdo;
  }

  private function initialize() {
    $query = $this->pdo->prepare("CREATE TABLE IF NOT EXISTS switchport(sn TEXT, port TEXT, status INT DEFAULT 0, hasbeenup INT DEFAULT 0, UNIQUE(sn, port))");
    return $query->execute();
  }

  public function setPort($sn, $port_id, $status) {
    $portStatus = $this->getPortHistorical($sn, $port_id);
    if ($portStatus == 1) {
      $hasbeenup = 1;
    } else {
      $hasbeenup = 0;
    };

    if ($status == 1) {
      $hasbeenup = 1;
    };

    if ($portStatus === FALSE) {
      $query = $this->pdo->prepare("INSERT INTO switchport(sn, port, status, hasbeenup) VALUES(:sn, :port_id, :status, :hasbeenup)");
    } else {
      $query = $this->pdo->prepare("UPDATE switchport SET status = :status, hasbeenup = :hasbeenup WHERE sn = :sn AND port = :port");
    };

    try {
      $query->execute(['sn' => $sn, 'port_id' => $port_id, 'status' => $status, 'hasbeenup' => $hasbeenup]);
      $result = $query->rowCount();
    } catch (PDOException $e) { }
  }

  public function getPortCurrent($sn, $port_id) {
    $query = $this->pdo->prepare("SELECT * FROM switchport WHERE sn = :sn AND port = :port_id");
    $query->execute(['sn' => $sn, 'port_id' => $port_id]);

    $retVal = $query->fetch();
    if ($retVal === FALSE) { return FALSE; } else { return $retVal['status']; };
  }

  public function getPortHistorical($sn, $port_id) {
    $query = $this->pdo->prepare("SELECT * FROM switchport WHERE sn = :sn AND port = :port_id");
    $query->execute(['sn' => $sn, 'port_id' => $port_id]);

    $retVal = $query->fetch();
    if ($retVal === FALSE) { return FALSE; } else { return $retVal['hasbeenup']; };
  }

}

$db = new SQLiteConnection();

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

function fix_port($switch, $port) {
  global $host, $token;
  global $fortiap_vlan, $fortiap_allowed_vlans;
  global $readonly;

  $uri = "/api/v2/cmdb/switch-controller/managed-switch/" . $switch . "/ports/" . $port;

  $av_block = "";
  foreach($fortiap_allowed_vlans as $av) {
    $av_block = $av_block . "{ \"vlan-name\": \"" . $av . "\", \"q_origin_key\": \"" . $av  . "\"},";
  };

  $put_data = "
{
  \"json\" : {
      \"vlan\": \"". $fortiap_vlan . "\",
      \"allowed-vlans\": [
        " . $av_block . "
      ],
  }
}";

  $defaults = array(
    CURLOPT_URL => $host . $uri . "?&access_token=" . $token,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CUSTOMREQUEST => "PUT",
    CURLOPT_POSTFIELDS => $put_data
  );

  $ch = curl_init();
  curl_setopt_array($ch, $defaults);
  if ($readonly != true) {
    $data = curl_exec($ch);
    $retval = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($retval == "200") {
      echo date('d-m-Y G:i') . ": " . $switch . " - " . $port . " is reconfigured!\n";
    } else {
      echo $switch . " - " . $port . " FAILED TO RECONFIGURE!\n";
    };
  } else {
      echo $switch . " - " . $port . " NOT CHANGED. Read Only Mode is enabled\n";
  }
}

$defaults = array(
  CURLOPT_URL => $host . $uri . "?&access_token=" . $token,
  CURLOPT_RETURNTRANSFER => TRUE,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
);

$ch = curl_init();
curl_setopt_array($ch, $defaults);

while(true) {
  $data = curl_exec($ch);

  #var_dump(curl_getinfo($ch));
  #var_dump(curl_error($ch));
  #var_dump($data);
  $json = json_decode($data);
  foreach($json->results as $switch) {
#    echo "Switch " . $switch->{'switch-id'} . ":\n";
    foreach($switch->ports as $port) {
#      echo $port->{'switch-id'} . " - " . $port->{'port-name'} . " - " . $port->{'status'} . "\n";
      if (str_contains(strtoupper($port->{'description'}), strtoupper($trigger_description)) && $port->vlan == $trigger_vlan) {
#        echo $port->{'switch-id'} . " - " . $port->{'port-name'} . " is configured as accesspoint and has trigger VLAN!\n";
        if ($port->{'status'} == "down") {
          if ($db->getPortHistorical($port->{'switch-id'}, $port->{'port-name'}) == 1) {
            echo date('d-m-Y G:i') . ": " . $port->{'switch-id'} . " - " . $port->{'port-name'} . " is a trigger port with status DOWN and was up at some point. Reconfiguring...\n";
            $db->setPort($port->{'switch-id'}, $port->{'port-name'}, 0);
            fix_port($port->{'switch-id'}, $port->{'port-name'});
          };
        } else {
           // Port is up. Store in DB.
          $db->setPort($port->{'switch-id'}, $port->{'port-name'}, 1);
        }
      };
    };
  };
  sleep(1);
};
