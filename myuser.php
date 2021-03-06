<?php
//DAL:当前用户自身数据访问模块
namespace DAL;

class Myuser extends _Dal {

	/**
	 * 用户支付宝保存
	 * @param  [type] $alipay 支付宝账号
	 * @return array          用户ID，账号已存在状态
	 */
	function saveAlipay($alipay, &$err=''){

		if(!$alipay){
			$err = '支付宝账号不能为空!';
			return;
		}

		if(!valid($alipay, 'email') && !valid($alipay, 'mobile')){
			$err = '支付宝错误，是手机号或邮箱才对哟!';
			return;
		}

		$ret = array();
		if($user_id = $this->db('user')->getIdByAlipay($alipay)){
			$ret['user_id'] = $user_id;
			$ret['exist'] = true;
		}else{
			$this->db()->begin();

			if($user_id = $this->db('user')->add($alipay)){
				$ret['user_id'] = $user_id;
				$ret['exist'] = false;

				//保存邀请好友
				$parent_id = getCookieParentId();

				if($parent_id){
					D('friend')->addInvite($user_id, $parent_id);
					//互为朋友圈
					if($_GET['allow']=='not'){
						D('friend')->addQuan($parent_id, $user_id, 0);
					}else{
						D('friend')->addQuan($parent_id, $user_id);
					}
				}
				$this->db()->commit();
			}else{
				$err = '系统失败，请稍后尝试，或联系客服！';
				$this->db()->rollback();
			}
		}

		if(!$ret){
			if(!$err)$err = '系统登陆错误，请稍后尝试，或联系客服！';
		}
		return $ret;
	}

	/**
	 * 用户更改支付宝，仅当新支付宝没人使用
	 * @param  [type] $alipay 支付宝账号
	 * @return bigint         用户ID
	 */
	function changeAlipay($alipay, &$err){

		if(!$alipay){
			$err = '支付宝账号不能为空!';
			return;
		}

		if(!valid($alipay, 'email') && !valid($alipay, 'mobile')){
			$err = '支付宝错误，是手机号或邮箱才对哟!';
			return;
		}

		if(!$this->isLogined()){
			$err = '操作非法，请刷新页面！';
			return;
		}

		if($this->getAlipayValid() != \DAL\User::ALIPAY_VALID_ERROR){
			$err = '当前状态不允许修改支付宝账号！';
			return;
		}

		$ret = array();
		if($user_id = $this->db('user')->getIdByAlipay($alipay)){
			//$err = '您新输的支付宝已存在，请换一个!';
			//return;
			//直接登录旧用户
			return $this->login($user_id);
		}else{
			$this->db()->begin();

			if($ret = $this->db('user')->update($this->getId(), array('alipay'=>$alipay, 'alipay_valid'=>\DAL\User::ALIPAY_VALID_NONE))){
				$this->db()->commit();
				$this->relogin();
			}else{
				$this->db()->rollback();
			}
		}

		if(!$ret){
			$err = '系统发生错误，请刷新页面后重试!';
		}
		return $ret;
	}

	/**
	 * 用户登录，设置session
	 * @return [type] [description]
	 */
	function login($userid){

		if(!$userid)return;
		$user = D('user')->detail($userid);
		if(!$user)return;
		//加载个人信息到session
		$user['islogined'] = true;
		if($user['sp'])$user['sp'] = unserialize($user['sp']);


		$this->sess('userinfo', $user);
		//如果用户在未登录前有过搜索日志，此处加入到log_search
		D('log')->searchSave();

		//加载到cookie方便静态js直接调用
		setcookie('display_name', $this->getNickname(), time() + MONTH, '/', CAKE_SESSION_DOMAIN);

		return true;
	}

	//更新用户信息后，重新刷新用户session数据
	function relogin(){
		return $this->login($this->getId());
	}

	//获取用户ID
	function getId(){
		return $this->sess('userinfo.id');
	}

