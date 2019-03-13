<?php

namespace app\schedule\controller;

use think\Config;
use think\Db;
use think\Request;

set_time_limit(0);
//脚本控制器
class ScriptCommon
{
    protected $redisLockKey = '';
    protected $redisInstance = null;
    /**
     * 获取redis实例
     * */
    public function getRedisInstance()
    {
        if (is_null($this->redisInstance)) {
            $this->redisInstance = new \Redis();
            $this->redisInstance->connect(Config::get('redis_address'), Config::get('redis_port'));
            $this->redisInstance->auth(Config::get('redis_password'));
        }
        return $this->redisInstance;
    }
    /**
     * 构造函数，创建请求对应的并发锁
     * */
    public function __construct()
    {
        $request = Request::instance();
        //检查当前路由
        $route = $request->controller().$request->action();
        $route = strtolower($route);
        //判断是否串行请求，串行则需要加上redis锁
        //0:并行1:串行
        $isSerial = Db::table('schedule_plan')->where('route', $route)->value('is_serial');

        //串行请求，需添加redis锁防止并发访问
        if (is_null($isSerial) || $isSerial === false || $isSerial) {
            //加锁的两种因素
            //未查找到执行route 结果为null 或 false
            //根据route明确要求
            $redis = $this->getRedisInstance();

            //并发锁
            $redisKey = $this->redisLockKey = 'redis:schedule:'.$route;
            $nowTime = date('Y-m-d H:i:s');
            if (!$redis->setnx($redisKey, $nowTime)) {  //分布式锁写入失败
                if ($redis->ttl($redisKey) < 0) {
                    $redis->expire($redisKey, 1800);
                }
                exit('脚本正在执行');
            } else {    //锁写入成功
                //设置超时时间
                $redis->expire($redisKey, 1800);

                //注册自动删锁结束方法
                register_shutdown_function([$this, 'deleteRedisLock']);
            }
        }
    }
    /**
     * 定时器触发，轮询表内脚本
     * */
    public function index()
    {
        try {
            // +---------------获取时间参数比对运行脚本----------------
            // ** 前置0需被清除
            // | 日数 Day of the month without leading zeros	1 to 31
            $monthDay = date('j');
            // | 周几
            $weekDay = date('w');
            // | 小时 24-hour format of an hour without leading zeros	0 through 23 （单个数不包含0）
            $hour = date('G');
            // | 分钟 Minutes with leading zeros	00 to 59
            $minute = ltrim(date('i'), '0');
            $minute = $minute ?: '0';
            // +----------------------------------------------------

            //查找待执行的定时脚本
            $lists = Db::table('schedule_plan')->where('state', 0)->select();
            $lists = $lists ?: [];
            foreach ($lists as $key => $value) {
                //判断定时器是否在 执行时间区间内
                if ($value['execute_area'] != '-1') {
                    list($start, $end) = explode('-', $value['execute_area']);
                    //执行时间不满足条件 则不执行
                    if ($hour < $start || $hour > $end) {
                        continue;
                    }
                }
                $execFlag = false;  //脚本执行标记
                if ($value['type'] == 0) {
                    /*
                     * 定点时间运行
                     * 例:每月15号下午15点00分执行、周一早上8点30分执行、每天中午12点20分执行
                     * */
                    //1、月日期数和周天数未设置，可运行
                    //2、月日期数已设置且满足当前要求，可运行
                    //3、周天数已设置且满足当前要求，可运行
                    if ($value['month_day'] == -1 && $value['week_day'] == -1) {
                        $execFlag = true;
                    } else if ($value['month_day'] != -1 && $monthDay == $value['month_day']) {
                        $execFlag = true;
                    } else if ($value['week_day'] != -1 && $weekDay == $value['week_day']) {
                        $execFlag = true;
                    }

                    //前置检查满足要求，时时间未设置 或 与当前时时间 不符合，则不满足要求，设置标识为false
                    if ($execFlag && ($value['hour_time'] == -1 || $hour != $value['hour_time'])) {
                        $execFlag = false;
                    }
                    //前置检查满足要求，分时间未设置 或 与当前分时间 不符合，则不满足要求，设置标识为false
                    if ($execFlag && ($value['minute_time'] == -1 || $minute != $value['minute_time'])) {
                        $execFlag = false;
                    }
                } else if ($value['type'] == 1) {
                    /*
                     * 间隔时间运行
                     * 例:每5分钟执行一次，每1小时30分钟执行一次
                     * */
                    $time = 0;
                    if ($value['hour_time'] != -1) {
                        $time = $value['hour_time'] * 3600;
                    }
                    if ($value['minute_time'] != -1) {
                        $time += $value['minute_time'] * 60;
                    }
                    //保证时间间隔大于0
                    $execFlag = ($time > 0) ? true : false;

                    //通过last_time字段判断之前是否已执行过
                    //已执行 则进行时间比较，判断是否达到时间间隔条件
                    //未执行 现在执行
                    if ($execFlag && !empty($value['last_time'])) {
                        //当前时间 - 上次执行时间 小于 间隔时间（10秒预留，防止临界点导致未运行）
                        if ((time() - strtotime($value['last_time'])) < ($time - 10)) {
                            $execFlag = false;
                        }
                    }
                }
                //未满足运行条件，跳过
                if (!$execFlag) {
                    continue;
                }
                //保存此次执行时间
                Db::table('schedule_plan')->where('id', $value['id'])
                    ->update(['last_time'=>date('Y-m-d H:i:s')]);
                //非堵塞URL请求指定定时脚本
                echo $value['description']."<br />";
                $this->asyncCurl($value['url']);
                echo $value['url']."<br />";
            }
            return 'SUCCESS';
        } catch (\Exception $e) {
            return 'FAILURE'.$e->getMessage();
        }
    }
    /**
     * CURL请求，1秒钟停止连接
     *
     * @param string $url 请求URL地址
     * @return mixed
     * */
    private function asyncCurl($url) {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch,CURLOPT_TIMEOUT,1);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    /**
     * redis锁删除
     * */
    public function deleteRedisLock()
    {
        if (!empty($this->redisLockKey)) {
            $redis = $this->redisInstance;
            //并发锁删除
            $redis->del($this->redisLockKey);
        }
        if (!is_null($this->redisInstance)) {
            $this->redisInstance->close();
        }
    }
}