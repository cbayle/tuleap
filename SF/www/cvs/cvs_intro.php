<?php
//
// CodeX: Breaking Down the Barriers to Source Code Sharing inside Xerox
// Copyright (c) Xerox Corporation, CodeX / CodeX Team, 2001-2002. All Rights Reserved
// http://codex.xerox.com
//
// $Id$
//
//	Originally written by Laurent Julliard 2001- 2003 CodeX Team, Xerox
//

if (!$group_id) {
    exit_no_group(); // need a group_id !!!
}

commits_header(array ('title'=>'CVS Information'));

// Table for summary info
print '<TABLE width="100%"><TR valign="top"><TD width="65%">'."\n";

// Get group properties
$res_grp = db_query("SELECT * FROM groups WHERE group_id=$group_id");
$row_grp = db_fetch_array($res_grp);

// Show CVS access information
if ($row_grp['cvs_preamble']!='') {
    echo util_unconvert_htmlspecialchars($row_grp['cvs_preamble']);
} else {
    include(util_get_content('cvs/intro'));
}

// Summary info
print '</TD><TD width="25%">';
print $HTML->box1_top("Repository History");

// Is there anything in the cvs history table ?
$res_cvshist = db_query("SELECT * FROM group_cvs_history WHERE group_id='$group_id'");
if (db_numrows($res_cvshist) < 1) {
        print '<P>This project has no CVS history.';
} else {

    print '<P><b>Developer (Commits) (Adds) 7day/Total</b><BR>&nbsp;';

    while ($row_cvshist = db_fetch_array($res_cvshist)) {
        print '<BR>'.$row_cvshist['user_name'].' ('.$row_cvshist['cvs_commits_wk'].'/'
	    .$row_cvshist['cvs_commits'].') ('.$row_cvshist['cvs_adds_wk'].'/'
	    .$row_cvshist['cvs_adds'].')';
    }

}

// CVS Browsing Box
$uri = session_make_url('/cgi-bin/viewcvs.cgi/?root='.$row_grp['unix_group_name'].'&roottype=cvs');
print '<HR><B>Browse the CVS Tree</B>
<P>Browsing the CVS tree gives you a great view into the current status
of this project\'s code. You may also view the complete histories of any
file in the repository.
<UL>
<LI><A href="'.$uri.'"><B>Browse CVS Tree</B></A></LI>';

print $HTML->box1_bottom();

print '</TD></TR></TABLE>';

commits_footer(array());
?>
