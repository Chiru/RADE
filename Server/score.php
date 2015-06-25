<?php

// require 'db_utils.php';

$algorithm_weights = array();
$algorithm_weights['conn_est'] = 0.15;
$algorithm_weights['dl_speed'] = 0.3;
$algorithm_weights['log_net_location'] = 0.05;
$algorithm_weights['battery'] = 0.4;
$algorithm_weights['dev_perf'] = 0.1;

$param_battery_exp = 1.3;

function use_algorithm_weights($new_algorithm_weights) {
  global $algorithm_weights;
  $algorithm_weights = $new_algorithm_weights;
}

function score($dst, $src) {

  global $param_battery_exp;
  global $algorithm_weights;
/*
  global $param_connection_weight;
  global $param_net_speed_weight;
  global $param_net_location_weight;
  global $param_battery_weight;
  global $param_speed_weight;
*/

  $connection_score = 0;
  if (in_array($src['client'], $dst['connections'])) {
    $connection_score = 1;
  } if ($src['browser_type'] == 'Chrome') {
    $connection_score += 0.3;
  } if ($src['network_type'] == 'wifi') {
    $connection_score += 0.3;
  } if ($src['network_type'] == 'ethernet') {
    $connection_score += 0.3;
  }
  if ($connection_score > 1)
      $connection_score = 1;

//   if ($src['num_active_uploads'] >= 5) {
//     return -1;
//   } else if ($dst['num_active_downloads_from'][$src] >= 1) {
//     return -1;
//   } else {
    $net_speed_score = 1 - exp(-$src['upload_speed']/300);
    if ($net_speed_score > 1) $net_speed_score = 1;
//   }

//   $s = $src['net_location'];
//   $d = $dst['net_location'];
  $r2 = 0;
  for ($i = 0; $i < 5; $i++) {
    if (isset($src['logical_network_location_'.$i]) && isset($dst['logical_network_location_'.$i])) {
      $dif = $src['logical_network_location_'.$i] - $dst['logical_network_location_'.$i];
      $r2 += $dif * $dif;
    }
  }
/*
  echo "r2: " . $r2 . "\n<br>";
  echo "sqrt(r2): " . sqrt($r2) . "\n<br>\n<br>";
*/
  $net_location_score = 1 - sqrt($r2) / 1000;
  if ($net_location_score < 0) $net_location_score = 0;

  if ($src['battery_level'] < 20) {
    return -1;
  } else if ($src['battery_charging'] == "t") {
    $battery_score = 1;
  } else {
    $battery_score = pow($src['battery_level']/100, $param_battery_exp);
  }

  $speed_score = exp(-(($src['client_processing_speed'] - 200)) / 1000);
  if ($speed_score > 1) $speed_score = 1;
  if ($speed_score < 0) $speed_score = 0;

/*
  echo "Connection score: " . $connection_score;
  echo "\n<br>Net speed score: "  . $net_speed_score;
  echo "\n<br>Net location score: " . $net_location_score;
  echo "\n<br>Battery score: " . $battery_score;
  echo "\n<br>Speed score: " . $speed_score;
*/

//  $algorithm_weights = get_algorithm_weights();
  
//   $score = $param_connection_weight * $connection_score
//          + $param_net_speed_weight * $net_speed_score
//          + $param_net_location_weight * $net_location_score
//          + $param_battery_weight * $battery_score
//          + $param_speed_weight * $speed_score;

  $score = $algorithm_weights['conn_est'] * $connection_score
         + $algorithm_weights['dl_speed'] * $net_speed_score
         + $algorithm_weights['log_net_location'] * $net_location_score
         + $algorithm_weights['battery'] * $battery_score
         + $algorithm_weights['dev_perf'] * $speed_score;

  return $score;

}
