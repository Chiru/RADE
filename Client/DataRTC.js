// DataRTC.js

/*******************************************************************************
dataRTC: global object for all RTC connections
  methods:
    createConnection(name)
    useSignal(name, signal)
    getSignalingState(name) -- returns the signaling state of the connection
    getChannelState(name)
    sendData(name, data)
    close(name)
  callbacks: -- not called if the callback is set for the connection
    onConnection(name, connection) -- called when new connection is created
    onSignal(name, signal) -- called when signal needs to be sent
    onSignalingStateChange(name, signalingstate)
    onIceStateChange(name, newState, newCount)
    onChannelStateChange(name, newState)
    onOpen(name)
    onData(name, data)
    onClose(name)
    onError(name, error)

object for each data connection
  methods:
    useSignal(signal)
    getSignalingState() -- returns the signaling state of the connection
    getChannelState()
    sendData(data)
    close()
  callbacks:
    onSignal(signal) -- called when signal needs to be sent
    onSignalingStateChange(signalingstate)
    onIceStateChange(newState, newCount)
    onChannelStateChange(newState)
    onOpen()
    onData(data)
    onClose()
    onError(error)
*******************************************************************************/

var dataRTC = (function (undefined) {

  function null_function() { return null; }

  var connections = {};

  var dataRTC = {
    onConnection: null_function,
    onSignal: null_function,
    onSignalingStateChange: null_function,
    onIceStateChange: null_function,
    onOpen: null_function,
    onData: null_function,
    onClose: null_function,
    onError: null_function,
  };

  var iceServers = [
    {url:'stun:stun.l.google.com:19302'},
    /*
    {url:'stun:stun1.l.google.com:19302'},
    {url:'stun:stun2.l.google.com:19302'},
    {url:'stun:stun3.l.google.com:19302'},
    {url:'stun:stun4.l.google.com:19302'},
    {url:'stun:stun01.sipphone.com'},
    {url:'stun:stun.ekiga.net'},
    {url:'stun:stun.fwdnet.net'},
    {url:'stun:stun.ideasip.com'},
    {url:'stun:stun.iptel.org'},
    {url:'stun:stun.rixtelecom.se'},
    {url:'stun:stun.schlund.de'},
    {url:'stun:stunserver.org'},
    {url:'stun:stun.softjoys.com'},
    {url:'stun:stun.voiparound.com'},
    {url:'stun:stun.voipbuster.com'},
    {url:'stun:stun.voipstunt.com'},
    {url:'stun:stun.voxgratia.org'},
    {url:'stun:stun.xten.com'},
    */
    {url:'stun:23.21.150.121'}
  ];

  var getUserMedia = navigator.getUserMedia
                  || navigator.mozGetUserMedia
                  || navigator.webkitGetUserMedia;

  var PeerConnection = window.RTCPeerConnection
                    || window.mozRTCPeerConnection
                    || window.webkitRTCPeerConnection;

  var SessionDescription = window.RTCSessionDescription
                        || window.mozRTCSessionDescription
                        || window.webkitRTCSessionDescription;

  var IceCandidate = window.RTCIceCandidate
                  || window.mozRTCIceCandidate
                  || window.webkitRTCIceCandidate;

  var canSendBlob = !window.hasOwnProperty('webkitRTCPeerConnection');

  function dc_init(connection) {
    var dc = connection._dc;
    dc.onclose = function () {
      connection._dc_onclose();
      if (dc.readyState != connection.state) {
        connection.state = dc.readyState;
        connection.onChannelStateChange(dc.readyState);
      }
    };
    dc.onerror = function () { 
      connection.onError();
      if (dc.readyState != connection.state) {
        connection.state = dc.readyState;
        connection.onChannelStateChange(dc.readyState);
      }
    };
    dc.onmessage = function (ev) {
      if (dc.readyState != connection.state) {
        connection.state = dc.readyState;
        connection.onChannelStateChange(dc.readyState);
      }
      connection.onData(ev.data);
    };
    dc.onopen = function () {
      connection.onOpen();
      if (dc.readyState != connection.state) {
        connection.state = dc.readyState;
        connection.onChannelStateChange(dc.readyState);
      }
    };
  }

  function create_connection(name) {

    var connection = {
      name: name,
      state: "new",
      onSignal: function (signal) { dataRTC.onSignal(name, signal); },
      onSignalingStateChange: function (signalingstate) {
            dataRTC.onSignalingStateChange(name, signalingstate); },
      onIceStateChange: function (newState, newCount) {
            dataRTC.onIceStateChange(name, newState, newCount); },
      onChannelStateChange: function (newState) {
            dataRTC.onChannelStateChange(name, newState); },
      onOpen: function () { dataRTC.onOpen(name); },
      onData: function(data) { dataRTC.onData(name, data); },
      onClose: function() { dataRTC.onClose(name); },
      onError: function(error) { dataRTC.onError(name, error); } };

    var pc = new PeerConnection(
      { iceServers: iceServers },
      { optional: [ {DtlsSrtpKeyAgreement: true},
                    {RtpDataChannels: canSendBlob} ] } );

    connection._pc = pc;

    connection._dc = { readyState: "connecting" };

    pc.ondatachannel = function (ev) {
      connection._dc = ev.channel;
      dc_init(connection);
      connection.onChannelStateChange(connection._dc.readyState);
    };

    pc.onsignalingstatechange = function(ev) {
      connection.onSignalingStateChange(pc.signalingState);
      connection.onChannelStateChange(connection._dc.readyState);
    };

    var iceCandidates = [];
    var nextIceCandidate = 0;

    pc.oniceconnectionstatechange = function () {
      connection.onIceStateChange(pc.iceConnectionState, iceCandidates.length);
      var readyState = connection._dc.readyState;
      connection.onChannelStateChange(readyState);
    };

    pc.onicecandidate = function (ev) {
      if (ev.candidate) {
        iceCandidates.push(ev.candidate);
        connection.onIceStateChange(pc.iceConnectionState,
              iceCandidates.length);
      }
    };

    connection._popIceCandidate = function () {
      if (nextIceCandidate < iceCandidates.length) {
        candidate = iceCandidates[nextIceCandidate];
        nextIceCandidate++;
        return candidate;
      } else {
        return null;
      }
    }

    connection._rewindIceCandidates = function () {
      nextIceCandidate = 0;
      connection._retry = 5;
    }

    connection._dc_onclose = function () {
      this.onClose();
      pc.close();
      delete connections[this.name];
      this.onChannelStateChange("unconnected");
    };

    connection.useSignal = function (signal) {
      dataRTC.useSignal(name, signal);
    };

    connection.getSignalingState = function () {
      return pc.signalingState;
    };

    connection.getChannelState = function () {
      return this._dc.readyState;
    };

    connection.sendData = function (data) {
      this._dc.send(data);
    };

    connection.close = function () {
      this._dc.close();
      if (this._dc.readyState != this.state) {
        this.state = this._dc.readyState;
        this.onChannelStateChange(this._dc.readyState);
      }
    };

    connection._retry = 10;

    return connection;
  }

  dataRTC.useSignal = function (name, signal) {

    var connection;

    if (connections.hasOwnProperty(name)) {
      connection = connections[name];
    } else {
      connection = create_connection(name);
      connections[name] = connection;
      dataRTC.onConnection(name, connection);
      connection._pc.onsignalingstatechange();
    }

    var pc = connection._pc;

    if (!pc) return;

    if (!signal) {
      var localDescription = pc.localDescription;
      if (localDescription && localDescription.type == "offer") {
        var new_signal = JSON.stringify(localDescription);
        connection.onSignal(new_signal);
      } else {
        connection._dc = pc.createDataChannel(name, []);
        dc_init(connection);
        pc.createOffer(
          function (offer) {
            pc.setLocalDescription(
              offer,
              function () {
                connection._rewindIceCandidates();
                var new_signal = JSON.stringify(offer); 
                connection.onSignal(new_signal);
              },
              function (err) {
                connection.onError(err.message);
              });
          },
          function (err) {
            connection.onError(err.message);
          });
      }
    } else if (connection._dc.readyState == 'connecting') {
      var remoteDescription = JSON.parse(signal);
      if (remoteDescription) {
        var type = remoteDescription.type;
        if (type == "offer" || type == "answer") {
          pc.setRemoteDescription(
            new SessionDescription(remoteDescription),
            function () {
              connection._rewindIceCandidates();
              connection.onSignal('{"type":"need-candidate"}');
            },
            function (err) {
show_error("setRemoteDescription("+type+")", err);
              connection.onError(err.message);
            });
        } else {
          if (type == "candidate") {
            pc.addIceCandidate(new IceCandidate(remoteDescription.data));
            connection._retry = 5;
          }
          var candidate = connection._popIceCandidate();
          if (candidate) {
            new_signal = JSON.stringify({type: "candidate", data: candidate});
            connection.onSignal(new_signal);
          } else if ( type == "no-candidate" && pc.remoteDescription != null
                  && pc.remoteDescription.type != ""
                  && (!pc.localDescription || pc.localDescription.type == "") )
          {
            pc.createAnswer(
              function (answer) {
                connection._rewindIceCandidates();
                pc.setLocalDescription(answer);
                var new_signal = JSON.stringify(answer);
                connection.onSignal(new_signal);
              },
              function (err) {
                connection.onError(err.message);
              });
          } else if (type == "need-candidate" && connection._retry > 0) {
            connection._retry--;
            connection.onSignal('{"type":"need-candidate"}');
          } else if (type != "no-candidate") {
            connection.onSignal('{"type":"no-candidate"}');
          } else if (connection._retry > 0) {
            connection._retry--;
            connection.onSignal('{"type":"no-candidate"}');
          }
        }
      }
    }

  };

  dataRTC.createConnection = function (name) {
    dataRTC.useSignal(name, null);
  };

  dataRTC.getSignalingState = function (name) {
    if (connections.hasOwnProperty(name)) {
      return connections[name].getSignalingState();
    } else {
      return "unconnected";
    }
  };

  dataRTC.getChannelState = function (name) {
    if (connections.hasOwnProperty(name)) {
      return connections[name].getChannelState();
    } else {
      return "unconnected";
    }
  };

  dataRTC.sendData = function (name, data) {
    if (connections.hasOwnProperty(name)) {
      connections[name].sendData(data);
    }
  };

  dataRTC.close = function (name) {
    if (connections.hasOwnProperty(name)) {
      connections[name].close();
      delete connections[name];
    }
  };

  return dataRTC;

  function show_error(info, err) {
    var msg;
    if (typeof(err) == 'string') {
      msg = err;
    } else if (err.hasOwnProperty('message')) {
      msg = err.message;
    } else if (typeof(err) == 'object') {
      msg = JSON.stringfy(err);
    }
    alert("error -- "+info+"\n"+msg);
  }

})();