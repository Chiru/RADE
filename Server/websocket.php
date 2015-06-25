<?php // websocket library

/* =============================================================================
calling order:
      $websocket = websocket();
      websocket_set_address($websocket, "123.45.67.89"); // usually not needed
      websocket_set_port($websocket, 1234); // optional
      websocket_set_origin($websocket, "http://this.site"); // if check wanted
      websocket_set_message_limit($websocket, 1000); // optional
      websocket_on_client($websocket, handle_client);
      websocket_on_message($websocket, handle_message);
      websocket_on_close($websocket, handle_close);
      websocket_run($websocket);
callback functions:
        note that functions must be declared with an & before the first argument
        in all functions $client is a small int to identify the client
        the name of each function is not significant
        any name or an anonymous function can be used
      function handle_client(&$websocket, $client)
            called when a connection is opened to a new client
      function handle_message(&$websocket, $client, $message)
            called when received a new text message from the client
      function handle_close(&$websocket, $client)
            called when the connection is closed
            messages can be sent but it is possible that they go nowhere
callback functions may call:
        in all functions that use $client it is a small int to identify the
        client, either the same as the argument of the callback or another one
      $clients = websocket_get_clients($websocket);
            gets all connected clients as an array of integers
      websocket_send($websocket, $client, $message);
            sends a text message to the client
      websocket_send_all($websocket, $message);
            sends a text message to all clients
      websocket_send_others($websocket, $client, $message);
            sends a text message to all clients except $client
      websocket_close($websocket, $client);
            disconnects the client
      websocket_shutdown($websocket);
            disconnects all clients and returns from websocket_run after the
            completion of the calling callback
============================================================================= */

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

// =============================================================================

define("WEBSOCKET_NEW", 0); // no socket
define("WEBSOCKET_OPEN", 1); // hanshake ok, can send and receive
define("WEBSOCKET_CLOSING", 2); // closing, normal close message needed
define("WEBSOCKET_FAILING", 3); // closing, error close message needed
define("WEBSOCKET_CLOSED", 4); // closed
define("WEBSOCKET_REJECTED", 5); // failed to open

// =============================================================================

function websocket() {
  $websocket = array();
  $websocket['socket'] = array();
  $websocket['status'] = array(WEBSOCKET_NEW);
  $websocket['buf'] = array("");
  websocket_set_address($websocket, "0.0.0.0");
  websocket_set_port($websocket, 1234);
  websocket_set_message_limit($websocket, 1000);
  websocket_on_client($websocket, function($ws, $client) {});
  websocket_on_message($websocket, function($ws, $client, $data) {});
  websocket_on_close($websocket, function($ws, $client) {});
  return $websocket;
}

function websocket_set_address(&$websocket, $address) {
  $websocket['adr'] = $address;
}

function websocket_set_port(&$websocket, $port) {
  $websocket['port'] = (int) $port;
}

function websocket_set_origin(&$websocket, $origin) {
  $websocket['origin'] = $origin;
}

function websocket_set_message_limit(&$websocket, $limit) {
  $websocket['lim'] = (int) $limit;
}

function websocket_on_client(&$websocket, $handler) {
  $websocket['onclient'] = $handler;
}

function websocket_on_message(&$websocket, $handler) {
  $websocket['onmessage'] = $handler;
}

function websocket_on_close(&$websocket, $handler) {
  $websocket['onclose'] = $handler;
}

