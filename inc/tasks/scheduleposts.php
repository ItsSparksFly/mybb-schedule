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
                    $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$mybb->user['uid'], $alertType, (int)$post['tid']);
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
    add_task_log($task, 'Geplante Posts veröffentlicht.');
}
