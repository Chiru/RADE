<?php
?><!DOCTYPE html>
<html>
<head>

<title>Database content</title>
<meta charset="UTF-8">
<meta http-equiv="content-type" content="text/html; charset=UTF-8">

<style>
h1 {
    font-size: 24px;
}
h2 {
    font-size: 20px;
}
table {
    margin: 0 20px;
    border: 2px solid black;
    border-collapse: collapse;
}
th, td {
    font-size: 8px;
    border: 2px solid black;
    text-align: right;
    padding: 2px 5px;
}
td {
    font-size: 16px;
    font-family: monospace;
}
pre {
    font-family: monospace;
    font-size: 16px;
    padding-left: 20px;
}
p {
    margin: 6px 0;
    padding-left: 40px;
}
</style>
</head>
<body>
<h1>Database content</h1>
<?php
require 'db_utils.php';
require 'score.php';

$error = FALSE;
$candidate_peer_infos = array();

function q($txt) {
  return htmlspecialchars($txt, ENT_COMPAT | ENT_HTML401, 'UTF-8');
}

$database = pg_connect("dbname=cadist3d_db user=cadist3d");
pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);

if (!$error) {
  $query = 'SELECT * FROM links;';
  $result = pg_query($database, $query);
  if ($result === FALSE) {
    $error = q(pg_last_error($database));
  } else {
    $links = array();
    $n = pg_num_rows($result);
    for ($i = 0; $i < $n; $i++) {
      $a = pg_fetch_result($result,$i,'a');
      $b = pg_fetch_result($result,$i,'b');
      if (!isset($links[$b])) $links[$b] = array();
      if (!isset($links[$a])) $links[$a] = array();
      $links[$a][$b] = TRUE;
      $links[$b][$a] = TRUE;
    }
    pg_free_result($result);
  }
}

if (!$error) {
  $query = 'SELECT * FROM assets ORDER BY asset;';
  $result = pg_query($database, $query);
  if ($result === FALSE) {
    $error = q(pg_last_error($database));
  } else {
    $assets = array();
    $allassets = array();
    $n = pg_num_rows($result);
    for ($i = 0; $i < $n; $i++) {
      $name = pg_fetch_result($result, $i, 'client');
      $asset = pg_fetch_result($result, $i, 'asset');
      if (!isset($assets[$name])) $assets[$name] = array();
      $assets[$name][$asset] = TRUE;
      $allassets[$asset] = TRUE;
    }
    pg_free_result($result);
    $allassets = array_keys($allassets);
    $assetcnt = count($allassets);
  }
}

if (!$error) {
  $query = 'SELECT * FROM clients ORDER BY client;';
  $result = pg_query($database, $query);
  if ($result === FALSE) {
    $error = q(pg_last_error($database));
  }
}

if (!$error) {
  $wdt = pg_num_fields($result);
  $clientcnt = pg_num_rows($result);
  $allclients = array();
  for ($i = 0; $i < $clientcnt; $i++) {
    $client_name = pg_fetch_result($result, $i, 'client');
    $allclients[$i] = $client_name;
    $candidate_peer_infos[$client_name] = pg_fetch_array($result, $i, PGSQL_ASSOC);
    
  }
  echo "<table id=\"clients\">\n<tr>";
  $group_name = "";
  $group_name_len = 0;
  $group_size = 0;
  for ($f = 0; $f < $wdt + 1; $f++) {
    if ($f < $wdt) {
      $field_name = q(pg_field_name($result, $f));
    } else {
      $field_name = "";
    }
    if ( $group_name_len > 0 
         && substr($field_name, 0, $group_name_len) == $group_name )
    {
      $group_size++;
    } else if ($group_name_len > 0) {
      echo '<th colspan ="'.$group_size.'">'.$group_name.'</th>';
      $group_name = "";
      $group_name_len = 0;
      $group_size = 0;
    }
    if ($group_name_len == 0) {
      if (substr($field_name, -2) == "_1") {
        $group_name_len = strlen($field_name) - 2;
        $group_name = substr($field_name, 0, $group_name_len);
        $group_size = 1;
      } else if ($f < $wdt) {
        echo "<th>$field_name</th>";
        $old_field_name = $field_name;
      }
    }
  }
  for ($f = 0; $f < $clientcnt; $f++) {
    echo "<td>".$allclients[$f]."</td>";
  }
  for ($f = 0; $f < $assetcnt; $f++) {
    echo "<td>".$allassets[$f]."</td>";
  }
  echo "</tr>\n";
  for ($i = 0; $i < $clientcnt; $i++) {
    echo "<tr>";
    $client_i = $allclients[$i];
    $candidate_peer_infos[$client_i]["connections"] = array();
    $candidate_peer_infos[$client_i]["assets"] = array();
    $row = pg_fetch_row($result, $i);
    for ($f = 0; $f < $wdt; $f++) {
      if (isset($row[$f])) {
        $val = q($row[$f]);
      } else {
        $val = '';
      }
      echo "<td>$val</td>";
    }
    for ($f = 0; $f < $clientcnt; $f++) {
      $client_f = $allclients[$f];
      if (isset($links[$client_i]) && isset($links[$client_i][$client_f])) {
        array_push($candidate_peer_infos[$client_i]["connections"], $client_f);
        echo "<td>*</td>";
      } else {
        echo "<td></td>";
      }
    }
    for ($f = 0; $f < $assetcnt; $f++) {
      $asset_f = $allassets[$f];
      if (isset($assets[$client_i]) && isset($assets[$client_i][$asset_f])) {
        array_push($candidate_peer_infos[$client_i]["assets"], $asset_f);
        echo "<td>+</td>";
      } else {
        echo "<td></td>";
      }
    }
    echo "</tr>\n";
  }
  echo "</table>\n";
  if ($n < 1) {
    echo "<p><i>no data</i></p>\n";
  }
  pg_free_result($result);
}

