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
$plugins->add_hook("editpost_start", "schedule_editpost");
$plugins->add_hook("editpost_do_editpost_end", "schedule_do_editpost"); #TODO: edit date settings into scheduled table
$plugins->add_hook("newreply_start", "schedule_newreply"); 
$plugins->add_hook("newreply_do_newreply_end", "schedule_do_newreply");
if(class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
	$plugins->add_hook("global_start", "schedule_alerts"); #TODO: format alert
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

     // build task in admin cp
     $date = TIME_NOW;
     $nextrun = $date + 3600;
     $ScheduleTask = [
         'title' => 'Post scheduled threads/posts',
         'description' => 'Automatically posts all schedulded threads & posts',
         'file' => 'schedule',
         'minute' => 0,
         'hour' => '*',
         'day' => '*',
         'month' => '*',
         'weekday' => '*',
         'nextrun' => $nextrun,
         'logging' => 1,
         'locked' => 0
     ];
     $db->insert_query('tasks', $ScheduleTask);

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
		'ipt_inplay' => [
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

    // delete task
    $db->delete_query('tasks', 'file = "schedule"');

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
		$alertType->setCode('schedule_posted'); // The codename for your alert type. Can be any unique string.
		$alertType->setEnabled(true);
		$alertType->setCanBeUserDisabled(true);

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
			</select> <input type="date" name="sdate" value="{$sdate}" class="with hide" /> <input type="time" name="stime" required step="3600" value="{$stime}" class="with hide" />
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
	find_replace_templatesets("editpost", "#".preg_quote('{$attachbox}')."#i", '{$attachbox} {$editpost_schedule}');

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
	
	$db->delete_query("templates", "title IN(newthread_schedule)");
	include MYBB_ROOT."/inc/adminfunctions_templates.php";
    find_replace_templatesets("newthread", "#".preg_quote('{$newthread_schedule}')."#i", '', 0);
	find_replace_templatesets("newreply", "#".preg_quote('{$newreply_schedule}')."#i", '', 0);
    find_replace_templatesets("editpost", "#".preg_quote('{$editpost_schedule}')."#i", '', 0);

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
        if(preg_match("/,$selected,/i", $forum['parentlist'])) {
			if($mybb->usergroup['canschedule'] == 1) {
				// previewing post?
				if(isset($mybb->input['previewpost']) || $post_errors) {
					if($mybb->get_input('schedule') == 1) {
						$selected = "selected";
					}
					$sdate = $mybb->get_input('sdate');
					$stime = $mybb->get_input('stime');
				}
				if($mybb->input['action'] == "editdraft") {
					$scheduled = get_schedule($pid);
					if($scheduled) {
						$selected = "selected";
						$sdate = date("Y-m-d", $scheduled['date']);
						$stime = date("H:i", $scheduled['date']);
					}
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
		$sdate = strtotime("{$mybb->get_input('sdate')} {$mybb->get_input('stime')}");
		$new_array = [
			"tid" => $tid,
			"pid" => $thread['firstpost'],
			"date" => $sdate,
			"display" => 0
		];
		$db->insert_query("scheduled", $new_array);
	}
}

// load form template in newreply
function schedule_newreply() {
    global $mybb, $db, $lang, $templates, $post_errors, $pid, $forum, $newreply_schedule;
    $lang->load('schedule');
	$forum['parentlist'] = ",".$forum['parentlist'].",";   
    $selectedforums = explode(",", $mybb->settings['schedule_forums']);

    foreach($selectedforums as $selected) {
        if(preg_match("/,$selected,/i", $forum['parentlist'])) {
			if($mybb->usergroup['canschedule'] == 1) {
				// previewing post?
				if(isset($mybb->input['previewpost']) || $post_errors) {
					if($mybb->get_input('schedule') == 1) {
						$selected = "selected";
					}
					$sdate = $mybb->get_input('sdate');
					$stime = $mybb->get_input('stime');
				}
				if($mybb->input['action'] == "editdraft") {
					$scheduled = get_schedule($pid);
					if($scheduled) {
						$selected = "selected";
						$sdate = date("Y-m-d", $scheduled['date']);
						$stime = date("H:i", $scheduled['date']);
					}
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
		$sdate = strtotime("{$mybb->get_input('sdate')} {$mybb->get_input('stime')}");
		$new_array = [
			"tid" => $thread['tid'],
			"pid" => $pid,
			"date" => $sdate,
			"display" => 0
		];
		$db->insert_query("scheduled", $new_array);
	}
}

function schedule_editpost() {
	global $mybb, $db, $lang, $templates, $post_errors, $pid, $forum, $editpost_schedule;
	$lang->load('schedule');
		$forum['parentlist'] = ",".$forum['parentlist'].",";   
    $selectedforums = explode(",", $mybb->settings['schedule_forums']);

    foreach($selectedforums as $selected) {
        if(preg_match("/,$selected,/i", $forum['parentlist'])) {
			if($mybb->usergroup['canschedule'] == 1) {
				if(isset($mybb->input['previewpost']) || $post_errors) {
					if($mybb->get_input('schedule') == 1) {
						$selected = "selected";
					}
					$sdate = $mybb->get_input('sdate');
					$stime = $mybb->get_input('stime');
				}
				else {
					$scheduled = get_schedule($pid);
					if($scheduled) {
						$selected = "selected";
						$sdate = date("Y-m-d", $scheduled['date']);
						$stime = date("H:i", $scheduled['date']);
					}
				}		
				eval("\$editpost_schedule = \"".$templates->get("newthread_schedule")."\";");
			}
		}
	}
}

function schedule_do_editpost() {

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