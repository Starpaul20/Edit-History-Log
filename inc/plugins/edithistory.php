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

// Neat trick for caching our custom template(s)
if(defined('THIS_SCRIPT'))
{
	if(THIS_SCRIPT == 'showthread.php')
	{
		global $templatelist;
		if(isset($templatelist))
		{
			$templatelist .= ',';
		}
		$templatelist .= 'postbit_edithistory';
	}
}

// Tell MyBB when to run the hooks
$plugins->add_hook("datahandler_post_update", "edithistory_run");
$plugins->add_hook("postbit", "edithistory_postbit");
$plugins->add_hook("xmlhttp_update_post", "edithistory_xmlhttp");
$plugins->add_hook("class_moderation_delete_post_start", "edithistory_delete_post");
$plugins->add_hook("class_moderation_delete_thread_start", "edithistory_delete_thread");
$plugins->add_hook("class_moderation_merge_threads", "edithistory_merge_thread");
$plugins->add_hook("class_moderation_split_posts", "edithistory_split_post");
$plugins->add_hook("fetch_wol_activity_end", "edithistory_online_activity");
$plugins->add_hook("build_friendly_wol_location_end", "edithistory_online_location");
$plugins->add_hook("datahandler_user_delete_content", "edithistory_delete");

$plugins->add_hook("admin_user_users_merge_commit", "edithistory_merge");
$plugins->add_hook("admin_tools_recount_rebuild", "edithistory_do_recount");
$plugins->add_hook("admin_tools_recount_rebuild_output_list", "edithistory_recount");
$plugins->add_hook("admin_tools_menu_logs", "edithistory_admin_menu");
$plugins->add_hook("admin_tools_action_handler", "edithistory_admin_action_handler");
$plugins->add_hook("admin_tools_permissions", "edithistory_admin_permissions");
$plugins->add_hook("admin_tools_get_admin_log_action", "edithistory_admin_adminlog");

// The information that shows up on the plugin manager
function edithistory_info()
{
	global $lang;
	$lang->load("edithistory", true);

	return array(
		"name"				=> $lang->edithistory_info_name,
		"description"		=> $lang->edithistory_info_desc,
		"website"			=> "http://galaxiesrealm.com/index.php",
		"author"			=> "Starpaul20",
		"authorsite"		=> "http://galaxiesrealm.com/index.php",
		"version"			=> "1.6",
		"codename"			=> "edithistory",
		"compatibility"		=> "18*"
	);
}

// This function runs when the plugin is installed.
function edithistory_install()
{
	global $db;
	edithistory_uninstall();
	$collation = $db->build_create_table_collation();

	switch($db->type)
	{
		case "sqlite":
			$db->write_query("CREATE TABLE ".TABLE_PREFIX."edithistory (
				eid INTEGER PRIMARY KEY,
				pid int NOT NULL default '0',
				tid int NOT NULL default '0',
				uid int NOT NULL default '0',
				dateline int NOT NULL default '0',
				originaltext TEXT NOT NULL,
				subject varchar(120) NOT NULL default '',
				ipaddress blob(16) NOT NULL default '',
				reason varchar(150) NOT NULL default ''
			);");
			break;
		case "pgsql":
			$db->write_query("CREATE TABLE ".TABLE_PREFIX."edithistory (
				eid serial,
				pid int NOT NULL default '0',
				tid int NOT NULL default '0',
				uid int NOT NULL default '0',
				dateline numeric(30,0) NOT NULL default '0',
				originaltext text NOT NULL default '',
				subject varchar(120) NOT NULL DEFAULT '',
				ipaddress bytea NOT NULL default '',
				reason varchar(150) NOT NULL DEFAULT '',
				PRIMARY KEY (eid)
			);");
			break;
		default:
			$db->write_query("CREATE TABLE ".TABLE_PREFIX."edithistory (
				eid int(10) unsigned NOT NULL auto_increment,
				pid int(10) unsigned NOT NULL default '0',
				tid int(10) unsigned NOT NULL default '0',
				uid int(10) unsigned NOT NULL default '0',
				dateline int unsigned NOT NULL default '0',
				originaltext text NOT NULL,
				subject varchar(120) NOT NULL default '',
				ipaddress varbinary(16) NOT NULL default '',
				reason varchar(150) NOT NULL default '',
				KEY pid (pid),
				PRIMARY KEY(eid)
			) ENGINE=MyISAM{$collation};");
			break;
	}

	switch($db->type)
	{
		case "pgsql":
			$db->add_column("posts", "editcount", "smallint NOT NULL default '0'");
			break;
		case "sqlite":
			$db->add_column("posts", "editcount", "smallint(5) NOT NULL default '0'");
			break;
		default:
			$db->add_column("posts", "editcount", "smallint(5) unsigned NOT NULL default '0'");
			break;
	}
}

