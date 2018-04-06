<?php
/**
 * Created by PhpStorm.
 * User: HeXiangHui
 * Date: 2018/2/2
 * Time: 18:50
 */

require 'vendor/autoload.php';

use QL\QueryList;
use Predis\Client;


$client = new Predis\Client('tcp://127.0.0.1:6379');

while(true) {
    // 从 redis 中取出壁纸id
    $wallpaper_id = $client->rpop('wallpaper_id_queue');
    if($wallpaper_id == 'nil' || $wallpaper_id == ''){
        // 如果壁纸id为空的话就休眠5秒钟
        echo 'not have wallpaper data, sleep 5 second...' . PHP_EOL;
        sleep(5);
        continue;
    }

    try {
        downloadWallpaper($wallpaper_id);
        // 验证壁纸是否保存成功
        if (file_exists('./wallpaper/' . 'wallpaper-' . $wallpaper_id . '.jpg')) {
            echo 'success: ' . $wallpaper_id . PHP_EOL;
        } else {
            echo 'fail: ' . $wallpaper_id . PHP_EOL;
        }
    } catch (Exception $e) {
        echo 'img exception, sleep 1 second...' . PHP_EOL;
        sleep(1);
    }
};


/**
 * 下载壁纸
 * @param $wallpaper_id
 * @return int
 */
function downloadWallpaper($wallpaper_id) {
    // 拼接下载地
    $download_url = $url = "http://www.huanse.net/download.php?wallpaper={$wallpaper_id}&type=1";
    set_time_limit (24 * 60 * 60);
    $destination_folder = './wallpaper/';
    if (!is_dir($destination_folder)) {
        mkdir($destination_folder);
    }
    $newfname = $destination_folder . 'wallpaper-' . $wallpaper_id . '.jpg';
    $file = fopen ($url, "rb");
    if ($file) {
        $newf = fopen ($newfname, "wb");
        if ($newf)
            while (!feof($file)) {
                fwrite($newf, fread($file, 1024 * 8), 1024 * 8);
            }
    }
    if ($file) {
        fclose($file);
    }
    if ($newf) {
        fclose($newf);
    }
    return 0;
}