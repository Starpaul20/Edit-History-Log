<?php
/**
 * Edit History Log
 * Copyright 2010 Starpaul20
 */

define("IN_MYBB", 1);
define('THIS_SCRIPT', 'edithistory.php');

$templatelist = "edithistory,edithistory_item,edithistory_item_revert,edithistory_item_readmore,edithistory_view,edithistory_view_ipaddress,edithistory_item_ipaddress";
$templatelist .= ",edithistory_ipaddress,edithistory_nohistory,edithistory_comparison,multipage_page_current,multipage_page,multipage_nextpage,multipage_prevpage,multipage";

require_once "./global.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;

// Load global language phrases
$lang->load("edithistory");

// Get post info
$pid = $mybb->get_input('pid', MyBB::INPUT_INT);
$post = get_post($pid);
$post['subject'] = htmlspecialchars_uni($parser->parse_badwords($post['subject']));

// Invalid post
if(!$post['pid'])
{
	error($lang->error_invalidpost);
}

// Get thread info
$tid = $post['tid'];
$thread = get_thread($tid);

if(!$thread)
{
	error($lang->error_invalidthread);
}

$thread['subject'] = htmlspecialchars_uni($parser->parse_badwords($thread['subject']));

$fid = $post['fid'];

// Determine who can see the edit histories
if($mybb->settings['editmodvisibility'] == 2 && $mybb->usergroup['cancp'] != 1)
{
	error_no_permission();
}
elseif($mybb->settings['editmodvisibility'] == 1 && ($mybb->usergroup['issupermod'] != 1 && $mybb->usergroup['cancp'] != 1))
{
	error_no_permission();
}
elseif(!is_moderator($fid, "caneditposts"))
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
add_breadcrumb($thread['subject'], get_thread_link($thread['tid']));
add_breadcrumb($lang->nav_edithistory, "edithistory.php?pid={$pid}");

$mybb->input['action'] = $mybb->get_input('action');

// Comparing old/current post
if($mybb->input['action'] == "compare")
{
	add_breadcrumb($lang->nav_compareposts, "edithistory.php?action=compare&pid={$pid}");

	$query = $db->simple_select("edithistory", "*", "eid='".$mybb->get_input('eid', MyBB::INPUT_INT)."'");
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

	$newer_message = $post['message'];

	$timecut = (int)$editlog['dateline'];

	$query = $db->simple_select('edithistory', '*', "dateline>'{$timecut}' AND pid='{$pid}'", [
		'limit' => 1,
		'order_by' => 'dateline, eid',
		'order_dir' => 'asc'
	]);

	if($db->num_rows($query))
	{
		$newer_message = (string)$db->fetch_field($query, 'originaltext');
	}

	$message1 = explode("\n", $editlog['originaltext']);
	$message2 = explode("\n", $newer_message);

	$message1 = array_map('rtrim', $message1);
	$message2 = array_map('rtrim', $message2);

	$diff = new Horde_Text_Diff('auto', array($message1, $message2));
	$renderer = new Horde_Text_Diff_Renderer_Inline();

	if($message1 == $message2)
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
		SELECT e.*, u.username, u.usergroup, u.displaygroup
		FROM ".TABLE_PREFIX."edithistory e
		LEFT JOIN ".TABLE_PREFIX."users u ON (e.uid=u.uid)
		WHERE e.eid='".$mybb->get_input('eid', MyBB::INPUT_INT)."'
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
		$edit['reason'] = htmlspecialchars_uni($parser->parse_badwords($edit['reason']));
	}

	// Sanitize post
	$edit['subject'] = htmlspecialchars_uni($parser->parse_badwords($edit['subject']));
	$edit['originaltext'] = nl2br(htmlspecialchars_uni($parser->parse_badwords($edit['originaltext'])));

	$dateline = my_date('relative', $edit['dateline']);

	if($edit['username'])
	{
		$edit['username'] = format_name(htmlspecialchars_uni($edit['username']), $edit['usergroup'], $edit['displaygroup']);
		$edit['username'] = build_profile_link($edit['username'], $edit['uid']);
	}
	else
	{
		$edit['username'] = htmlspecialchars_uni($lang->na_deleted);
	}

	$ipaddress = '';
	if(is_moderator($fid, "canviewips") && ($mybb->settings['editipaddress'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editipaddress'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editipaddress'] == 0))
	{
		$edit['ipaddress'] = my_inet_ntop($db->unescape_binary($edit['ipaddress']));
		eval("\$ipaddress = \"".$templates->get("edithistory_view_ipaddress")."\";");
	}

	eval("\$view = \"".$templates->get("edithistory_view")."\";");
	output_page($view);
}

