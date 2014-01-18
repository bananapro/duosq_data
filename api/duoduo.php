<?php
//多多返利接口访问底层
namespace API;

class Duoduo extends _Api {

	/**
	 * 调用多多返利接口进行集分宝支付
	 * @param  bigint $o_id    扣款订单ID
	 * @param  string $alipay  支付宝账号
	 * @param  int $num        集分宝数量
	 * @return array           支付结果(status errcode api_result)
	 */
	function pay($o_id, $alipay, $num, &$errcode, &$api_ret) {

		$p = array();
		$p['mod'] = 'jifenbao';
		$p['act'] = 'pay';
		$p['alipay'] = $alipay;
		$p['num'] = $num;
		$p['txid'] = intval(str_replace('-', '', $o_id));
		$p['url'] = 'dd.duosq.com';
		$p['realname'] = $o_id;
		$p['mobile'] = $o_id;
		$p['version'] = 2;
		$p['openname'] = 'duosq.com';

		$key = D()->redis('keys')->duoduo();
		$p['checksum'] = md5($key['value']);
		$p['format'] = 'json';
		$p['client_url'] = 'dd.duosq.com';
		$url = 'http://issue.duoduo123.com/api/' . '?' . http_build_query($p);

		if(MY_DEBUG_PAY_SUCC==true){
			$api_ret = array('s'=>1);
		}else if($p['checksum']){
			$json = file_get_contents($url);
			$api_ret = json_decode($json, true);
		}else{
			$api_ret = array('s'=>0, 'r'=>'校验码');
		}

		if ($api_ret['s'] == 1) {
			$ret = 1;

		} elseif ($api_ret['s'] == 2 || $api_ret['s'] == 0) {
			$ret = 0;

			if (strpos($api_ret['r'], '此单提现已发放') !== false) {
				$errcode = _e('jfb_trade_repeat');
			} elseif (strpos($api_ret['r'], '没有找到用户') !== false) {
				$errcode = _e('jfb_account_nofound');
			} elseif (strpos($api_ret['r'], '支付宝一日内第3次提现') !== false) {
				$errcode = _e('jfb_duoduo_limit_3times_pre_day');
			} elseif (strpos($api_ret['r'], '校验码') !== false) {
				$errcode = _e('jfb_apikey_invalide');
			} else {
				$errcode = _e('jfb_api_err');
			}

		} else {
			$ret = 0;
			$errcode = _e('jfb_api_err');
		}

		if($ret){
			$action_code = 1100;
			$action_status = 1;
		}else{
			$action_code = 1101;
			$action_status = 0;
		}

		$api_ret = serialize($api_ret);
		D('log')->action($action_code, $action_status, array('operator'=>2, 'status'=>$action_status, 'data1'=>$o_id, 'data2'=>$alipay, 'data3'=>$num, 'data4'=>$api_ret, 'data5'=>$errcode));

		return $ret;
	}

	/**
	 * 调用多多接口获取新的支付授权码
	 * @return [type] [description]
	 */
	function sendPayKey(){
		$p = array();
		$p['mod'] = 'user';
		$p['act'] = 'get_info';
		$p['tag'] = 'send_email';
		$p['checksum'] = '';
		$p['version'] = 2;
		$p['openname'] = 'duosq.com';
		$p['openpwd']=md5('bpro880214');
		$p['format']='json';
		$p['client_url']='dd.duosq.com';
		$url = 'http://issue.duoduo123.com/api/' . '?' . http_build_query($p);

		$json = file_get_contents($url);
		$api_ret = json_decode($json, true);
		return $api_ret;
	}
}
?>