// Checks to make sure plugin is installed
function edithistory_is_installed()
{
	global $db;
	if($db->table_exists("edithistory"))
	{
		return true;
	}
	return false;
}

// This function runs when the plugin is uninstalled.
function edithistory_uninstall()
{
	global $db;
	if($db->table_exists("edithistory"))
	{
		$db->drop_table("edithistory");
	}

	if($db->field_exists("editcount", "posts"))
	{
		$db->drop_column("posts", "editcount");
	}

	if($db->field_exists("hashistory", "posts"))
	{
		$db->drop_column("posts", "hashistory");
	}
}

// This function runs when the plugin is activated.
function edithistory_activate()
{
	global $db;

	// Upgrade support (from 1.2 to 1.3)
	if(!$db->field_exists("editcount", "posts"))
	{
		switch($db->type)
		{
			case "pgsql":
				$db->add_column("posts", "editcount", "smallint NOT NULL default '0'");
				break;
			case "sqlite":
				$db->add_column("posts", "editcount", "smallint(5) NOT NULL default '0'");
				break;
			default:
				$db->add_column("posts", "editcount", "smallint(5) unsigned NOT NULL default '0'");
				break;
		}

		$query = $db->simple_select("edithistory", "DISTINCT pid");
		while($history = $db->fetch_array($query))
		{
			$query2 = $db->query("
				SELECT COUNT(eid) as num_edits
				FROM ".TABLE_PREFIX."edithistory
				WHERE pid='{$history['pid']}'
			");
			$num_edits = $db->fetch_field($query2, "num_edits");

			$db->update_query("posts", array("editcount" => (int)$num_edits), "pid='{$history['pid']}'");
		}

		if($db->field_exists("hashistory", "posts"))
		{
			$db->drop_column("posts", "hashistory");
		}
	}

	// Insert settings
	$insertarray = array(
		'name' => 'edithistory',
		'title' => 'Edit History Settings',
		'description' => 'Various option related to edit history (edithistory.php) can be managed and set here.',
		'disporder' => 32,
		'isdefault' => 0,
	);
	$gid = $db->insert_query("settinggroups", $insertarray);

	$insertarray = array(
		'name' => 'editmodvisibility',
		'title' => 'Edit History Visibility',
		'description' => 'Allows you to determine who has permission to view edit histories.',
		'optionscode' => 'radio
0=Admins, Super Mods and Mods
1=Admins and Super Mods only
2=Admins only',
		'value' => 0,
		'disporder' => 1,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'editrevert',
		'title' => 'Edit Reversion',
		'description' => 'Allows you to determine who has permission to revert posts.',
		'optionscode' => 'radio
0=Admins, Super Mods and Mods
1=Admins and Super Mods only
2=Admins only',
		'value' => 1,
		'disporder' => 2,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'editipaddress',
		'title' => 'Edit IP Address',
		'description' => $db->escape_string('Allows you to determine who has permission to view edit IP addresses. Please note Forum Moderators must also have permission to view IP addresses in forums they\'re assigned to.'),
		'optionscode' => 'radio
0=Admins, Super Mods and Mods
1=Admins and Super Mods only
2=Admins only',
		'value' => 0,
		'disporder' => 3,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'editsperpages',
		'title' => 'Edits Per Page',
		'description' => 'Here you can enter the number of edits to show per page.',
		'optionscode' => 'numeric
min=1',
		'value' => 10,
		'disporder' => 4,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorychar',
		'title' => 'Post Character Cutoff',
		'description' => 'The number of characters needed for the post to be cut off and a link to view the full text appears.',
		'optionscode' => 'numeric
min=0',
		'value' => 500,
		'disporder' => 5,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorypruning',
		'title' => 'Prune Edit History',
		'description' => 'The number of days to keep edit histories before they are pruned. Set to 0 to disable.',
		'optionscode' => 'numeric
min=0',
		'value' => 120,
		'disporder' => 6,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorycount',
		'title' => 'Display Edit Count on posts',
		'description' => 'Diplay the number of edits that have been made to a post. Edited By messages must also be enabled.',
		'optionscode' => 'yesno',
		'value' => 1,
		'disporder' => 7,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorytime',
		'title' => 'Minimum Time For Logging Edits',
		'description' => 'The number of seconds after a post has been made before edits are logged. Set to 0 to log all edits regardless of time.',
		'optionscode' => 'numeric
min=0',
		'value' => 30,
		'disporder' => 8,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	rebuild_settings();

	// Insert templates
	$insert_array = array(
		'title'		=> 'edithistory',
		'template'	=> $db->escape_string('<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->edit_history}</title>
{$headerinclude}
</head>
<body>
{$header}
{$post_errors}
{$multipage}
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
	<tr>
		<td class="thead" colspan="{$colspan}"><strong>{$lang->edit_history}</strong></td>
	</tr>
	<tr>
		<td class="tcat" align="center"><span class="smalltext"><strong>{$lang->edit_reason}</strong></span></td>
		<td class="tcat" width="10%" align="center"><span class="smalltext"><strong>{$lang->edited_by}</strong></span></td>
		{$ipaddress_header}
		<td class="tcat" width="15%" align="center"><span class="smalltext"><strong>{$lang->date}</strong></span></td>
		<td class="tcat" width="35%" align="center"><span class="smalltext"><strong>{$lang->original_text}</strong></span></td>
		<td class="tcat" width="15%" align="center"><span class="smalltext"><strong>{$lang->options}</strong></span></td>
	</tr>
	{$edit_history}
</table>
{$multipage}
{$footer}
</body>
</html>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_ipaddress',
		'template'	=> $db->escape_string('<td class="tcat" width="10%" align="center"><span class="smalltext"><strong>{$lang->ip_address}</strong></span></td>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_nohistory',
		'template'	=> $db->escape_string('<tr>
	<td class="trow1" colspan="{$colspan}" align="center">{$lang->no_history}</td>
</tr>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_item',
		'template'	=> $db->escape_string('<tr>
	<td class="{$alt_bg}" align="center">{$history[\'reason\']}</td>
	<td class="{$alt_bg}" align="center">{$history[\'username\']}</td>
	{$ipaddress}
	<td class="{$alt_bg}" align="center">{$dateline}</td>
	<td class="{$alt_bg}">{$originaltext}</td>
	<td class="{$alt_bg}" align="center"><strong><a href="edithistory.php?action=compare&amp;pid={$history[\'pid\']}&amp;eid={$history[\'eid\']}" title="{$lang->compare_posts}">{$lang->compare}</a> | <a href="edithistory.php?action=view&amp;pid={$history[\'pid\']}&amp;eid={$history[\'eid\']}" title="{$lang->view_original_text_post}">{$lang->view}</a>{$revert}</strong></td>
</tr>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_item_ipaddress',
		'template'	=> $db->escape_string('<td class="{$alt_bg}" align="center">{$history[\'ipaddress\']}</td>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_item_revert',
		'template'	=> $db->escape_string(' | <a href="edithistory.php?action=revert&amp;pid={$history[\'pid\']}&amp;eid={$history[\'eid\']}&amp;my_post_key={$mybb->post_code}" title="{$lang->revert_current_post}" onclick="if(confirm(&quot;{$lang->revert_post_confirm}&quot;))window.location=this.href.replace(\'action=revert\',\'action=revert\');return false;">{$lang->revert}</a>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_item_readmore',
		'template'	=> $db->escape_string('<strong><a href="edithistory.php?action=view&amp;pid={$history[\'pid\']}&amp;eid={$history[\'eid\']}">{$lang->read_more}</a></strong>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'postbit_edithistory',
		'template'	=> $db->escape_string('<span class="edited_post">(<a href="edithistory.php?pid={$post[\'pid\']}">{$lang->view_edit_history}</a>)</span>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_comparison',
		'template'	=> $db->escape_string('<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->edit_history}</title>
{$headerinclude}
<style type="text/css">
ins {
background: #bfb;
text-decoration: none;
padding: 2px;
}

del {
background: #fbb;
text-decoration: none;
padding: 2px;
}
</style>
</head>
<body>
{$header}
<span class="smalltext">{$lang->highlight_added}</span><br />
<span class="smalltext">{$lang->highlight_deleted}</span><br />
<br />
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
	<tr>
		<td class="thead"><strong>{$lang->edit_history}</strong></td>
	</tr>
	<tr>
		<td class="tcat"><span class="smalltext"><strong>{$lang->edit_as_of}</strong></span></td>
	</tr>
	<tr>
		<td class="trow1"><pre style="white-space: pre-wrap;">{$comparison}</pre></td>
	</tr>
</table>
{$footer}
</body>
</html>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_view',
		'template'	=> $db->escape_string('<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->view_original_text}</title>
{$headerinclude}
</head>
<body>
{$header}
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
	<tr>
		<td class="thead" colspan="2"><strong>{$lang->view_original_text}</strong></td>
	</tr>
	<tr>
		<td class="trow1" width="30%"><strong>{$lang->edited_by}:</strong></td>
		<td class="trow1">{$edit[\'username\']}</td>
	</tr>
	{$ipaddress}
	<tr>
		<td class="trow1" width="30%"><strong>{$lang->subject}:</strong></td>
		<td class="trow1">{$edit[\'subject\']}</td>
	</tr>
	<tr>
		<td class="trow2" width="30%"><strong>{$lang->date}:</strong></td>
		<td class="trow2">{$dateline}</td>
	</tr>
	<tr>
		<td class="trow1" width="30%"><strong>{$lang->edit_reason}:</strong></td>
		<td class="trow1">{$edit[\'reason\']}</td>
	</tr>
	<tr>
		<td class="trow2" width="30%"><strong>{$lang->original_text}:</strong></td>
		<td class="trow2">{$edit[\'originaltext\']}</td>
	</tr>
</table>
{$footer}
</body>
</html>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	$insert_array = array(
		'title'		=> 'edithistory_view_ipaddress',
		'template'	=> $db->escape_string('<tr>
	<td class="trow2" width="30%"><strong>{$lang->ip_address}:</strong></td>
	<td class="trow2">{$edit[\'ipaddress\']}</td>
</tr>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	// Insert task
	require_once MYBB_ROOT."inc/functions_task.php";
	$subscription_insert = array(
		"title"			=> "Edit History Pruning",
		"description"	=> "Automatically prunes edit history based on the time set in the settings every day.",
		"file"			=> "edithistory",
		"minute"		=> "0",
		"hour"			=> "3",
		"day"			=> "*",
		"month"			=> "*",
		"weekday"		=> "*",
		"enabled"		=> 0,
		"logging"		=> 1,
		"locked"		=> 0
	);

	$subscription_insert['nextrun'] = fetch_next_run($subscription_insert);
	$db->insert_query("tasks", $subscription_insert);

	// Update templates
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("postbit", "#".preg_quote('{$post[\'editedmsg\']}')."#i", '{$post[\'editedmsg\']}{$post[\'edithistory\']}');
	find_replace_templatesets("postbit_classic", "#".preg_quote('{$post[\'editedmsg\']}')."#i", '{$post[\'editedmsg\']}{$post[\'edithistory\']}');
	find_replace_templatesets("postbit_editedby", "#".preg_quote('{$editreason}')."#i", '<!-- editcount -->{$editreason}');

	change_admin_permission('tools', 'edithistory');
}

// This function runs when the plugin is deactivated.
function edithistory_deactivate()
{
	global $db;
	$db->delete_query("settings", "name IN('editmodvisibility','editrevert','editipaddress','editsperpages','edithistorychar','edithistorypruning','edithistorycount','edithistorytime')");
	$db->delete_query("settinggroups", "name IN('edithistory')");
	$db->delete_query("templates", "title IN('edithistory','edithistory_ipaddress','edithistory_nohistory','edithistory_item','edithistory_item_ipaddress','edithistory_item_revert','edithistory_item_readmore','postbit_edithistory','edithistory_comparison','edithistory_view','edithistory_view_ipaddress')");
	$db->delete_query("tasks", "file='edithistory'");
	rebuild_settings();

	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("postbit", "#".preg_quote('{$post[\'edithistory\']}')."#i", '', 0);
	find_replace_templatesets("postbit_classic", "#".preg_quote('{$post[\'edithistory\']}')."#i", '', 0);
	find_replace_templatesets("postbit_editedby", "#".preg_quote('<!-- editcount -->')."#i", '', 0);

	change_admin_permission('tools', 'edithistory', -1);
}

// Log original post when edited
function edithistory_run()
{
	global $db, $mybb, $post, $session;
	$edit = get_post($post['pid']);
	$mybb->binary_fields["edithistory"] = array('ipaddress' => true);
	$time = TIME_NOW;

	// Insert original message into edit history
	if($mybb->settings['edithistorytime'] == 0 || ($edit['dateline'] < ($time - $mybb->settings['edithistorytime'])))
	{
		$edit_history = array(
			"pid" => (int)$edit['pid'],
			"tid" => (int)$edit['tid'],
			"uid" => (int)$mybb->user['uid'],
			"dateline" => TIME_NOW,
			"originaltext" => $db->escape_string($edit['message']),
			"subject" => $db->escape_string($edit['subject']),
			"ipaddress" => $db->escape_binary($session->packedip),
			"reason" => $db->escape_string($mybb->get_input('editreason'))
		);
		$db->insert_query("edithistory", $edit_history);

		$edit_count = array(
			"editcount" => (int)$edit['editcount'] + 1,
		);
		$db->update_query("posts", $edit_count, "pid='{$edit['pid']}'");
	}
}

// Display log link on postbit (mods/admins only)
function edithistory_postbit($post)
{
	global $mybb, $lang, $templates, $fid;
	$lang->load("edithistory");

	$post['edithistory'] = '';
	if(is_moderator($fid, "caneditposts"))
	{
		if($post['editcount'] > 0 && ($mybb->settings['editmodvisibility'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editmodvisibility'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editmodvisibility'] == 0))
		{
			eval("\$post['edithistory'] = \"".$templates->get("postbit_edithistory")."\";");
		}
	}

	$edited_by_count = '';
	if($mybb->settings['edithistorycount'] == 1 && $post['editcount'] > 0)
	{
		if($post['editcount'] == 1)
		{
			$edited_by_count = $lang->edited_time_total;
		}
		else
		{
			$post['editcount'] = my_number_format($post['editcount']);
			$edited_by_count = $lang->sprintf($lang->edited_times_total, $post['editcount']);
		}

		$post['editedmsg'] = str_replace("<!-- editcount -->", $edited_by_count, $post['editedmsg']);
	}

	return $post;
}

// Add Edit History link when quick editing (mods/admins only)
function edithistory_xmlhttp()
{
	global $mybb, $lang, $templates, $post, $editedmsg_response;
	$lang->load("edithistory");
	$time = TIME_NOW;

	$edithistory = '';
	if(is_moderator($post['fid'], "caneditposts"))
	{
		if(($mybb->settings['editmodvisibility'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editmodvisibility'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editmodvisibility'] == 0) && ($mybb->settings['edithistorytime'] == 0 || $post['dateline'] < ($time - $mybb->settings['edithistorytime'])))
		{
			eval("\$edithistory = \"".$templates->get("postbit_edithistory")."\";");
		}
	}

	$edited_by_count = '';
	if($mybb->settings['edithistorycount'] == 1 && ($mybb->settings['edithistorytime'] == 0 || $post['dateline'] < ($time - $mybb->settings['edithistorytime'])))
	{
		$post['editcount'] = $post['editcount'] + 1;

		if($post['editcount'] == 1)
		{
			$edited_by_count = $lang->edited_time_total;
		}
		else
		{
			$post['editcount'] = my_number_format($post['editcount']);
			$edited_by_count = $lang->sprintf($lang->edited_times_total, $post['editcount']);
		}

		$editedmsg_response = str_replace("<!-- editcount -->", $edited_by_count, $editedmsg_response);
	}

	$editedmsg_response .= $edithistory;
}

// Delete logs if post is deleted
function edithistory_delete_post($pid)
{
	global $db;
	$db->delete_query("edithistory", "pid='{$pid}'");
}

// Delete logs if thread is deleted
function edithistory_delete_thread($tid)
{
	global $db;
	$db->delete_query("edithistory", "tid='{$tid}'");
}

// Update tid if threads are merged
function edithistory_merge_thread($arguments)
{
	global $db;
	$sqlarray = array(
		"tid" => "{$arguments['tid']}",
	);
	$db->update_query("edithistory", $sqlarray, "tid='{$arguments['mergetid']}'");
}

// Update tid if post(s) are split
function edithistory_split_post($arguments)
{
	global $db;
	$pids = array_map('intval', $arguments['pids']);
	$pids_list = implode(',', $pids);

	$query = $db->simple_select("posts", "tid", "pid IN ({$pids_list})", array('limit' => 1));
	$new_tid = $db->fetch_array($query);

	$sql_array = array(
		"tid" => "{$new_tid['tid']}",
	);
	$db->update_query("edithistory", $sql_array, "pid IN ({$pids_list})");
}

// Online activity
function edithistory_online_activity($user_activity)
{
	global $user, $parameters;

	$split_loc = explode(".php", $user_activity['location']);
	if($split_loc[0] == $user['location'])
	{
		$filename = '';
	}
	else
	{
		$filename = my_substr($split_loc[0], -my_strpos(strrev($split_loc[0]), "/"));
	}

	switch($filename)
	{
		case "edithistory":
			if($parameters['action'] == "compare")
			{
				$user_activity['activity'] = "edithistory_compare";
			}
			elseif($parameters['action'] == "view")
			{
				$user_activity['activity'] = "edithistory_history";
			}
			else
			{
				$user_activity['activity'] = "edithistory_history";
			}
			break;
	}

	return $user_activity;
}

function edithistory_online_location($plugin_array)
{
	global $lang;
	$lang->load("edithistory");

	if($plugin_array['user_activity']['activity'] == "edithistory_compare")
	{
		$plugin_array['location_name'] = $lang->comparing_edit_history;
	}
	elseif($plugin_array['user_activity']['activity'] == "edithistory_history")
	{
		$plugin_array['location_name'] = $lang->viewing_edit_history;
	}

	return $plugin_array;
}

// Update edit history if user is deleted
function edithistory_delete($delete)
{
	global $db;

	$db->update_query('edithistory', array('uid' => 0), 'uid IN('.$delete->delete_uids.')');

	return $delete;
}

// Update edit history user if users are merged
function edithistory_merge()
{
	global $db, $source_user, $destination_user;

	$uid = array(
		"uid" => $destination_user['uid']
	);	
	$db->update_query("edithistory", $uid, "uid='{$source_user['uid']}'");
}

// Actually recount edit count
function edithistory_do_recount()
{
	global $db, $mybb, $lang;
	$lang->load("tools_edithistory");

	if($mybb->request_method == "post")
	{
		if(!isset($mybb->input['page']) || $mybb->get_input('page', MyBB::INPUT_INT) < 1)
		{
			$mybb->input['page'] = 1;
		}

		if(isset($mybb->input['do_recounteditcount']))
		{
			if($mybb->input['page'] == 1)
			{
				// Log admin action
				log_admin_action("editcount");
			}

			if(!$mybb->get_input('editcount', MyBB::INPUT_INT))
			{
				$mybb->input['editcount'] = 500;
			}

			$query = $db->simple_select("posts", "COUNT(pid) as num_posts");
			$num_posts = $db->fetch_field($query, 'num_posts');

			$page = $mybb->get_input('page', MyBB::INPUT_INT);
			$per_page = $mybb->get_input('editcount', MyBB::INPUT_INT);
			if($per_page <= 0)
			{
				$per_page = 500;
			}
			$start = ($page-1) * $per_page;
			$end = $start + $per_page;

			$query = $db->simple_select("posts", "pid", '', array('order_by' => 'pid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $per_page));
			while($post = $db->fetch_array($query))
			{
				$query2 = $db->query("
					SELECT COUNT(eid) as num_edits
					FROM ".TABLE_PREFIX."edithistory
					WHERE pid='{$post['pid']}'
				");
				$num_edits = $db->fetch_field($query2, "num_edits");

				$db->update_query("posts", array("editcount" => (int)$num_edits), "pid='{$post['pid']}'");
			}

			check_proceed($num_posts, $end, ++$page, $per_page, "editcount", "do_recounteditcount", $lang->success_rebuilt_editcount);
		}
	}
}

// Recount edit count
function edithistory_recount()
{
	global $lang, $form_container, $form;
	$lang->load("tools_edithistory");

	$form_container->output_cell("<label>{$lang->recount_editcount}</label><div class=\"description\">{$lang->recount_editcount_desc}</div>");
	$form_container->output_cell($form->generate_numeric_field("editcount", 500, array('style' => 'width: 150px;', 'min' => 0)));
	$form_container->output_cell($form->generate_submit_button($lang->go, array("name" => "do_recounteditcount")));
	$form_container->construct_row();
}

// Admin CP log page
function edithistory_admin_menu($sub_menu)
{
	global $lang;
	$lang->load("tools_edithistory");

	$sub_menu['110'] = array('id' => 'edithistory', 'title' => $lang->edit_history_log, 'link' => 'index.php?module=tools-edithistory');

	return $sub_menu;
}

function edithistory_admin_action_handler($actions)
{
	$actions['edithistory'] = array('active' => 'edithistory', 'file' => 'edithistory.php');

	return $actions;
}

function edithistory_admin_permissions($admin_permissions)
{
	global $lang;
	$lang->load("tools_edithistory");

	$admin_permissions['edithistory'] = $lang->can_manage_edit_history;

	return $admin_permissions;
}

// Admin Log display
function edithistory_admin_adminlog($plugin_array)
{
	global $lang;
	$lang->load("tools_edithistory");

	if($plugin_array['lang_string'] == 'admin_log_tools_edithistory_prune')
	{
		if($plugin_array['logitem']['data'][1] && !$plugin_array['logitem']['data'][2])
		{
			$plugin_array['lang_string'] = 'admin_log_tools_edithistory_prune_user';
		}
		elseif($plugin_array['logitem']['data'][2] && !$plugin_array['logitem']['data'][1])
		{
			$plugin_array['lang_string'] = 'admin_log_tools_edithistory_prune_thread';
		}
		elseif($plugin_array['logitem']['data'][1] && $plugin_array['logitem']['data'][2])
		{
			$plugin_array['lang_string'] = 'admin_log_tools_edithistory_prune_user_thread';
		}
	}

	return $plugin_array;
}
