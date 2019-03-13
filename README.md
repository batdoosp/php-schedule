# php-schedule 文件以TP5框架模块方式引入
通过HTTP简单实现的定时任务调度方式

# 执行schedule.sql创建表
route字段标记指定任务路由，需转换成小写格式，用于redis加锁
is_serial字段定义是否加锁，继而决定运行方式是否为串行
url为指定任务的HTTP请求地址
type判定间隔执行或定点定时执行
其他字段基本用于控制脚本的运行触发条件
（
  可定制脚本是否可同时执行;
  定时指定时间点执行，如凌晨3点、10点30分、每周一、每月15号等;
  定义间隔运行任务的时间区间
）

# crontab设置1分钟定时任务
*/1 * * * * curl http://localhost/xxx/schedule/ScriptCommon

# 新增一条运行实例
任务将以每分钟触发一次的频繁执行

INSERT INTO schedule_plan VALUES (NULL, 'myscriptindex', 1, '测试定时任务', 'http://localhost/xxx/schedule/MyScript/index', 1, '-1', -1, 1, -1, -1, 0, NOW(), NOW(), NULL, '');


