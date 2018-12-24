<?php

/**
 * 通用socket
 * @author xun_yu
 * @version 2018.07.17
 * @update 以后可升级多进程也可升级为集群socket
 */
use Workerman\Worker;
use Workerman\Lib\Timer;

$localfile = __DIR__;
require_once $localfile . '/Common/Common.php';
require_once $localfile . '/Workerman/Autoloader.php';
require_once $localfile . '/mysql-master/src/Connection.php';
require_once $localfile . '/Conf/conf.php';
Worker::$stdoutFile = $localfile . '/Log/socket.log';

$ws_worker = new Worker("websocket://" . Conf::$bindUrl);
$ws_worker->count = Conf::$LightweightProces;
$ws_worker->onWorkerStart = function($ws_worker) {
    global $db;
    global $redis;
    @$ws_worker->connect_pool = [];
    // 只在id编号为0的进程上设置定时器，用于系统自检等信息记录
    if($ws_worker->id === 0)
    {
        Timer::add(Conf::$timeSet['autoCheck'], function()use($ws_worker){
            writeDetailLog('当前登录在线人数：'. count($ws_worker->connect_pool,1));
        });
    }
    //启动立即链接数据库
    //$db = connectDatabase();
    //链接redis  
    //$redis = connectRedis();
    $ht_worker = new Worker("http://" . Conf::$bindHttp);
    $ht_worker->count = Conf::$HttpLightweightProces;
    $ht_worker->onMessage = function ($connection, $data)use($ws_worker) {
        writeDetailLog('获取http数据msg_packet_id：' . $data['post']['msg_packet_id']);
        $start_get_curl_time = WEMicrotime();
        global $ws_worker;
        $post = $data['post'];
        try {
            check_curl_data($post);
            $ret = sendMessage($post);
            $ret = array('status' => 0, 'msg_packet_id' => $post['msg_packet_id'], 'msg' => 'success', 'data' => $ret);
        } catch (Exception $exc) {
            writeErrLog('处理http请求包id' . $post['msg_packet_id'] . '失败，失败原因：' . $exc->getMessage(), $post);
            $ret = array('status' => -1, 'msg_packet_id' => $post['msg_packet_id'], 'msg' => $exc->getMessage());
        }
        $end_get_curl_time = WEMicrotime() - $start_get_curl_time;
        $log = '本次处理http请求包id:' . $post['msg_packet_id'] . '费时：' . $end_get_curl_time . "ms";
        writeDetailLog($log);
        $connection->send(json_encode(array($ret)));
    };
    $ht_worker->onError = function($connection, $code, $msg) {
        writeErrLog("http服务本身出现错误，请立即处理。ERROR：code： $code msg：$msg");
    };
    $ht_worker->listen();
};

