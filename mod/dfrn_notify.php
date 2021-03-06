<?php

require_once('simplepie/simplepie.inc');
require_once('include/items.php');


function dfrn_notify_post(&$a) {

	$dfrn_id      = ((x($_POST,'dfrn_id'))      ? notags(trim($_POST['dfrn_id']))   : '');
	$dfrn_version = ((x($_POST,'dfrn_version')) ? (float) $_POST['dfrn_version']    : 2.0);
	$challenge    = ((x($_POST,'challenge'))    ? notags(trim($_POST['challenge'])) : '');
	$data         = ((x($_POST,'data'))         ? $_POST['data']                    : '');
	$key          = ((x($_POST,'key'))          ? $_POST['key']                     : '');

	$direction = (-1);
	if(strpos($dfrn_id,':') == 1) {
		$direction = intval(substr($dfrn_id,0,1));
		$dfrn_id = substr($dfrn_id,2);
	}

	$r = q("SELECT * FROM `challenge` WHERE `dfrn-id` = '%s' AND `challenge` = '%s' LIMIT 1",
		dbesc($dfrn_id),
		dbesc($challenge)
	);
	if(! count($r)) {
		logger('dfrn_notify: could not match challenge to dfrn_id ' . $dfrn_id);
		xml_status(3);
	}

	$r = q("DELETE FROM `challenge` WHERE `dfrn-id` = '%s' AND `challenge` = '%s' LIMIT 1",
		dbesc($dfrn_id),
		dbesc($challenge)
	);

	// find the local user who owns this relationship.

	$sql_extra = '';
	switch($direction) {
		case (-1):
			$sql_extra = sprintf(" AND ( `issued-id` = '%s' OR `dfrn-id` = '%s' ) ", dbesc($dfrn_id), dbesc($dfrn_id));
			break;
		case 0:
			$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
			break;
		case 1:
			$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
			break;
		default:
			xml_status(3);
			break; // NOTREACHED
	}
		 

	$r = q("SELECT `contact`.*, `contact`.`uid` AS `importer_uid`, 
		`contact`.`pubkey` AS `cpubkey`, `contact`.`prvkey` AS `cprvkey`, `user`.* FROM `contact` 
		LEFT JOIN `user` ON `contact`.`uid` = `user`.`uid` 
		WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0 
		AND `user`.`nickname` = '%s' $sql_extra LIMIT 1",
		dbesc($a->argv[1])
	);

	if(! count($r)) {
		logger('dfrn_notify: contact not found for dfrn_id ' . $dfrn_id);
		xml_status(3);
		//NOTREACHED
	}

	$importer = $r[0];

	logger('dfrn_notify: received notify from ' . $importer['name'] . ' for ' . $importer['username']);
	logger('dfrn_notify: data: ' . $data, LOGGER_DATA);

	if($importer['readonly']) {
		// We aren't receiving stuff from this person. But we will quietly ignore them
		// rather than a blatant "go away" message.
		logger('dfrn_notify: ignoring');
		xml_status(0);
		//NOTREACHED
	}

	if(strlen($key)) {
		$rawkey = hex2bin(trim($key));
		logger('rino: md5 raw key: ' . md5($rawkey));
		$final_key = '';

		if((($importer['duplex']) && strlen($importer['cpubkey'])) || (! strlen($importer['cprvkey']))) {
			openssl_public_decrypt($rawkey,$final_key,$importer['cpubkey']);
		}
		else {
			openssl_private_decrypt($rawkey,$final_key,$importer['cprvkey']);
		}

		logger('rino: received key : ' . $final_key);
		$data = aes_decrypt(hex2bin($data),$final_key);
		logger('rino: decrypted data: ' . $data, LOGGER_DATA);
	}

	// Consume notification feed. This may differ from consuming a public feed in several ways
	// - might contain email
	// - might contain remote followup to our message
	//		- in which case we need to accept it and then notify other conversants
	// - we may need to send various email notifications

	$feed = new SimplePie();
	$feed->set_raw_data($data);
	$feed->enable_order_by_date(false);
	$feed->init();

	$ismail = false;

	$rawmail = $feed->get_feed_tags( NAMESPACE_DFRN, 'mail' );
	if(isset($rawmail[0]['child'][NAMESPACE_DFRN])) {

		logger('dfrn_notify: private message received');

		$ismail = true;
		$base = $rawmail[0]['child'][NAMESPACE_DFRN];

		$msg = array();
		$msg['uid'] = $importer['importer_uid'];
		$msg['from-name'] = notags(unxmlify($base['sender'][0]['child'][NAMESPACE_DFRN]['name'][0]['data']));
		$msg['from-photo'] = notags(unxmlify($base['sender'][0]['child'][NAMESPACE_DFRN]['avatar'][0]['data']));
		$msg['from-url'] = notags(unxmlify($base['sender'][0]['child'][NAMESPACE_DFRN]['uri'][0]['data']));
		$msg['contact-id'] = $importer['id'];
		$msg['title'] = notags(unxmlify($base['subject'][0]['data']));
		$msg['body'] = escape_tags(unxmlify($base['content'][0]['data']));
		$msg['seen'] = 0;
		$msg['replied'] = 0;
		$msg['uri'] = notags(unxmlify($base['id'][0]['data']));
		$msg['parent-uri'] = notags(unxmlify($base['in-reply-to'][0]['data']));
		$msg['created'] = datetime_convert(notags(unxmlify('UTC','UTC',$base['sentdate'][0]['data'])));
		
		dbesc_array($msg);

		$r = dbq("INSERT INTO `mail` (`" . implode("`, `", array_keys($msg)) 
			. "`) VALUES ('" . implode("', '", array_values($msg)) . "')" );

		// send email notification if requested.

		require_once('bbcode.php');
		if($importer['notify-flags'] & NOTIFY_MAIL) {

			$body = html_entity_decode(strip_tags(bbcode(stripslashes($msg['body']))),ENT_QUOTES,'UTF-8');

			if(function_exists('quoted_printable_encode'))
				$body = quoted_printable_encode($body);
			else
				$body = qp($body);

			$tpl = load_view_file('view/mail_received_eml.tpl');			
			$email_tpl = replace_macros($tpl, array(
				'$sitename' => $a->config['sitename'],
				'$siteurl' =>  $a->get_baseurl(),
				'$username' => $importer['username'],
				'$email' => $importer['email'],
				'$from' => $msg['from-name'],
				'$title' => stripslashes($msg['title']),
				'$body' => $body
			));

			$res = mail($importer['email'], t('New mail received at ') . $a->config['sitename'],
				$email_tpl, 'From: ' . t('Administrator') . '@' . $a->get_hostname() . "\r\n"
					. 'MIME-Version: 1.0' . "\r\n"
					. 'Content-type: text/plain; charset=UTF-8' . "\r\n" 
					. 'Content-transfer-encoding: quoted-printable' . "\r\n"
			);
		}
		xml_status(0);
		// NOTREACHED
	}	
	
	logger('dfrn_notify: feed item count = ' . $feed->get_item_quantity());

	foreach($feed->get_items() as $item) {

		$deleted = false;

		$rawdelete = $item->get_item_tags( NAMESPACE_TOMB , 'deleted-entry');
		if(isset($rawdelete[0]['attribs']['']['ref'])) {
			$uri = $rawthread[0]['attribs']['']['ref'];
			$deleted = true;
			if(isset($rawdelete[0]['attribs']['']['when'])) {
				$when = $rawthread[0]['attribs']['']['when'];
				$when = datetime_convert('UTC','UTC', $when, 'Y-m-d H:i:s');
			}
			else
				$when = datetime_convert('UTC','UTC','now','Y-m-d H:i:s');
		}
		if($deleted) {
			$r = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($uri),
				intval($importer['importer_uid'])
			);
			if(count($r)) {
				$item = $r[0];
				if($item['uri'] == $item['parent-uri']) {
					$r = q("UPDATE `item` SET `deleted` = 1, `edited` = '%s', `changed` = '%s'
						WHERE `parent-uri` = '%s' AND `uid` = %d",
						dbesc($when),
						dbesc(datetime_convert()),
						dbesc($item['uri']),
						intval($importer['importer_uid'])
					);
				}
				else {
					$r = q("UPDATE `item` SET `deleted` = 1, `edited` = '%s', `changed` = '%s' 
						WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
						dbesc($when),
						dbesc(datetime_convert()),
						dbesc($uri),
						intval($importer['importer_uid'])
					);
					if($item['last-child']) {
						// ensure that last-child is set in case the comment that had it just got wiped.
						$q("UPDATE `item` SET `last-child` = 0, `changed` = '%s' WHERE `parent-uri` = '%s' AND `uid` = %d ",
							dbesc(datetime_convert()),
							dbesc($item['parent-uri']),
							intval($item['uid'])
						);
						// who is the last child now? 
						$r = q("SELECT `id` FROM `item` WHERE `parent-uri` = '%s' AND `type` != 'activity' AND `deleted` = 0 AND `uid` = %d
							ORDER BY `created` DESC LIMIT 1",
								dbesc($item['parent-uri']),
								intval($importer['importer_uid'])
						);
						if(count($r)) {
							q("UPDATE `item` SET `last-child` = 1 WHERE `id` = %d LIMIT 1",
								intval($r[0]['id'])
							);
						}	
					}
				}
			}	
			continue;
		}

		$is_reply = false;		
		$item_id = $item->get_id();
		$rawthread = $item->get_item_tags( NAMESPACE_THREAD, 'in-reply-to');
		if(isset($rawthread[0]['attribs']['']['ref'])) {
			$is_reply = true;
			$parent_uri = $rawthread[0]['attribs']['']['ref'];
		}

		if($is_reply) {
			if($feed->get_item_quantity() == 1) {
				logger('dfrn_notify: received remote comment');
				$is_like = false;
				// remote reply to our post. Import and then notify everybody else.
				$datarray = get_atom_elements($feed,$item);
				$datarray['type'] = 'remote-comment';
				$datarray['wall'] = 1;
				$datarray['parent-uri'] = $parent_uri;
				$datarray['uid'] = $importer['importer_uid'];
				$datarray['contact-id'] = $importer['id'];
				if(($datarray['verb'] == ACTIVITY_LIKE) || ($datarray['verb'] == ACTIVITY_DISLIKE)) {
					$is_like = true;
					$datarray['type'] = 'activity';
					$datarray['gravity'] = GRAVITY_LIKE;
					$datarray['last-child'] = 0;
				}
				$posted_id = item_store($datarray);

				if($posted_id) {
					if(! $is_like) {
						$r = q("SELECT `parent` FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1",
							intval($posted_id),
							intval($importer['importer_uid'])
						);
						if(count($r)) {
							$r1 = q("UPDATE `item` SET `last-child` = 0, `changed` = '%s' WHERE `uid` = %d AND `parent` = %d",
								dbesc(datetime_convert()),
								intval($importer['importer_uid']),
								intval($r[0]['parent'])
							);
						}
						$r2 = q("UPDATE `item` SET `last-child` = 1, `changed` = '%s' WHERE `uid` = %d AND `id` = %d LIMIT 1",
								dbesc(datetime_convert()),
								intval($importer['importer_uid']),
								intval($posted_id)
						);
					}

					$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');

					proc_close(proc_open("\"$php_path\" \"include/notifier.php\" \"comment-import\" \"$posted_id\" &", 
						array(),$foo));

					if((! $is_like) && ($importer['notify-flags'] & NOTIFY_COMMENT) && (! $importer['self'])) {
						require_once('bbcode.php');
						$from = stripslashes($datarray['author-name']);
						$tpl = load_view_file('view/cmnt_received_eml.tpl');			
						$email_tpl = replace_macros($tpl, array(
							'$sitename' => $a->config['sitename'],
							'$siteurl' =>  $a->get_baseurl(),
							'$username' => $importer['username'],
							'$email' => $importer['email'],
							'$display' => $a->get_baseurl() . '/display/' . $importer['nickname'] . '/' . $posted_id, 
							'$from' => $from,
							'$body' => strip_tags(bbcode(stripslashes($datarray['body'])))
						));
	
						$res = mail($importer['email'], $from . t(' commented on an item at ') . $a->config['sitename'],
							$email_tpl, "From: " . t('Administrator') . '@' . $a->get_hostname() );
					}
				}

				xml_status(0);
				// NOTREACHED

			}
			else {
				// regular comment that is part of this total conversation. Have we seen it? If not, import it.

				$item_id = $item->get_id();

				$r = q("SELECT `uid`, `last-child`, `edited` FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($item_id),
					intval($importer['importer_uid'])
				);
				// FIXME update content if 'updated' changes
				if(count($r)) {
					$allow = $item->get_item_tags( NAMESPACE_DFRN, 'comment-allow');
					if($allow && $allow[0]['data'] != $r[0]['last-child']) {
						$r = q("UPDATE `item` SET `last-child` = %d, `changed` = '%s' WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
							intval($allow[0]['data']),
							dbesc(datetime_convert()),
							dbesc($item_id),
							intval($importer['importer_uid'])
						);
					}
					continue;
				}
				$datarray = get_atom_elements($feed,$item);
				$datarray['parent-uri'] = $parent_uri;
				$datarray['uid'] = $importer['importer_uid'];
				$datarray['contact-id'] = $importer['id'];
				if(($datarray['verb'] == ACTIVITY_LIKE) || ($datarray['verb'] == ACTIVITY_DISLIKE)) {
					$datarray['type'] = 'activity';
					$datarray['gravity'] = GRAVITY_LIKE;
				}

				$r = item_store($datarray);

				// find out if our user is involved in this conversation and wants to be notified.
			
				if(($datarray['type'] != 'activity') && ($importer['notify-flags'] & NOTIFY_COMMENT)) {

					$myconv = q("SELECT `author-link` FROM `item` WHERE `parent-uri` = '%s' AND `uid` = %d",
						dbesc($parent_uri),
						intval($importer['importer_uid'])
					);
					if(count($myconv)) {
						foreach($myconv as $conv) {
							if($conv['author-link'] != $importer['url'])
								continue;
							require_once('bbcode.php');
							$from = stripslashes($datarray['author-name']);
							$tpl = load_view_file('view/cmnt_received_eml.tpl');			
							$email_tpl = replace_macros($tpl, array(
								'$sitename' => $a->config['sitename'],
								'$siteurl' =>  $a->get_baseurl(),
								'$username' => $importer['username'],
								'$email' => $importer['email'],
								'$from' => $from,
								'$display' => $a->get_baseurl() . '/display/' . $importer['nickname'] . '/' . $r,
								'$body' => strip_tags(bbcode(stripslashes($datarray['body'])))
							));

							$res = mail($importer['email'], $from . t(" commented on an item at ") 
								. $a->config['sitename'],
								$email_tpl,t("From: Administrator@") . $a->get_hostname() );
							break;
						}
					}
				}
				continue;
			}
		}
		else {
			// Head post of a conversation. Have we seen it? If not, import it.

			$item_id = $item->get_id();
			$r = q("SELECT `uid`, `last-child`, `edited` FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($item_id),
				intval($importer['importer_uid'])
			);
			if(count($r)) {
				$allow = $item->get_item_tags( NAMESPACE_DFRN, 'comment-allow');
				if($allow && $allow[0]['data'] != $r[0]['last-child']) {
					$r = q("UPDATE `item` SET `last-child` = %d, `changed` = '%s' WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
						intval($allow[0]['data']),
						dbesc(datetime_convert()),
						dbesc($item_id),
						intval($importer['importer_uid'])
					);
				}
				continue;
			}


			$datarray = get_atom_elements($feed,$item);
			$datarray['parent-uri'] = $item_id;
			$datarray['uid'] = $importer['importer_uid'];
			$datarray['contact-id'] = $importer['id'];
			$r = item_store($datarray);
			continue;
		}
	}

	xml_status(0);
	// NOTREACHED

}


