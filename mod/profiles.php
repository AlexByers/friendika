<?php


function profiles_post(&$a) {

	if(! local_user()) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	$namechanged = false;

	if(($a->argc > 1) && ($a->argv[1] !== "new") && intval($a->argv[1])) {
		$orig = q("SELECT * FROM `profile` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($a->argv[1]),
			intval(local_user())
		);
		if(! count($orig)) {
			notice( t('Profile not found.') . EOL);
			return;
		}
		$is_default = (($orig[0]['is-default']) ? 1 : 0);

		$profile_name = notags(trim($_POST['profile_name']));
		if(! strlen($profile_name)) {
			notify( t('Profile Name is required.') . EOL);
			return;
		}
	
		$year = intval($_POST['year']);
		if($year < 1900 || $year > 2100 || $year < 0)
			$year = 0;
		$month = intval($_POST['month']);
			if(($month > 12) || ($month < 0))
				$month = 0;
		$mtab = array(0,31,29,31,30,31,30,31,31,30,31,30,31);
		$day = intval($_POST['day']);
			if(($day > $mtab[$month]) || ($day < 0))
				$day = 0;
		$dob = '0000-00-00';
		$dob = sprintf('%04d-%02d-%02d',$year,$month,$day);

			
		$name = notags(trim($_POST['name']));

		if($orig[0]['name'] != $name)
			$namechanged = true;

		$gender = notags(trim($_POST['gender']));
		$address = notags(trim($_POST['address']));
		$locality = notags(trim($_POST['locality']));
		$region = notags(trim($_POST['region']));
		$postal_code = notags(trim($_POST['postal_code']));
		$country_name = notags(trim($_POST['country_name']));
		$keywords = notags(trim($_POST['keywords']));
		$marital = notags(trim($_POST['marital']));
		if($marital != $orig[0]['marital'])
			$maritalchanged = true;

		$with = ((x($_POST,'with')) ? notags(trim($_POST['with'])) : '');

		// linkify the relationship target if applicable

		if(strlen($with)) {
			if($with != strip_tags($orig[0]['with'])) {
				$prf = '';
				$lookup = $with;
				if((strpos($lookup,'@')) || (strpos($lookup,'http://'))) {
					$newname = $lookup;
					$links = @lrdd($lookup);
					if(count($links)) {
						foreach($links as $link) {
							if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page') {
            	       			$prf = $link['@attributes']['href'];
							}
						}
					}
				}
				else {
					$newname = $lookup;
					if(strstr($lookup,' ')) {
						$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($newname),
							intval(local_user())
						);
					}
					else {
						$r = q("SELECT * FROM `contact` WHERE `nick` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($lookup),
							intval(local_user())
						);
					}
					if(count($r)) {
						$prf = $r[0]['url'];
						$newname = $r[0]['name'];
					}
				}
	
				if($prf) {
					$with = str_replace($lookup,'<a href="' . $prf . '">' . $newname	. '</a>', $with);
				}
			}
			else
				$with = $orig[0]['with'];
		}

		$sexual = notags(trim($_POST['sexual']));
		$homepage = notags(trim($_POST['homepage']));
		$politic = notags(trim($_POST['politic']));
		$religion = notags(trim($_POST['religion']));

		$about = escape_tags(trim($_POST['about']));
		$interest = escape_tags(trim($_POST['interest']));
		$contact = escape_tags(trim($_POST['contact']));
		$music = escape_tags(trim($_POST['music']));
		$book = escape_tags(trim($_POST['book']));
		$tv = escape_tags(trim($_POST['tv']));
		$film = escape_tags(trim($_POST['film']));
		$romance = escape_tags(trim($_POST['romance']));
		$work = escape_tags(trim($_POST['work']));
		$education = escape_tags(trim($_POST['education']));
		$hide_friends = (($_POST['hide-friends'] == 1) ? 1: 0);


		$r = q("UPDATE `profile` 
			SET `profile-name` = '%s',
			`name` = '%s',
			`gender` = '%s',
			`dob` = '%s',
			`address` = '%s',
			`locality` = '%s',
			`region` = '%s',
			`postal-code` = '%s',
			`country-name` = '%s',
			`marital` = '%s',
			`with` = '%s',
			`sexual` = '%s',
			`homepage` = '%s',
			`politic` = '%s',
			`religion` = '%s',
			`keywords` = '%s',
			`about` = '%s',
			`interest` = '%s',
			`contact` = '%s',
			`music` = '%s',
			`book` = '%s',
			`tv` = '%s',
			`film` = '%s',
			`romance` = '%s',
			`work` = '%s',
			`education` = '%s',
			`hide-friends` = %d
			WHERE `id` = %d AND `uid` = %d LIMIT 1",
			dbesc($profile_name),
			dbesc($name),
			dbesc($gender),
			dbesc($dob),
			dbesc($address),
			dbesc($locality),
			dbesc($region),
			dbesc($postal_code),
			dbesc($country_name),
			dbesc($marital),
			dbesc($with),
			dbesc($sexual),
			dbesc($homepage),
			dbesc($politic),
			dbesc($religion),
			dbesc($keywords),
			dbesc($about),
			dbesc($interest),
			dbesc($contact),
			dbesc($music),
			dbesc($book),
			dbesc($tv),
			dbesc($film),
			dbesc($romance),
			dbesc($work),
			dbesc($education),
			intval($hide_friends),
			intval($a->argv[1]),
			intval($_SESSION['uid'])
		);

		if($r)
			notice( t('Profile updated.') . EOL);


		if($namechanged && $is_default) {
			$r = q("UPDATE `contact` SET `name-date` = '%s' WHERE `self` = 1 AND `uid` = %d LIMIT 1",
				dbesc(datetime_convert()),
				intval(local_user())
			);
		}

		if($is_default) {
			// Update global directory in background
			$php_path = ((strlen($a->config['php_path'])) ? $a->config['php_path'] : 'php');
			$url = $_SESSION['my_url'];
			if($url && strlen(get_config('system','directory_submit_url')))
				proc_close(proc_open("\"$php_path\" \"include/directory.php\" \"$url\" &",
					array(),$foo));
		}
	}
}




