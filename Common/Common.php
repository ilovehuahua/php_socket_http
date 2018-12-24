<?php

/**
 * redis S 方法
 * @author xun_yu
 * @param type $key
 * @param type $value
 * @param type $outTime
 */
function S($key, $value = 'q23dcs34fgc25fs3t5gxsse5', $outTime = 0) {
    global $redis;
    $key = Conf::$redisConf['DATA_CACHE_PREFIX'] . $key;
    try {
        if ($value === 'q23dcs34fgc25fs3t5gxsse5') {
            return json_decode($redis->get($key), 1);
        } elseif ($value === NULL) {
            return $redis->delete($key);
        } elseif ($outTime !== 0) {
            return $redis->setex($key, $outTime, json_encode($value));
        } else {
            return $redis->set($key, json_encode($value));
        }
    } catch (Exception $exc) {
        var_dump($exc);
        connectRedis();
        if ($value === 'q23dcs34fgc25fs3t5gxsse5') {
            return json_decode($redis->get($key));
        } elseif ($value === NULL) {
            return $redis->delete($key);
        } elseif ($outTime !== 0) {
            return $redis->setex($key, $outTime, json_encode($value));
        } else {
            return $redis->set($key, json_encode($value));
        }
    }
}

/**
 * 重新连接redis
 */
function connectRedis() {
    $redis = new Redis();
    $redis->connect(Conf::$redisConf['host'], Conf::$redisConf['port']);
    $redis->auth(Conf::$redisConf['password']);
    return $redis;
}

/**
 * 链接数据库
 * @return \Workerman\MySQL\Connection
 */
function connectDatabase() {
    if (Conf::$is_real) {
        return new Workerman\MySQL\Connection(Conf::$mainDatabase['host'], Conf::$mainDatabase['port'], Conf::$mainDatabase['user'], Conf::$mainDatabase['password'], Conf::$mainDatabase['db_name'], Conf::$mainDatabase['charset']);
    } else {
        return new Workerman\MySQL\Connection(Conf::$testDatabase['host'], Conf::$testDatabase['port'], Conf::$testDatabase['user'], Conf::$testDatabase['password'], Conf::$testDatabase['db_name'], Conf::$testDatabase['charset']);
    }
}

/**
 * @name 查询数据库
 * @author xun_yu
 * @global type $db
 * @param type $columns
 * @param type $where
 * @param type $tableName
 * @param type $orderBy
 * @param type $order
 * @return type
 */
function getAllFromTable($columns = '', $where = 1, $tableName = "", $orderBy = array('id'), $order = 'asc') {
    global $db;
    if (is_array($where)) {
        foreach ($where as $key => $value) {
            if (is_array($value)) {
                if (empty($strWhere)) {
                    $strWhere = $key . $value[0] . ' :' . $key;
                } else {
                    $strWhere = $strWhere . ' AND ' . $key . $value[0] . ' :' . $key;
                }
                //清除二维键
                $where[$key] = $value[1];
            } else {
                if (empty($strWhere)) {
                    $strWhere = $key . '= :' . $key;
                } else {
                    $strWhere = $strWhere . ' AND ' . $key . '= :' . $key;
                }
            }
        }
        if ($order == 'asc') {
            $outData = $db->select($columns)->from($tableName)->where($strWhere)->orderByASC($orderBy)->bindValues($where)->query();
        } else {
            $outData = $db->select($columns)->from($tableName)->where($strWhere)->orderByDESC($orderBy)->bindValues($where)->query();
        }
    } else {
        if ($order == 'asc') {
            $outData = $db->select($columns)->from($tableName)->where($where)->orderByASC($orderBy)->query();
        } else {
            $outData = $db->select($columns)->from($tableName)->where($where)->orderByDESC($orderBy)->query();
        }
    }
    return $outData;
}

/**
 * 新增直接查询函数
 */
function query_select($sql) {
    global $db;
    return $db->query($sql);
}

/**
 * 获取精确到微秒的时间，13位
 * @return type 13位精确到微秒的时间
 */
function WEMicrotime() {
    list($usec, $sec) = explode(" ", microtime());
    return substr(((float) $usec + (float) $sec) * 1000, 0, 13);
}

/**
 * curl
 */
function curl_request($url, $data = array(), $method = 'get', $toHtml = 1, $escapeHttps = 0, $isJsonType = 0, $isXml = 0,$header=[],$CURLOPT_TIMEOUT=5) {
    $method = strtolower($method); //转化为小写
    $ch = curl_init(); //初始化CURL句柄 
    if ($escapeHttps) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // 跳过证书检查 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); // 跳过证书检查
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, $toHtml); //设为TRUE把curl_exec()结果转化为字串，而不是直接输出 

    if ($isJsonType) {
        $data_string = json_encode($data, JSON_UNESCAPED_UNICODE);
        $header= array_merge($header,array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string),
            "X-HTTP-Method-Override: $method"));
    } else if ($isXml) {
        $header= array_merge($header,array("Content-type: text/xml", "X-HTTP-Method-Override: $method"));
    } else {
        $header= array_merge($header,array("X-HTTP-Method-Override: $method"));
    }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    switch ($method) {
        case 'post':
            curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            break;
        case 'get':
            if (empty($data)) {
                curl_setopt($ch, CURLOPT_URL, $url);
            } else {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
            }
            break;
        case 'put':
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            break;
        default:
            curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
            break;
    }
    if ($isJsonType && $method != 'get') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    } else if ($isXml) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else if ($method != 'get') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); //设置提交的字符串
    }
    //设置curl最大执行时间
    curl_setopt($ch,CURLOPT_TIMEOUT, $CURLOPT_TIMEOUT);
    $document = curl_exec($ch); //执行预定义的CURL
    if (curl_errno($ch)) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $document;
}
