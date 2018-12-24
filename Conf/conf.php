<?php

class Conf{
    //判断是不是正式环境
    public static $is_real=0;
    //socket链接地址和端口号
    public static $bindUrl='0.0.0.0:2003';
    //http服务地址
    public static $bindHttp='0.0.0.0:2004';
    //http服务器地址
    public static $curlHost='https://dev.baidu.com';

    //是否开启详细日志
    public static $isOpenDetailLog=1;

    //服务器thinkphp http请求地址
    public static $curlHttpUrlLogin='/auth/authorizeToken';//处理登录事件
    public static $curlHttpUrlSocketBreak='/chat/dealSocketBreak';//处理socket断开连接
    //与服务器通信secret_key
    public static $secretKey='faf2123f23_q4324r$#$#@rfa4345e%#$';
    //socket开多少进程
    public static $LightweightProces=1;
    //http开多少进程
    public static $HttpLightweightProces=10;
    //定时任务时间
    public static $timeSet=array(
        'heart'=>6,
        'newmsgCheck'=>2,
        'timeOut'=>10,
        'sysCheck'=>3,//心跳定时器
        'autoCheck'=>5,//系统自检时间间隔
    );
}
