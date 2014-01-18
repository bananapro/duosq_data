<?php
//商城订单表操作基类，***订单相关操作出错必须throw Exception***
//子订单模块必须定义确认状态STATUS_PASS[到账网站]常量
namespace DB;

class OrderMall extends _Db {

	var $name = 'OrderMall';
	var $primaryKey = 'o_id'; //指定主键

	//商城订单状态常量
	const STATUS_WAIT_DEAL_DONE = 0; //等待订单成交
	const STATUS_WAIT_CONFIRM = 1; //等待确认
	const STATUS_WAIT_D1 = 5; //延期5天
	const STATUS_WAIT_D2 = 6; //延期14天
	const STATUS_WAIT_D3 = 7; //延期50天
	const STATUS_PASS = 10; //已到账[网站]
	const STATUS_INVALID = 20; //不通过

	//对方_订单状态
	const R_STATUS_CREATED = 0; //对方:订单创建
	const R_STATUS_INVALID = 1; //对方:订单失效
	const R_STATUS_UNKNOW = 9; //对方:订单状态未知
	const R_STATUS_COMPLETED = 10; //对方:订单结算

	/**
	 * 新增用户扣款订单
	 * @param char    $o_id     主订单编号
	 * @param bigint  $user_id  用户ID
	 * @param array   $data     订单初始数据，扣款类型typ(1:系统提现)
	 * return char              主订单编号
	 */
	function add($o_id, $user_id, $data=array()){

		if(!$o_id || !$user_id || !$data['r_orderid']){
			throw new \Exception("[order_mall][o_id:{$o_id}][add][param error]");
		}

		//判断是否已存在
		$exist = $this->isExisted($data);

		if($exist){

			throw new \Exception("[order_mall][o_id:{$o_id}][add][r_orderid:{$data['r_orderid']} existed]");
		}

		$this->create();
		$data['o_id'] = $o_id;
		$data['user_id'] = $user_id;
		$ret = parent::save($data);
		if(!$ret){
			throw new \Exception("[order_mall][o_id:{$o_id}][add][save error]");
		}

		return $ret;
	}

	/**
	 * 更新扣款订单数据
	 * @param char    $o_id       子订单编号
	 * @param int     $new_field  新字段信息
	 */
	function update($o_id, $new_field){

		if(!$o_id || !$new_field){
			throw new \Exception("[order_mall][o_id:{$o_id}][update][param error]");
		}
		$old_detail = $this->find(array('o_id'=>$o_id));

		$new_field['o_id'] = $o_id;
		$ret = parent::save(arrayClean($new_field));

		if(!$ret){
			throw new \Exception("[order_mall][o_id:{$o_id}][update][save error]");
		}

		clearTableName($old_detail);

		//触发主状态变化，主状态在后台审核时会更新
		if(isset($new_field['status'])){

			if($old_detail['status'] != $new_field['status']){
				$trigger = $this->afterUpdateStatus($o_id, $old_detail['status'], $new_field['status'], $new_field);
				if($trigger) return $ret; //碰到触发规则，不再往下执行子订单状态变化
			}
		}

		//触发子状态更新，子状态在上传旧订单有可能更新，也可能触发主状态更新
		if(isset($new_field['r_status'])){

			if($old_detail['r_status'] != $new_field['r_status']){
				$trigger = $this->afterUpdateRStatus($o_id, $old_detail['r_status'], $new_field['r_status'], $new_field);
				if($trigger) return $ret;
			}
		}

		//TODO 订单金额变动，判断是否通过状态，以及是否有相应资产流水，多则补资产并触发打款，少则扣除，没资产变动则不变
		return $ret;
	}

