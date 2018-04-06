<?php
require 'vendor/autoload.php';
use QL\QueryList;
use Predis\Client;


$client = new Predis\Client('tcp://127.0.0.1:6379');

$page = 1;
do {
    // 使用 QueryList 获取 html 网页内容，找到所有 img 标签的 data-echo 属性的值
    $data = QueryList::get("http://www.huanse.net/new-{$page}.html")->find('img')->attrs('data-echo');
    if (count($data) > 0) {
        foreach ($data as $datum) {
            if ($datum) {
                // 通过分割字符串得到壁纸id
                $wallpaper_id = explode('.', explode('-', $datum)[1])[0];
                // 将壁纸id push 到 redis 中
                $client->lpush('wallpaper_id_queue', $wallpaper_id);
            }
        }
    } else {
        echo 'no data. page: ' . $page . PHP_EOL;
        break;
    }
    $page++;
    echo 'now page: ' . $page . PHP_EOL;
}while(true);

