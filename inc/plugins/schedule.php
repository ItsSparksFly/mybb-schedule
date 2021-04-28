<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.");
}

$plugins->add_hook("admin_formcontainer_output_row", "schedule_permission"); 
$plugins->add_hook("admin_user_groups_edit_commit", "schedule_permission_commit"); 
$plugins->add_hook("newthread_start", "schedule_newthread");
$plugins->add_hook("datahandler_post_validate_thread", "schedule_validate");
$plugins->add_hook("datahandler_post_validate_post", "schedule_validate");
$plugins->add_hook("newthread_do_newthread_end", "schedule_do_newthread");
$plugins->add_hook("newreply_start", "schedule_newreply"); 
$plugins->add_hook("newreply_do_newreply_end", "schedule_do_newreply");
$plugins->add_hook("usercp_drafts_start", "schedule_drafts");
$plugins->add_hook("index_end", "schedule_index");
if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
	$plugins->add_hook("global_start", "schedule_alerts");
}

function schedule_info()
{
	global $lang;
	$lang->load('schedule');
	
	return array(
		"name"			=> $lang->schedule_name,
		"description"	=> $lang->schedule_description,
		"website"		=> "https://github.com/ItsSparksFly",
		"author"		=> "sparks fly",
		"authorsite"	=> "https://github.com/ItsSparksFly",
		"version"		=> "1.0",
		"compatibility" => "18*"
	);
}

