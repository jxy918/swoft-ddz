<?php
/**
 * Created by PhpStorm.
 * User: jiang
 * Date: 2019/9/10
 * Time: 14:46
 */

go(function () {
    $redis = new \Swoole\Coroutine\Redis();
    $redis->connect('192.168.1.155', 6379);
    $redis->setOptions(['compatibility_mode' => true]);
    $room_no_key = "user:room:no";
    if($redis->exists($room_no_key)) {
        echo '+++++++++++++++++++++++++++++++++++';
        $room_no = $redis->incr($room_no_key);
        echo $room_no;
    } else {
        $room_no = 1000001;
        $redis->set($room_no_key, $room_no);
    }
    var_dump($room_no);
});