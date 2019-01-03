# php_socket_http 基本说明
php语言编写基于workman框架。基本优势

1、采用数据推送方式通信和larval或者thinkphp项目同步数据，而不是共享内存方式通信同步数据。

2、socket程序内不直接处理业务逻辑，只做通信桥梁，从而剥离业务只做通信桥梁，减少耦合。

# 使用
启动： 切换root用户，执行命令：php socket.php start -d 然后就可以ctrl+c退出了，系统会自己后台运行

关闭： 切换root用户，执行命令：php socket.php stop

重启： 先执行关闭，在执行开启即可。

查看运行状态： php socket.php status

查看动态日志： tail -f log/socket.log

# 作用
socket服务和http服务二合一。
# 设计目的
可以使后端http直接使用socket进行推送。socket也可以给http推送
# 运用指导思想
socket连接和php后端框架通信中间件。不做业务只做通信
