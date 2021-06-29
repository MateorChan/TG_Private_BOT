<?php
set_time_limit(0);
//  配置
const CHAT_ID = ''; //  本人的chat_id
const REDIS_SERVER = '127.0.0.1'; //  redis主机
const REDIS_PORT = 6379;  //  redis端口
const API_HOST = 'http://127.0.0.1:8081'; //  API地址，推荐自己搭建local server，才可以下载大文件
const BOT_TOKEN = ''; //  bot的token

function doCurl($url, $params)
{
  $baseUrl = API_HOST . '/bot' . BOT_TOKEN . '/';
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $baseUrl . $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_POST, count($params));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $result = curl_exec($ch);
  curl_close($ch);
  return json_decode($result, true);
}

function randStr($length)
{
  $str = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $len = strlen($str) - 1;
  $randstr = '';
  for ($i = 0; $i < $length; $i++) {
    $num = mt_rand(0, $len);
    $randstr .= $str[$num];
  }
  return $randstr;
}

function sendMessage($text)
{
  $res = doCurl('sendMessage', [
    'chat_id' =>  CHAT_ID,
    'text'  =>  $text,
  ]);
  return $res;
}

function deleteMessage($messageId)
{
  if (empty($messageId)) {
    return;
  }
  $res = doCurl('deleteMessage', [
    'chat_id' =>  CHAT_ID,
    'message_id'  =>  $messageId,
  ]);
  return;
}

function getFile($fileName, $fileId, $messageId, $fileUnique, $retry = 0)
{
  if ($retry !== 0) {
    //  非第一次尝试，等待三秒
    sleep(3);
  }
  //  增加尝试次数
  $retry++;
  //  处理缓存
  $redis = new \Redis();
  $redis->connect(REDIS_SERVER, REDIS_PORT);
  $redisKey = $fileUnique;
  if ($redis->exists($redisKey)) {
    $redis->expire($redisKey, 7200);
    return deleteMessage($messageId);
  }
  $res = doCurl('getFile', [
    'file_id' =>  $fileId,
  ]);
  if ($res['ok']) {
    //  下载成功设置缓存，避免二次下载
    $redis->set($redisKey, 'done');
    $redis->expire($redisKey, 7200);
    sendMessage("状态：👌 保存成功\n文件名：$fileName");
  } else {
    //  保存不成功，删除缓存
    if ($redis->exists($redisKey)) {
      $redis->del($redisKey);
    }
    if ($retry < 3) {
      sendMessage("状态：❌ 第 $retry 次保存失败\n文件名：$fileName\n文件ID：$fileId\n原因：" . json_encode($res) . "\n\n等待重试...");
      return getFile($fileName, $fileId, $messageId, $fileUnique, $retry);
    } else {
      return sendMessage("状态：❌ $retry 次保存失败，不再重试\n文件名：$fileName\n文件ID：$fileId\n原因：" . json_encode($res) . "\n\n请重新向我发送该内容");
    }
  }
  //  判断文件是否存在
  if (file_exists('/webdata/remoteSync/TG/' . $fileName)) {
    //  判断文件是否相同
    if (md5_file($res['result']['file_path'] . $fileName) === md5_file('/webdata/remoteSync/TG/' . $fileName)) {
      // 文件相同，删除文件
      unlink($res['result']['file_path']);
    } else {
      //  文件不相同，重命名文件
      copy($res['result']['file_path'], '/webdata/remoteSync/TG/' . randStr(4) . $fileName);
      unlink($res['result']['file_path']);
    }
  } else {
    //  保存文件
    copy($res['result']['file_path'], '/webdata/remoteSync/TG/' . $fileName);
    unlink($res['result']['file_path']);
  }
  return deleteMessage($messageId);
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
  return;
}
$data = json_decode($raw, true);
//  判断消息
if (isset($data['message'])) {
  //  仅允许处理自己发来的信息
  if ($data['message']['chat']['id'] != CHAT_ID) {
    return ;
  }
  //  判断文件
  if (isset($data['message']['document'])) {
    $fileName = (empty($data['message']['document']['file_name'])) ? '' : $data['message']['document']['file_name'];
    if (empty($fileName)) {
      return sendMessage('无法获取文件名，下载失败');
    }
    return getFile($fileName, $data['message']['document']['file_id'], $data['message']['message_id'], $data['message']['document']['file_unique_id']);
  }
  //  判断视频
  if (isset($data['message']['video'])) {
    //  处理文件名
    $fileName = (empty($data['message']['video']['file_name'])) ? '' : $data['message']['video']['file_name'];
    //  无名则取caption
    if (empty($fileName) || $fileName === 'More-Telegram@HTHUB.mp4') {
      $fileName = ((empty($data['message']['caption'])) ? '' : $data['message']['caption']) . '.mp4';
    }
    //  再无名则取唯一ID和时长
    if (empty($fileName)) {
      $duration = $data['message']['video']['duration'];
      $fileName = $data['message']['video']['file_unique_id'] . '[' . $duration / 60 . '分' . $duration % 60 . '秒].mp4';
    }
    return getFile($fileName, $data['message']['video']['file_id'], $data['message']['message_id'], $data['message']['video']['file_unique_id']);
  }
  //  判断图片
  if (isset($data['message']['photo'])) {
    $photoMaxKey = 0;
    foreach ($data['message']['photo'] as $photoIndex => $photo) {
      //  遍历处理所有图片，取尺寸最大的一张
      if ($data['message']['photo'][$photoMaxKey]['file_size'] <= $photo['file_size']) {
        $photoMaxKey = $photoIndex;
      }
    }
    return getFile($data['message']['photo'][$photoMaxKey]['file_unique_id'] . '.jpg', $data['message']['photo'][$photoMaxKey]['file_id'], $data['message']['message_id'], $data['message']['photo'][$photoMaxKey]['file_unique_id']);
  }
}
sendMessage($raw);