function websocket_run(&$websocket) {

  $websocket['status'][0] = WEBSOCKET_NEW;

  // socket creation
  $masterSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  socket_set_option($masterSocket, SOL_SOCKET, SO_REUSEADDR, 1);

  if (!is_resource($masterSocket)) {
    $websocket['error'] = "socket_create() failed: "
           . socket_strerror(socket_last_error());
    return FALSE;
  };

  if (!socket_bind($masterSocket, $websocket['adr'], $websocket['port'])) {
    $websocket['error'] = "socket_bind() failed: "
           . socket_strerror(socket_last_error());
    return FALSE;
  };

  if(!socket_listen($masterSocket, 20)) {
    $websocket['error'] = "socket_listen() failed: "
           . socket_strerror(socket_last_error());
    return FALSE;
  };

  $websocket['socket'] = array($masterSocket);
  $websocket['status'][0] = WEBSOCKET_OPEN;

  $lim = $websocket['lim'] + 14; // max message size + max headers

  while($websocket['status'][0] < WEBSOCKET_CLOSED) {
    $changed = $websocket['socket'];
    $write = NULL;
    $except = NULL;
    socket_select($changed, $write, $except, NULL);
    foreach($changed as $changedSocket) {
      if ($changedSocket == $masterSocket) {
        // new client
        $clientSocket = socket_accept($masterSocket);
        if($clientSocket < 0) {
          // socket_accept() failed
        } else {
          $client = 1;
          while (isset($websocket['socket'][$client])) $client++;
          $websocket['socket'][$client] = $clientSocket;
          $websocket['status'][$client] = WEBSOCKET_NEW;
          $websocket['buf'][$client] = "";
        };
      } else {
        // clients who are connected with server will enter into this case
        // first client will handshake with server
        // and then exchange data with server
        $clientSocket = $changedSocket;
        $client = array_search($clientSocket, $websocket['socket']);
        $clientStatus = $websocket['status'][$client];
        $bytes = @socket_recv($clientSocket, $newdata, $lim, MSG_DONTWAIT);
        if ($bytes < 1) {
          if ($clientStatus < WEBSOCKET_CLOSING) {
            // error or unexpected termination
            $clientStatus = WEBSOCKET_FAILING;
          };
        } else if ($clientStatus == WEBSOCKET_NEW) {
          if (websocket_handshake($websocket, $client, $newdata)) {
            $websocket['status'][$client] = WEBSOCKET_OPEN;
            $websocket['onclient']($websocket, $client);
            continue;
          } else {
            // handshake failed
            $clientStatus = WEBSOCKET_REJECTED;
          };
        } else {
          $msg = websocket_decode($websocket, $client, $newdata);
          $opcode = $msg['opcode'];
          $data = $msg['data'];
          if ($opcode < 0) { // no message
          } else if ($opcode == 1) { // text message
            $websocket['onmessage']($websocket, $client, $data);
            continue;
          } else if ($opcode == 8) { // close
            if ($clientStatus < WEBSOCKET_CLOSING) {
              $clientStatus = WEBSOCKET_CLOSING;
            };
          } else if ($opcode == 9) { // ping
            socket_write($clientSocket, websocket_encode(10, $data)); // pong
          } else if ($opcode == 10) { // pong -- no action
          } else { // error
            if ($clientStatus < WEBSOCKET_FAILING) {
              $clientStatus = WEBSOCKET_FAILING;
            }
          };
        };
        $websocket['status'][$client] = $clientStatus;
      };
    };
    if ($websocket['status'][0] > WEBSOCKET_OPEN) {
      $websocket['status'][0] = WEBSOCKET_CLOSED;
      $changed = array();
      foreach ($websocket['socket'] as $client => $clientSocket) {
        if ($clientSocket != NULL && $clientSocket != $masterSocket) {
          $changed[] = $clientSocket;
          if ($websocket['status'][$client] < WEBSOCKET_OPEN) {
            $websocket['status'][$client] = WEBSOCKET_REJECTED;
          } else if ($websocket['status'][$client] < WEBSOCKET_CLOSING) {
            $websocket['status'][$client] = WEBSOCKET_CLOSING;
          };
        };
      };
    };
    foreach($changed as $clientSocket) {
      if ($clientSocket != $masterSocket) {
        $client = array_search($clientSocket, $websocket['socket']);
        $clientState = $websocket['status'][$client];
        if ($clientState > WEBSOCKET_OPEN) {
          if ($clientState < WEBSOCKET_REJECTED) {
            $websocket['onclose']($websocket, $client); // call close handler
          };
          if ($clientState == WEBSOCKET_CLOSING) {
            @socket_write($clientSocket, websocket_encode(8));
          } else if ($clientState == WEBSOCKET_FAILING) {
            @socket_write($clientSocket, websocket_encode(8), 1011); // error
          };
          @socket_close($clientSocket);
          unset($websocket['socket'][$client]);
          unset($websocket['status'][$client]);
          unset($websocket['buf'][$client]);
        };
      };
    };
  };

  // CLose the master socket
  @socket_close($masterSocket);
  $websocket['status'] = array(0);
  $websocket['buf'] = array("");
};

function websocket_get_clients(&$websocket) {
  $clients = array();
  foreach ($websocket['status'] as $client => $status) {
    if ($client > 0 && $status < WEBSOCKET_CLOSING) {
      $clients[] = $client;
    };
  };
  return $clients;
}

function websocket_send(&$websocket, $client, $data) {
  if ( isset($websocket['socket'][$client])
    && $websocket['status'][$client] < WEBSOCKET_CLOSED )
  {
    $clientSocket = $websocket['socket'][$client];
    socket_write($clientSocket, websocket_encode(1, $data));
  };
}

function websocket_send_others(&$websocket, $client, $data) {
  $clients = websocket_get_clients($websocket);
  foreach($clients as $other) {
    if ($other != $client) {
      websocket_send($websocket, $other, $data);
    };
  };
}

