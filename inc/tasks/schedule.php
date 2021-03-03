<?php

function task_schedule($task) {
    global $db, $lang, $posthandler;
    $lang->load('schedule');

    require_once MYBB_ROOT."inc/datahandlers/post.php";
	$posthandler = new PostDataHandler("insert");

    $now = TIME_NOW;

    $query = $db->simple_select("schedule", "*", "date < '$now'");
    while($scheduled = $db->fetch_array($query)) {
        $thread = get_thread($tid);
        $post = get_post($pid);

        // we're updating a thread
        if($thread['visible'] == "-2") {
            $thread['pid'] = $post['pid'];
            $posthandler->action = "thread";
            $posthandler->set_data($thread);
        }
        // or a post
        elseif($post['visible'] == "-2" && $thread['visible'] == "1") {
            $posthandler->action = "post";
            $posthandler->set_data($post);
        }
    }

    add_task_log($task, $lang->schedule_task);
}

?>