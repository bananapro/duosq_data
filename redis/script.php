<?php
//脚本专用redis连接，无限执行时间

namespace REDIS;

class Script extends _Redis {

	protected $namespace = 'script';

}
?>