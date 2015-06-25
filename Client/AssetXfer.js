// AssetXfer.js
"use strict";

function AssetXfer () {


var assetXfer = {
  serverSuffix: "",
  noSuffix: false,
  onChunkNeeded: function(peer, asset, fst, length) {},
  onDone: function(asset, assetBlob, error) {},
  num_chunks: 0,
  num_chunks_from_peers: 0,
  assetBlob: null };

//Global variables
var max_active_peer_downloads = 5;
var num_active_peer_downloads = 0;
var num_active_ASE_downloads = 1; // <-><-><-><-><-><->
//var num_active_ASE_downloads = 0;
//var ASE_initial_allocation = 0.5;
//var ASE_continuation_allocation = 0.5;
var ASE_initial_allocation = 0.25;
var ASE_continuation_allocation = 0.25;
var num_chunks = 0;
var num_started_chunks = 0;
var num_completed_chunks = 0;
var chunk_size_bytes = 256000;
//var chunk_size_bytes = 5000000;
var file_size_bytes;
var asset_base_URL = "Assets";
var chunked_asset_data = [];
var asset_name = "";
var peer_ranks = {};
var ranked_peer_list = [
/*
  {name: "peer1", downloading: false, connected: false},
  {name: "peer2", downloading: false, connected: true},
  {name: "peer3", downloading: false, connected: true},
  {name: "peer4", downloading: false, connected: true},
  {name: "peer5", downloading: false, connected: true}
*/
];
var ase_info = {
  name: "ASE",
  downloading: false,
  asset_URL: "Assets/" };

assetXfer.setAsset = function (new_asset_name, serverSuffix) {
  assetXfer.serverSuffix = serverSuffix;
  asset_name = new_asset_name;
  ase_info.asset_URL = asset_base_URL + serverSuffix + "/"
          + new_asset_name + ".dat";
};

assetXfer.setPeers = function (new_ranked_peer_list) {
  ranked_peer_list = new_ranked_peer_list;
  peer_ranks = {};
  for (var i = 0; i < new_ranked_peer_list.length; i++) {
    peer_ranks[new_ranked_peer_list[i].name] = i;
  }
};

assetXfer.start = function(mode) {
  if (mode == 1 || ranked_peer_list.length == 0) { // from server
    num_active_ASE_downloads = 0;
    max_active_peer_downloads = 0;
  } else if (mode == 2) { // from peers
    num_active_ASE_downloads = 1;
  } else { // from server and peers
    num_active_ASE_downloads = 0;
  }
  initDownloadScheduler(asset_name, chunk_size_bytes,
      max_active_peer_downloads);
  scheduleChunkDownloads();            
};

assetXfer.continue = function() {
  scheduleChunkDownloads();            
};

assetXfer.handle_chunk = function (peer, chunkBlob, fst, length) {
  assetXfer.num_chunks_from_peers++;
  var peer_info = ranked_peer_list[peer_ranks[peer]];
  var fstx = fst / chunk_size_bytes;
  var lstx = Math.floor((fst + length - 1) /  chunk_size_bytes);
  handleCompletedChunks(fstx, lstx, asset_name, peer_info, chunkBlob);
};

function initDownloadScheduler(_asset_name, _chunk_size_bytes,
        _max_active_peer_downloads)
{
  asset_name = _asset_name;
  chunk_size_bytes = _chunk_size_bytes;
  max_active_peer_downloads = _max_active_peer_downloads;
  if (max_active_peer_downloads > ranked_peer_list.length) {
    max_active_peer_downloads > ranked_peer_list.length;
  }
  file_size_bytes = getFileSize(ase_info.asset_URL);
  //info.append("File size: " + file_size_bytes + "bytes<br>");
  //info.append("Chunk size: " + (chunk_size_bytes) + " bytes<br>");
//         num_chunks_dec = file_size_bytes / chunk_size_bytes;
//         info.append("Number of chunks (decimal): " + num_chunks_dec + "<br>");
  if (chunk_size_bytes > 0) {
    num_chunks = Math.ceil(file_size_bytes / chunk_size_bytes);
  } else {
    num_chunks = 1;
  }
  //info.append("Number of chunks: " + num_chunks + "<br><br>");
        
  //TODO: Connect peers that are not yet connected!
}
    
function scheduleChunkDownloads() {
  if ((num_started_chunks < num_chunks) && (num_active_ASE_downloads == 0)) {
    var ASE_start_chunk_idx = 0;
    var ASE_end_chunk_idx = 0;
    if (num_started_chunks == 0) {
      ASE_start_chunk_idx = 0;
      ASE_end_chunk_idx = Math.ceil(num_chunks * ASE_initial_allocation - 1);
    } else {  
      ASE_start_chunk_idx = num_started_chunks;
      ASE_end_chunk_idx = Math.ceil(num_started_chunks
         + (num_chunks - num_started_chunks) * ASE_continuation_allocation - 1);
    }
    downloadChunksASE(ASE_start_chunk_idx, ASE_end_chunk_idx, ase_info);
  }
        
  if ( (num_started_chunks < num_chunks) 
    && (num_active_peer_downloads < max_active_peer_downloads) )
  {
    for (i=0; i<ranked_peer_list.length && i<max_active_peer_downloads; i++) {
      var peer_info = ranked_peer_list[i];
      if ( (peer_info.connected == true) && (peer_info.downloading == false)
        && (num_started_chunks < num_chunks)
        && (num_active_peer_downloads < max_active_peer_downloads))
      {
        downloadChunksPeer(num_started_chunks, num_started_chunks, peer_info);
      }
    }
  }
        
  if (num_completed_chunks == num_chunks) {
    var chunk_list = [];
    for (var i=0; i < chunked_asset_data.length; i++) {
      if (chunked_asset_data[i] != undefined) {
        chunk_list.push(chunked_asset_data[i]);
      }
    }
    assetXfer.num_chunks = num_chunks;
    assetXfer.assetBlob = new Blob(chunk_list);
    assetXfer.onDone(asset_name, assetXfer.assetBlob);
  }
}

function downloadChunksASE(start_chunk_idx, end_chunk_idx, ase_info) {
  num_started_chunks += (end_chunk_idx+1 - start_chunk_idx);
  if (ase_info.name == "ASE") num_active_ASE_downloads += 1;
  var start_byte_idx = start_chunk_idx * chunk_size_bytes;
  var end_byte_idx = ((end_chunk_idx+1) * chunk_size_bytes) - 1;
        
  //info.append("--> Downloading chunks from " + ase_info.name + " (" + start_chunk_idx + " - " + end_chunk_idx + ")<br><br>");
        
  var xhr = new XMLHttpRequest;
    
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4) {
      handleCompletedChunks(start_chunk_idx, end_chunk_idx, asset_name,
            ase_info, new Blob([xhr.response]));
    }
  };
    
  var suffix = (assetXfer.noSuffix ? "" : "?"+(new Date().getTime()));
  xhr.open("GET", ase_info.asset_URL + suffix, true);
  xhr.responseType="arraybuffer";
  xhr.setRequestHeader("Range", "bytes=" + start_byte_idx + "-" + end_byte_idx);
  xhr.send();   
}
    
