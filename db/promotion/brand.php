<?php
//品牌数据
namespace DB;

class Brand extends _Db {

	var $name = 'brand';
	var $useDbConfig = 'promotion';

	var $validate = array('cat'=>VALID_NOT_EMPTY);

	//新增品牌，返回品牌ID
	function add($data){

		if(!@$data['cat']){
			$this->validationErrors['cat'] = 1;
			return;
		}

		if(!@$data['name'] && !@$data['name_en']){
			$this->validationErrors['name'] = 1;
			return;
		}

		if($this->find(array('name'=>$data['name'],'name_en'=>$data['name_en'],'cat'=>$data['cat']))){
			$this->validationErrors['exist'] = 1;
			return;
		}

		return parent::add($data);
	}
}
?>