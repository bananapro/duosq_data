<?php
//DAL:订单数据访问模块, ***订单/资产相关表操作必须catch Exception***
namespace DAL;

class Order extends _Dal {

	//主订单表状态定义
	const STATUS_WAIT_CONFIRM = 0;
	const STATUS_PASS = 1;
	const STATUS_INVALID = 10;

	const CASHTYPE_JFB = 1; //资金类型：集分宝
	const CASHTYPE_CASH = 2; //资金类型：现金
	const CASHTYPE_BAO = 3; //资金类型：宝币

	const N_ADD = 1; //增加资产
	const N_REDUCE = -1; //减少资产
	const N_ZERO = 0; //资产不变

	/**
	 * 获取用户订单列表(主订单数据)
	 * @param  array   $condition 搜索条件(user_id, sub, status, is_show)
	 * @param  object  $pn        分页组件对象
	 * @param  string  $sub       子订单标识(默认全部)
	 * @param  integer $show      每页显示几条
	 * @param  integer $maxPages  最大页数
	 * return  array              订单数据
	 */
	function getList($pn, $condition, $show = 10, $maxPages = 10) {

		$condition = arrayClean($condition);

		//page = 0 返回总页数
		$pn->show = $show;
		$pn->sortBy = 'o_id';
		$pn->direction = 'desc';
		$pn->maxPages = $maxPages;

		list($order, $limit, $page) = $pn->init($condition, array('modelClass' => $this->db('order')));

		$result = $this->db('order')->findAll($condition, '', $order, $limit, $page);
		//TODO联合子表查出子表状态
		$result = clearTableName($result);
		$result = $this->_withSubDetail($result);
		$result = $this->_renderStatus($result);
		$result = $this->_renderSub($result);
		return $result;
	}

	/**
	 * 搜索相关主订单
	 * @param  array   $condition 搜索条件
	 * @param  bool    $one       仅搜索唯一订单
	 * @return array              搜索结果
	 */
	function search($condition, $limit=0, $orderby='o_id ASC'){

		if(!$condition)return;
		if($limit){
			if($limit == 1){
				$ret = $this->db('order')->find($condition, '', $orderby);
			}else{
				$ret = $this->db('order')->findAll($condition, '', $orderby, $limit);
			}
		}else{
			$ret = $this->db('order')->findAll($condition, '', $orderby);
		}

		if($ret){
			return clearTableName($ret);
		}
	}

	/**
	 * 获取单条主订单详情
	 * @param  char $o_id   订单号
	 * @return [type]       [description]
	 */
	function detail($o_id, $field=''){

		if(!$o_id)return;
		$ret = $this->db('order')->find(array('o_id'=>$o_id));
		$ret = clearTableName($ret);
		if($field && $ret){
			return $ret[$field];
		}else{
			return $ret;
		}
	}

	/**
	 * 获取用户子订单列表
	 * @param  string  $sub       子订单标识
	 * @param  array   $condition 搜索条件(user_id, sub, status, is_show)
	 * @return array              订单列表
	 */
	function getSubList($sub, $condition=array(), $orderby='o_id ASC', $limit=1000){

		if(!$sub)return;
		$lists = $this->db('order_'.$sub)->findAll(arrayClean($condition), '', $orderby, $limit);
		$lists = clearTableName($lists);
		$lists = $this->_withMainDetail($lists);
		return $lists;
	}

	/**
	 * 获取用户子订单总额
	 * @param  [type] $sub       [description]
	 * @param  array  $condition [description]
	 * @param  [type] $field     [description]
	 * @return [type]            [description]
	 */
	function getSubSumAmount($sub, $condition=array()){
		if(!$sub)return;
		return $this->db('order_'.$sub)->findSum('amount', arrayClean($condition));
	}

	/**
	 * 获取单条子订单详情
	 * @param  string  $sub    子订单标识
	 * @param  char    $o_id   订单号
	 * @return [type]          [description]
	 */
	function getSubDetail($sub, $o_id, $field=''){

		if(!$sub)return;
		$ret = $this->db('order_'.$sub)->find(array('o_id'=>$o_id));
		$ret = clearTableName($ret);
		if($field){
			return $ret[$field];
		}else{
			return $ret;
		}
	}

