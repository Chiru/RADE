<?php  /*     >php -q Server.php -- port               */
       /* where port is optional port number           */
       /*       by default the value below is used     */

$port = "1234"; 

$maxMessageSize = 2000;

/* =============================================================================
Commands are sent over websocket as JSON encoded command objects. For each
command a response is sent over websocket as a JSON encoded response object.

Each commad object must have an action property. Depending on the action, other
properties may be required. Extraneous properties are ignored.

Each response object has properties that depend on the action and the result of
the action. If (and only if) an there was an error there is an "error" property
giving the error message as a string. Unless there was a syntax error in the
command, the response also has an "action" property that is a copy of the
"action" property of the command object.

Possibe commands are:
  "set": creates or updates the record for the specified client; the property
         "client" is required; the optional property "data" is an object that
         specifies values of record fields; if "data" contains another "client"
         property the client is renamed; fields not specified will keep their
         old values (or lack of any)
  "get": reads records; optional properties "client" and "data" may restrict the
         set of the records; the "data" object may contain a "client" property
         but two different client specifications are not accepted; if neither
         "client" nor "data" is specified, all records are given; the result
         object has a "res" property that is an array with the selected (or all)
         records; if no records match the "client" and "data" properties of the
         command, the "res" property is an empty array
  "ask_asset": determine how an asset should be loaded; required properties are
         "client" to identify the client that wants the asset and "asset" to
         identify the required asset
  "delete": deletes a record; the "client" property is required to identify the
         record to be deleted; no "data" property is allowed
  "clear": deletes all records; neither "client" nor "data" property is allowed
  "name": if no "client" property is set, asks for a name for a client; proposed name is returned as the "ans"
         property of the response object; if the "client" property is set,
         stores the name as the name of the sending client
  "say": send a message to another client or all other clients; the property
         "client" is optional; if present, the message is sent to that client;
         the message can be delivered only if the client has identified itself
         with a "name" command; if no "client" property is present the message
         is sent to all connected clients; all other properties are copied to
         the object that is delivered to the receiving client(s)
  "version": asks the version of this program; the version is returned as the
         "ans" property of the response object
  "log": adds a message to server's log file Server_log.txt
         the following properties are written if present:
         "client": to identify the client
         "message": a message text as a string
         "data": written as JSON-encoded
  "clear_log": deletes contents of the log file
  "terminate": stops the server  

Possible properties of a command object are:
  "action": required; specifies the action
  "client": required for "set", optional for "get" and "log";
         specifies the client
  "data": required for "set", optional for "get"; fields of the record;
         optional for "log", may contain any data
  "message": optional for "log"; any string to be logged; required for "say"

Possible properties of a response object are:
  "action": the action that was performed or attempted
  "error": the error message
  "ans": the answer as a string for actions that ask for one
  "res": the records from a get action as an array; may contain zero, one or
         more records
  "clients": in an answer to "ask_asset" a (possibly empty) list of client names

The "data" property of a "set" command and a "get" response may have a
"connections" property. If present, it is an array of the names of the other
clients that are connected to the client.

A message from another client is delivered like a response. The "action"
property is "said", the "client" property the sending client, and all other
properties are copied from the origial "say" command.
============================================================================= */

header("Content-type: text/plain; text/html; charset=UTF-8", TRUE);

// =============================================================================

$console_enabled = FALSE;

// =============================================================================

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

setlocale(LC_ALL, "C.UTF-8");
date_default_timezone_set("Europe/Helsinki");

// =============================================================================

include('websocket.php');
include('db_utils.php');
include('score.php');

// =============================================================================
// for function handle_message

$fragments = array();

// =============================================================================

function error_handler($errno, $errstr=NULL, $errfile=NULL,
       $errline=NULL, $errcontext=NULL)
{
  global $console_enabled;
  if ($errno > 0) {
    $msg = $errstr."\n";
    if ($errfile) { $msg .= "  File: ".$errfile."\n"; }
    if ($errline) { $msg .= "  Line: ".$errline."\n"; }
    console("*** Error: ".$msg."***************");
  };
}

set_error_handler("error_handler");

// =============================================================================

