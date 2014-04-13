<?php
//DAL:商城数据访问模块
namespace DAL;

class Shop extends _Dal {

	function detail($sp, $field=false) {

		if(!$sp)return;
		$shop = $this->db('shop')->find(array('sp'=>$sp));
		clearTableName($shop);
		if($field){
			return $shop[$field];
		}else{
			return $shop;
		}
	}

	function getName($sp){

		if(!$sp)return;
		$shop = $this->detail($sp);
		if(mb_strlen($shop['name'], 'utf8')<3){
			$shop['name'] = $shop['name'].'商城';
		}
		return $shop['name'];
	}
}
?>