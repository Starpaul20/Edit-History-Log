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
if(my_strpos($_SERVER['PHP_SELF'], 'showthread.php'))
{
	global $templatelist;
	if(isset($templatelist))
	{
		$templatelist .= ',';
	}
	$templatelist .= 'postbit_edithistory';
}

// Tell MyBB when to run the hooks
$plugins->add_hook("datahandler_post_update", "edithistory_run");
$plugins->add_hook("postbit", "edithistory_postbit");
$plugins->add_hook("class_moderation_delete_post_start", "edithistory_delete_post");
$plugins->add_hook("class_moderation_delete_thread_start", "edithistory_delete_thread");
$plugins->add_hook("class_moderation_merge_threads", "edithistory_merge_thread");
$plugins->add_hook("class_moderation_split_posts", "edithistory_split_post");
$plugins->add_hook("fetch_wol_activity_end", "edithistory_online_activity");
$plugins->add_hook("build_friendly_wol_location_end", "edithistory_online_location");
$plugins->add_hook("datahandler_user_delete_content", "edithistory_delete");

$plugins->add_hook("admin_user_users_merge_commit", "edithistory_merge");
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
		"version"			=> "1.1",
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
			$db->add_column("posts", "hashistory", "smallint NOT NULL default '0'");
			break;
		default:
			$db->add_column("posts", "hashistory", "tinyint(1) NOT NULL default '0'");
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

	if($db->field_exists("hashistory", "posts"))
	{
		$db->drop_column("posts", "hashistory");
	}
}

// This function runs when the plugin is activated.
function edithistory_activate()
{
	global $db;

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
		'optionscode' => 'numeric',
		'value' => 10,
		'disporder' => 4,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorychar',
		'title' => 'Post Character Cutoff',
		'description' => 'The number of characters needed for the post to be cut off and a link to view the full text appears.',
		'optionscode' => 'numeric',
		'value' => 500,
		'disporder' => 5,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	$insertarray = array(
		'name' => 'edithistorypruning',
		'title' => 'Prune Edit History',
		'description' => 'The number of days to keep edit histories before they are pruned. Set to 0 to disable.',
		'optionscode' => 'numeric',
		'value' => 120,
		'disporder' => 6,
		'gid' => $gid
	);
	$db->insert_query("settings", $insertarray);

	rebuild_settings();

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
		'template'	=> $db->escape_string(' | <a href="edithistory.php?action=revert&amp;pid={$history[\'pid\']}&amp;eid={$history[\'eid\']}" title="{$lang->revert_current_post}" onclick="if(confirm(&quot;{$lang->revert_post_confirm}&quot;))window.location=this.href.replace(\'action=revert\',\'action=revert\');return false;">{$lang->revert}</a>'),
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

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("postbit", "#".preg_quote('{$post[\'editedmsg\']}')."#i", '{$post[\'editedmsg\']}{$post[\'edithistory\']}');
	find_replace_templatesets("postbit_classic", "#".preg_quote('{$post[\'editedmsg\']}')."#i", '{$post[\'editedmsg\']}{$post[\'edithistory\']}');

	change_admin_permission('tools', 'edithistory');
}

// This function runs when the plugin is deactivated.
function edithistory_deactivate()
{
	global $db;
	$db->delete_query("settings", "name IN('editmodvisibility','editrevert','editipaddress','editsperpages','edithistorychar','edithistorypruning')");
	$db->delete_query("settinggroups", "name IN('edithistory')");
	$db->delete_query("templates", "title IN('edithistory','edithistory_ipaddress','edithistory_nohistory','edithistory_item','edithistory_item_ipaddress','edithistory_item_revert','edithistory_item_readmore','postbit_edithistory','edithistory_comparison','edithistory_view','edithistory_view_ipaddress')");
	$db->delete_query("tasks", "file='edithistory'");
	rebuild_settings();

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("postbit", "#".preg_quote('{$post[\'edithistory\']}')."#i", '', 0);
	find_replace_templatesets("postbit_classic", "#".preg_quote('{$post[\'edithistory\']}')."#i", '', 0);

	change_admin_permission('tools', 'edithistory', -1);
}

