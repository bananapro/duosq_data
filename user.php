<?php
//DAL:用户数据访问模块
namespace DAL;

class user extends _Dal {

	const ALIPAY_VALID_NONE = 0;
	const ALIPAY_VALID_JFB = 1;
	const ALIPAY_VALID_TRUENAME = 2;
	const ALIPAY_VALID_ERROR = 10;

	//获取用户信息
	function detail($user_id, $field = false){

		if(!$user_id)return;
		$ret = $this->db('user')->find(array('id'=>$user_id));
		clearTableName($ret);
		if($field){
			return $ret[$field];
		}else{
			return $ret;
		}
	}

	//用户支付宝验证信息更新
	function validAlipay($user_id, $level=0){

		if(!$user_id)return;
		$curr = D('user')->detail($user_id, 'alipay_valid');
		if($curr==self::ALIPAY_VALID_TRUENAME && $level<$curr){
			return;//不允许将实名认证级别的支付宝降为低级别
		}
		return $this->db('user')->update($user_id, array('alipay_valid'=>$level));
	}

	//根据淘宝订单末位，拉出匹配的用户
	function getUserByTaobaoNo($no){

		if(!$no)return;
		$user_id = $this->db('user_taobao')->field('user_id', array('taobao_no'=>$no));
		return $this->detail($user_id);
	}
}

?>