	/**
	 * 搜索相关子订单
	 * @param  string  $sub       子订单标识
	 * @param  array   $condition 搜索条件
	 * @param  bool    $one       仅搜索唯一订单
	 * @return array              搜索结果
	 */
	function searchSubOrders($sub, $condition, $limit=0, $orderby='o_id ASC'){

		if(!$condition)return;
		if($limit){
			if($limit == 1){
				$ret = $this->db('order_'.$sub)->find($condition, '', $orderby);
			}else{
				$ret = $this->db('order_'.$sub)->findAll($condition, '', $orderby, $limit);
			}
		}else{
			$ret = $this->db('order_'.$sub)->findAll($condition, '', $orderby);
		}

		if($ret){
			return clearTableName($ret);
		}
	}

	/**
	 * 新增用户子订单&主订单，如果主订单状态为已到账，则新增用户资产流水
	 * @param bigint  $user_id  用户ID
	 * @param int     $status   主订单初始状态，状态常量定义见开篇
	 * @param string  $sub      子订单标识
	 * @param int     $cashtype 资金类型(1:集分宝 2:现金)
	 * @param int     $n        资产增减类型(-1:减少 1:增加)
	 * @param int     $amount   订单金额(单位:分)
	 * @param array   $sub_data 子订单初始值(参见各子订单db层)
	 * @param int     $is_show  是否显示在个人中心
	 * return char              主订单号
	 */
	function add($user_id, $status, $sub, $cashtype, $n, $amount, $sub_data, $is_show=1){

		if(!$user_id || !$sub || !$cashtype || !$sub_data){
			return;
		}

		if($n && $amount <= 0){ //允许插入平账订单
			return;
		}

		if($cashtype != self::CASHTYPE_JFB && $cashtype != self::CASHTYPE_CASH && $cashtype != self::CASHTYPE_BAO){
			return;
		}

		$this->db()->begin();

		try{

			$o_id = $this->redis('order')->createId();
			$sub_table = 'order_'.$sub;

			//深度黑名单不增加任何资产
			if($n == self::N_ADD && $amount){
				$user_status = D('user')->getStatus($user_id);
				if($user_status == \DAL\User::STATUS_BLACK_2){
					$n = self::N_ZERO;
					//$amount = 0;
					//$status = self::STATUS_INVALID;
					if($sub=='invite'){
						$is_show = 0;
					}

					if($sub == 'cashgift'){
						$this->db('order_cashgift');
						$sub_data['status'] = \DB\OrderCashgift::STATUS_INVALID;
					}
				}
			}

			$this->db('order_reduce');
			if($sub == 'reduce' && $sub_data['type'] == \DB\OrderReduce::TYPE_ORDER){
				$is_show = 0;
			}

			if($sub == 'coupon'){
				$is_show = 0;
			}

			$this->db('order')->add($o_id, $user_id, $status, $sub, $cashtype, $n, $amount, $is_show);
			$this->db($sub_table)->add($o_id, $user_id, $sub_data);

		//订单、资产相关DB操作遇到错误均会抛异常，直接捕获，model db对象注销时自动rollback
		}catch(\Exception $e){
			writeLog('exception', 'DAL:order:add', $e->getMessage());
			$this->db()->rollback();
			return false;
		}

		//初始化主订单为确认状态，则触发更新子订单为确认状态，激活首次后续动作
		if($status == self::STATUS_PASS){
			$class = $this->_table2class('order_'.$sub);
			$ret = $this->updateSub($sub, $o_id, array('status'=>$class::STATUS_PASS));
			if(!$ret){
				$this->db()->rollback();
				return false;
			}
		}

		$this->db()->commit();

		return $o_id;
	}

	/**
	 * 更新主订单状态
	 * @param  char   $o_id      主订单编号
	 * @param  array  $new_field 新的字段信息
	 * @return bool              更新结果
	 */
	function updateStatus($o_id, $new_status){

		if(!$o_id || $new_status === null){
			return;
		}

		$this->db()->begin();
		try{
			$this->db('order')->updateStatus($o_id, $new_status);

		}catch(\Exception $e){
			writeLog('exception', 'DAL:order:updateStatus', $e->getMessage());
			$this->db()->rollback();
			return false;
		}

		$this->db()->commit();
		return true;
	}

