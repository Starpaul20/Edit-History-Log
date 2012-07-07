<?php
/**
 * Edit History Log
 * Copyright 2010 Starpaul20
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$page->add_breadcrumb_item($lang->edit_history, "index.php?module=tools-edithistory");

$sub_tabs['edit_history'] = array(
	'title' => $lang->edit_history,
	'link' => "index.php?module=tools-edithistory",
	'description' => $lang->edit_history_desc
);
$sub_tabs['prune_edit_history'] = array(
	'title' => $lang->prune_edit_history,
	'link' => "index.php?module=tools-edithistory&amp;action=prune",
	'description' => $lang->prune_edit_history_desc
);

if($mybb->input['action'] == 'prune')
{
	if($mybb->request_method == 'post')
	{
		$pids = array();

		$where = 'dateline < '.(TIME_NOW-(intval($mybb->input['older_than'])*86400));

		// Searching for entries by a particular user
		if($mybb->input['uid'])
		{
			$where .= " AND uid='".intval($mybb->input['uid'])."'";
		}

		// Searching for entries in a specific thread
		if($mybb->input['tid'])
		{
			$where .= " AND tid='".intval($mybb->input['tid'])."'";
		}

		$update_array = array(
			"hashistory" => 0
		);
		$db->update_query("posts", $update_array);

		$db->delete_query("edithistory", $where);
		$num_deleted = $db->affected_rows();

		$query = $db->simple_select("edithistory", "pid");
		while($history = $db->fetch_array($query))
		{
			$update_array = array(
				"hashistory" => 1
			);
			$db->update_query("posts", $update_array, "pid='{$history['pid']}'");
		}

		// Log admin action
		log_admin_action($mybb->input['older_than'], $mybb->input['uid'], $mybb->input['tid'], $num_deleted);

		flash_message($lang->success_pruned_edit_history, 'success');
		admin_redirect("index.php?module=tools-edithistory");
	}
	$page->add_breadcrumb_item($lang->prune_edit_history, "index.php?module=tools-edithistory&amp;action=prune");
	$page->output_header($lang->prune_edit_history);
	$page->output_nav_tabs($sub_tabs, 'prune_edit_history');

	// Fetch filter options
	$sortbysel[$mybb->input['sortby']] = 'selected="selected"';
	$ordersel[$mybb->input['order']] = 'selected="selected"';

	$user_options[''] = $lang->all_users;
	$user_options['0'] = '----------';

	$query = $db->query("
		SELECT DISTINCT e.uid, u.username
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."users u ON (e.uid=u.uid)
		ORDER BY u.username ASC
	");
	while($user = $db->fetch_array($query))
	{
		$user_options[$user['uid']] = $user['username'];
	}

	$thread_options[''] = $lang->all_threads;
	$thread_options['0'] = '----------';

	$query2 = $db->query("
		SELECT DISTINCT e.tid, t.subject
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."threads t ON (e.tid=t.tid)
		ORDER BY t.subject ASC
	");
	while($thread = $db->fetch_array($query2))
	{
		$thread_options[$thread['tid']] = $thread['subject'];
	}

	$form = new Form("index.php?module=tools-edithistory&amp;action=prune", "post");
	$form_container = new FormContainer($lang->prune_edit_history);
	$form_container->output_row($lang->user, "", $form->generate_select_box('uid', $user_options, $mybb->input['uid'], array('id' => 'uid')), 'uid');
	$form_container->output_row($lang->thread, "", $form->generate_select_box('tid', $thread_options, $mybb->input['tid'], array('id' => 'tid')), 'tid');

	if(!$mybb->input['older_than'])
	{
		$mybb->input['older_than'] = '30';
	}
	$form_container->output_row($lang->date_range, "", $lang->older_than.$form->generate_text_box('older_than', $mybb->input['older_than'], array('id' => 'older_than', 'style' => 'width: 30px')).' '.$lang->days, 'older_than');
	$form_container->end();
	$buttons[] = $form->generate_submit_button($lang->prune_edit_history);
	$form->output_submit_wrapper($buttons);
	$form->end();

	$page->output_footer();
}

if(!$mybb->input['action'])
{
	$page->output_header($lang->edit_history);

	$page->output_nav_tabs($sub_tabs, 'edit_history');

	$perpage = intval($mybb->input['perpage']);
	if(!$perpage)
	{
		$perpage = intval($mybb->settings['threadsperpage']);
	}

	$where = 'WHERE 1=1';

	// Searching for entries by a particular user
	if($mybb->input['uid'])
	{
		$where .= " AND e.uid='".intval($mybb->input['uid'])."'";
	}

	// Searching for entries in a specific thread
	if($mybb->input['tid'])
	{
		$where .= " AND e.tid='".intval($mybb->input['tid'])."'";
	}

	// Order?
	switch($mybb->input['sortby'])
	{
		case "username":
			$sortby = "u.username";
			break;
		case "thread":
			$sortby = "t.subject";
			break;
		default:
			$sortby = "e.dateline";
	}
	$order = $mybb->input['order'];
	if($order != "asc")
	{
		$order = "desc";
	}

	$query = $db->query("
		SELECT COUNT(e.dateline) AS count
		FROM ".TABLE_PREFIX."edithistory e
		{$where}
	");
	$rescount = $db->fetch_field($query, "count");

	// Figure out if we need to display multiple pages.
	if($mybb->input['page'] != "last")
	{
		$pagecnt = intval($mybb->input['page']);
	}

	$postcount = intval($rescount);
	$pages = $postcount / $perpage;
	$pages = ceil($pages);

	if($mybb->input['page'] == "last")
	{
		$pagecnt = $pages;
	}

	if($pagecnt > $pages)
	{
		$pagecnt = 1;
	}

	if($pagecnt)
	{
		$start = ($pagecnt-1) * $perpage;
	}
	else
	{
		$start = 0;
		$pagecnt = 1;
	}

	$table = new Table;
	$table->construct_header($lang->username, array('width' => '10%'));
	$table->construct_header($lang->edit_date, array("class" => "align_center", 'width' => '15%'));
	$table->construct_header($lang->ipaddress, array("class" => "align_center", 'width' => '10%'));
	$table->construct_header($lang->information, array("class" => "align_center", 'width' => '35%'));
	$table->construct_header($lang->reason, array("class" => "align_center", 'width' => '30%'));

	$query = $db->query("
		SELECT e.*, u.username, u.usergroup, u.displaygroup, t.subject AS tsubject
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=e.uid)
		LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=e.tid)
		LEFT JOIN ".TABLE_PREFIX."posts p ON (p.pid=e.pid)
		{$where}
		ORDER BY {$sortby} {$order}
		LIMIT {$start}, {$perpage}
	");
	while($logitem = $db->fetch_array($query))
	{
		$information = '';
		$logitem['dateline'] = date("jS M Y, G:i", $logitem['dateline']);
		$trow = alt_trow();
		$username = format_name($logitem['username'], $logitem['usergroup'], $logitem['displaygroup']);
		$logitem['profilelink'] = build_profile_link($username, $logitem['uid']);
		if($logitem['tsubject'])
		{
			$information = "<strong>{$lang->thread}</strong> <a href=\"../".get_thread_link($logitem['tid'])."\" target=\"_blank\">".htmlspecialchars_uni($logitem['tsubject'])."</a><br />";
		}
		if($logitem['subject'])
		{
			$information .= "<strong>{$lang->post}</strong> <a href=\"../".get_post_link($logitem['pid'])."#pid{$logitem['pid']}\">".htmlspecialchars_uni($logitem['subject'])."</a>";
		}
		$logitem['reason'] = htmlspecialchars_uni($logitem['reason']);

		$table->construct_cell($logitem['profilelink']);
		$table->construct_cell($logitem['dateline'], array("class" => "align_center"));
		$table->construct_cell($logitem['ipaddress'], array("class" => "align_center"));
		$table->construct_cell($information);
		$table->construct_cell($logitem['reason'], array("class" => "align_center"));
		$table->construct_row();
	}

	if($table->num_rows() == 0)
	{
		$table->construct_cell($lang->no_edit_history, array("colspan" => "5"));
		$table->construct_row();
	}

	$table->output($lang->edit_history);

	// Do we need to construct the pagination?
	if($rescount > $perpage)
	{
		echo draw_admin_pagination($pagecnt, $perpage, $rescount, "index.php?module=tools-edithistory&amp;perpage=$perpage&amp;uid={$mybb->input['uid']}&amp;tid={$mybb->input['tid']}&amp;sortby={$mybb->input['sortby']}&amp;order={$order}")."<br />";
	}

	// Fetch filter options
	$sortbysel[$mybb->input['sortby']] = "selected=\"selected\"";
	$ordersel[$mybb->input['order']] = "selected=\"selected\"";

	$user_options[''] = $lang->all_users;
	$user_options['0'] = '----------';

	$query = $db->query("
		SELECT DISTINCT e.uid, u.username
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."users u ON (e.uid=u.uid)
		ORDER BY u.username ASC
	");
	while($user = $db->fetch_array($query))
	{
		$selected = '';
		if($mybb->input['uid'] == $user['uid'])
		{
			$selected = "selected=\"selected\"";
		}
		$user_options[$user['uid']] = $user['username'];
	}

	$thread_options[''] = $lang->all_threads;
	$thread_options['0'] = '----------';
	
	$query2 = $db->query("
		SELECT DISTINCT e.tid, t.subject
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."threads t ON (e.tid=t.tid)
		ORDER BY t.subject ASC
	");
	while($thread = $db->fetch_array($query2))
	{
		$thread_options[$thread['tid']] = $thread['subject'];
	}

	$sort_by = array(
		'dateline' => $lang->date,
		'username' => $lang->username,
		'thread' => $lang->thread_subject
	);

	$order_array = array(
		'asc' => $lang->asc,
		'desc' => $lang->desc
	);

	$form = new Form("index.php?module=tools-edithistory", "post");
	$form_container = new FormContainer($lang->filter_edit_history);
	$form_container->output_row($lang->user, "", $form->generate_select_box('uid', $user_options, $mybb->input['uid'], array('id' => 'uid')), 'uid');
	$form_container->output_row($lang->thread, "", $form->generate_select_box('tid', $thread_options, $mybb->input['tid'], array('id' => 'tid')), 'tid');
	$form_container->output_row($lang->sort_by, "", $form->generate_select_box('sortby', $sort_by, $mybb->input['sortby'], array('id' => 'sortby'))." {$lang->in} ".$form->generate_select_box('order', $order_array, $order, array('id' => 'order'))." {$lang->order}", 'order');
	$form_container->output_row($lang->results_per_page, "", $form->generate_text_box('perpage', $perpage, array('id' => 'perpage')), 'perpage');

	$form_container->end();
	$buttons[] = $form->generate_submit_button($lang->filter_edit_history);
	$form->output_submit_wrapper($buttons);
	$form->end();

	$page->output_footer();
}
?>