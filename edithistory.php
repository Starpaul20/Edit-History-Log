<?php
/**
 * Edit History Log
 * Copyright 2010 Starpaul20
 */

define("IN_MYBB", 1);
define('THIS_SCRIPT', 'edithistory.php');

$templatelist = "edithistory,edithistory_nohistory,edithistory_item,edithistory_item_revert,edithistory_comparison,edithistory_view,multipage_page_current,multipage_page,multipage_nextpage,multipage_prevpage,multipage";

require_once "./global.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;

// Load global language phrases
$lang->load("edithistory");

// Get post info
$pid = intval($mybb->input['pid']);
$post = get_post($pid);
$post['subject'] = htmlspecialchars_uni($parser->parse_badwords($post['subject']));

// Invalid post
if(!$post['pid'])
{
	error($lang->error_invalidpost);
}

$fid = $post['fid'];

// Determine who can see the edit histories
if($mybb->settings['editmodvisibility'] == "2" && $mybb->usergroup['cancp'] != 1)
{
	error_no_permission();
}
else if($mybb->settings['editmodvisibility'] == "1" && ($mybb->usergroup['issupermod'] != 1 && $mybb->usergroup['cancp'] != 1))
{
	error_no_permission();
}
else if(!is_moderator($fid, "caneditposts"))
{
	error_no_permission();
}

if($fid > 0)
{
	$forum = get_forum($fid);

	if(!$forum)
	{
		error($lang->error_invalidforum);
	}

	// Make navigation
	build_forum_breadcrumb($forum['fid']);

	// Permissions
	$forumpermissions = forum_permissions($forum['fid']);

	if($forumpermissions['canview'] == 0 || $forumpermissions['canviewthreads'] == 0)
	{
		error_no_permission();
	}
	
	// Check if this forum is password protected and we have a valid password
	check_forum_password($forum['fid']);
}

// Make navigation
add_breadcrumb($post['subject'], get_thread_link($post['tid']));
add_breadcrumb($lang->nav_edithistory, "edithistory.php?pid={$pid}");

// Comparing old/current post
if($mybb->input['action'] == "compare")
{
	add_breadcrumb($lang->nav_compareposts, "edithistory.php?action=compare&pid={$pid}");

	$query = $db->simple_select("edithistory", "*", "eid='".intval($mybb->input['eid'])."'");
	$editlog = $db->fetch_array($query);

	if(!$editlog['eid'])
	{
		error($lang->error_no_log);
	}

	if($editlog['pid'] != $pid)
	{
		error($lang->error_cannot_compare_other_posts);
	}

	$lang->edit_history = $lang->sprintf($lang->edit_history, $post['subject']);
	$dateline = my_date('relative', $editlog['dateline']);
	$lang->edit_as_of = $lang->sprintf($lang->edit_as_of, $dateline);

	require_once MYBB_ROOT."inc/3rdparty/diff/Diff.php";
	require_once MYBB_ROOT."inc/3rdparty/diff/Diff/Renderer.php";
	require_once MYBB_ROOT."inc/3rdparty/diff/Diff/Renderer/Inline.php";

	$message1 = explode("\n", $editlog['originaltext']);
	$message2 = explode("\n", $post['message']);

	$diff = new Horde_Text_Diff('auto', array($message1, $message2));
	$renderer = new Horde_Text_Diff_Renderer_Inline();

	if($editlog['originaltext'] == $post['message'])
	{
		$comparison = $lang->post_same;
	}
	else
	{
		$comparison = $renderer->render($diff);
	}

	eval("\$postcomparison = \"".$templates->get("edithistory_comparison")."\";");
	output_page($postcomparison);
}

