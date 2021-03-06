<?php
//各类业务锁专用底层
namespace REDIS;

class Lock extends _Redis {

	protected $namespace = 'lock';
	protected $dsn_type = 'database';

	const LOCK_QUAN_REWARD = 'quan_reward';
	const LOCK_CASHGIFT_ADD = 'cashgift_add';
	const LOCK_LOTTERY_ADD = 'lottery_add';
	const LOCK_YUNGOU_SIGN = 'yungou_sign';
	const LOCK_COUPON_ROB = 'coupon_rob';
	const LOCK_COUPON_ROB_NUM = 'coupon_rob_num';
	const LOCK_SIGN = 'sign';
	const LOCK_SUBSCRIBE_OPTION = 'subscribe_option';
	const LOCK_SUBSCRIBE_PUSH = 'subscribe_push';
	const LOCK_EMAIL_MONITOR = 'email_monitor';
	const LOCK_SUBSCRIBE_CANG = 'subscribe_cang';
	const LOCK_IMPORT_GOODS_ZHE800 = 'import_goods_zhe800';
	const LOCK_IMPORT_GOODS_MEILISHUO = 'import_goods_meilishuo';
	const LOCK_GET_TAOBAO_ITEM_DEEP_INFO = 'api_taobao_deep_info';
	const LOCK_CLEAR_TAOBAO_ITEM_SMALL_IMG = 'clear_taobao_item_small_img';
	const LOCK_CHECK_TAOBAO_ITEM_VALID = 'check_taobao_item_vaild';
	const LOCK_LEAVE_REWARD_PKG = 'leave_reward_pkg';
	const LOCK_LEAVE_REWARD_PKG_2WEEK = 'leave_reward_pkg_2week';
	const LOCK_P2P_RECORD = 'p2p_record';
	const LOCK_P2P_ALARM = 'p2p_alarm';
	const LOCK_YUNGOU_TOKEN = 'yungou_token';

	/**
	 * 获得一个业务锁
	 * @param  string $trade_type 业务类型
	 * @param  bigint $id         业务ID
	 * @return bool               是否成功获得锁
	 */
	function getlock($trade_type, $id){

		$expire = 5;//默认5秒锁
		$key = $this->key($trade_type, $id);
		if(!$key)return false;

		$ret = $this->setnx($key['key'], time());
		if(!$ret){
			return false; //锁被占用了
		}else{
			if($expire)
				$this->expire($key['key'], $key['expire']);
			return true;
		}
	}

	//判断锁是否存在
	function check($trade_type, $id){

		$key = $this->key($trade_type, $id);
		if(!$key)return false;
		return $this->exists($key['key']);
	}

	/**
	 * 释放一个业务锁
	 * @param  string $trade_type 业务类型
	 * @param  bigint $id         业务ID
	 */
	function unlock($trade_type, $id){

		if(!$trade_type || !$id)return false;

		$key = $this->key($trade_type, $id);
		if(!$key)return false;
		$this->del($key['key']);
	}

	//返回拼装好的key
	protected function key($trade_type, $id){

		if(!$trade_type || !$id)return false;
		$expire = 5;//默认5秒锁
		switch ($trade_type) {
			case self::LOCK_QUAN_REWARD:
				$expire = 30;
				break;
			case self::LOCK_CASHGIFT_ADD:
				$expire = 10;
				break;
			case self::LOCK_LOTTERY_ADD:
				$id = $id.':day:'.date('d');
				$expire = DAY;
				break;
			case self::LOCK_COUPON_ROB_NUM:
				$id = $id.':day:'.date('d');
				$expire = DAY;
				break;
			case self::LOCK_COUPON_ROB:
				$expire = 60;
				break;
			case self::LOCK_SIGN:
			case self::LOCK_YUNGOU_SIGN:
				$id = $id.':day:'.date('d');
				$expire = DAY*2;
				break;
			case self::LOCK_SUBSCRIBE_OPTION:
				$expire = 5;
				break;
			case self::LOCK_SUBSCRIBE_PUSH:
				$expire = DAY*2;
				break;
			case self::LOCK_EMAIL_MONITOR:
				$expire = HOUR*6;
				break;
			case self::LOCK_SUBSCRIBE_CANG:
				$expire = 1;
				break;
			case self::LOCK_GET_TAOBAO_ITEM_DEEP_INFO:
				$expire = DAY;
				break;
			case self::LOCK_IMPORT_GOODS_ZHE800:
				$expire = WEEK;
				break;
			case self::LOCK_IMPORT_GOODS_MEILISHUO:
				$expire = MONTH;
				break;
			case self::LOCK_CHECK_TAOBAO_ITEM_VALID:
				$expire = DAY;
				break;
			case self::LOCK_YUNGOU_TOKEN:
				$expire = DAY;
				break;
			case self::LOCK_CLEAR_TAOBAO_ITEM_SMALL_IMG:
				$expire = WEEK;
				break;
			case self::LOCK_LEAVE_REWARD_PKG:
				$expire = YEAR*2;
				break;
			case self::LOCK_LEAVE_REWARD_PKG_2WEEK:
				$expire = WEEK*2;
				break;
			case self::LOCK_P2P_RECORD:
				$expire = WEEK;
				break;
			case self::LOCK_P2P_ALARM:
				$expire = HOUR*2;
				break;
			default:
				$expire = 10;
		}

		return array('key'=>$trade_type.':id:'.$id, 'expire'=>$expire);
	}
}
?>