if (isset($_SERVER['argc']) && isset($_SERVER['argv'])) {
  $argc = $_SERVER['argc'];
  $argv = $_SERVER['argv'];
  for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg[0] != '-') {
      $portPart = $arg;
      if (ctype_digit($arg) && strlen($arg) < 6) {
        if ((int)$arg < 65536) {
          $port = (int)$arg;
        }
      }
    }
  }
} else if (isset($_REQUEST) && isset($_REQUEST['port'])) {
  $port = (int)$_REQUEST['port'];
}

// =============================================================================

$database = pg_connect("dbname=cadist3d_db user=cadist3d");
pg_set_error_verbosity($database, PGSQL_ERRORS_VERBOSE);

// =============================================================================
// get algorithm weights from database

use_algorithm_weights(get_algorithm_weights());

// =============================================================================

$websocket = websocket();

websocket_set_port($websocket, $port);
websocket_set_message_limit($websocket, $maxMessageSize);
websocket_on_client($websocket, 'handle_client');
websocket_on_message($websocket, 'handle_message');
websocket_on_close($websocket, 'handle_close');

console("===\nServer started on port $port\n\n");

websocket_run($websocket);

if (isset($websocket['error'])) {
  console("=## Error: ".$websocket['error']);
}

pg_close($database);

console("===");
$console_enabled = TRUE;
console("Server terminated");

// =============================================================================

$client_names = array();
$client_numbers = array();

function set_client($number, $name) {
  global $client_names;
  global $client_numbers;
  $old_number = NULL;
  $old_name = NULL;
  if (isset($name) && isset($client_numbers[$name])) {
    $old_number = $client_numbers[$name];
  };
  if (isset($number) && isset($client_names[$number])) {
    $old_name = $client_names[$number];
  };
  if (isset($old_number)) {
    $client_names[$old_number] = NULL;
  };
  if (isset($old_name)) {
    $client_numbers[$old_name] = NULL;
  };
  if (isset($name)) {
    $client_numbers[$name] = $number;
  };
  if (isset($number)) {
    $client_names[$number] = $name;
  };
}

// =============================================================================

function handle_client(&$websocket, $client) {
  console("= Client $client connected");
}

// =============================================================================