//新连接接入的时候
$ws_worker->onConnect = function($connection) {
    //global $db;
    //global $redis;
    global $ws_worker;
    $connection->onWebSocketConnect = function($connection, $http_header) {
        // 最后活跃时间
        $connection->last_active = WEMicrotime();
        //发送connect_id
        $connection->send(json_encode(array(array('msgType' => 'CON_ID', 'data' => $connection->id))));
    };
    // 当收到客户端发来的数据后返回hello $data给客户端
    $connection->onMessage = function($connection, $data) {
        try {
            global $ws_worker;
            $data_all = json_decode($data, 1);
            if (!$data_all) {
                throw new Exception("数据必须是json格式");
            }
            foreach ($data_all as $key => $data) {
                if (empty($data['msgType'])) {
                    throw new Exception('msgType不能为空');
                }
                switch ($data['msgType']) {
                    case 'LOGIN':
                        $curl_data = curl_is_login(array('token' => $data['data']));
                        if (!$curl_data) {
                            throw new Exception("token无效");
                        }
                        writeDetailLog('接受客户端登录：user_id:' . $curl_data['id'] . ' connection_id:' . $connection->id);
                        $connection->islogin = 1;
                        $connection->user_id = $curl_data['id'];
                        $ws_worker->connect_pool[$connection->user_id][$connection->id] = $connection;
                        //刷新最后时间
                        $connection->last_active = WEMicrotime();
                        break;
                    case 'PONG':
                        if (isset($connection->islogin) && $connection->islogin == 1) {
                            writeDetailLog('接受客户端pong：user_id:' . $connection->user_id . ' connection_id:' . $connection->id);
                            $connection->last_active = WEMicrotime();
                        }
                        break;
                    case 'NEWMSG':
                        throw new Exception('发送消息接口关闭，发送消息走http');
                        break;
                    default:
                        break;
                }
            }
        } catch (Exception $exc) {
            writeErrLog('socket接受消息处理错误，连接user_id:' . $connection->user_id . ' 连接connect_id:' . $connection->id . '错误原因：' . $exc->getMessage(), $data);
            $connection->send(json_encode(array(array('msgType' => 'ERR', 'data' => $exc->getMessage()))));
        }
    };
    //定时器，心跳
    $connection->timer_heart = Timer::add(Conf::$timeSet['sysCheck'], function()use($connection) {
                if (WEMicrotime() - $connection->last_active > Conf::$timeSet['timeOut'] * 1000) {
                    writeErrLog('socket连接心跳超时，立即销毁连接:' . 'connection_id:' . $connection->id);
                    //心跳超时直接调用销毁
                    $connection->destroy();
                } else {
                    if (isset($connection->islogin) && $connection->islogin == 1 && WEMicrotime() - $connection->last_active >= conf::$timeSet['heart'] * 1000) {
                        $send_ping_return = $connection->send(json_encode(array(array('msgType' => 'PING'))));
                        if ($send_ping_return === false) {
                            //发送ping失败，立即关闭连接
                            writeErrLog('socket发送PING失败，立即销毁连接:' . 'connection_id:' . $connection->id);
                            $connection->destroy();
                        } else {
                            writeDetailLog('socket心跳发送：user_id:' . $connection->user_id . ' connection_id:' . $connection->id);
                        }
                    }
                }
            });
    /*
     * 链接关闭
     */
    $connection->onClose = function($connection) {
        global $ws_worker;
        if (!empty($connection->timer_heart)) {
            Timer::del($connection->timer_heart);
        }
        if (!empty($connection->user_id) && !empty($ws_worker->connect_pool[$connection->user_id][$connection->id])) {
            unset($ws_worker->connect_pool[$connection->user_id][$connection->id]);
            //通知http后端断开socket连接
            dealSocketBreak($connection->user_id,$connection->id);
            writeDetailLog('连接关闭，关闭连接user_id:' . $connection->user_id . ' connection_id:' . $connection->id);
        } else {
            writeDetailLog('连接关闭，关闭未认证连接connection_id：' . $connection->id);
        }
    };
};
/**
 * workman发生错误
 */
$ws_worker->onError = function($connection, $code, $msg) {
    writeErrLog("socket服务本身出现错误，请立即处理。ERROR：code： $code msg：$msg");
};

/**
 * http发生错误
 */
function curl_is_login($data) {
    $data = json_decode(curl_request(Conf::$curlHost . Conf::$curlHttpUrlLogin, array('token' => $data['token']), 'post', 1, 1), 1);
    if ($data['status']['code'] == 0) {
        return $data['data'];
    } else {
        writeErrLog('socket登录token验证接口报错', $data);
        return FALSE;
    }
}

/**
 * 判断curl的数据是否正确
 */
function check_curl_data($data) {
    if (empty($data['msg_packet_id'])) {
        throw new Exception("msg_packet_id不能为空");
    }
    if (empty($data['timestamp'])) {
        throw new Exception("timestamp不能为空");
    }
    if (empty($data['data'])) {
        throw new Exception("data不能为空");
    }
    if (empty($data['token'])) {
        throw new Exception("token不能为空");
    }
    if (empty($data['from'])) {
        throw new Exception("from不能为空");
    }
    $token = md5($data['from'] . $data['msg_packet_id'] . json_encode($data['data']) . $data['timestamp'] . Conf::$secretKey);
    if ($token != $data['token']) {
        throw new Exception('token不合法');
    }
}

/**
 * 加密发送
 * @param type $data
 */
function encryption($data) {
    $msg_packet_id = time() . rand(1000, 9999);
    $timestamp = time();
    $from = $data['from'];
    $md5 = md5($from . $msg_packet_id . json_encode($data['data']) . $timestamp . Conf::$secretKey);
    return array(
        'from' => $from,
        'msg_packet_id' => $msg_packet_id,
        'data' => $data['data'],
        'timestamp' => $timestamp,
        'token' => $md5
    );
}