pg_query($database, "VACUUM FULL clients;");

pg_close($database);

//$error = q("All \"<OK>\" & no 'error'!");

if ($error) {
  echo "<div><h2>Error</h2>\n<pre>$error</pre></div>\n";
}
?>
<p style="margin-left:200px">&#xA0;</p>

<h1>Resource-aware Browser-to-browser 3D Graphics Delivery Load Balancing Algorithm</h1>

<form action="Show.php" method="post">
<h3>Settings</h3>
<?php

if (isset($_POST["settings"])) {
    if (isset($_POST["random"]) && ($_POST["random"] == "random")) {
      $random = "true";
    } else {
      $random = "false";
    }
    $new_settings = array();
    $new_settings[0] = array(key=>"random", "value"=>$random);
    update_settings($new_settings);
}

$settings = get_settings();
$random = FALSE;
foreach ($settings as $setting) {
  if ($setting['key'] == 'random') $random = $setting['value'];
  if (is_string($random) && $random) {
    $random = ($random[0] != 'F') && ($random[0] != 'f');
  }
}

?>
<input type="hidden" name="settings" value="settings">
<p><input type="checkbox" name="random" value="random"<?php
if ($random) echo " checked";
?>>Random<br>
<input type="submit" value="Update settings"></p>
</form>
<form action="Show.php" method="post">
<h3>Algorithm sub-score weights</h3>
Connection establishment

<?php

if (isset($_POST["conn_est_input"]) && isset($_POST["dl_speed_input"]) && isset($_POST["log_net_location_input"]) && isset($_POST["dev_perf_input"]) && isset($_POST["battery_input"]))
{
    $conn_est = $_POST["conn_est_input"];
    $dl_speed = $_POST["dl_speed_input"];
    $log_net_location = $_POST["log_net_location_input"];
    $dev_perf = $_POST["dev_perf_input"];
    $battery = $_POST["battery_input"];
    
    set_algorithm_weights($conn_est, $dl_speed, $log_net_location, $dev_perf, $battery);
}

$alg_weights = get_algorithm_weights();
echo "<input name=\"conn_est_input\" type=\"number\" step=0.1 value=" . $alg_weights['conn_est'] . " style=\"width:40px\">";
echo "Download speed";
echo "<input name=\"dl_speed_input\" type=\"number\" step=0.1 value=" . $alg_weights['dl_speed'] . " style=\"width:40px\">";
echo "Logical network location";
echo "<input name=\"log_net_location_input\" type=\"number\" step=0.1 value=" . $alg_weights['log_net_location'] . " style=\"width:40px\">";
echo "Battery";
echo "<input name=\"battery_input\" type=\"number\" step=0.1 value=" . $alg_weights['battery'] . " style=\"width:40px\">";
echo "Device performance";
echo "<input name=\"dev_perf_input\" type=\"number\" step=0.1 value=" . $alg_weights['dev_perf'] . " style=\"width:40px\"><br>";
echo "<input type=\"submit\" value=\"Update weights\">";
echo "</form>";

echo "<br>";



$candidate_peer_infos = get_peer_infos();
// echo json_encode($candidate_peer_infos);
echo "<br><br>";

$requestor = $candidate_peer_infos[0];
echo "Requestor: " . $requestor['client'];
echo "<br><br>";
$peer_count = count($candidate_peer_infos);
use_algorithm_weights(get_algorithm_weights());
if ($peer_count > 1)
{
    for ($i = 1; $i < $peer_count; $i++)
    {
        $candidate = $candidate_peer_infos[$i];
        echo "<b>Candidate " . $candidate['client'] . "</b><br><br>";
        $score = score($requestor, $candidate);
        echo "<br><br>";
        echo "<b>Score for candidate " . $candidate['client']. ": " . $score . "<br><br></b>";
    }
}
// echo "<br><br>";
// echo "<div>";
// print_r($allclients);
// echo "<br>";
// print_r($links);
// echo "<br>";
// print_r($assets);



// echo var_dump($candidate_peer_infos["client_1"]);

// echo "</div>";

?>

</body>
</html>
