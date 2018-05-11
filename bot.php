#!/usr/bin/env php
<?php

require_once __DIR__ . "/config.php";
require __DIR__ . "/vendor/autoload.php";

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Logger;

const BOT_SESSION = "YAUploadBot.session";
const TMP_DOWNLOADS = __DIR__ . '/temporary_downloads';

set_include_path(get_include_path() . ':' . realpath(dirname(__FILE__) . '/MadelineProto/'));

$settings = [
  'app_info' => [
    'api_id' => $APP_ID,
    'api_hash' => $API_HASH
  ]
];

try {
    $MadelineProto = new API(BOT_SESSION, $settings);
} catch (Exception $e) {
    $MadelineProto = new API($settings);
}

$authorization = $MadelineProto->bot_login($BOT_TOKEN);

if (!file_exists(TMP_DOWNLOADS)){
  mkdir(TMP_DOWNLOADS);
}

$MadelineProto->session = BOT_SESSION;
$offset = 0;
$conversations = array();
while (true) {
    $updates = $MadelineProto->get_updates(['offset' => $offset, 'limit' => 50, 'timeout' => 0]);
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
                    if (isset($update['update']['message']['media']) && (retrieveFromMessage($update, 'media')['_'] == 'messageMediaPhoto' || retrieveFromMessage($update, 'media')['_'] == 'messageMediaDocument')) {
                        $time = time();
                        $MadelineProto->messages->sendMessage([
                          'peer' => $destination,
                          'message' => 'Downloading file...',
                          'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                        ]);
                        $file = $MadelineProto->download_to_file(
                          $update['update']['message']['media'],
                          TMP_DOWNLOADS . DIRECTORY_SEPARATOR . $update['update']['message']['media']['document']['attributes'][0]['file_name']
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
                                $conversations[$destination] = downloadFile($message);
                                $MadelineProto->messages->sendMessage([
                                  'peer' => $destination,
                                  'message' => 'File downloaded!',
                                  'reply_to_msg_id' => retrieveFromMessage($update, 'id')
                                ]);
                                // successfully downloaded, now start the upload
                                $sentMessage = $MadelineProto->messages->sendMedia([
                                  'peer' => $destination,
                                  'media' => [
                                    '_' => 'inputMediaUploadedDocument',
                                    'file' => new \danog\MadelineProto\FileCallback(
                                      $conversations[$destination]['downloadDir'],
                                      function ($progress) use ($MadelineProto, $destination) {
                                        $MadelineProto->messages->sendMessage([
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
                                  'message' => 'Uploaded using @MadeLineProto ',
                                  'parse_mode' => 'Markdown'
                                ]);
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
    return $splitted[count($splitted) - 1];
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

function downloadFile($message)
{
  var_dump($message);
    $fileName = getFileName($message, "/");
    $downloadDir = TMP_DOWNLOADS . DIRECTORY_SEPARATOR . $fileName;
    if (!file_exists($downloadDir)){
      file_put_contents($downloadDir, fopen($message, 'r'));
    }
    return array('downloadDir' => $downloadDir, 'fileName' => $fileName);
}

function startsWith($string, $toCheck)
{
    return substr($string, 0, strlen($toCheck)) === $toCheck;
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