function schedule_install()
{
    global $db, $lang, $cache;
    $lang->load('schedule');

    // add scheduled draft table
    $db->query("CREATE TABLE ".TABLE_PREFIX."scheduled (
        `sid` int(11) NOT NULL AUTO_INCREMENT,
        `tid` int(11) NOT NULL,
        `pid` int(11) NOT NULL,
        `date` varchar(140) NOT NULL,
        `display` tinyint NOT NULL,
        PRIMARY KEY (`sid`),
        KEY `lid` (`sid`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci AUTO_INCREMENT=1");


     // add table field => group permissions
     if(!$db->field_exists("canschedule", "usergroups"))
     {
         switch($db->type)
         {
             case "pgsql":
                 $db->add_column("usergroups", "canschedule", "smallint NOT NULL default '1'");
                 break;
             default:
                 $db->add_column("usergroups", "canschedule", "tinyint(1) NOT NULL default '1'");
                 break;
 
         }
     } 
     $cache->update_usergroups();

	 // add css to themes 
	 $css = array(
        'name' => 'schedule.css',
        'tid' => 1,
        "stylesheet" => '.hide { display: none; }',
        'cachefile' => $db->escape_string(str_replace('/', '', schedule.css)),
        'lastmodified' => time()
    );

    require_once MYBB_ADMIN_DIR."inc/functions_themes.php";
    $sid = $db->insert_query("themestylesheets", $css);
    $db->update_query("themestylesheets", array("cachefile" => "css.php?stylesheet=".$sid), "sid = '".$sid."'", 1);

    $tids = $db->simple_select("themes", "tid");
    while($theme = $db->fetch_array($tids)) {
        update_theme_stylesheet_list($theme['tid']);
    }

	// settings
	$setting_group = [
		'name' => 'schedule',
		'title' => $lang->schedule_name,
		'description' => $lang->schedule_settings,
		'disporder' => 5,
		'isdefault' => 0
	];

	$gid = $db->insert_query("settinggroups", $setting_group);
	
	$setting_array = [
		'schedule_forums' => [
			'title' => $lang->schedule_forums,
			'description' => $lang->schedule_forums_desc,
			'optionscode' => 'forumselect',
			'disporder' => 1
        ]
    ];

	foreach($setting_array as $name => $setting)
	{
		$setting['name'] = $name;
		$setting['gid'] = $gid;

		$db->insert_query('settings', $setting);
	}

	rebuild_settings();
	
}

function schedule_is_installed()
{
	global $db;
	if($db->table_exists("scheduled"))
	{
		return true;
	}

	return false;
}

function schedule_uninstall()
{
	global $db;

    // drop table
    $db->query("DROP TABLE ".TABLE_PREFIX."scheduled");

    // drop fields
	if($db->field_exists("canschedule", "usergroups"))
	{
    	$db->drop_column("usergroups", "canschedule");
	}

    // drop css
    require_once MYBB_ADMIN_DIR."inc/functions_themes.php";
    $db->delete_query("themestylesheets", "name = 'schedule.css'");
    $query = $db->simple_select("themes", "tid");
    while($theme = $db->fetch_array($query)) {
        update_theme_stylesheet_list($theme['tid']);
    }

	// settings
	$db->delete_query('settings', "name IN ('schedule_forums')");
	$db->delete_query('settinggroups', "name = 'schedule'");
	rebuild_settings();

}
function schedule_activate()
{
    global $db, $cache;

    if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
		$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

		if (!$alertTypeManager) {
			$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
		}

		$alertType = new MybbStuff_MyAlerts_Entity_AlertType();
		$alertType->setCode('schedule_posted');
		$alertType->setEnabled(true);
		$alertType->setCanBeUserDisabled(true);

		$alertTypeManager->add($alertType);
	}   

	$newthread_schedule = [
        'title'        => 'newthread_schedule',
        'template'    => $db->escape_string('<script>
		$(document).on(\'change\', \'.div-toggle\', function() {
			  var target = $(this).data(\'target\');
			  var show = $("option:selected", this).data(\'show\');
			  $(target).children().addClass(\'hide\');
			  $(show).removeClass(\'hide\');
		});
		$(document).ready(function(){
				$(\'.div-toggle\').trigger(\'change\');
		});
	</script>
	
	<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
	<tr>
	<td class="thead" colspan="2"><strong>{$lang->schedule}</strong></td>
	</tr>
	<tr>
	<td class="trow2" width="20%">
		<strong>{$lang->schedule}:</strong><br />
		<span class="smalltext">{$lang->schedule_desc}</span>
	</td>
		<td class="trow2">
			<select name="schedule" class="div-toggle">
				<option>Nein</option>
				<option value="1" data-show=".with" {$selected}>Ja</option>
			</select> <input type="date" name="sdate" value="{$sdate}" class="with hide" /> <input type="time" name="stime" value="{$stime}" class="with hide" />
		</td>
	</tr>
	</table>'),
        'sid'        => '-1',
        'version'    => '',
        'dateline'    => TIME_NOW
    ];
    
	$db->insert_query("templates", $newthread_schedule);

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
    find_replace_templatesets("newthread", "#".preg_quote('{$attachbox}')."#i", '{$attachbox} {$newthread_schedule}');
	find_replace_templatesets("newreply", "#".preg_quote('{$attachbox}')."#i", '{$attachbox} {$newreply_schedule}');
	find_replace_templatesets("usercp_drafts_draft", "#".preg_quote('{$detail}')."#i", '{$detail} <br />{$scheduled[$id]}');

}

function schedule_deactivate()
{
    global $db, $cache;
    
	if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
		$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

		if (!$alertTypeManager) {
			$alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
		}

		$alertTypeManager->deleteByCode('schedule_posted');
	}
	
	$db->delete_query("templates", "title IN('newthread_schedule')");
	include MYBB_ROOT."/inc/adminfunctions_templates.php";
    find_replace_templatesets("newthread", "#".preg_quote('{$newthread_schedule}')."#i", '', 0);
	find_replace_templatesets("newreply", "#".preg_quote('{$newreply_schedule}')."#i", '', 0);
    find_replace_templatesets("editpost", "#".preg_quote('{$editpost_schedule}')."#i", '', 0);
	find_replace_templatesets("usercp_drafts_draft", "#".preg_quote(' <br />{$scheduled[$id]}')."#i", '', 0);

}

function schedule_permission($above)
{
	global $mybb, $lang, $form;
    $lang->load('schedule');

	if($above['title'] == $lang->misc && $lang->misc)
	{
		$above['content'] .= "<div class=\"group_settings_bit\">".$form->generate_check_box("canschedule", 1, $lang->schedule_permission, array("checked" => $mybb->input['canschedule']))."</div>";
	}

	return $above;
}

function schedule_permission_commit()
{
	global $mybb, $updated_group;
	$updated_group['canschedule'] = $mybb->get_input('canschedule', MyBB::INPUT_INT);
}

// load form template in newthread
function schedule_newthread() {
    global $mybb, $lang, $templates, $post_errors, $pid, $forum, $newthread_schedule;
    $lang->load('schedule');
	$forum['parentlist'] = ",".$forum['parentlist'].",";   
    $selectedforums = explode(",", $mybb->settings['schedule_forums']);

    foreach($selectedforums as $selected) {
        if(preg_match("/,$selected,/i", $forum['parentlist']) || $mybb->settings['schedule_forums'] == "-1") {
			if($mybb->usergroup['canschedule'] == 1) {
				// previewing post?
				if(isset($mybb->input['previewpost']) || $post_errors) {
					if($mybb->get_input('schedule') == 1) {
						$selected = "selected";
					}
					$sdate = $mybb->get_input('sdate');
					$stime = $mybb->get_input('stime');
				}
				elseif($mybb->input['action'] == "editdraft") {
					$scheduled = get_schedule($pid);
					if($scheduled) {
						$selected = "selected";
						$sdate = date("Y-m-d", $scheduled['date']);
						$stime = date("H:i", $scheduled['date']);
					}
				}
				else {
					$stime = "00:00";
				}
				eval("\$newthread_schedule = \"".$templates->get("newthread_schedule")."\";");
			}
		}
	}
}

// catch various errors
function schedule_validate(&$dh) {
	global $mybb, $lang;
	$lang->load('schedule');
	if($mybb->get_input('schedule') == 1 && $mybb->usergroup['canschedule'] == 1) {
		// post is not saved as draft
		if(!$mybb->get_input('savedraft')) {
			$dh->set_error($lang->schedule_error_no_draft);
		}
		// no valid timestamp given
		if(!$mybb->get_input('sdate') || !$mybb->get_input('stime')) {
			$dh->set_error($lang->schedule_error_no_time);
		}
		// given timestamp is below current time
		$sdate = strtotime("{$mybb->get_input('sdate')} {$mybb->get_input('stime')}");
		$now = TIME_NOW;
		if($now > $sdate) {
			$dh->set_error($lang->schedule_error_wrong_timing);
		}
	}
}

// get data into database
function schedule_do_newthread() {
	global $db, $mybb, $tid;
	$ownuid = $mybb->user['uid'];
	$thread = get_thread($tid);
	if($mybb->get_input('schedule') == 1) {
		$check = $db->fetch_field($db->simple_select("scheduled", "sid", "tid='{$thread['tid']}'"), "sid");
		$sdate = strtotime("{$mybb->get_input('sdate')} {$mybb->get_input('stime')}");
		$new_array = [
			"tid" => $tid,
			"pid" => $thread['firstpost'],
			"date" => $sdate,
			"display" => 0
		];
		if($check) {
			$db->update_query("scheduled", $new_array, "sid='$check'");
		} else {
			$db->insert_query("scheduled", $new_array);
		}
	}
}

// load form template in newreply
function schedule_newreply() {
    global $mybb, $db, $lang, $templates, $post_errors, $pid, $forum, $newreply_schedule;
    $lang->load('schedule');
	$forum['parentlist'] = ",".$forum['parentlist'].",";   
    $selectedforums = explode(",", $mybb->settings['schedule_forums']);

    foreach($selectedforums as $selected) {
        if(preg_match("/,$selected,/i", $forum['parentlist']) || $mybb->settings['schedule_forums'] == "-1") {
			if($mybb->usergroup['canschedule'] == 1) {
				// previewing post?
				if(isset($mybb->input['previewpost']) || $post_errors) {
					if($mybb->get_input('schedule') == 1) {
						$selected = "selected";
					}
					$sdate = $mybb->get_input('sdate');
					$stime = $mybb->get_input('stime');
				}
				elseif($mybb->input['action'] == "editdraft") {
					$scheduled = get_schedule($pid);
					if($scheduled) {
						$selected = "selected";
						$sdate = date("Y-m-d", $scheduled['date']);
						$stime = date("H:i", $scheduled['date']);
					}
				}
				else {
					$stime = "00:00";
				}
				eval("\$newreply_schedule = \"".$templates->get("newthread_schedule")."\";");
			}
		}
	}
}

function schedule_do_newreply() {
	global $db, $mybb, $lang, $thread, $pid;
	$ownuid = $mybb->user['uid'];

	if($mybb->get_input('schedule') == 1) {
		$check = $db->fetch_field($db->simple_select("scheduled", "sid", "tid='{$thread['tid']}' AND pid = '$pid'"), "sid");
		$sdate = strtotime("{$mybb->get_input('sdate')} {$mybb->get_input('stime')}");
		$new_array = [
			"tid" => $thread['tid'],
			"pid" => $pid,
			"date" => $sdate,
			"display" => 0
		];
		if($check) {
			$db->update_query("scheduled", $new_array, "sid='$check'");
		} else {
			$db->insert_query("scheduled", $new_array);
		}
	}
}

function schedule_drafts() {
	global $mybb, $db, $lang, $scheduled;
	$lang->load('schedule');
	$scheduled = [];

	$query = $db->query("
			SELECT p.subject, p.pid, t.tid, t.subject AS threadsubject, t.fid, p.dateline, t.visible AS threadvisible, p.visible AS postvisible, s.date
			FROM ".TABLE_PREFIX."posts p
			LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=p.tid)
			RIGHT JOIN ".TABLE_PREFIX."scheduled s ON (s.pid=p.pid)
			WHERE p.uid = '{$mybb->user['uid']}' AND p.visible = '-2'
			ORDER BY p.dateline DESC
	");

	while($draftinfo = $db->fetch_array($query)) {
		if($draftinfo['threadvisible'] == 1) {
			$pids[] = $draftinfo['pid'];
		} elseif($draftinfo['threadvisible'] == -2) {
			$pids[] = $draftinfo['tid'];
		}
		$dates[] = $draftinfo['date'];
	}
	if(!empty($pids)) {
		foreach($pids as $key => $pid) {
			$datestring = "<strong>Veröffentlichung am:</strong> " . date("d.m.Y H:i", $dates[$key]);
			$scheduled[$pid] = $datestring;
		}
	}

}

function schedule_index() {
	global $mybb, $db, $lang, $posthandler, $scheduled, $thread, $post, $new_thread, $valid_thread, $thread_info;
   	$lang->load('schedule');
	
	if($mybb->user['uid']) {
		require_once MYBB_ROOT."inc/datahandlers/post.php";
		$posthandler = new PostDataHandler("insert");
		$now = TIME_NOW;

		$query = $db->simple_select("scheduled", "*", "date < '$now' AND display = 0");
		while($scheduled = $db->fetch_array($query)) {

			$thread = get_thread($scheduled['tid']);
			$post = get_post($scheduled['pid']);

			// we're updating a thread
			if($thread['visible'] == "-2") {
			    $posthandler->action = "thread";

			    // Set the thread data that came from the input to the $thread array.
			    $new_thread = array(
				"fid" => $thread['fid'],
				"subject" => $db->escape_string($thread['subject']),
				"prefix" => "0",
				"icon" => "0",
				"uid" => $thread['uid'],
				"username" => $thread['username'],
				"message" => $db->escape_string($post['message']),
				"ipaddress" => $session->packedip,
				"dateline" => $scheduled['date']
			    );

			    $posthandler->set_data($new_thread);

			    $valid_thread = $posthandler->validate_thread();

			    $post_errors = array();
			    // Fetch friendly error messages if this is an invalid thread
			    if(!$valid_thread)
			    {
				$post_errors = $posthandler->get_friendly_errors();
			    }

			    foreach($post_errors as $error) {
				echo $error;
			    }

			    $thread_info = $posthandler->insert_thread();
			    $tid = $thread_info['tid'];

			    $db->delete_query("threads", "tid = '{$scheduled['tid']}'");
			    $db->delete_query("posts", "pid = '{$scheduled['pid']}'");

			    if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
				$alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('schedule_posted');
				if ($alertType != NULL && $alertType->getEnabled()) {
				    $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$post['uid'], $alertType, (int)$tid);
				    MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
				}
			    }
			}

	if($post['visible'] == "-2" && $thread['visible'] == "1") {
		    $posthandler->action = "post";
		    $posthandler->set_data($post);

		    // Set the post data that came from the input to the $post array.
		    $post = array(
			"tid" => $post['tid'],
			"replyto" => $post['replyto'],
			"fid" => $thread['fid'],
			"subject" => $post['subject'],
			"icon" => $post['icon'],
			"uid" => $post['uid'],
			"username" => $post['username'],
			"message" => $post['message'],
			"ipaddress" => $session->packedip,
			"posthash" => $post['posthash'],
			"dateline" => $scheduled['date']
		    );

		    $posthandler->set_data($post);
		    $valid_post = $posthandler->validate_post();
		    $post_errors = array();
		    // Fetch friendly error messages if this is an invalid post
		    if(!$valid_post)
		    {
			$post_errors = $posthandler->get_friendly_errors();
		    }

		    foreach($post_errors as $error) {
			echo $error;
		    }

		    $postinfo = $posthandler->insert_post();

		    $db->delete_query("posts", "pid = '{$scheduled['pid']}'");            

		    if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
			$alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('schedule_posted');
			if ($alertType != NULL && $alertType->getEnabled()) {
			    $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$post['uid'], $alertType, (int)$post['tid']);
			    MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
			}
		    }
		}

			$new_array = [
			    "display" => 1
			];
			$db->update_query("scheduled", $new_array, "sid = '{$scheduled['sid']}'");
		}
	}
}

function schedule_alerts() {
	global $mybb, $lang;
	$lang->load('schedule');
	/**
	 * Alert formatter for my custom alert type.
	 */
	class MybbStuff_MyAlerts_Formatter_schedulePostedFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
	{
	    /**
	     * Format an alert into it's output string to be used in both the main alerts listing page and the popup.
	     *
	     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
	     *
	     * @return string The formatted alert string.
	     */
	    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
	    {
	        return $this->lang->sprintf(
	            $this->lang->schedule_posted,
	            $outputAlert['from_user'],
	            $outputAlert['dateline']
	        );
	    }

	    /**
	     * Init function called before running formatAlert(). Used to load language files and initialize other required
	     * resources.
	     *
	     * @return void
	     */
	    public function init()
	    {
	        if (!$this->lang->schedule) {
	            $this->lang->load('schedule');
	        }
	    }

	    /**
	     * Build a link to an alert's content so that the system can redirect to it.
	     *
	     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to build the link for.
	     *
	     * @return string The built alert, preferably an absolute link.
	     */
	    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
	    {
	        return $this->mybb->settings['bburl'] . '/' . get_thread_link($alert->getObjectId());
	    }
	}

	if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
		$formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();

		if (!$formatterManager) {
			$formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
		}

		$formatterManager->registerFormatter(
				new MybbStuff_MyAlerts_Formatter_schedulePostedFormatter($mybb, $lang, 'schedule_posted')
		);
	}

}

function get_schedule(int $pid) {
	global $db;

	$query = $db->simple_select("scheduled", "*", "pid = '{$pid}'");
	$result = $db->fetch_array($query);
	return $result;
}

?>