function dfrn_notify_content(&$a) {

	if(x($_GET,'dfrn_id')) {

		// initial communication from external contact, $direction is their direction.
		// If this is a duplex communication, ours will be the opposite.

		$dfrn_id = notags(trim($_GET['dfrn_id']));
		$dfrn_version = (float) $_GET['dfrn_version'];

		logger('dfrn_notify: new notification dfrn_id=' . $dfrn_id);

		$direction = (-1);
		if(strpos($dfrn_id,':') == 1) {
			$direction = intval(substr($dfrn_id,0,1));
			$dfrn_id = substr($dfrn_id,2);
		}

		$hash = random_string();

		$status = 0;

		$r = q("DELETE FROM `challenge` WHERE `expire` < " . intval(time()));

		$r = q("INSERT INTO `challenge` ( `challenge`, `dfrn-id`, `expire` )
			VALUES( '%s', '%s', '%s') ",
			dbesc($hash),
			dbesc($dfrn_id),
			intval(time() + 60 )
		);


		$sql_extra = '';
		switch($direction) {
			case (-1):
				$sql_extra = sprintf(" AND ( `issued-id` = '%s' OR `dfrn-id` = '%s' ) ", dbesc($dfrn_id), dbesc($dfrn_id));
				$my_id = $dfrn_id;
				break;
			case 0:
				$sql_extra = sprintf(" AND `issued-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
				$my_id = '1:' . $dfrn_id;
				break;
			case 1:
				$sql_extra = sprintf(" AND `dfrn-id` = '%s' AND `duplex` = 1 ", dbesc($dfrn_id));
				$my_id = '0:' . $dfrn_id;
				break;
			default:
				$status = 1;
				break; // NOTREACHED
		}

		$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid` 
				WHERE `contact`.`blocked` = 0 AND `contact`.`pending` = 0 AND `user`.`nickname` = '%s' $sql_extra LIMIT 1",
				dbesc($a->argv[1])
		);

		if(! count($r))
			$status = 1;

		$challenge = '';
		$encrypted_id = '';
		$id_str = $my_id . '.' . mt_rand(1000,9999);

		if((($r[0]['duplex']) && strlen($r[0]['pubkey'])) || (! strlen($r[0]['prvkey']))) {
			openssl_public_encrypt($hash,$challenge,$r[0]['pubkey']);
			openssl_public_encrypt($id_str,$encrypted_id,$r[0]['pubkey']);
		}
		else {
			openssl_private_encrypt($hash,$challenge,$r[0]['prvkey']);
			openssl_private_encrypt($id_str,$encrypted_id,$r[0]['prvkey']);
		}

		$challenge    = bin2hex($challenge);
		$encrypted_id = bin2hex($encrypted_id);

		$rino = ((function_exists('mcrypt_encrypt')) ? 1 : 0);

		$rino_enable = get_config('system','rino_encrypt');

		if(! $rino_enable)
			$rino = 0;


		header("Content-type: text/xml");

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\r\n" 
			. '<dfrn_notify>' . "\r\n"
			. "\t" . '<status>' . $status . '</status>' . "\r\n"
			. "\t" . '<dfrn_version>' . DFRN_PROTOCOL_VERSION . '</dfrn_version>' . "\r\n"
			. "\t" . '<rino>' . $rino . '</rino>' . "\r\n" 
			. "\t" . '<dfrn_id>' . $encrypted_id . '</dfrn_id>' . "\r\n" 
			. "\t" . '<challenge>' . $challenge . '</challenge>' . "\r\n"
			. '</dfrn_notify>' . "\r\n" ;

		killme();
	}

}