// Revert edited post
if($mybb->input['action'] == "revert")
{
	// Verify incoming POST request
	verify_post_check($mybb->get_input('my_post_key'));

	// First, determine if they can revert edit
	if($mybb->settings['editrevert'] == 2 && $mybb->usergroup['cancp'] != 1)
	{
		error_no_permission();
	}
	else if($mybb->settings['editrevert'] == 1 && ($mybb->usergroup['issupermod'] != 1 && $mybb->usergroup['cancp'] != 1))
	{
		error_no_permission();
	}

	$query = $db->simple_select("edithistory", "*", "eid='".$mybb->get_input('eid', MyBB::INPUT_INT)."'");
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
		"pid" => (int)$history['pid'],
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
	$lang->edit_history = $lang->sprintf($lang->edit_history, $post['subject']);

	// Get edit history
	$edit_history = '';

	if(!$mybb->settings['editsperpages'] || (int)$mybb->settings['editsperpages'] < 1)
	{
		$mybb->settings['editsperpages'] = 10;
	}

	// Figure out if we need to display multiple pages.
	$perpage = (int)$mybb->settings['editsperpages'];
	$page = $mybb->get_input('page', MyBB::INPUT_INT);

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
		SELECT e.*, u.username, u.usergroup, u.displaygroup
		FROM ".TABLE_PREFIX."edithistory e
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
			$history['reason'] = htmlspecialchars_uni($parser->parse_badwords($history['reason']));
		}

		$ipaddress = '';
		if(is_moderator($fid, "canviewips") && ($mybb->settings['editipaddress'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editipaddress'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editipaddress'] == 0))
		{
			$history['ipaddress'] = my_inet_ntop($db->unescape_binary($history['ipaddress']));
			eval("\$ipaddress = \"".$templates->get("edithistory_item_ipaddress")."\";");
		}

		if($history['username'])
		{
			$history['username'] = format_name(htmlspecialchars_uni($history['username']), $history['usergroup'], $history['displaygroup']);
			$history['username'] = build_profile_link($history['username'], $history['uid']);
		}
		else
		{
			$history['username'] = htmlspecialchars_uni($lang->na_deleted);
		}

		$dateline = my_date('relative', $history['dateline']);

		// Sanitize post
		$history['originaltext'] = htmlspecialchars_uni($parser->parse_badwords($history['originaltext']));

		$readmore = '';
		if($mybb->settings['edithistorychar'] > 0 && my_strlen($history['originaltext']) > $mybb->settings['edithistorychar'])
		{
			eval("\$readmore = \"".$templates->get("edithistory_item_readmore", 1, 0)."\";");
			$history['originaltext'] = my_substr($history['originaltext'], 0, $mybb->settings['edithistorychar']) . "... {$readmore}";
			$originaltext = nl2br($history['originaltext']);
		}
		else
		{
			$originaltext = nl2br($history['originaltext']);
		}

		// Show revert option if allowed
		$revert = '';
		if($mybb->settings['editrevert'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editrevert'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editrevert'] == 0)
		{
			eval("\$revert = \"".$templates->get("edithistory_item_revert")."\";");
		}

		eval("\$edit_history .= \"".$templates->get("edithistory_item")."\";");
	}

	$ipaddress_header = '';
	if(is_moderator($fid, "canviewips") && ($mybb->settings['editipaddress'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editipaddress'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editipaddress'] == 0))
	{
		$colspan = 6;
		eval("\$ipaddress_header = \"".$templates->get("edithistory_ipaddress")."\";");
	}
	else
	{
		$colspan = 5;
	}

	if(!$edit_history)
	{
		eval("\$edit_history = \"".$templates->get("edithistory_nohistory")."\";");
	}

	eval("\$edithistory = \"".$templates->get("edithistory")."\";");
	output_page($edithistory);
}