// Log original post when edited
function edithistory_run()
{
	global $db, $mybb, $post, $session;
	$edit = get_post($post['pid']);
	$mybb->binary_fields["edithistory"] = array('ipaddress' => true);

	// Insert original message into edit history
	$edit_history = array(
		"pid" => (int)$edit['pid'],
		"tid" => (int)$edit['tid'],
		"uid" => (int)$mybb->user['uid'],
		"dateline" => TIME_NOW,
		"originaltext" => $db->escape_string($edit['message']),
		"subject" => $db->escape_string($edit['subject']),
		"ipaddress" => $db->escape_binary($session->packedip),
		"reason" => $db->escape_string($mybb->input['editreason'])
	);
	$db->insert_query("edithistory", $edit_history);

	$reason = array(
		"hashistory" => 1,
	);
	$db->update_query("posts", $reason, "pid='{$edit['pid']}'");
}

// Display log link on postbit (mods/admins only)
function edithistory_postbit($post)
{
	global $mybb, $lang, $templates, $fid;
	$lang->load("edithistory");

	$post['edithistory'] = '';
	if(is_moderator($fid, "caneditposts"))
	{
		if($post['hashistory'] == 1 && ($mybb->settings['editmodvisibility'] == 2 && $mybb->usergroup['cancp'] == 1 || $mybb->settings['editmodvisibility'] == 1 && ($mybb->usergroup['issupermod'] == 1 || $mybb->usergroup['cancp'] == 1) || $mybb->settings['editmodvisibility'] == 0))
		{
			eval("\$post['edithistory'] = \"".$templates->get("postbit_edithistory")."\";");
		}
	}

	return $post;
}

// Delete logs if post is deleted
function edithistory_delete_post($pid)
{
	global $db, $mybb;
	$db->delete_query("edithistory", "pid='{$pid}'");
}

// Delete logs if thread is deleted
function edithistory_delete_thread($tid)
{
	global $db, $mybb;
	$db->delete_query("edithistory", "tid='{$tid}'");
}

// Update tid if threads are merged
function edithistory_merge_thread($arguments)
{
	global $db, $mybb;
	$sqlarray = array(
		"tid" => "{$arguments['tid']}",
	);
	$db->update_query("edithistory", $sqlarray, "tid='{$arguments['mergetid']}'");
}

// Update tid if post(s) are split
function edithistory_split_post($arguments)
{
	global $db, $mybb;
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
    global $db, $mybb, $lang, $parameters;
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
	global $db, $mybb, $source_user, $destination_user;

	$uid = array(
		"uid" => $destination_user['uid']
	);	
	$db->update_query("edithistory", $uid, "uid='{$source_user['uid']}'");
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
  	global $db, $mybb, $lang;
	$lang->load("tools_edithistory");

	$admin_permissions['edithistory'] = $lang->can_manage_edit_history;

	return $admin_permissions;
}

// Admin Log display
function edithistory_admin_adminlog($plugin_array)
{
	global $lang;
	$lang->load("tools_edithistory");

	if($plugin_array['lang_string'] == admin_log_tools_edithistory_prune)
	{
		if($plugin_array['logitem']['data'][1] && !$plugin_array['logitem']['data'][2])
		{
			$plugin_array['lang_string'] = admin_log_tools_edithistory_prune_user;
		}
		elseif($plugin_array['logitem']['data'][2] && !$plugin_array['logitem']['data'][1])
		{
			$plugin_array['lang_string'] = admin_log_tools_edithistory_prune_thread;
		}
		elseif($plugin_array['logitem']['data'][1] && $plugin_array['logitem']['data'][2])
		{
			$plugin_array['lang_string'] = admin_log_tools_edithistory_prune_user_thread;
		}
	}

	return $plugin_array;
}

?>