// Viewing full text
if($mybb->input['action'] == "view")
{
	add_breadcrumb($lang->view_original_text, "edithistory.php?action=view&pid={$pid}");

	$query = $db->query("
		SELECT e.*, u.username
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."users u ON (e.uid=u.uid)
		WHERE e.eid='".intval($mybb->input['eid'])."'
	");
	$edit = $db->fetch_array($query);

	if(!$edit['eid'])
	{
		error($lang->error_no_log);
	}

	if(!$edit['reason'])
	{
		$edit['reason'] = $lang->na;
	}
	else
	{
		$edit['reason'] = htmlspecialchars_uni($edit['reason']);
	}

	// Sanitize post
	$edit['subject'] = htmlspecialchars_uni($edit['subject']);
	$edit['originaltext'] = nl2br(htmlspecialchars_uni($edit['originaltext']));

	$dateline = my_date('relative', $edit['dateline']);
	$edit['username'] = build_profile_link($edit['username'], $edit['uid']);
	$edit['ipaddress'] = my_inet_ntop($db->unescape_binary($edit['ipaddress']));

	eval("\$view = \"".$templates->get("edithistory_view")."\";");
	output_page($view);
}

// Revert edited post
if($mybb->input['action'] == "revert")
{
	// First, determine if they can revert edit
	if($mybb->settings['editrevert'] == "2" && $mybb->usergroup['cancp'] != 1)
	{
		error_no_permission();
	}
	else if($mybb->settings['editrevert'] == "1" && ($mybb->usergroup['issupermod'] != 1 && $mybb->usergroup['cancp'] != 1))
	{
		error_no_permission();
	}

	$query = $db->simple_select("edithistory", "*", "eid='".intval($mybb->input['eid'])."'");
	$history = $db->fetch_array($query);

	if(!$history['eid'])
	{
		error($lang->error_no_log);
	}

	// Set up posthandler.
	require_once MYBB_ROOT."inc/datahandlers/post.php";
	$posthandler = new PostDataHandler("update");
	$posthandler->action = "post";

	// Set the post data that came from the input to the $post array.
	$post = array(
		"pid" => intval($history['pid']),
		"subject" => $history['subject'],
		"edit_uid" => 0,
		"message" => $history['originaltext'],
	);

	$posthandler->set_data($post);

	// Now let the post handler do all the hard work.
	if(!$posthandler->validate_post())
	{
		$edit_errors = $posthandler->get_friendly_errors();
		$post_errors = inline_error($edit_errors);
		$mybb->input['action'] = "";
	}
	// No errors were found, we can call the update method.
	else
	{
		$postinfo = $posthandler->update_post();
		$url = get_post_link($history['pid'], $history['tid'])."#pid{$history['pid']}";

		redirect($url, $lang->redirect_postreverted);
	}
}

// Show the edit history for this post.
if(!$mybb->input['action'])
{
	$lang->edit_history = $lang->sprintf($lang->edit_history, htmlspecialchars_uni($post['subject']));

	// Get edit history
	$edit_history = '';

	if(!$mybb->settings['editsperpages'])
	{
		$mybb->settings['editsperpages'] = 10;
	}

	// Figure out if we need to display multiple pages.
	$perpage = intval($mybb->settings['editsperpages']);
	$page = intval($mybb->input['page']);

	$query = $db->simple_select("edithistory", "COUNT(eid) AS history_count", "pid='{$pid}'");
	$history_count = $db->fetch_field($query, "history_count");

	$pages = ceil($history_count/$perpage);

	if($page > $pages || $page <= 0)
	{
		$page = 1;
	}
	if($page)
	{
		$start = ($page-1) * $perpage;
	}
	else
	{
		$start = 0;
		$page = 1;
	}

	$multipage = multipage($history_count, $perpage, $page, "edithistory.php?pid={$pid}");

	$query = $db->query("
		SELECT e.*, u.username
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."posts p ON (e.pid=p.pid)
		LEFT JOIN ".TABLE_PREFIX."users u ON (e.uid=u.uid)
		WHERE e.pid='{$pid}'
		ORDER BY e.dateline DESC
		LIMIT {$start}, {$perpage}
	");
	while($history = $db->fetch_array($query))
	{
		$alt_bg = alt_trow();
		if(!$history['reason'])
		{
			$history['reason'] = $lang->na;
		}
		else
		{
			$history['reason'] = htmlspecialchars_uni($history['reason']);
		}

		$history['ipaddress'] = my_inet_ntop($db->unescape_binary($history['ipaddress']));
		$history['username'] = build_profile_link($history['username'], $history['uid']);
		$dateline = my_date('relative', $history['dateline']);

		// Sanitize post
		$history['originaltext'] = htmlspecialchars_uni($history['originaltext']);

		if($mybb->settings['edithistorychar'] > 0 && my_strlen($history['originaltext']) > $mybb->settings['edithistorychar'])
		{
			$history['originaltext'] = my_substr($history['originaltext'], 0, $mybb->settings['edithistorychar']) . "... <strong><a href=\"edithistory.php?action=view&amp;pid={$history['pid']}&amp;eid={$history['eid']} \">{$lang->read_more}</a></strong>";
			$originaltext = nl2br($history['originaltext']);
		}
		else
		{
			$originaltext = nl2br($history['originaltext']);
		}

		// Show revert option if allowed
		if($mybb->settings['editrevert'] == "2" && $mybb->usergroup['cancp'] == 1)
		{
			eval("\$revert = \"".$templates->get("edithistory_item_revert")."\";");
		}
		elseif($mybb->settings['editrevert'] == "1" && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1))
		{
			eval("\$revert = \"".$templates->get("edithistory_item_revert")."\";");
		}
		elseif($mybb->settings['editrevert'] == "0")
		{
			eval("\$revert = \"".$templates->get("edithistory_item_revert")."\";");
		}
		else
		{
			$revert = "";
		}

		eval("\$edit_history .= \"".$templates->get("edithistory_item")."\";");
	}

	if(!$edit_history)
	{
		eval("\$edit_history = \"".$templates->get("edithistory_nohistory")."\";");
	}

	eval("\$edithistory = \"".$templates->get("edithistory")."\";");
	output_page($edithistory);
}

?>