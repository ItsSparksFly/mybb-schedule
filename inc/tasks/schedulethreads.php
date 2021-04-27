<?php

function task_schedule($task){
    global $mybb, $db, $lang, $posthandler, $scheduled, $thread, $post, $new_thread, $valid_thread, $thread_info;
    $lang->load('schedule');

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
                    $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$mybb->user['uid'], $alertType, (int)$tid);
                    MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
                }
            }
        }
        $new_array = [
            "display" => 1
        ];
        $db->update_query("scheduled", $new_array, "sid = '{$scheduled['sid']}'");

    }

    // Add an entry to the log
    add_task_log($task, 'Geplante Threads veröffentlicht.');
}