function websocket_send_all(&$websocket, $data) {
  websocket_send_others($websocket, 0, $data);
}

function websocket_close(&$websocket, $client) {
  if ($websocket['status'][$client] < WEBSOCKET_CLOSING) {
    $websocket['status'][$client] = WEBSOCKET_CLOSING;
  };
}

function websocket_shutdown(&$websocket) {
  $websocket['status'][0] = WEBSOCKET_CLOSING;
}

function websocket_decode(&$websocket, $client, $newdata) {
  $buf = $websocket['buf'][$client] . $newdata;
  $lim = $websocket['lim'];
  $len = strlen($buf);
  $opcode = -1;
  $data = "";
  $msglen = 0;
  if ($len > 1) {
    if ((ord($buf[0]) & 0xF0) != 0x80) {
      // fragmented messages not supported
      return array( 'opcode' => 8, 'data' => "" );
    }
    $opcode = ord($buf[0]) & 0x0f;
    $masked = ((ord($buf[1]) & 0x80) != 0);
    $maskstart = 2;
    $datalen = ord($buf[1]) & 0x7f;
    if ($datalen == 126) {
      $maskstart = 4;
      if ($len < 4) {
        return array( 'opcode' => -1, 'data' => "" );
      } else {
        $datalen = (ord($buf[2]) << 8) | ord($buf[3]);
      };
    } else if ($datalen == 127) {
      $maskstart = 10;
      if ($len < 10) {
        return array( 'opcode' => -1, 'data' => "" );
      } else {
        $datalen = (ord($buf[2]) << 56)
                 | (ord($buf[3]) << 48)
                 | (ord($buf[4]) << 40)
                 | (ord($buf[5]) << 32)
                 | (ord($buf[6]) << 24)
                 | (ord($buf[7]) << 16)
                 | (ord($buf[8]) << 8)
                 | (ord($buf[9])) ;
      };
    };
    if ($datalen > 0 && !$masked) {
      return array( 'opcode' => 8, 'data' => "" );
    };
    $datastart = ($masked ? $maskstart + 4 : $maskstart);
    $msglen = $datastart + $datalen;
    if ($datalen > $lim) {
      // Message too big
      return array( 'opcode' => 8, 'data' => "" );
    } else if ($msglen > $len) {
      return array( 'opcode' => -1, 'data' => "" );
    } else {
      $data = substr($buf, $datastart, $datalen);
      if ($masked) {
        $mask = substr($buf, $maskstart, 4);
        for ($i = 0; $i < $datalen; $i++) {
          $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i&3]));
        }
      }
      $opcode = $opcode;
        // 1 = text; 2 = binary; 8 = close; 9 = ping; 10 = pong;
    };
  };
  $websocket['buf'][$client] = substr($buf, $msglen);
  return array( 'opcode' => $opcode, 'data' => $data );
}

function websocket_encode($opcode, $data = "") {
  $msg = chr(0x80 | $opcode);
  $length = strlen($data);
  if ($length < 126) {
    $msg .= chr($length);
  } else if ($length < 65536) {
    $msg .= chr(126) . chr($length >> 8) . chr($length & 0xff);
  } else {
    $msg .= chr(127) . chr($length >> 56) . chr(($length >> 48) & 0xff)
          . chr(($length >> 40) & 0xff) . chr(($length >> 32) & 0xff)
          . chr(($length >> 24) & 0xff) . chr(($length >> 16) & 0xff)
          . chr(($length >> 8) & 0xff) . chr($length & 0xff);
  };
  $msg .= $data;
  return $msg;
}

function websocket_handshake(&$websocket, $client, $headers) {

  if(preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $headers, $match)) {
    $version = $match[1];
  } else {
    // The client doesn't support WebSocket
    return false;
  }

  if ($version == 13) {
    // Extract header variables
    if(preg_match("/GET (.*) HTTP/", $headers, $match)) $root = $match[1];
    if(preg_match("/Host: (.*)\r\n/", $headers, $match)) $host = $match[1];
    if(preg_match("/Origin: (.*)\r\n/", $headers, $match)) $origin = $match[1];
    if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $match)) {
      $key = $match[1];
    };

    if (isset($websocket['origin']) && $origin != $websocket['origin']) {
      return false;
    };

    $acceptKey = $key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    $acceptKey = base64_encode(sha1($acceptKey, true));

    $upgrade = "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: $acceptKey"
             . "\r\n\r\n";
    socket_write($websocket['socket'][$client], $upgrade);
    return true;
  } else {
    // WebSocket version 13 required
    return false; 
  };
}
