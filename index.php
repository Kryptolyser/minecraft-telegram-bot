<?php
$start = microtime(true);
include "config.php";
include "bot/Telegram.php";
require "query/MinecraftQuery.php";
require "query/MinecraftQueryException.php";
include "lang/" . $config["lang"] . ".php";

use xPaw\MinecraftQuery;
use xPaw\MinecraftQueryException;

$live_playerlist = get_live_playerlist();
$cache = load_cache();
$cached_playerlist = $cache["playerlist"];
$player_disconnect_time = is_array($cache["player_disconnect_time"])
  ? $cache["player_disconnect_time"]
  : [];
$message_to_chat = "";

// Compare live playerlist to cached playerlist. If there is a change, generate a message
if ($live_playerlist != $cached_playerlist) {
  list($message_to_chat, $send_new_msg) = 
    generate_message($live_playerlist, $cached_playerlist, $player_disconnect_time);
}

// If there's a new message, post it
if ($message_to_chat) {
  if ($send_new_msg) {
    delete_last_message();
    post_message_to_chat($message_to_chat);
  }
  else {
    update_last_message($message_to_chat);
  }
  echo $message_to_chat; // also echo the result for easier debugging without chat spam
}

// When done, cache current playerlist
$cache["playerlist"] = $live_playerlist;
$cache["player_disconnect_time"] = $player_disconnect_time;
save_cache($cache);

// Display execution time. Useful for adjusting the interval to call this script
$execution_time = microtime(true) - $start;
echo "\r\nExecution time: " . $execution_time;

function get_live_playerlist()
{
  global $config;
  $Query = new MinecraftQuery();
  try {
    $Query->Connect($config["server_url"], $config["server_port"]);
    $playerlist = $Query->GetPlayers();
    sort($playerlist);
    return $playerlist;
  } catch (MinecraftQueryException $e) {
    echo $e->getMessage();
  }
}

function load_cache()
{
  $cache = unserialize(file_get_contents("cache"));
  return $cache;
}

function save_cache($cache)
{
  file_put_contents("cache", serialize($cache));
}

function generate_message($live_playerlist, $cached_playerlist, &$player_disconnect_time)
{
  global $config, $lang, $cache;
  // The playerlist is an empty string if no players joined but we ALWAYS need an array for the following functions
  $live_playerlist_array = is_array($live_playerlist) ? $live_playerlist : [];
  $cached_playerlist_array = is_array($cached_playerlist)
    ? $cached_playerlist
    : [];

  $players_joined = array_diff(
    $live_playerlist_array,
    $cached_playerlist_array
  );
  $players_disconnected = array_diff(
    $cached_playerlist_array,
    $live_playerlist_array
  );

  $send_new_msg = false;

  $lines = [];
  foreach ($players_joined as $player_joined) {
    $disconnect_time = $player_disconnect_time[$player_joined] ?? 0;
    if (time() - $disconnect_time > $config["disconnect_time"] * 60) {
      $send_new_msg = true;
    }
    array_push($lines, $player_joined . " " . $lang["joined"] . " ");
  }

  foreach ($players_disconnected as $player_disconnected) {
    array_push($lines, "<s>". $player_disconnected . " " . $lang["joined"] . "</s> ");
    $player_disconnect_time[$player_disconnected] = time();
  }

  if (!empty($lines)) {
    $message = implode("\r\n", $lines) ."\r\n";
  }

  $player_count = count($live_playerlist_array);

  switch ($player_count) {
    case 0:
      $message .= "<b>". $lang["no_players_connected"] ."</b>";
      break;
    case 1:
      $message .= $lang["one_player_connected"];
      break;
    default:
      $message .= $player_count . " " . $lang["players_connected"];
  }

  return [$message, $send_new_msg];
}

function post_message_to_chat($message)
{
  global $config, $cache;
  $telegram = new Telegram($config["bot_token"]);
  $content = ["chat_id" => $config["chat_id"], "text" => $message, "parse_mode" => "HTML"];
  $response = $telegram->sendMessage($content);
  if (isset($response["result"]) && isset($response["result"]["message_id"])) {
    $cache["last_message_id"] = $response["result"]["message_id"];
  }
}

function delete_last_message()
{
  global $config, $cache;

  if (!isset($cache["last_message_id"])) {
    return;
  }

  $telegram = new Telegram($config["bot_token"]);
  $content = ["chat_id" => $config["chat_id"], "message_id" => $cache["last_message_id"]];
  $telegram->deleteMessage($content);
  unset($cache["last_message_id"]);
}

function update_last_message($message)
{
  global $config, $cache;

  if (!isset($cache["last_message_id"])) {
    return;
  }

  $telegram = new Telegram($config["bot_token"]);
  $content = [
    "chat_id" => $config["chat_id"], 
    "text" => $message, 
    "message_id" => $cache["last_message_id"], 
    "parse_mode" => "HTML"];
  $telegram->editMessageText($content);
}
?>
