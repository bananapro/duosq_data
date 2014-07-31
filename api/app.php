<?PHP
//APP接口底层
namespace API;

class App extends _Api {

	//推送消息，待解决指定url问题
	function pushMessage($device_id, $platform, $message, $notify_num, $url=''){

		if(!$device_id || !in_array($platform, array('ios', 'android')) || !$message)return;

		if($platform == 'ios'){
			I('api/ios_push');
			$obj = new \iosPush();
			$obj->pushMessage($device_id, $message, $notify_num, $url='');
		}
	}

	//推送消息数
	function pushNotify($device_id, $platform, $num=0){

		if(!$device_id || !in_array($platform, array('ios', 'android')))return;

		if($platform == 'ios'){
			I('api/ios_push');
			$obj = new \iosPush();
			return $obj->pushBadge($device_id, $num);
		}
	}
}

?>