<?php
//审核总表
namespace DB;

class Review extends _Db {

	var $name = 'Review';
	var $useDbConfig = 'promotion';

	//待审状态定义
	const STATUS_WAIT_REVIEW = 0;
	const STATUS_DONE = 1;

	const TYPE_PROMO = 1; //特卖商品
	const TYPE_GOODS_COMMENT = 2; //商品评论
	const TYPE_BRAND_COMMENT = 3; //品牌评论
	const TYPE_ZHIDAO = 4; //品牌知道
	const TYPE_9 = 5; //9块9商品
	const TYPE_JIE = 6; //逛街商品

	function add($type, $data){

		if($type == self::TYPE_PROMO){
			if(!$data['sp'] || !$data['goods_id'])return;
			$id = $this->field('id', array('sp'=>$data['sp'], 'goods_id'=>$data['goods_id']));
			if($id)return;
		}

		return parent::add(array('sp'=>$data['sp'], 'goods_id'=>$data['goods_id'], 'type'=>$type));
	}

	//删除待审特卖
	function delete($sp, $goods_id){

		if(!$sp || !$goods_id)return;
		return $this->query("DELETE FROM duosq_promotion.review WHERE sp = '{$sp}' AND goods_id = '{$goods_id}'");
	}
}
?>