	/**
	 * 更新子订单信息
	 * @param  char   $o_id      主订单编号
	 * @param  string $sub       子订单标识
	 * @param  array  $new_field 新的字段信息
	 * @return bool              更新结果
	 */
	function updateSub($sub, $o_id, $new_field, $force=false){

		if(!$sub || !$o_id || !$new_field){
			return;
		}

		$this->db()->begin();

		try{
			$this->db('order_'.$sub)->update($o_id, $new_field, $force);

		}catch(\Exception $e){
			writeLog('exception', 'DAL:order:update_sub', $e->getMessage());
			$this->db()->rollback();
			return false;
		}

		$this->db()->commit();
		return true;
	}

	/**
	 * 封装增加红包订单便捷方法
	 * @param bigint $user_id  用户ID
	 * @param int    $gifttype 新人礼包类型，常量定义见开篇
	 * @param int    $amount   红包价值金额单位分，仅当类型为新人抽奖/新人任务时有效，且不能超过100
	 * return array            o_id, amount
	 */
	function addCashgift($user_id, $gifttype, $amount=0, $refer_o_id=''){

		if(!$user_id)return;

		D('order')->db('order_cashgift');

		$new_cashgift_type = array();
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_LUCK;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_TASK;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_LOTTERY;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_LOTTERY_CASH;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_COND_10;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_COND_20;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_COND_50;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_COND_100;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_YUNGOU_SIGN;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_YUNGOU_SHOPPING;
		$new_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_YUNGOU_REWARD;

		$all_cashgift_type = $new_cashgift_type;
		$all_cashgift_type[] = \DB\OrderCashgift::GIFTTYPE_QUAN;

		if(!D('user')->detail($user_id, 'can_get_cashgift') && in_array($gifttype, $new_cashgift_type))return true;
		D()->db('order_cashgift');
		if(array_search($gifttype, $all_cashgift_type)===false)return; //保护类型

		$status = self::STATUS_WAIT_CONFIRM;

		switch ($gifttype) {
			case \DB\OrderCashgift::GIFTTYPE_COND_10:
				$amount = 1000;
				$cashtype = self::CASHTYPE_CASH;
				break;
			case \DB\OrderCashgift::GIFTTYPE_COND_20:
				$amount = 2000;
				$cashtype = self::CASHTYPE_CASH;
				break;
			case \DB\OrderCashgift::GIFTTYPE_COND_50:
				$amount = 5000;
				$cashtype = self::CASHTYPE_CASH;
				break;
			case \DB\OrderCashgift::GIFTTYPE_COND_100:
				$amount = 10000;
				$cashtype = self::CASHTYPE_CASH;
				break;
			case \DB\OrderCashgift::GIFTTYPE_LUCK:
			case \DB\OrderCashgift::GIFTTYPE_LOTTERY:
			case \DB\OrderCashgift::GIFTTYPE_TASK:
				$cashtype = self::CASHTYPE_JFB;
				$status = self::STATUS_PASS;
				break;
			case \DB\OrderCashgift::GIFTTYPE_LOTTERY_CASH:
			case \DB\OrderCashgift::GIFTTYPE_QUAN:
			case \DB\OrderCashgift::GIFTTYPE_YUNGOU_REWARD:
				$cashtype = self::CASHTYPE_CASH;
				$status = self::STATUS_PASS;
				break;
			case \DB\OrderCashgift::GIFTTYPE_YUNGOU_SIGN:
			case \DB\OrderCashgift::GIFTTYPE_YUNGOU_SHOPPING:
				$cashtype = self::CASHTYPE_BAO;
				$status = self::STATUS_PASS;
				break;
		}

		if($amount && ($gifttype == \DB\OrderCashgift::GIFTTYPE_LUCK || $gifttype == \DB\OrderCashgift::GIFTTYPE_TASK)){

			$review_reason = array();

			//B段IP速度控制，超速进入审核
			if(D('speed')->cashgift('ip')){
				$status = self::STATUS_WAIT_CONFIRM;
				$review_reason['ip_b'] = getIpByLevel('b');
			}

			//做支付宝手机号前7位审核队列
			$alipay = D('user')->detail($user_id, 'alipay');
			if(valid($alipay, 'mobile')){
				$ret = D('speed')->cashgift('mobile', $alipay);
				if($ret){
					$status = self::STATUS_WAIT_CONFIRM;
					$review_reason['mobile_pre'] = $ret;
				}
			}else{
				$ret = D('speed')->cashgift('alipay_pre', $alipay);
				if($ret){
					$status = self::STATUS_WAIT_CONFIRM;
					$review_reason['alipay_pre'] = $ret;
				}
			}

			//1小时内、同地区、同浏览器，进入审核
			$ret = D('speed')->cashgift('agent');
			if($ret){
				$status = self::STATUS_WAIT_CONFIRM;
				$review_reason['area_agent'] = $ret;
			}

			//国外地址全部进入审核
			$ret = D('speed')->cashgift('country');
			if($ret){
				$status = self::STATUS_WAIT_CONFIRM;
				$review_reason['country'] = $ret;
			}

			$alipay = D('user')->detail($user_id, 'alipay');
			if(preg_match('/^(jk|jzk|xia|jiao)\_/i', $alipay)){
				$status = self::STATUS_WAIT_CONFIRM;
				$review_reason['alipay'] = 'jk|jzk|xia|jiao';
			}

			if(preg_match('/^[0-9a-z]{7,}@21cn.com$/i', $alipay)){
				$status = self::STATUS_WAIT_CONFIRM;
				$review_reason['alipay'] = '21cn.com';
			}
		}

		if(@$review_reason){
			$review_reason = serialize($review_reason);
		}else{
			$review_reason = '';
		}

		//TODO 不允许重复增加新人礼包
		$ret = $this->add($user_id, $status, 'cashgift', $cashtype, self::N_ADD, $amount, array('gifttype'=>$gifttype, 'refer_o_id'=>$refer_o_id, 'review_reason'=>$review_reason, 'amount'=>$amount));
		if($ret){
			$ret_true = array();
			$ret_true['amount'] = $amount;
			$ret_true['o_id'] = $ret;
		}
		return $ret;
	}

