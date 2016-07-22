<?php
/**
 * Edit History Log
 * Copyright 2010 Starpaul20
 */

function task_edithistory($task)
{
	global $mybb, $db, $lang;
	$lang->load("edithistory", true);

	// Delete old thread subscriptions
	if((int)$mybb->settings['edithistorypruning'] > 0)
	{
		$cut = TIME_NOW-((int)$mybb->settings['edithistorypruning']*60*60*24);

		$query = $db->simple_select("edithistory", "eid, pid", "dateline < '".(int)$cut."'");
		while($history = $db->fetch_array($query))
		{
			$db->delete_query("edithistory", "eid='{$history['eid']}'");

			$update_array = array(
				"editcount" => "editcount-1"
			);
			$db->update_query("posts", $update_array, "pid='{$history['pid']}'", 1, true);
		}
	}

	add_task_log($task, $lang->edit_history_pruning_ran);
}
?>