function handle_message(&$websocket, $client, $message) {

  global $database;
  global $client_names;
  global $client_numbers;
  global $fragments;

echo "$client |==> $message <==|\n";

  if ($message[0] == '?') {
    $resp = file_get_contents("test.dat");
    websocket_send($websocket, $client, '!');
    websocket_send($websocket, $client, $resp);
    return;
  }

  if ($message[0] == "-") {
    if (!array_key_exists($client, $fragments)) {
      $fragments[$client] = "";
    };
    $fragments[$client] .= substr($message, 1);
    websocket_send($websocket, $client, "&");
    return;
  } else if ($message[0] == "%") {
    if ( array_key_exists($client, $fragments)
         && $fragments[$client] != "" )
    {
      websocket_send($websocket, $client, "&");
    };
    return;
  } else if ($message[0] == "+") {
    if ( array_key_exists($client, $fragments)
         && $fragments[$client] != "" )
    {
      $message = $fragments[$client].substr($message, 1);
      $fragments[$client] = "";
    } else {
      $message = substr($message, 1);
    }
  };

  $args = NULL;
  $action = NULL;
  $name = NULL;
  $data = NULL;
  $links = NULL;
  $assets = NULL;
  $asset = NULL;

  $error = NULL;
  $ans = NULL;
  $res = NULL;
  $clients = NULL;

  if ($message[0] != '{') {
    $error = 'an object expected';
    $args = array();
  } else {
    $args = json_decode($message, true);
    if (!$args) {
      $error = 'JSON error '.json_last_error().': '.json_last_error_msg();
      $args = array();
    };
  };

  if (isset($args['action'])) {
    $action = $args['action'];
  } else if (!$error) {
    $error = 'no "action" property specified';
  };

  if (isset($args['client'])) {
    $name = $args['client'];
  };

  if (!$error && isset($args['data'])) {
    $data = $args['data'];
    if (!is_array($data)) {
      $error = 'property "data" is not an object';
    } else {
      if (!$name && isset($data['client'])) {
        $name = $data['client'];
      };
      if (isset($data['connections'])) {
        $links = array();
        foreach ($data['connections'] as $other) {
          $links[$other] = TRUE;
        };        
        unset($data['connections']);
      };
      if (isset($data['assets'])) {
        $assets = array();
        foreach ($data['assets'] as $asset) {
          $assets[$asset] = TRUE;
        };
        unset($data['assets']);
      };
    };
  };

  if (isset($args['asset'])) {
    $asset = $args['asset'];
  }

  if ($error) {
    // error, do nothing

  } else if (!is_string($action)) {
    $error = 'action is not a string';

  } else if ($action == 'set') {
    if (!$name) {
      $error = 'no "client" specified';
    } else {
      $query = "";
      $result = FALSE;
      $esc_name = escape_literal($name);
      if (isset($links)) {
        $query = 'DELETE FROM links WHERE a = '.$esc_name
                .' OR b = '.$esc_name.';';
        $newlinks = "";
        foreach ($links as $other => $val) {
          $newlinks .= ', ('.$esc_name.','.escape_literal($other).')';
        };
        if ($newlinks != "") {
          $query .= 'INSERT INTO links (a, b) VALUES'.substr($newlinks, 1).';';
        };
        $result = pg_query($database, $query);
        if ($result === FALSE) {
          $error = pg_last_error($database);
        };
      };
      if ($data && !$error) {
        $query = '';
        foreach ($data as $key => $val) {
          $query .= ', '.pg_escape_identifier($key)
                   .' = '.escape_literal($val);
        };
        if (strlen($query) > 1) {
          $query = 'UPDATE clients SET'.substr($query, 1).' WHERE client = '
                  .escape_literal($name).';';
          $result = pg_query($database, $query);
          if (!$result) {
            $error = pg_last_error($database);
          } else if (pg_affected_rows($result) < 1) {
            $result = FALSE;
          };
        };
      };
      if (!$error && !$result) {
        if ($name) {
          if (!$data) {
            $data = array('client' => $name);
          } else if (!isset($data['client'])) {
            $data['client'] = $name;
          };
        };
        if (isset($data['client'])) {
          $keys = '';
          $vals = '';
          foreach ($data as $key => $val) {
            $keys .= ', '.pg_escape_identifier($key);
            $vals .= ', '.escape_literal($val);
          };
          $query = 'INSERT INTO clients ('.substr($keys, 2)
                  .') VALUES ('.substr($vals, 2).');';
          $result = pg_query($database, $query);
          if (!$result) {
            $error = pg_last_error($database);
          };
        };
      };
    };

  } else if ($action == 'get') {
    if ($name) {
      if (!isset($data['client'])) {
        $data['client'] = $name;
      } else if ($data['client'] != $name) {
        $error = 'two clients specified';
      };
    };
    $res = NULL;
    if (!$error) {
      $filter = '';
      if (!empty($data)) {
        foreach ($data as $key => $val) {
          $filter .= ' AND '.pg_escape_identifier($database, $key)
                     .' = '.escape_literal($val);
        };
        $filter = ' WHERE'.substr($filter, 4);
      };
      $query = 'SELECT * FROM clients'.$filter.';';
      $result = pg_query($database, $query);
      if ($result === FALSE) {
        $error = pg_last_error($database);
      } else {
        $res = pg_fetch_all($result);
        pg_free_result($result);
      };
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
      if ($name) {
        $query .= ' WHERE "client" = '.escape_literal($name);
      };
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

  } else if ($action == 'ask_asset') {
    $logmsg = NULL;
    $settings = get_settings();
    $random = TRUE;
    foreach ($settings as $setting) {
      if ($setting['key'] == 'random') {
        $val = $setting['value'];
        $random = ($val[0] != 'F' && $val[0] != 'f');
      };
    };
    $clients = array();
    $info = NULL;
    $result = pg_query($database, 'SELECT * FROM clients;');
    if ($result === FALSE) {
      $error = pg_last_error($database);
    } else {
      $info = pg_fetch_all($result);
      pg_free_result($result);
      $m = count($info);
      $query = 'SELECT * FROM links;';
      $result = pg_query($database, $query);
      if ($result === FALSE) {
        $error = pg_last_error($database);
      } else {
        $links = pg_fetch_all($result);
        pg_free_result($result);
        $n = count($links);
        $my_i = -1;
        $weights = get_algorithm_weights();
        use_algorithm_weights($weights);
        for ($i = 0; $i < $m; $i++) {
          $name_i = $info[$i]['client'];
          if ($name_i == $name) $my_i = $i;
          $links_i = array();
          for ($j = 0; $j < $n; $j++) {
            if ($links[$j]['a'] == $name_i) {
              $links_i[$links[$j]['b']] = 1;
            } else if ($links[$j]['b'] == $name_i) {
              $links_i[$links[$j]['a']] = 1;
            };
          };
          $info[$i]['connections'] = array_keys($links_i);
        };
        if ($my_i >= 0) {
          $scores = array();
          for ($i = 0; $i < $m; $i++) {
            $info_i = $info[$i];
            if ($i != $my_i && isset($client_numbers[$info_i['client']])) {
              if ($random) {
                $score_i = mt_rand(0, mt_getrandmax());
              } else {
                $score_i = score($info[$my_i], $info_i);
              };
              if ($score_i >= 0) {
                $scores[$info_i['client']] = $score_i;
              };
            };
          };
          arsort($scores);
          $clients = array_keys($scores);
        };
        if ($random) {
          $logmsg = "asset source random selection";
        } else {
          $logmsg = "asset source selection";
        };
      };
    };
    if ($logmsg != NULL) {
      $n = count($clients);
      if ($n > 5) {
        $clients = array_slice($clients, 0, 5);
      };
      $n = count($clients);
      $log_data = array();
      $log_data['client'] = $name;
      $log_data['weights'] = $weights;
      if ($n > 0) {
        $sum_battery = 0;
        $sum_speed = 0;
        for ($i = 0; $i < $m; $i++) {
          if (array_search($info[$i]['client'], $clients) !== FALSE) {
            if ($info[$i]['battery_charging'][0] == 't') {
              $sum_battery += 100;
            } else {
              $sum_battery += $info[$i]['battery_level'];
            };
            $sum_speed += $info[$i]['client_processing_speed'];
          };
        };
        $log_data['avg_battery'] = number_format($sum_battery / $n, 0, '.','');
        $log_data['avg_speed'] = number_format($sum_speed / $n, 1, '.', '');
      };
      $log_data['peers'] = $clients;
      log_message($name, $logmsg, $log_data);
    };

  } else if ($action == 'delete') {
    if (!isset($name)) {
      $error = 'no "client" property';
    } else if (isset($data)) {
      $error = 'extraneous "data" property';
    } else {
      $esc_name = escape_literal($name);
      $query = 'DELETE FROM clients WHERE client = '.$esc_name.';';
      $result = pg_query($database, $query);
      if ($result === FALSE) {
        $error = pg_last_error($database);
      } else {
        if (pg_affected_rows($result) < 1) {
          $error = 'client '.$args['client'].' not found';
        };
        pg_free_result($result);
      };
      $query = 'DELETE FROM links WHERE a = '.$esc_name
               .' OR b = '.$esc_name.';';
      $result = pg_query($database, $query);
      if ($result === FALSE) {
        $error = pg_last_error($database);
      };
    };

  } else if ($action == 'clear') {
    if (isset($name)) {
      $error = 'extraneous "client" property';
    } else if (isset($data)) {
      $error = 'extraneous "data" property';
    } else {
      $result = pg_query($database, 'DELETE FROM clients;');
      if ($result === FALSE) {
        $error = pg_last_error($database);
      } else {
        $ans = pg_affected_rows($result);
        pg_free_result($result);
      };
      pg_query($database, "VACUUM FULL clients;");
    };

  } else if ($action == 'name') {
    if (isset($name)) {
      set_client($client, $name);
    } else {
      $ans = NULL;
      $guess = -1;
      while ($ans == NULL) {
        if ($guess < 0) {
          if (isset($client_names[$client])) {
            $ans = $client_names[$client];
          };
        } else if ($guess == 0) {
          $ans = 'client_'.$client;
        } else {
          $letter = chr(0x61 + (($guess - 1) % 26));
          $num = ($guess - 1) / 26;
          $ans = 'client_'.$client.$letter.($num > 0 ? $num : '');
          if (isset($client_numbers[$ans])) {
            if ($client_numbers[$ans] != $client) {
              $ans = NULL;
            };
          };
        };
        $guess++;
      };
    };

  } else if ($action == 'version') {
    $ans = date("Y-m-d G:i:s", filemtime("Server.php"));

  } else if ($action == 'log') {
    log_message($name, $args['message'], $data);

  } else if ($action == 'clear_log') {
    $text = date("Y-m-d G:i:s")."\n";
    $text .= "========================================\n";
    file_put_contents("Server_log.txt", $text, LOCK_EX);

  } else if ($action == 'say') {
    if (!isset($client_names[$client])) {
      $error = 'Client name not set';
    } else {
      $msg = $args;
      $msg['action'] = "said";
      $msg['client'] = $client_names[$client];
      $msge = json_encode($msg);
      if (isset($name)) {
        if (isset($client_numbers[$name])) {
          websocket_send($websocket, $client_numbers[$name], $msge);
        } else {
          $error = 'Client "'.$name.'" not known';
        };
      } else {
        websocket_send_others($websocket, $client, $msge);
      };
    };

  } else if ($action == 'terminate') {
    websocket_shutdown($websocket); // after response is sent

  } else if ($action == 'nop') {
    // no action
  } else {
    $error = 'action '.$action.' not understood by server';
  };

  $resp = array();

  if (isset($error)) {
    $resp['error'] = $error;
console("<*> "+$error);
  };

  if (isset($action)) {
    $resp['action'] = $action;
  };

  if (isset($ans)) {
    $resp['ans'] = $ans;
  };

  if (isset($res)) {
    $resp['res'] = $res;
  };

  if (isset($asset)) {
    $resp['asset'] = $asset;
  };

  if (isset($clients)) {
    $resp['clients'] = $clients;
  };

  $resp = json_encode($resp);

  websocket_send($websocket, $client, $resp);
}

// =============================================================================

function log_message($name, $message, $data) {
  $text = date("Y-m-d G:i:s");
  if ($name) {
    $text .= " ".$name;
  };
  $text .= "\n";
  if (isset($message)) {
    $text .= $message."\n";
  };
  if ($data) {
    $text .= json_encode($data)."\n";
  };
  $text .= "========================================\n";
  file_put_contents("Server_log.txt", $text, FILE_APPEND | LOCK_EX);
}

// =============================================================================

function escape_literal($value) {
  if ($value === NULL) return "NULL";
  if ($value === FALSE) return "FALSE";
  if ($value === TRUE) return "TRUE";
  return pg_escape_literal($value);
}

// =============================================================================

function handle_close(&$websocket, $client) {
  console("= Client $client closed");
  set_client($client, NULL);
}

// =============================================================================

function console($text) {
global $console_enabled;
/**/$console_enabled = TRUE; // use this to show all output
if ($console_enabled) {
  $img = "";
  //$img = date("Y-m-d G:i:s "); // use this to show output time
  $indent = str_repeat(" ", strlen($img));
  $len = strlen($text);
  for ($i = 0; $i < $len; $i++) {
    $c = ord($text[$i]);
    if ($c == 10) {
      $img .= "\n".$indent;
    } else if ($c < 0x10) {
      $img .= '%0'.dechex($c);
      if ($c == 11 || $c == 12) {
        $img .= "\n";
      };
    } else if ($c < 0x20 || $c > 0x7e) {
      $img .= '%'.dechex($c);
    } else {
      $img .= chr($c);
    };
  };
  echo $img . "\n";
  flush();
} else if ($text[0] == '*') { // show important text even if console disabled
  $img = explode("\n", $text, 2)[0];
  echo $img . "\n";
};
}

// =============================================================================