	/**
	 * 封装增加淘宝订单便捷方法
	 * @param bigint $user_id  用户ID
	 * @param array  $sub_data 淘宝订单数据
	 */
	function addTaobao($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;

		$this->db('order_taobao');
		if(@$sub_data['status'] == \DB\OrderTaobao::STATUS_INVALID){
			$status = self::STATUS_INVALID;
		}else{
			$status = self::STATUS_WAIT_CONFIRM;
		}

		if(@$sub_data['fanli']){//订单一开始提交已处于“已成交”状态
			$ret = $this->add($user_id, $status, 'taobao', self::CASHTYPE_JFB, self::N_ADD, intval($sub_data['fanli']), $sub_data);
		}else{
			$ret = $this->add($user_id, $status, 'taobao', self::CASHTYPE_JFB, self::N_ZERO, intval($sub_data['fanli']), $sub_data);
		}

		if($ret){
			//通知用户订单到了
			D('notify')->addOrderBackJob($ret);
			//标识用户下过订单
			D('user')->markUserHasOrder($user_id);
		}

		return $ret;
	}

	/**
	 * 封装增加商城订单便捷方法
	 * @param bigint $user_id  用户ID
	 * @param array  $sub_data 商城订单数据
	 */
	function addMall($user_id, $sub_data){

		$this->db('order_mall');
		if(@$sub_data['status'] == \DB\OrderMall::STATUS_INVALID){
			$status = self::STATUS_INVALID;
		}else{
			$status = self::STATUS_WAIT_CONFIRM;
		}

		if(@$sub_data['fanli']){//订单一开始提交已处于“已成交”状态
			$ret = $this->add($user_id, $status, 'mall', $sub_data['cashtype'], self::N_ADD, intval($sub_data['fanli']), $sub_data);
		}else{
			$ret = $this->add($user_id, $status, 'mall', $sub_data['cashtype'], self::N_ZERO, 0, $sub_data);
		}

		if($ret){
			//通知用户订单到了
			D('notify')->addOrderBackJob($ret);
			//标识用户下过订单
			D('user')->markUserHasOrder($user_id);
		}

		return $ret;
	}