function profiles_content(&$a) {
	$o = '';
	$o .= '<script>	$(document).ready(function() { $(\'#nav-profiles-link\').addClass(\'nav-selected\'); });</script>';

	if(! local_user()) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	if(($a->argc > 2) && ($a->argv[1] === "drop") && intval($a->argv[2])) {
		$r = q("SELECT * FROM `profile` WHERE `id` = %d AND `uid` = %d AND `is-default` = 0 AND `self` = 0 LIMIT 1",
			intval($a->argv[2]),
			intval(local_user())
		);
		if(! count($r)) {
			notice( t('Profile not found.') . EOL);
			goaway($a->get_baseurl() . '/profiles');
			return; // NOTREACHED
		}

		// move every contact using this profile as their default to the user default

		$r = q("UPDATE `contact` SET `profile-id` = (SELECT `profile`.`id` AS `profile-id` FROM `profile` WHERE `profile`.`is-default` = 1 AND `profile`.`uid` = %d LIMIT 1) WHERE `profile-id` = %d AND `uid` = %d ",
			intval(local_user()),
			intval($a->argv[2]),
			intval(local_user())
		);
		$r = q("DELETE FROM `profile` WHERE `id` = %d LIMIT 1",
			intval($a->argv[2])
		);
		if($r)
			notice( t('Profile deleted.') . EOL);

		goaway($a->get_baseurl() . '/profiles');
		return; // NOTREACHED
	}





	if(($a->argc > 1) && ($a->argv[1] === 'new')) {

		$r0 = q("SELECT `id` FROM `profile` WHERE `uid` = %d",
			intval(local_user()));
		$num_profiles = count($r0);

		$name = t('Profile-') . ($num_profiles + 1);

		$r1 = q("SELECT `name`, `photo`, `thumb` FROM `profile` WHERE `uid` = %d AND `is-default` = 1 LIMIT 1",
			intval(local_user()));
		
		$r2 = q("INSERT INTO `profile` (`uid` , `profile-name` , `name`, `photo`, `thumb`)
			VALUES ( %d, '%s', '%s', '%s', '%s' )",
			intval(local_user()),
			dbesc($name),
			dbesc($r1[0]['name']),
			dbesc($r1[0]['photo']),
			dbesc($r1[0]['thumb'])
		);

		$r3 = q("SELECT `id` FROM `profile` WHERE `uid` = %d AND `profile-name` = '%s' LIMIT 1",
			intval(local_user()),
			dbesc($name)
		);

		notice( t('New profile created.') . EOL);
		if(count($r3) == 1)
			goaway($a->get_baseurl() . '/profiles/' . $r3[0]['id']);
		goaway($a->get_baseurl() . '/profiles');
	}		 

	if(($a->argc > 2) && ($a->argv[1] === 'clone')) {

		$r0 = q("SELECT `id` FROM `profile` WHERE `uid` = %d",
			intval(local_user()));
		$num_profiles = count($r0);

		$name = t('Profile-') . ($num_profiles + 1);
		$r1 = q("SELECT * FROM `profile` WHERE `uid` = %d AND `id` = %d LIMIT 1",
			intval(local_user()),
			intval($a->argv[2])
		);
		if(! count($r1)) {
			notice( t('Profile unavailable to clone.') . EOL);
			return;
		}
		unset($r1[0]['id']);
		$r1[0]['is-default'] = 0;
		$r1[0]['publish'] = 0;	
		$r1[0]['net-publish'] = 0;	
		$r1[0]['profile-name'] = dbesc($name);

		dbesc_array($r1[0]);

		$r2 = dbq("INSERT INTO `profile` (`" 
			. implode("`, `", array_keys($r1[0])) 
			. "`) VALUES ('" 
			. implode("', '", array_values($r1[0])) 
			. "')" );

		$r3 = q("SELECT `id` FROM `profile` WHERE `uid` = %d AND `profile-name` = '%s' LIMIT 1",
			intval(local_user()),
			dbesc($name)
		);
		notice( t('New profile created.') . EOL);
		if(count($r3) == 1)
			goaway($a->get_baseurl() . '/profiles/' . $r3[0]['id']);
	goaway($a->get_baseurl() . '/profiles');
	return; // NOTREACHED
	}		 


	if(($a->argc > 1) && (intval($a->argv[1]))) {
		$r = q("SELECT * FROM `profile` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($a->argv[1]),
			intval(local_user())
		);
		if(! count($r)) {
			notice( t('Profile not found.') . EOL);
			return;
		}

		profile_load($a,$a->user['nickname'],$r[0]['id']);

		require_once('include/profile_selectors.php');

		$tpl = load_view_file('view/profed_head.tpl');

		$opt_tpl = load_view_file("view/profile-hide-friends.tpl");
		$hide_friends = replace_macros($opt_tpl,array(
			'$yes_selected' => (($r[0]['hide-friends']) ? " checked=\"checked\" " : ""),
			'$no_selected' => (($r[0]['hide-friends'] == 0) ? " checked=\"checked\" " : "")
		));


		$a->page['htmlhead'] .= replace_macros($tpl, array('$baseurl' => $a->get_baseurl()));
		$a->page['htmlhead'] .= "<script type=\"text/javascript\" src=\"include/country.js\" ></script>";


		$is_default = (($r[0]['is-default']) ? 1 : 0);
		$tpl = load_view_file("view/profile_edit.tpl");
		$o .= replace_macros($tpl,array(
			'$disabled' => (($is_default) ? 'onclick="return false;" style="color: #BBBBFF;"' : ''),
			'$baseurl' => $a->get_baseurl(),
			'$profile_id' => $r[0]['id'],
			'$profile_name' => $r[0]['profile-name'],
			'$default' => (($is_default) ? '<p id="profile-edit-default-desc">' . t('This is your <strong>public</strong> profile.<br />It <strong>may</strong> be visible to anybody using the internet.') . '</p>' : ""),
			'$name' => $r[0]['name'],
			'$dob' => dob($r[0]['dob']),
			'$hide_friends' => $hide_friends,
			'$address' => $r[0]['address'],
			'$locality' => $r[0]['locality'],
			'$region' => $r[0]['region'],
			'$postal_code' => $r[0]['postal-code'],
			'$country_name' => $r[0]['country-name'],
			'$age' => ((intval($r[0]['dob'])) ? '(' . t('Age: ') . age($r[0]['dob'],$a->user['timezone'],$a->user['timezone']) . ')' : ''),
			'$gender' => gender_selector($r[0]['gender']),
			'$marital' => marital_selector($r[0]['marital']),
			'$with' => strip_tags($r[0]['with']),
			'$sexual' => sexpref_selector($r[0]['sexual']),
			'$about' => $r[0]['about'],
			'$homepage' => $r[0]['homepage'],
			'$politic' => $r[0]['politic'],
			'$religion' => $r[0]['religion'],
			'$keywords' => $r[0]['keywords'],
			'$music' => $r[0]['music'],
			'$book' => $r[0]['book'],
			'$tv' => $r[0]['tv'],
			'$film' => $r[0]['film'],
			'$interest' => $r[0]['interest'],
			'$romance' => $r[0]['romance'],
			'$work' => $r[0]['work'],
			'$education' => $r[0]['education'],
			'$contact' => $r[0]['contact']
		));

		return $o;
	}
	else {

		$r = q("SELECT * FROM `profile` WHERE `uid` = %d",
			local_user());
		if(count($r)) {

			$o .= load_view_file('view/profile_listing_header.tpl');
			$tpl_default = load_view_file('view/profile_entry_default.tpl');
			$tpl = load_view_file('view/profile_entry.tpl');

			foreach($r as $rr) {
				$template = (($rr['is-default']) ? $tpl_default : $tpl);
				$o .= replace_macros($template, array(
					'$photo' => $rr['thumb'],
					'$id' => $rr['id'],
					'$profile_name' => $rr['profile-name']
				));
			}
		}
		return $o;
	}

}