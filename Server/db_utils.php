<?php
function get_peer_infos()
{
    $error = FALSE;
    $database = pg_connect("dbname=cadist3d_db user=cadist3d");
    pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);
    
    $query = 'SELECT * FROM clients ORDER BY client;';
    $result = pg_query($database, $query);
    if ($result === FALSE) {
        $error = pg_last_error($database);
    } else {
        $res = pg_fetch_all($result);
        pg_free_result($result);
    };
    if (!$res) {
        $res = array();
    };
    if (!$error) {
        $result = pg_query($database, 'SELECT * FROM links;');
        if ($result === FALSE) {
            $error = pg_last_error($database);
        } else {
            $links = pg_fetch_all($result);
            pg_free_result($result);
            $m = count($res);
            $n = count($links);
            for ($i = 0; $i < $m; $i++) {
                $name_i = $res[$i]['client'];
                $links_i = array();
                for ($j = 0; $j < $n; $j++) {
                    if ($links[$j]['a'] == $name_i) {
                        $links_i[$links[$j]['b']] = 1;
                    } else if ($links[$j]['b'] == $name_i) {
                        $links_i[$links[$j]['a']] = 1;
                    };
                };
                $res[$i]['connections'] = array_keys($links_i);
            };
        };
    };
    if (!$error) {
        $query = 'SELECT * FROM assets';
        $result = pg_query($database, $query);
        $assets = array();
        $n = pg_num_rows($result);
        for ($i = 0; $i < $n; $i++) {
            $name_i = pg_fetch_result($result, $i, 'client');
            $asset_i = pg_fetch_result($result, $i, 'asset');
            if (!isset($assets[$name_i])) $assets[$name_i] = array();
            $assets[$name_i][] = $asset_i;
        };
        $n = count($res);
        for ($i = 0; $i < $n; $i++) {
            $name_i = $res[$i]['client'];
            if (isset($assets[$name_i])) {
                $res[$i]['assets'] = $assets[$name_i];
            } else {
                $res[$i]['assets'] = array();
            };
        };
    };
    
    return $res;
}

function get_algorithm_weights()
{
    $database = pg_connect("dbname=cadist3d_db user=cadist3d");
    pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);
    
    $query = 'SELECT * FROM algorithm_weights;';
    $result = pg_query($database, $query);
    $ret = pg_fetch_assoc($result);
//     var_dump ($ret);
    return $ret;
}

function set_algorithm_weights($conn_est, $dl_speed, $log_net_location, $dev_perf, $battery)
{
    $database = pg_connect("dbname=cadist3d_db user=cadist3d");
    pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);
    
    $update = "UPDATE algorithm_weights SET conn_est=" . $conn_est . ", dl_speed=" . $dl_speed . ", log_net_location=" . $log_net_location . ", dev_perf=" . $dev_perf . ", battery=" . $battery .  ";";
    
    return pg_query($database, $update);
}

function get_settings()
{
    $database = pg_connect("dbname=cadist3d_db user=cadist3d");
    pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);
    
    $query = 'SELECT * FROM settings;';
    $result = pg_query($database, $query);
    $ret = pg_fetch_all($result);
//     var_dump ($ret);
    return $ret;
}

function update_settings($new_settings)
{
    $database = pg_connect("dbname=cadist3d_db user=cadist3d");
    pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);
    
    $old_settings = get_settings();
    foreach ($new_settings as $new_setting) {
        $found = FALSE;
        foreach ($old_settings as $old_setting) {
            $old_key = $old_setting['key'];
            if ($new_setting['key'] == $old_key) {
              $new_value = $new_setting['value'];
                $found = TRUE;
                if ($new_value != $old_setting['value']) {
                    $query = "UPDATE settings SET value = '$new_value'"
                           ." WHERE key = '$old_key' ;";
                    $result = pg_query($database, $query);
                }
                break;
            }
        }
    }
}

?>