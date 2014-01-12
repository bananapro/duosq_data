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
	function getList($condition, $pn, $show = 10, $maxPages = 10) {

		$condition = arrayClean($condition);

		//page = 0 返回总页数
		$pn->show = $show;
		$pn->sortBy = 'o_id';
		$pn->direction = 'desc';
		$pn->maxPages = $maxPages;


		list($order, $limit, $page) = $pn->init($condition, array('modelClass' => $this->db('order')));

		$result = $this->db('order')->findAll($condition, '', $order, $limit, $page);
		//TODO联合子表查出子表状态
		clearTableName($result);
		$result = $this->_withSubDetail($result);
		$result = $this->_renderStatus($result);
		$result = $this->_renderSub($result);
		return $result;
	}

	/**
	 * 获取单条主订单详情
	 * @param  char $o_id   订单号
	 * @return [type]       [description]
	 */
	function detail($o_id){

		if(!$o_id)return;
		$ret = $this->db('order')->find(array('o_id'=>$o_id));
		return clearTableName($ret);
	}

	/**
	 * 获取用户子订单列表
	 * @param  string  $sub       子订单标识
	 * @param  array   $condition 搜索条件(user_id, sub, status, is_show)
	 * @return array              订单列表
	 */
	function getSubList($sub, $condition=array()){

		if(!$sub)return;
		$lists = $this->db('order_'.$sub)->findAll(arrayClean($condition));
		clearTableName($lists);
		$lists = $this->_withMainDetail($lists);
		return $lists;
	}

	/**
	 * 获取单条子订单详情
	 * @param  string  $sub    子订单标识
	 * @param  char    $o_id   订单号
	 * @return [type]          [description]
	 */
	function getSubDetail($sub, $o_id){

		if(!$sub)return;
		$ret = $this->db('order_'.$sub)->find(array('o_id'=>$o_id));
		return clearTableName($ret);
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

		if($n && !$amount){ //允许插入平账订单
			return;
		}

		if($cashtype != self::CASHTYPE_JFB && $cashtype != self::CASHTYPE_CASH){
			return;
		}

		$this->db()->begin();

		try{

			$o_id = $this->redis('order')->createId();

			$sub_table = 'order_'.$sub;
			$this->db('order')->add($o_id, $user_id, $status, $sub, $cashtype, $n, $amount, $is_show);

			$this->db($sub_table)->add($o_id, $user_id, $sub_data);

		//订单、资产相关DB操作遇到错误均会抛异常，直接捕获，model db对象注销时自动rollback
		}catch(\Exception $e){
			writeLog('exception', 'dal_order_add', $e->getMessage());
			$this->db()->rollback();
			return false;
		}

		//初始化主订单为确认状态，则触发更新子订单为确认状态，激活首次后续动作
		if($status == self::STATUS_PASS){
			$class = $this->_table2class('order_'.$sub);
			$this->updateSub($sub, $o_id, array('status'=>$class::STATUS_PASS));
		}


		$this->db()->commit();


		return $o_id;
	}

	/**
	 * 更新子订单信息
	 * @param  char   $o_id      主订单编号
	 * @param  string $sub       子订单标识
	 * @param  array  $new_field 新的字段信息
	 * @return bool              更新结果
	 */
	function updateSub($sub, $o_id, $new_field){

		if(!$o_id || !$sub || !$new_field){
			return;
		}

		$this->db()->begin();

		try{
			$this->db('order_'.$sub)->update($o_id, $new_field);

		}catch(\Exception $e){
			writeLog('exception', 'dal_order_update_sub', $e->getMessage());
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
	function addCashgift($user_id, $gifttype, $amount=0){

		if($amount>100)return; //保护金额

		D()->db('order_cashgift');
		if(array_search($gifttype, array(\DB\OrderCashgift::GIFTTYPE_LUCK, \DB\OrderCashgift::GIFTTYPE_TASK, \DB\OrderCashgift::GIFTTYPE_COND_10, \DB\OrderCashgift::GIFTTYPE_COND_20, \DB\OrderCashgift::GIFTTYPE_COND_50, \DB\OrderCashgift::GIFTTYPE_COND_100))===false)return; //保护类型

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
			case \DB\OrderCashgift::GIFTTYPE_TASK:
				$cashtype = self::CASHTYPE_JFB;
				$status = self::STATUS_PASS;
				break;
		}

		//TODO 不允许重复增加新人礼包
		$ret = $this->add($user_id, $status, 'cashgift', $cashtype, self::N_ADD, $amount, array('gifttype'=>$gifttype));
		if($ret){
			$ret_true = array();
			$ret_true['amount'] = $amount;
			$ret_true['o_id'] = $ret;

			//在业务表层触发打款，防止加资产事务未完成，打款发现资产不足
			D('pay')->addAutopayJob($cashtype, $user_id);
		}
		return $ret;
	}

	/**
	 * 封装增加淘宝订单便捷方法
	 * @param bigint $user_id  用户ID
	 * @param array  $sub_data 淘宝订单数据
	 */
	function addTaobao($user_id, $sub_data){

		//不允许重复添加
		if($this->db('order_taobao')->find(array('r_orderid'=>$sub_data['r_orderid'], 'r_id'=>$sub_data['r_id']))){
			return false;
		}

		if(@$sub_data['fanli']){
			$ret = $this->add($user_id, self::STATUS_WAIT_CONFIRM, 'taobao', self::CASHTYPE_JFB, self::N_ADD, $sub_data['fanli'], $sub_data);
		}else{
			$ret = $this->add($user_id, self::STATUS_WAIT_CONFIRM, 'taobao', self::CASHTYPE_JFB, self::N_ZERO, 0, $sub_data);
		}

		if($ret){
			//通知用户订单到了
			D('notify')->addOrderBackJob($ret);
		}

		return $ret;
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

					$v['status_display'] = $map_taobao_st[$v['sub_detail']['status']];

				}else if($v['sub'] == 'cashgift'){

					$v['status_display'] = $map_cashgift_st[$v['sub_detail']['status']];

				}else if($v['sub'] == 'reduce'){

					$v['status_display'] = $map_reduce_st[$v['sub_detail']['status']];

				}else{

					$v['status_display'] = $map_st[$v['status']];

					if($v['status'] == self::STATUS_INVALID){
						$v['n'] = 0;
					}
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
					$v['sub_display'] = D('shop')->getShopName($v['sub_detail']['sp']).'购物订单'.$v['o_id'];
					break;
				case 'reduce':
					$map = C('options', 'order_reduce_type');
					$v['sub_display'] = $map[$v['sub_detail']['type']] . $v['sub_detail']['refer_o_id'];
					break;
				case 'cashgift':
					$map = C('options', 'order_cashgift_gifttype');
					$v['sub_display'] = $map[$v['sub_detail']['gifttype']];
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
			clearTableName($details);
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
			clearTableName($details);
			foreach ($details as $detail) {
				$marked_list[$detail['o_id']]['main_detail'] = $detail;
			}
		}

		return $marked_list;
	}
}
?>