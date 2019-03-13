CREATE TABLE `schedule_plan` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '定时任务调度表',
  `route` varchar(50) NOT NULL COMMENT '路由地址',
  `is_serial` tinyint(4) NOT NULL DEFAULT '1' COMMENT '脚本执行堵塞标记0:并行1:串行',
  `description` varchar(100) NOT NULL COMMENT '定时脚本描述说明',
  `url` varchar(100) NOT NULL COMMENT '定时任务URL地址',
  `type` tinyint(4) NOT NULL COMMENT '类型0:定点时间1:间隔时间',
  `execute_area` varchar(10) NOT NULL DEFAULT '-1' COMMENT '定时器执行小时区间-1:无限制|7-15(7-15点执行)',
  `hour_time` tinyint(4) NOT NULL DEFAULT '-1' COMMENT '小时',
  `minute_time` tinyint(4) NOT NULL DEFAULT '0' COMMENT '分钟',
  `week_day` tinyint(4) NOT NULL DEFAULT '-1' COMMENT '周日、周一……周六|0-6',
  `month_day` tinyint(4) NOT NULL DEFAULT '-1' COMMENT '月-日数',
  `state` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态0:正常1:禁用',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  `last_time` datetime DEFAULT NULL COMMENT '上次执行时间',
  `error_msg` varchar(100) DEFAULT NULL COMMENT '规则不符合原因',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