	//商城订单状态更新后，触发资产变化、打款成功应发通知
	function afterUpdateStatus($o_id, $from, $to, $new_field){

		//订单主状态变为通过，判断是否已经打过款，增加资产，触发自动打款操作
		$m_order = D('order')->detail($o_id);

		if(!$m_order){
			throw new \Exception("[order_mall][afterUpdateStatus][m_order:{$o_id} not found]");
		}

		//商城订单状态由 已通过 => 待审，不允许逆向，防止上传商城订单重置状态到待审核
		if($from == self::STATUS_PASS && $to == self::STATUS_WAIT_CONFIRM){
			throw new \Exception("[order_mall][o_id:{$o_id}][can not from STATUS_PASS to STATUS_WAIT_CONFIRM][warn]");
		}

		//商城订单状态由待处理 => 已通过，进行账号增加流水
		if($from == self::STATUS_WAIT_CONFIRM && $to == self::STATUS_PASS){

			$ret = D('fund')->adjustBalanceForOrder($o_id);
			if(!$ret){
				throw new \Exception("[order_mall][o_id:{$o_id}][afterUpdateStatus][adjustBalanceForOrder error]");
			}

			//主订单状态变为已通过
			D('order')->db('order')->update($o_id, \DAL\Order::STATUS_PASS);
			return true;
		}

		//商城订单状态由已通过 => 不通过，进行账号扣除流水，主订单变为不通过
		if($to == self::STATUS_INVALID){

			//有可能是afterUpdateRStatus传递过来，因此再次设置主状态
			$this->update($o_id, array('status'=>self::STATUS_INVALID));
			$money = D('fund')->getOrderBalance($o_id, $m_order['cashtype'], true);

			if($money > 0){
				D()->db('order_reduce');
				$errcode = '';

				$ret = D('fund')->reduceBalance($m_order['user_id'], \DB\OrderReduce::TYPE_ORDER, array('refer_o_id'=>$o_id, 'cashtype'=>$m_order['cashtype'], 'amount'=>$money), $errcode);

				if(!$ret){
					throw new \Exception("[order_mall][o_id:{$o_id}][afterUpdateStatus][reduceBalance error]["._e($errcode, false)."]");
				}
			}

			//主订单状态变为不通过
			D('order')->db('order')->update($o_id, \DAL\Order::STATUS_INVALID);
			return true;
		}
	}

	function afterUpdateRStatus($o_id, $from, $to, $new_field){

		$m_order = D('order')->detail($o_id);
		if(!$m_order){
			throw new \Exception("[order_mall][afterUpdateStatus][m_order:{$o_id} not found]");
		}

		//商城订单子状态由 其他状态 => 已成交，子订单主状态变为待审，修改主订单金额，资产操作为增加
		if( $to == self::R_STATUS_COMPLETED){

			D()->db('order')->updateFanli($o_id, $new_field['fanli']);
			$this->update($o_id, array('status'=>self::STATUS_WAIT_CONFIRM));
			return true;
		}

		//订单子状态变为无效/失败，判断是否有打款流水平衡，扣除多余部分，主状态变为不通过
		if( $to == self::R_STATUS_INVALID){
			$this->afterUpdateStatus($o_id, self::STATUS_PASS, self::STATUS_INVALID, $new_field);
			return true;
		}
	}

	/**
	 * 判断订单唯一性，较为复杂
	 * @param  array  $order  商城订单比对信息
	 * @return boolean        [description]
	 */
	function isExisted($order){

		if(!@$order['r_orderid'])return false;
		//商城订单ID有可能全部一样，每总商品一张订单，因此判断商品ID
		if($order['r_id']){
			$exist = D('order')->db('order_mall')->field('o_id', array('r_orderid'=>$order['r_orderid'], 'r_id'=>$order['r_id'], 'sp'=>$order['sp'], 'driver'=>$order['driver']));
		}else{
			//此处有可能订单ID相同，但没返回商品ID，需用排除法分析是否存在订单
			$sim_exist = D('order')->db('order_mall')->find(array('r_orderid'=>$order['r_orderid'], 'sp'=>$order['sp'], 'driver'=>$order['driver']));

			if(!$sim_exist){
				$exist = false;
			}else{
				clearTableName($sim_exist);
				//存在相同ID订单，但入库时间小于10秒(证明在同批列表)
				if(abs(strtotime($sim_exist['createtime']) - time()) < 10){
					$exist = false;
				//存在相同ID订单，下单时间不同
				}else if($sim_exist['buydatetime'] != $order['buydatetime']){
					$exist = false;
				}else{
					$tmp = D('order')->db('order_mall')->findAll(array('r_orderid'=>$order['r_orderid'], 'sp'=>$order['sp'], 'r_price'=>$order['r_price'], 'driver'=>$order['driver']));
					clearTableName($tmp);
					if(count($tmp) == 1){//当且仅当存在一个相同价格，相同对方订单ID的订单，该订单命中
						$exist = $tmp[0]['o_id'];
					}else{ //有可能价格变了(但已存在)，也有可能是对方后续才补的旧订单
						$exist = true;
					}
				}
			}
		}
		return $exist;
	}

	//置空save，只允许从add/update进入
	function save(){}
}
?>