	/**
	 * 封装增加扣款订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 扣款订单数据
	 * @param integer $is_show  是否显示在个人中心
	 */
	function addReduce($user_id, $sub_data, $is_show=1){

		if(!$user_id || !$sub_data)return;
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'reduce', $sub_data['cashtype'], self::N_REDUCE, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装云购下注订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 下注订单数据
	 */
	function addYungou($user_id, $sub_data){

		if(!$user_id || !$sub_data || !$sub_data['yungou_id'] || !$sub_data['amount'])return;

		$total = $this->db('order_yungou')->getChipSum($sub_data['yungou_id']);
		$sub_data['number_begin'] = $total+1;
		$sub_data['number_end'] = $sub_data['number_begin']+$sub_data['amount']-1;
		
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'yungou', self::CASHTYPE_BAO, self::N_REDUCE, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装增加退款订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 退款订单数据
	 */
	function addRefund($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'refund', $sub_data['cashtype'], self::N_ADD, $sub_data['amount'], $sub_data, 0);

		return $ret;
	}

	/**
	 * 封装增加邀请好友奖励订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 奖励订单数据
	 */
	function addInvite($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'invite', self::CASHTYPE_CASH, self::N_ADD, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装增加优惠券使用订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 奖励订单数据
	 */
	function addCoupon($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;
		$cashtype = $this->detail($sub_data['refer_o_id'], 'cashtype');
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'coupon', $cashtype, self::N_ADD, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装增加每日签到奖励订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 奖励订单数据
	 */
	function addSign($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'sign', self::CASHTYPE_JFB, self::N_ADD, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装增加待变更用户ID订单便捷方法
	 * @param char   $o_id      订单ID
	 * @param bigint $user_id   用户ID
	 * @param [type] $r_orderid [description]
	 * @param [type] $buydate   [description]
	 */
	function addChUser($o_id, $r_orderid, $r_id, $buydate){

		if(!$o_id || !$r_orderid || !$r_id)return;
		return $this->db('order_chuser')->add(array('o_id'=>$o_id, 'r_orderid'=>$r_orderid, 'r_id'=>$r_id, 'buydate'=>$buydate));
	}

	/**
	 * 封装增加推广任务订单便捷方法
	 * @param bigint  $user_id  用户ID
	 * @param array   $sub_data 奖励订单数据
	 */
	function addAdvtask($user_id, $sub_data){

		if(!$user_id || !$sub_data)return;
		$ret = D('order')->add($user_id, self::STATUS_PASS, 'advtask', self::CASHTYPE_CASH, self::N_ADD, $sub_data['amount'], $sub_data);

		return $ret;
	}

	/**
	 * 封装增加用户待跟单订单号方法
	 * @param [type] $user_id   [description]
	 * @param [type] $taobao_no [description]
	 */
	function addWaitTrace($user_id, $r_orderid){

		if(!$user_id || !$r_orderid)return;
		return D('order')->db('order_wait_trace')->add(array('user_id'=>$user_id, 'r_orderid'=>$r_orderid));
	}

	/**
	 * 封装查找用户待跟单订单号方法
	 * @param [type] $user_id   [description]
	 * @param [type] $taobao_no [description]
	 */
	function getWaitTrace($user_id=null, $r_orderid=null, $limit = 10){

		if($user_id){
			if($r_orderid){
				$ret = D('order')->db('order_wait_trace')->findAll(array('user_id'=>$user_id, 'r_orderid'=>$r_orderid));
				return clearTableName($ret);
			}else{
				$ret = D('order')->db('order_wait_trace')->findAll(array('user_id'=>$user_id), '', 'id DESC', $limit);
				return clearTableName($ret);
			}
		}else{
			$ret = D('order')->db('order_wait_trace')->findAll(array('status'=>0));
			return clearTableName($ret);
		}
	}

	/**
	 * 封装更新用户待跟单订单号方法
	 * @param [type] $user_id   [description]
	 * @param [type] $taobao_no [description]
	 */
	function updateWaitTrace($id, $status){

		if(!$id || !$status)return;
		return D('order')->db('order_wait_trace')->update($id, array('status'=>$status));
	}

	/**
	 * 更改订单归属用户ID
	 * @param  [type] $o_id    [description]
	 * @param  bigint $user_id 用户ID
	 * @return [type]          [description]
	 */
	function changeUser($o_id, $user_id, &$err=''){

		if(!$o_id || !$user_id)return false;

		$main_detail = $this->detail($o_id);

		$sub = $main_detail['sub'];
		$cashtype = $main_detail['cashtype'];

		if(!in_array($sub, array('taobao', 'mall'))){
			$err = 'err_sp';
			return false;
		}
		$detail = $this->getSubDetail($sub, $o_id);

		$old_user_id = $detail['user_id'];
		$new_user_id = $user_id;
		if($sub == 'taobao')
			$sp = 'taobao';
		else
			$sp = $detail['sp'];

		if($old_user_id == $new_user_id){
			$err = 'same_user_id';
			return false;
		}
		$has_pay = D('fund')->getOrderBalance($o_id, $cashtype)?1:0;

		//检测订单1.1后的订单
		if($detail['buydate'] == '0000-00-00' || strtotime($detail['buydate']) < strtotime('2014-12-31')){
			$err = 'buydate_invalid';
			return false;
		}

		$this->db()->begin();
		try{

			if($has_pay){
				//扣除旧用户资产流水
				$ret = D('fund')->reduceBalanceForOrder($o_id);
				if(!$ret)throw new \Exception("reduce balance");
			}

			//更新主、子订单用户ID
			//判断用户是否现金白名单，如果是则使用现金
			if(D('user')->getForceCash($new_user_id)){
				$cashtype = self::CASHTYPE_CASH;
			}else{
				$cashtype = $main_detail['cashtype'];
			}

			$ret = $this->db('order')->update($o_id, array('user_id'=>$new_user_id, 'cashtype'=>$cashtype));
			$ret = $this->updateSub($sub, $o_id, array('user_id'=>$new_user_id), true);
			if(!$ret)throw new \Exception("sub user_id");

			//如果订单已经结算，则再次进入审核，下次提交订单即增加资产
			if($sub == 'taobao'){

				if($detail['r_status'] == \DB\OrderTaobao::R_STATUS_COMPLETED){
					$ret = $this->updateStatus($o_id, self::STATUS_WAIT_CONFIRM);
					if(!$ret)throw new \Exception("main status");

					$order_update = array();
					$order_update['status'] = \DB\OrderTaobao::STATUS_WAIT_CONFIRM;

					$ret = $this->updateSub($sub, $o_id, $order_update, true);
					if(!$ret)throw new \Exception("sub status");
				}

				//加上用户等级修正，补充加上等级后的金额
				if(D('user')->lvRate($user_id)){
					$order_update = array();
					$order_update['fanli_lv_rate'] = D('user')->lvRate($user_id);
					if($order_update['fanli_lv_rate']){
						$order_update['fanli'] = ceil($detail['r_yongjin'] * ($detail['fanli_rate']+$detail['fanli_rate']*$order_update['fanli_lv_rate']/100) /100);
						$ret = $this->updateSub($sub, $o_id, $order_update, true);
					}
				}

			}else{
				if($detail['r_status'] == \DB\OrderMall::R_STATUS_COMPLETED){
					$ret = $this->updateStatus($o_id, self::STATUS_WAIT_CONFIRM);
					if(!$ret)throw new \Exception("main status");
					$ret = $this->updateSub($sub, $o_id, array('status'=>\DB\OrderMall::STATUS_WAIT_CONFIRM), true);
					if(!$ret)throw new \Exception("sub status");
				}
			}

			if($sub == 'taobao'){
				//更新淘宝用户标识
				$taobao_no = getTaobaoNo($detail['r_orderid']);

				$old_taobao_no = D('user')->getTaobaoNo($old_user_id);
				if($old_taobao_no && in_array($taobao_no, $old_taobao_no)){
					D('user')->deleteTaobaoNo($old_user_id, $taobao_no);
				}

				//计算用户taobao_no，并增加taobao_no
				if(!D('user')->sys($user_id)){
					D('user')->addTaobaoNo($user_id, $taobao_no);
					//标识大于4人，加入黑名单需提供证据解锁
					$user_taobao_no = D('user')->getTaobaoNo($user_id, true);
					if(count($user_taobao_no) > 4){
						D('user')->markBlack($user_id, \DAL\User::STATUS_BLACK_2, 'taobao_no');
					}
				}
			}

		}catch(\Exception $e){
			writeLog('exception', 'DAL:order:changeUser', $e->getMessage());
			$this->db()->rollback();
			return false;
		}

		//增加订单用户变更日志
		D('log')->orderChuser($o_id, $old_user_id, $new_user_id, $sp, $main_detail['amount'], $has_pay);
		//标识用户下过订单
		D('user')->markUserHasOrder($new_user_id);

		$this->db()->commit();
		return true;
	}

	//获取最新有效订单
	function lastOrders($sp='taobao', $limit=20){

		$key = 'last_orders:sp:'.$sp.':limit:'.$limit;
		$cache = D('cache')->get($key);
		if($cache)return D('cache')->ret($cache);

		D('order')->db('order_taobao');
		$recent_orders = D('order')->searchSubOrders($sp, array('status'=>'<> '.\DB\OrderTaobao::STATUS_INVALID, 'user_id'=>'> 100'), $limit, 'o_id DESC');

		D('cache')->set($key, $recent_orders, MINUTE);
		return $recent_orders;
	}

	//检查是否奖励过宝币
	function checkRewardedBaoBi($o_id){

		if(!$o_id)return false;
		return $this->db('order_cashgift')->find(array('refer_o_id'=>$o_id));
	}

	//检查是否奖励过宝币
	function checkCanRewardedBaoBi($o_id){

		if(!$o_id)return false;
		$status = $this->db('order')->field('status', array('o_id'=>$o_id));
		if($status == self::STATUS_PASS)return true;
	}

	/**
	 * 根据不同订单，渲染订单状态字段，附加到status_display字段
	 * @param  [type] $lists [description]
	 * @return [type]       [description]
	 */
	private function _renderStatus($lists) {

		if(!$lists)return;
		$map_st = C('options', 'order_status');
		$map_taobao_st = C('options', 'order_taobao_status');
		$map_cashgift_st = C('options', 'order_cashgift_status');
		$map_reduce_st = C('options', 'order_reduce_status');

		foreach ($lists as &$v) {

			if(isset($v['status'])){

				if($v['sub'] == 'taobao' && $v['status']==0){

					if($v['n'] == 0 && $v['amount'] > 0){
						$v['status_display'] = '账号超限';
					}else{
						$v['status_display'] = $map_taobao_st[$v['sub_detail']['status']];
					}
				}else if($v['sub'] == 'cashgift'){

					$v['status_display'] = $map_cashgift_st[$v['sub_detail']['status']];

				}else if($v['sub'] == 'reduce'){

					$v['status_display'] = $map_reduce_st[$v['sub_detail']['status']];

				}else if($v['sub'] == 'mall'){

					if($v['status'] == self::STATUS_WAIT_CONFIRM){
						if($v['sub_detail']['buydatetime']){
							if(strtotime($v['sub_detail']['buydatetime'])+50*DAY < time()){
								$v['status_display'] = date('n月份确认');
							}else{
								$v['status_display'] = date('n月份确认', strtotime($v['sub_detail']['buydatetime']) + 50*DAY);
							}

						}else{
							$v['status_display'] = $map_st[$v['status']];
						}
					}else{
						$v['status_display'] = $map_st[$v['status']];
					}
				}else{
					$v['status_display'] = $map_st[$v['status']];
				}
			}
		}

		return $lists;
	}

	/**
	 * 根据不同子订单类型，渲染订单业务类型显示
	 * @param  [type] $lists [description]
	 * @return [type]        [description]
	 */
	private function _renderSub($lists){

		if(!$lists)return;
		foreach ($lists as &$v) {

			switch ($v['sub']) {
				case 'taobao':
					$v['sub_display'] = '<a href="http://trade.tmall.com/detail/orderDetail.htm?spm=a1z09.2.9.15.KtRJFt&bizOrderId='.$v['sub_detail']['r_orderid'].'" target="_blank">订单编号：'.$v['sub_detail']['r_orderid'].'</a><br />'.$v['sub_detail']['r_title'];
					break;
				case 'mall':
					$v['sub_display'] = '购物订单号： '.$v['sub_detail']['r_orderid'];
					break;
				case 'reduce':
					$map = C('options', 'order_reduce_type');
					$order_id = '';
					if($v['sub_detail']['type'] == \DB\OrderReduce::TYPE_ORDER){
						$sub = $this->detail($v['sub_detail']['refer_o_id'], 'sub');
						$order_id = $this->getSubDetail($sub, $v['sub_detail']['refer_o_id'], 'r_orderid');
						$order_id = "[{$order_id}]";
					}else if($v['sub_detail']['refer_o_id']){
						$order_id = $v['sub_detail']['refer_o_id'];
						$order_id = "[{$order_id}]";
					}
					$v['sub_display'] = $map[$v['sub_detail']['type']].$order_id;
					break;
				case 'cashgift':
					$map = C('options', 'order_cashgift_gifttype');
					$v['sub_display'] = $map[$v['sub_detail']['gifttype']];
					break;
				case 'invite':
					$nickname = D('user')->getNickname($v['sub_detail']['child_id']);
					$v['sub_display'] = '好友['.$nickname.']购物分成奖励';
					break;
				case 'sign':
					$v['sub_display'] = '每日签到奖励集分宝';
					break;
				case 'yungou':
					$v['sub_display'] = '0元夺宝下注：' . D('yungou')->detail($v['sub_detail']['yungou_id'] , 'title');
					break;
				case 'advtask':
					$options = C('options', 'advtask_type');
					$url = r('http://', '', $v['sub_detail']['advtask_url']);
					$v['sub_display'] = '[推广] <a href="http://'.$url.'" target="_blank">'.$url.'</a>';
					break;
				default:
					break;
			}
		}
		return $lists;
	}

	/**
	 * 渲染上子订单的属性，附加到sub_detail字段
	 * @param  [type] $lists [description]
	 * @return [type]       [description]
	 */
	private function _withSubDetail($lists){

		if(!$lists)return;
		$o_ids = array();
		$marked_list = array();
		foreach($lists as $list){
			$o_ids[$list['sub']][] = $list['o_id'];
			$marked_list[$list['o_id']] = $list;
		}

		foreach($o_ids as $sub => $o_id){
			$details = $this->db('order_'.$sub)->findAll(array('o_id'=>$o_id));
			$details = clearTableName($details);
			foreach ($details as $detail) {
				$marked_list[$detail['o_id']]['sub_detail'] = $detail;
			}
		}

		return $marked_list;
	}

	/**
	 * 渲染上主订单的属性，附加到main_detail字段
	 * @param  [type] $lists [description]
	 * @return [type]       [description]
	 */
	private function _withMainDetail($lists){

		if(!$lists)return;
		$o_ids = array();
		$marked_list = array();
		foreach($lists as $list){
			$o_ids[] = $list['o_id'];
			$marked_list[$list['o_id']] = $list;
		}

		foreach($o_ids as $o_id){
			$details = $this->db('order')->findAll(array('o_id'=>$o_id));
			$details = clearTableName($details);
			foreach ($details as $detail) {
				$marked_list[$detail['o_id']]['main_detail'] = $detail;
			}
		}

		return $marked_list;
	}

	//获取/设置 最后跟单时间
	function lastTraceTime($sub=null, $time=null){

		if(!$sub)return;
		if($time){
			$last = $this->redis('keys')->orderTrace($sub);
			if($time > $last)
				$this->redis('keys')->orderTrace($sub, $time);
		}else{
			return $this->redis('keys')->orderTrace($sub);
		}
	}
}
?>