/**
 * socket发送数据接口
 * @param type $data
 */
function sendMessage($data) {
    global $ws_worker;
    //进入发送消息流程
    //判断要发送的user_id
    $loss = $suc_send = $failed = [];
    foreach ($data['data'] as $key => $one_need_send) {
        if (empty($ws_worker->connect_pool[$one_need_send['to']])) {
            $loss[] = array('msg_id' => $one_need_send['msg_id'], 'to' => $one_need_send['to'], 'type' => $one_need_send['type']);
        } else {
            $send_flag = 0;
            foreach ($ws_worker->connect_pool[$one_need_send['to']] as $key => $one_con) {
                //执行发送http任务
                $start_times = WEMicrotime();
                $return_s = $one_con->send(json_encode(array(array('msgType' => 'NEWMSG', 'data' => $one_need_send, 'from' => $data['from']))));
                $end_times = WEMicrotime() - $start_times;
                $log = '执行发送http任务user_id:' . $one_need_send['to'] . ' connect_id:' . $one_con->id . '费时：' . $end_times . "ms";
                writeDetailLog($log);
                if ($return_s !== FALSE) {
                    $send_flag = 1;
                }
            }
            if ($send_flag == 0) {
                $failed[] = array('msg_id' => $one_need_send['msg_id'], 'to' => $one_need_send['to'], 'type' => $one_need_send['type']);
            } else {
                $suc_send[] = array('msg_id' => $one_need_send['msg_id'], 'to' => $one_need_send['to'], 'type' => $one_need_send['type']);
            }
        }
    }
    return array('loss' => $loss, 'success' => $suc_send, 'failed' => $failed);
}

/**
 * 处理socket断开连接的时候给后端发送消息
 */
function dealSocketBreak($user_id,$id) {
    try {
        $start_time = WEMicrotime();
        $data = json_decode(curl_request(Conf::$curlHost . Conf::$curlHttpUrlSocketBreak, array('user_id' => $user_id,'con_id'=>$id), 'post', 1, 1), 1);
        $end_send = WEMicrotime() - $start_time;
        writeDetailLog("处理socket断开连接的时候给后端发送消息，花费时间为：" . $end_send . 'ms,user_id:' . $user_id);
        if ($data['status']['code'] == 0) {
            if (!empty($data['data'])) {
                //处理发送逻辑
                $send_offline_start = WEMicrotime();
                try {
                    check_curl_data($data['data']);
                    sendMessage($data['data']);
                } catch (Exception $exc) {
                    writeErrLog("处理socket断开连接的时候给其他socket连接发送通知，返回错误：" . $exc->getMessage() . 'user_id:' . $user_id);
                }
                $send_offline_end = WEMicrotime() - $send_offline_start;
                writeDetailLog("处理socket断开连接的时候给其他socket连接发送通知，花费时间为：" . $send_offline_end . 'ms,user_id:' . $user_id);
            }
            return TRUE;
        } else {
            writeErrLog("处理socket断开连接的时候给后端发送消息，返回错误json：" . json_encode($data) . 'user_id:' . $user_id);
            return FALSE;
        }
    } catch (Exception $exc) {
        writeErrLog('dealSocketBreak函数出错，user_id:' . $user_id . '出错msg' . $exc->getMessage());
        return FALSE;
    }
}

//写错误日志，这个一直会写
function writeErrLog($str, $data = []) {
    if (!empty($data)) {
        echo 'ERR: ' . date("Y-m-d H:i:s") . ' ' . $str . "\n" . 'json_data:' . json_encode($data) . "\n";
    } else {
        echo 'ERR: ' . date("Y-m-d H:i:s") . ' ' . $str . "\n";
    }
    return TRUE;
}

//详细日志
function writeDetailLog($str, $data = []) {
    if (Conf::$isOpenDetailLog) {
        if (!empty($data)) {
            echo 'NOTICE: ' . date("Y-m-d H:i:s") . ' ' . $str . "\n" . 'json_data:' . json_encode($data) . "\n";
        } else {
            echo 'NOTICE: ' . date("Y-m-d H:i:s") . ' ' . $str . "\n";
        }
    }
    return true;
}

// 运行worker
Worker::runAll();
