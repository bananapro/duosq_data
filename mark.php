<?php
//DAL:用户标识信息访问模块
namespace DAL;

class Mark extends _Dal {

	/**
	 * 返回来源渠道信息，但有可能不存在
	 * @return [type] [description]
	 */
	function detail($mark_id=0) {

		if(!$mark_id){
			$mark_id = $this->getId();
			if(!$mark_id)return false;
		}
		$ret = $this->db('mark')->find(array('id'=>$mark_id));
		return clearTableName($ret);
	}

	/**
	 * 返回来源渠道名称，但有可能不存在
	 * @return [type] [description]
	 */
	function getSc(){

		$info = $this->detail();
		return myIsset($info['sc']);
	}

	/**
	 * 获取渠道风险等级
	 * @return [type] [description]
	 */
	function getScRisk(){

		return 0;
	}

	/**
	 * 返回渠道编号
	 * @return [type] [description]
	 */
	function getId(){

		$mark_id = intval(@$_COOKIE['mark']);
		if($mark_id){
			if($this->detail($mark_id)){
				return $mark_id;
			}
		}
		return false;
	}

	/**
	 * 添加用户流量信息
	 */
	function add($sc){

		if($this->getId())return;
		$data = array();
		$data['sc'] = $sc;
		$data['referer'] = env('HTTP_REFERER');
		$data['ip'] = getIp();

		I('ip2location');
		$ip = new \ip2location();
		$data['area'] = $ip->province($data['ip']);
		$data['area_detail'] = $ip->location($data['ip']);

		$data['client'] = getBrowser();
		$data['client_full'] = getAgent();

		if($data['area'] == '上海' && $data['client'] == 'Mozilla/4.0' && !$data['referer'])return;

		$mark_id = $this->db('mark')->add(arrayClean($data));
		if($mark_id){
			setcookie('mark', $mark_id, time() + YEAR, '/', CAKE_SESSION_DOMAIN);
		}
	}
}
?>