jQuery(function () {
	jQuery.websocket_connect = function (websocket_id, callback) {
		jQuery.get(
			websocket.ajaxurl,
			{
				'action': 'websocket_connect',
				'websocket_id': websocket_id
			},
			function (res) {
				res = JSON.parse(res);
				if (res.status) {
					$return = callback(res);
					if ($return === false) {
						return false;
					}
				} else {
					return false;
				}
				return jQuery.websocket_connect(websocket_id, callback);
			}
		);
	}
	
	jQuery.websocket_emit = function (websocket_id, event_type, event_info, callback) {
		jQuery.post(
			websocket.ajaxurl,
			{
				'action': 'websocket_emit',
				'websocket_id': websocket_id,
				'event_type': event_type,
				'event_info': JSON.stringify(event_info)
			},
			function (res) {
				res = JSON.parse(res);
				return callback(res.status);
			}
		);
	}
});