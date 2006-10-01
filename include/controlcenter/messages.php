<?php

////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2006  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
////////////////////////////////////////////////////////////////////////////////

if(!defined("PHORUM_CONTROL_CENTER")) return;

if (!$PHORUM["DATA"]["MESSAGE_MODERATOR"]) {
    phorum_redirect_by_url(phorum_get_url(PHORUM_CONTROLCENTER_URL));
    exit();
}

// the number of days to show
if (isset($_POST['moddays']) && is_numeric($_POST['moddays'])) {
    $moddays = (int)$_POST['moddays'];
} elseif(isset($PHORUM['args']['moddays']) && !empty($PHORUM["args"]['moddays']) && is_numeric($PHORUM["args"]['moddays'])) {
    $moddays = (int)$PHORUM['args']['moddays'];
} elseif(isset($PHORUM["user"]["settings_data"]["cc_messages_moddays"])) {
    $moddays = $PHORUM["user"]["settings_data"]["cc_messages_moddays"];
} else {
    $moddays = 2;
}


if (isset($_POST['onlyunapproved']) && is_numeric($_POST['onlyunapproved'])) {
    $showwaiting = (int)$_POST['onlyunapproved'];
} elseif(isset($PHORUM['args']['onlyunapproved']) && !empty($PHORUM["args"]['onlyunapproved']) && is_numeric($PHORUM["args"]['onlyunapproved'])) {
    $showwaiting = (int)$PHORUM['args']['onlyunapproved'];
} elseif(isset($PHORUM["user"]["settings_data"]["cc_messages_onlyunapproved"])) {
    $showwaiting = $PHORUM["user"]["settings_data"]["cc_messages_onlyunapproved"];
} else {
    $showwaiting = 0;
}
$PHORUM['DATA']['SELECTED'] = $moddays;
$PHORUM['DATA']['SELECTED_2'] = $showwaiting?true:false;

// Store current selection for the user.
phorum_user_settings_data_save(array(
    "cc_messages_moddays" => $moddays,
    "cc_messages_onlyunapproved" => $showwaiting
));

// some needed vars
$numunapproved = 0;
$oldforum = $PHORUM['forum_id'];

$mod_forums = phorum_user_access_list(PHORUM_USER_ALLOW_MODERATE_MESSAGES);
$gotforums = (count($mod_forums) > 0);

$PHORUM['DATA']['PREPOST'] = array();

if ($gotforums)
    $foruminfo = phorum_db_get_forums($mod_forums,-1,$PHORUM['vroot']);
else
    $foruminfo = array();

// Make sure we have a forum name for unapproved announcements.
$foruminfo[$PHORUM["vroot"]] = array (
    'name' => $PHORUM["DATA"]["LANG"]["Announcement"]
);

// Find a forum id to use for announcements. To read an announcement,
// we need a real forum id, but announcements are linked to the vroot folder.
$announce_forum_id = $PHORUM["forum_id"];
if ($PHORUM["forum_id"] == $PHORUM["vroot"] || $PHORUM["folder_flag"]) {
    // Walk through all forums in the current vroot to find
    // a suitable candidate.
    foreach ($foruminfo as $id => $forum) {
        if ($forum["forum_id"] != $PHORUM["vroot"] && !$forum["folder_flag"]) {
            $announce_forum_id = $forum["forum_id"];
            break;
        }
    }
}

foreach($mod_forums as $forum => $rest) {

    $checkvar = 1;
    // Get the threads
    $rows = array();
    // get the thread set started
    $rows = phorum_db_get_unapproved_list($forum,$showwaiting,$moddays);

    // loop through and read all the data in.
    foreach($rows as $key => $row) {
        $numunapproved++;
        $rows[$key]['forumname'] = $foruminfo[$forum]['name'];
        $rows[$key]['checkvar'] = $checkvar;
        if ($checkvar)
            $checkvar = 0;
        $rows[$key]['forum_id'] = $forum;
        // use a real forum id for announcements
        $read_forum_id = $forum==$PHORUM["vroot"] ? $announce_forum_id:$forum;
        $rows[$key]["URL"]["READ"] = phorum_get_url(PHORUM_FOREIGN_READ_URL, $read_forum_id, $row["thread"], $row['message_id']);
        // we need to fake the forum_id here
        $PHORUM["forum_id"] = $forum;
        $rows[$key]["URL"]["APPROVE_MESSAGE"] = phorum_get_url(PHORUM_MODERATION_URL, PHORUM_APPROVE_MESSAGE, $row["message_id"], "prepost=1", "old_forum=" . $oldforum,"onlyunapproved=".$showwaiting,"moddays=".$moddays);
        $rows[$key]["URL"]["APPROVE_TREE"] = phorum_get_url(PHORUM_MODERATION_URL, PHORUM_APPROVE_MESSAGE_TREE, $row["message_id"], "prepost=1", "old_forum=" . $oldforum,"onlyunapproved=".$showwaiting,"moddays=".$moddays);
        $rows[$key]["URL"]["DELETE"] = phorum_get_url(PHORUM_MODERATION_URL, PHORUM_DELETE_TREE, $row["message_id"], "prepost=1", "old_forum=" . $oldforum,"onlyunapproved=".$showwaiting,"moddays=".$moddays);
        $PHORUM["forum_id"] = $oldforum;
        $rows[$key]["short_datestamp"] = phorum_date($PHORUM["short_date_time"], $row["datestamp"]);

        if ($row["user_id"]) {
            $url = phorum_get_url(PHORUM_PROFILE_URL, $row["user_id"]);
            $rows[$key]["URL"]["READ"]["PROFILE"] = $url;
            $rows[$key]["linked_author"] = "<a href=\"$url\">$row[author]</a>";
        } else {
            $rows[$key]["profile_url"] = "";
            $rows[$key]["linked_author"] = $row["author"];
        }
    }
    // $PHORUM['DATA']['FORUMS'][$forum]['forum_id']=$forum;
    $PHORUM['DATA']['PREPOST'] = array_merge($PHORUM['DATA']['PREPOST'], $rows);
}


if (!$numunapproved) {
    $PHORUM["DATA"]["UNAPPROVEDMESSAGE"] = $PHORUM["DATA"]["LANG"]["NoUnapprovedMessages"];
}

$PHORUM["DATA"]["HEADING"] = $PHORUM["DATA"]["LANG"]["UnapprovedMessages"];

$template = "cc_prepost";
?>