function downloadChunksPeer(start_chunk_idx, end_chunk_idx, peer_info) {
  num_started_chunks += (end_chunk_idx+1 - start_chunk_idx);
  var start_byte_idx = start_chunk_idx*chunk_size_bytes;
  var byte_length = (end_chunk_idx - start_chunk_idx + 1) * chunk_size_bytes;
  if (start_byte_idx + byte_length > file_size_bytes) {
    byte_length = file_size_bytes - start_byte_idx;
  }
  num_active_peer_downloads +=1;
  peer_info.downloading = true;
console.log("downloadChunksPeer "+start_chunk_idx+" .. "+end_chunk_idx);
  assetXfer.onChunkNeeded(peer_info.name,
        asset_name, start_byte_idx, byte_length);
}
    
function handleCompletedChunks(start_chunk_idx, end_chunk_idx, asset_name,
         chunk_source_info, chunk_data)
{
console.log("handleCompeletedChunks "+start_chunk_idx+" .. "
+end_chunk_idx+" from "+chunk_source_info.name);
  //info.append("<b><== Received chunks [" + start_chunk_idx + "-" + end_chunk_idx + "] from " + chunk_source_info.name + "<br><br></b>");
//         info.append("Payload data:<br>" + chunk_data + "<br>");        
  chunked_asset_data[start_chunk_idx] = chunk_data;
  num_completed_chunks += (end_chunk_idx+1 - start_chunk_idx);
  chunk_source_info.downloading = false;
  if (chunk_source_info.name == "ASE") {
    num_active_ASE_downloads -=1;
  } else {
    num_active_peer_downloads -=1;
  }
  scheduleChunkDownloads();
}
    
function getFileSize(url) {
  var xhr = new XMLHttpRequest();
  xhr.open("HEAD", url, false);
  xhr.send();
  return parseInt(xhr.getResponseHeader("Content-Length"));
}

return assetXfer;

}