	//获取用户支付宝
	function getAlipay(){
		return $this->sess('userinfo.alipay');
	}

	//获取用户支付宝验证信息
	function getAlipayValid(){
		return D('user')->detail($this->getId(), 'alipay_valid', false);
	}

	//获取用户接收信息验证
	function getMsgValid(){
		return D('user')->detail($this->getId(), 'msg_valid');
	}

	//获取用户下单数
	function getOrder(){
		return D('user')->detail($this->getId(), 'has_order');
	}

	//获取用户当前等级
	function getLevel(){
		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'level');
	}

	//获取用户来源
	function getReferer(){
		return $this->sess('userinfo.referer');
	}

	//获取用户来源风险等级
	function getScRisk(){
		return $this->sess('userinfo.sc_risk');
	}

	//获取用户来源标识
	function getMarkSc(){

		return isset($_GET['from']) ? $_GET['from'] : $this->sess('userinfo.mark_sc');
	}

	//获取用户来源标识ID
	function getMarkId(){
		return $this->sess('userinfo.mark_id');
	}

	//获取用户昵称
	function getNickname($strict=false){

		if(!$strict){
			if($this->sess('userinfo.nickname')){
				return $this->sess('userinfo.nickname');
			}else{
				return mask($this->getAlipay());
			}
		}else{
			return $this->sess('userinfo.nickname');
		}
	}

	//保存用户昵称
	function saveNickname($nickname){

		if(!$nickname)return;
		if(D('user')->search(array('nickname'=>$nickname))){
			return;
		}

		$ret = $this->db('user')->update(D('myuser')->getId(), array('nickname'=>$nickname));
		if($ret){
			$this->sess('userinfo.nickname', $nickname);
			//加载到cookie方便静态js直接调用
			setcookie('display_name', $this->getNickname(), time() + MONTH, '/', CAKE_SESSION_DOMAIN);
			return true;
		}
	}

	//设置用户强制现金支付标志位
	function updateForceCash($force = 1){

		if(!$this->isLogined())return;
		return $this->db('user')->update($this->getId(), array('force_cash'=>$force));
	}

	//判断用户是否已现金支付
	function isForceCash(){

		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'force_cash');
	}

	//判断用户是否登录
	function isLogined(){
		return $this->sess('userinfo.islogined')?true:false;
	}

	//判断用户是否抢过官方红包
	function hasRobtime(){
		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'has_robtime');
	}

	//判断用户是否允许赠送新人红包
	function canGetCashgift(){
		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'can_get_cashgift');
	}

	//判断用户是否允许切换结算模式
	function canSwitchCash(){
		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'can_switch_cash');
	}

	//判断用户是否有过订单
	function hasOrder(){
		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'has_order');
	}

	//判断用户是否黑名单
	function isBlack(){
		if(!$this->isLogined())return;
		return D('user')->isBlack($this->getId());
	}

	//存取随机计算的新人抽奖集分宝数量
	function newgift($amount=0, $get=false){

		if(!$get){
			if($amount){
				$this->sess('newgift', $amount);
			}else{
				$amount = $this->sess('newgift');
				$this->sess('newgift', null);
				return $amount;
			}
		}else{
			$amount = $this->sess('newgift');
			return $amount;
		}
	}

	//存取随机计算的每日抽奖
	function lottery($amount=0, $gifttype=1, $prize=0, $get=false){

		if(!$get){
			if($amount){
				$this->sess('lottery', array('amount'=>$amount, 'gifttype'=>$gifttype, 'prize'=>$prize));
			}else{
				$data = $this->sess('lottery');
				$this->sess('lottery', null);
				return $data;
			}
		}else{
			$data = $this->sess('lottery');
			return $data;
		}
	}

	/**
	 * 获取去过的商城/判断是否去过某商城($sp赋值)
	 * 用途：跳转页面出现首次提醒
	 * @param  [string] $sp 商城标识，如果不为空则提取具体商城跳转次数
	 */
	function getSp($sp=''){

		if(!$this->isLogined())return;
		if(!$sp){
			return $this->sess('userinfo.sp');
		}else{
			return intval($this->sess('userinfo.sp.'.$sp));
		}
	}

	//增加当前用户去过的商城次数
	function addSp($sp){

		if(!$this->isLogined())return;
		$count = intval($this->sess('userinfo.sp.'.$sp));
		$count++;
		$this->sess('userinfo.sp.'.$sp, $count);
		$this->db('user')->update($this->getId(), array('sp'=>serialize($this->getSp())));
		return $count;
	}

	//更新自己的昵称
	function updateNickname($nickname){

		if(!$this->isLogined())return;
		if(strlen($nickname)>20)return false;
		if(!$nickname){
			$this->db('user')->update($this->getId(), array('nickname'=>$nickname));
			$this->sess('userinfo.nickname', $nickname);
			setcookie('display_name', $this->getNickname(), time() + MONTH, '/', CAKE_SESSION_DOMAIN);
			return true;
		}
	}

	/**
	 * 获取当前用户的红包信息
	 * @return [type] [description]
	 */
	function getCashGift($status=''){

		if(!$this->isLogined())return;
		return D('cashgift')->getSummary($this->getId(), $status);
	}

	//获取当前用户抽奖等级(3个月内购物)
	function getLotteryLevel(){
		
		if(!$this->isLogined())return 1;

		$shopping_balance = D('fund')->getShoppingBalance($this->getId(), date('Y-m-d', time()-MONTH*3));

		$level = 1;

		if($shopping_balance < 10000){
			$level = 1;
		}else if($shopping_balance >= 10000 && $shopping_balance < 30000){
			$level = 2;
		}else if($shopping_balance >= 30000 && $shopping_balance < 60000){
			$level = 3;
		}else if($shopping_balance >= 60000 && $shopping_balance < 100000){
			$level = 4;
		}else if($shopping_balance >= 100000 && $shopping_balance < 150000){
			$level = 5;
		}else if($shopping_balance >= 150000 && $shopping_balance < 200000){
			$level = 6;
		}else if($shopping_balance >=2000000){
			$level = 7;
		}

		return $level;
	}

	//标记打开小金库的时间
	function markOpenCenter($type='set', $time=''){

		if($type == 'set'){
			if(!$time){
				$time = time();
			}
			//已有时间是超前的，则不用更新
			if($this->sess('open_center') && $this->sess('open_center')>$time)return;
			$this->sess('open_center', $time);
		}else{
			return $this->sess('open_center');
		}
	}

	//更新最后登录时间
	function updateLasttime(){

		if(!$this->isLogined())return;
		$this->db('user')->update($this->getId(), array('lasttime'=>date('Y-m-d H:i:s')));
	}

	//获取最后登录时间
	function getLasttime(){

		if(!$this->isLogined())return;
		return D('user')->detail($this->getId(), 'lasttime');
	}

	//设置会话订阅邮箱(加密)
	function setSubscribeEmail($email){

		if(!$email)return;

		setcookie('subscribe_email', $email, 0, '/', CAKE_SESSION_DOMAIN);
		setcookie('subscribe_sn', md5($email.MY_API_SECRET), 0, '/', CAKE_SESSION_DOMAIN);

		return true;
	}

	//获取会话订阅邮箱(解密)
	function getSubscribeEmail(){

		$email = @$_COOKIE['subscribe_email'];
		if($email && md5($email.MY_API_SECRET) == @$_COOKIE['subscribe_sn']){
			return $email;
		}
	}

	//清空订阅会话
	function closeSubscribe(){

		setcookie('subscribe_email', '', time()-MONTH, '/', CAKE_SESSION_DOMAIN);
		setcookie('subscribe_sn', '', time()-MONTH, '/', CAKE_SESSION_DOMAIN);
	}
}

?>