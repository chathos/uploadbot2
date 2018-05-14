#!/usr/bin/env php
<?php

require_once __DIR__ . "/config.php";
require __DIR__ . "/vendor/autoload.php";

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Logger;

set_include_path(get_include_path() . ':' . realpath(dirname(__FILE__) . '/MadelineProto/'));

$settings = [
  'app_info' => [
    'api_id' => $APP_ID,
    'api_hash' => $API_HASH
  ]
];

try {
    $MadelineProto = new API($BOT_SESSION, $settings);
} catch (Exception $e) {
    $MadelineProto = new API($settings);
}

$authorization = $MadelineProto->bot_login($BOT_TOKEN);

if (!file_exists($TMP_DOWNLOADS)){
  mkdir($TMP_DOWNLOADS);
}

$MadelineProto->session = $BOT_SESSION;
$offset = 0;
$conversations = array();
while (true) {
    $updates = $MadelineProto->get_updates([
      'offset' => $offset,
      'limit' => 50,
      'timeout' => 0
    ]);
    Logger::log([$updates]);
    foreach ($updates as $update) {
        $offset = $update['update_id'] + 1;
        switch ($update['update']['_']) {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
                if (isset($update['update']['message']['out']) && $update['update']['message']['out']) {
                    continue;
                }
                try {
                    $destination = retrieveDestination($update);
                    if(in_array($destination, $OWNER_IDS)) {
                      if (isset($update['update']['message']['media']) && (retrieveFromMessage($update, 'media')['_'] == 'messageMediaPhoto' || retrieveFromMessage($update, 'media')['_'] == 'messageMediaDocument')) {
                          $time = time();
                          $id = $MadelineProto->messages->sendMessage([
                            'peer' => $destination,
                            'message' => 'Downloading file...',
                            'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                          ])['id'];
                          $file = $MadelineProto->download_to_file(
                            new \danog\MadelineProto\FileCallback(
                              $update['update']['message']['media'],
                              function ($progress) use ($MadelineProto, $destination, $id) {
                                $MadelineProto->messages->editMessage([
                                  'id' => $id,
                                  'peer' => $peer,
                                  'message' => 'Download progress: '.$progress.'%'
                                ]);
                              }
                            ),
                            $TMP_DOWNLOADS . "/" . $update['update']['message']['media']['document']['attributes'][0]['file_name']
                          );
                          $MadelineProto->messages->sendMessage([
                            'peer' => $destination,
                            'message' => 'Downloaded in ' . (time() - $time) . ' seconds',
                            'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                          ]);
                          $conversations[$destination] = array(
                            'downloadDir' => $file,
                            'fileName' => getFileName($file, DIRECTORY_SEPARATOR)
                          );
                      } else if (isset($update['update']['message']['message'])) {
                          $message = retrieveFromMessage($update, 'message');
                          if (startsWith($message, 'http://') || startsWith($message, 'https://') || startsWith($message, 'ftp://')) {
                              $MadelineProto->messages->sendMessage([
                                'peer' => $destination,
                                'message' => 'Downloading file...',
                                'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                              ]);
                              // TODO: Progress CallBack for Download Function
                              try {
                                  $conversations[$destination] = downloadFile($TMP_DOWNLOADS, $message);
                                  $MadelineProto->messages->sendMessage([
                                    'peer' => $destination,
                                    'message' => 'File downloaded!',
                                    'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                                  ]);
                                  // successfully downloaded, now start the upload
                                  $id = $MadelineProto->messages->sendMessage([
                                    'peer' => $destination,
                                    'message' => 'upload will be started soon',
                                    'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                                  ])['id'];
                                  $file_name = $conversations[$destination]["fileName"];
                                  $file_path = $conversations[$destination]["downloadDir"];
                                  $caption = '' . $file_name . '       Uploaded using @MadeLineProto ';
                                  if((endsWith($message, ".mp4")) || (endsWith($message, ".mkv")) || (endsWith($message, ".avi"))) {
                                    $sentMessage = $MadelineProto->messages->sendMedia([
                                      'peer' => $destination,
                                      'media' => [
                                        '_' => 'inputMediaUploadedDocument',
                                        'file' => new \danog\MadelineProto\FileCallback(
                                          $file_path,
                                          function ($progress) use ($MadelineProto, $destination, $id) {
                                            $MadelineProto->messages->editMessage([
                                              'id' => $id,
                                              'peer' => $destination,
                                              'message' => 'Upload progress: '.$progress.'%'
                                            ]);
                                          }
                                        ),
                                        'attributes' => [
                                          [
                                            '_' => 'documentAttributeVideo',
                                            'round_message' => false,
                                            'supports_streaming' => true
                                          ]
                                        ]
                                      ],
                                      'message' => $caption,
                                      'parse_mode' => 'Markdown'
                                    ]);
                                  }
                                  else {
                                    $sentMessage = $MadelineProto->messages->sendMedia([
                                      'peer' => $destination,
                                      'media' => [
                                        '_' => 'inputMediaUploadedDocument',
                                        'file' => new \danog\MadelineProto\FileCallback(
                                          $file_path,
                                          function ($progress) use ($MadelineProto, $destination, $id) {
                                            $MadelineProto->messages->editMessage([
                                              'id' => $id,
                                              'peer' => $destination,
                                              'message' => 'Upload progress: '.$progress.'%'
                                            ]);
                                          }
                                        ),
                                        'attributes' => [
                                          [
                                            '_' => 'documentAttributeFilename',
                                            'file_name' => "@MadeLineProto " . $file_name
                                          ]
                                        ]
                                      ],
                                      'message' => $caption,
                                      'parse_mode' => 'Markdown'
                                    ]);
                                  }

                                  // var_dump($sentMessage);
                                  // TODO: delete the original file, after successful UPLOAD
                              } catch (Exception $e) {
                                  // var_dump($e);
                                  $MadelineProto->messages->sendMessage([
                                    'peer' => $destination,
                                    'message' => 'Unable to download file',
                                    'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                                  ]);
                              }
                          }
                          else {
                            $MadelineProto->messages->sendMessage([
                              'peer' => $destination,
                              'message' => 'Hi!, please send me any file url i will upload to telegram as file.',
                              'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                            ]);
                          }
                      }
                  }
                  else {
                    $MadelineProto->messages->sendMessage([
                      'peer' => $destination,
                      'message' => 'Sorry! You do not have permission to use this bot. Please ask [the creator](tg://user?id=' . $OWNER_IDS[0] . ') for access.',
                      'reply_to_msg_id' => retrieveFromMessage($update, 'id'),
                      'parse_mode' => 'Markdown'
                    ]);
                  }
                } catch (RPCErrorException $e) {
                    $MadelineProto->messages->sendMessage([
                      'peer' => '@SpEcHiDe',
                      'message' => $e->getCode() . ': ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString()
                    ]);
                }
        }
    }
}

function getFileName($filePath, $separator)
{
    $splitted = explode($separator, $filePath);
    return urldecode($splitted[count($splitted) - 1]);
}

/*function progressCallback( $download_size, $downloaded_size, $upload_size, $uploaded_size )
{
    static $previousProgress = 0;

    if ( $download_size == 0 )
        $progress = 0;
    else
        $progress = round( $downloaded_size * 100 / $download_size );

    if ( $progress > $previousProgress)
    {
        $previousProgress = $progress;
        global $MadelineProto;
        $MadelineProto->messages->sendMessage([
          'peer' => $to,
          'message' => $message,
          'reply_to_msg_id' => $replyTo
        ]);
    }
}

// https://gist.github.com/bdunogier/1030450

function downloadRemoteFile($url, $destination_file)
{
  $targetFile = fopen( $destination_file, 'w' );
  $ch = curl_init( $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt( $ch, CURLOPT_NOPROGRESS, false );
  curl_setopt( $ch, CURLOPT_PROGRESSFUNCTION, 'progressCallback' );
  curl_setopt( $ch, CURLOPT_FILE, $targetFile );
  curl_exec( $ch );
  fclose( $targetFile );
}*/

function downloadFile($TMP_DOWNLOADS, $message)
{
    var_dump($message);
    $fileName = getFileName($message, "/");
    $downloadDir = $TMP_DOWNLOADS . "/" . $fileName;
    if (!file_exists($downloadDir)){
      file_put_contents($downloadDir, fopen($message, 'r'));
    }
    return array('downloadDir' => $downloadDir, 'fileName' => $fileName);
}

function startsWith($haystack, $needle) {
   $length = strlen($needle);
   return (substr($haystack, 0, $length) === $needle);
}

// <=> https://stackoverflow.com/a/834355/4723940

function endsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}

function retrieveFromMessage($update, $toRetrieve)
{
    return $update['update']['message'][$toRetrieve];
}

function retrieveDestination($update)
{
    return isset($update['update']['message']['from_id']) ? retrieveFromMessage($update, 'from_id') : retrieveFromMessage($update, 'to_id');
}

function sendMessage($to, $message, $replyTo)
{
    global $MadelineProto;
    $MadelineProto->messages->sendMessage(['peer' => $to, 'message' => $message, 'reply_to_msg_id' => $replyTo]);
}
