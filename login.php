<?php
////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2009  Phorum Development Team                              //
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
//                                                                            //
////////////////////////////////////////////////////////////////////////////////

define('phorum_page','login');

require_once './common.php';
require_once PHORUM_PATH.'/include/api/generate.php';

// ----------------------------------------------------------------------------
// Handle logout
// ----------------------------------------------------------------------------

if ($PHORUM['DATA']['LOGGEDIN'] && !empty($PHORUM["args"]["logout"]))
{
    /*
     * [hook]
     *     before_logout
     *
     * [description]
     *     This hook can be used for performing tasks before a user logout.
     *     The user data will still be availbale in 
     *     <literal>$PHORUM["user"]</literal> at this point.
     *
     * [category]
     *     Login/Logout
     *
     * [when]
     *     In <filename>login.php</filename>, just before destroying the user
     *     session.
     *
     * [input]
     *     None
     *
     * [output]
     *     None
     */

    if (isset($PHORUM["hooks"]["before_logout"])) {
        phorum_hook("before_logout");
    }

    phorum_api_user_session_destroy(PHORUM_FORUM_SESSION);

    // Determine the URL to redirect the user to. The hook "after_logout"
    // can be used by module writers to set a custom redirect URL.
    if (isset($_SERVER["HTTP_REFERER"]) && !empty($_SERVER['HTTP_REFERER'])) {
        $url = $_SERVER["HTTP_REFERER"];
    } else {
        $url = phorum_api_url(PHORUM_LIST_URL);
    }

    // Strip the session id from the URL in case URI auth is in use.
    if (stristr($url, PHORUM_SESSION_LONG_TERM)){
        $url = str_replace(PHORUM_SESSION_LONG_TERM."=".urlencode($PHORUM["args"][PHORUM_SESSION_LONG_TERM]), "", $url);
    }

    /*
     * [hook]
     *     after_logout
     *
     * [description]
     *     This hook can be used for performing tasks after a successful
     *     user logout and for changing the page to which the user will be
     *     redirected (by returning a different redirection URL). The user
     *     data will still be available in <literal>$PHORUM["user"]</literal>
     *     at this point.
     *
     * [category]
     *     Login/Logout
     *
     * [when]
     *     In <filename>login.php</filename>, after a logout, just before
     *     redirecting the user to a Phorum page.
     *
     * [input]
     *     The redirection URL.
     *
     * [output]
     *     Same as input.
     *
     * [example]
     *     <hookcode>
     *     function phorum_mod_foo_after_logout($url)
     *     {
     *         global $PHORUM;
     *
     *         // Return to the site's main page on logout
     *         $url = $PHORUM["mod_foo"]["site_url"];
     *
     *         return $url;
     *     }
     *     </hookcode>
     */
    if (isset($PHORUM["hooks"]["after_logout"]))
        $url = phorum_api_hook("after_logout", $url);

    phorum_api_redirect($url);
}

// ----------------------------------------------------------------------------
// Handle login and password reminder
// ----------------------------------------------------------------------------

// Set all our URLs.
phorum_build_common_urls();

$template = "login";
$error = "";
$okmsg = "";

// Handle posted form data.
if (count($_POST) > 0) {
    // The user wants to retrieve a new password.
    if (isset($_POST["lostpass"])) {

        // Trim the email address.
        $_POST["lostpass"] = trim($_POST["lostpass"]);

        // Did the user enter an email address?
        if (empty($_POST["lostpass"])) {
            $error = $PHORUM['DATA']['LANG']["LostPassError"];
        }

        // Is the email address available in the database?
        elseif ($uid = phorum_api_user_search("email", $_POST["lostpass"])) {

            // An existing user id was found for the entered email
            // address. Retrieve the user.
            $user = phorum_api_user_get($uid);

            $tmp_user=array();

            // User registration not yet approved by a moderator.
            if($user["active"] == PHORUM_USER_PENDING_MOD) {
                $template = "message";
                $okmsg = $PHORUM['DATA']['LANG']["RegVerifyMod"];
            // User registration still need email verification.
            } elseif ($user["active"] == PHORUM_USER_PENDING_EMAIL ||
                      $user["active"] == PHORUM_USER_PENDING_BOTH) {

                // Generate and store a new email confirmation code.
                $tmp_user["user_id"] = $uid;
                $tmp_user["password_temp"] = substr(md5(microtime()), 0, 8);
                phorum_api_user_save($tmp_user);

                // Mail the new confirmation code to the user.
                $verify_url = phorum_api_url(PHORUM_REGISTER_URL, "approve=".$tmp_user["password_temp"]."$uid");
                $maildata["mailsubject"] = $PHORUM['DATA']['LANG']["VerifyRegEmailSubject"];

                // The mailmessage can be composed in two different ways.
                // This was done for backward compatibility for the language
                // files. Up to Phorum 5.2, we had VerifyRegEmailBody1 and
                // VerifyRegEmailBody2 for defining the lost password mail body.
                // In 5.3, we switched to a single variable VerifyRegEmailBody.
                // Eventually, the variable replacements need to be handled
                // by the mail API layer.
                if (isset($PHORUM['DATA']['LANG']["VerifyRegEmailBody"]))
                {
                    $maildata['mailmessage'] = wordwrap(str_replace(
                        array(
                            '%title%',
                            '%username%',
                            '%verify_url%',
                            '%login_url%'
                        ),
                        array(
                            $PHORUM['title'],
                            $user['username'],
                            $verify_url,
                            phorum_api_url(PHORUM_LOGIN_URL)
                        ),
                        $PHORUM['DATA']['LANG']['VerifyRegEmailBody']
                    ), 72);
                }
                else
                {
                    // Hide the deprecated language strings from the
                    // amin language tool by not using the full syntax
                    // for those.
                    $lang = $PHORUM['DATA']['LANG'];

                    $maildata["mailmessage"] =
                       wordwrap($lang["VerifyRegEmailBody1"], 72).
                       "\n\n$verify_url\n\n".
                       wordwrap($lang["VerifyRegEmailBody2"], 72);
                }

                phorum_api_mail($user["email"], $maildata);

                $okmsg = $PHORUM['DATA']['LANG']["RegVerifyEmail"];
                $template="message";

            // The user is active.
            } else {

                // Generate and store a new password for the user.
                $newpass = phorum_api_generate_password();
                $tmp_user["user_id"] = $uid;
                $tmp_user["password_temp"] = $newpass;
                phorum_api_user_save($tmp_user);

                // Mail the new password.
                $user = phorum_api_user_get($uid);
                $maildata = array();

                // The mailmessage can be composed in two different ways.
                // This was done for backward compatibility for the language
                // files. Up to Phorum 5.2, we had LostPassEmailBody1 and
                // LostPassEmailBody2 for defining the lost password mail body.
                // In 5.3, we switched to a single variable LostPassEmailBody.
                // Eventually, the variable replacements need to be handled
                // by the mail API layer.
                if (isset($PHORUM['DATA']['LANG']["LostPassEmailBody"]))
                {
                    $maildata['mailmessage'] = wordwrap(str_replace(
                        array(
                            '%title%',
                            '%username%',
                            '%password%',
                            '%login_url%'
                        ),
                        array(
                            $PHORUM['title'],
                            $user['username'],
                            $newpass,
                            phorum_api_url(PHORUM_LOGIN_URL)
                        ),
                        $PHORUM['DATA']['LANG']["LostPassEmailBody"]
                    ), 72);
                }
                else
                {
                    // Hide the deprecated language strings from the
                    // amin language tool by not using the full syntax
                    // for those.
                    $lang = $PHORUM['DATA']['LANG'];

                    $maildata['mailmessage'] =
                       wordwrap($lang["LostPassEmailBody1"], 72) .
                       "\n\n".
                       $lang["Username"] .": $user[username]\n".
                       $lang["Password"] .": $newpass" .
                       "\n\n".
                       wordwrap($lang["LostPassEmailBody2"], 72);
                }

                $maildata['mailsubject'] = $PHORUM['DATA']['LANG']["LostPassEmailSubject"];
                phorum_api_mail($user['email'], $maildata);

                $okmsg = $PHORUM['DATA']['LANG']["LostPassSent"];

            }
        }

        // The entered email address was not found.
        else {
            $error = $PHORUM['DATA']['LANG']["LostPassError"];
        }
    }

    // The user wants to login.
    else {

        // Check if the phorum_tmp_cookie was set. If not, the user's
        // browser does not support cookies.
        if ($PHORUM["use_cookies"] == PHORUM_REQUIRE_COOKIES &&
            !isset($_COOKIE["phorum_tmp_cookie"])) {

            $error = $PHORUM['DATA']['LANG']["RequireCookies"];

        } else {

            // See if the temporary cookie was found. If yes, then the
            // browser does support cookies. If not, then we disable
            // the use of cookies.
            if (!isset($_COOKIE["phorum_tmp_cookie"])) {
                $PHORUM["use_cookies"] = PHORUM_NO_COOKIES;
            }

            // Check if the login credentials are right.
            $user_id = phorum_api_user_authenticate(
                PHORUM_FORUM_SESSION,
                trim($_POST["username"]),
                trim($_POST["password"])
            );

            // They are. Setup the active user and start a Phorum session.
            if ($user_id)
            {
                // Make the authenticated user the active Phorum user
                // and start a Phorum user session. Because this is a fresh
                // login, we can enable the short term session and we request
                // refreshing of the session id(s).
                if (phorum_api_user_set_active_user(PHORUM_FORUM_SESSION, $user_id, PHORUM_FLAG_SESSION_ST) && phorum_api_user_session_create(PHORUM_FORUM_SESSION, PHORUM_SESSID_RESET_LOGIN)) {

                    // Destroy the temporary cookie that is used for testing
                    // for cookie compatibility.
                    if (isset($_COOKIE["phorum_tmp_cookie"])) {
                        setcookie(
                            "phorum_tmp_cookie", "", 0,
                            $PHORUM["session_path"], $PHORUM["session_domain"]
                        );
                    }

                    // Determine the URL to redirect the user to.
                    // If redir is a number, it is a URL constant.
                    if(is_numeric($_POST["redir"])){
                        $redir = phorum_api_url((int)$_POST["redir"]);
                    }
                    // Redirecting to the registration or login page is a
                    // little weird, so we just go to the list page if we came
                    // from one of those.
                    elseif (isset($PHORUM['use_cookies']) && $PHORUM["use_cookies"] && !strstr($_POST["redir"], "register." . PHORUM_FILE_EXTENSION) && !strstr($_POST["redir"], "login." . PHORUM_FILE_EXTENSION)) {
                        $redir = $_POST["redir"];
                    // By default, we redirect to the list page.
                    } else {
                        $redir = phorum_api_url( PHORUM_LIST_URL );
                    }
                    
                    // checking for redirection url on the same domain, 
                    // localhost or domain defined through the settings
                    $redir_ok = false;
                    $check_urls = array();
                    if(!empty($PHORUM['login_redir_urls'])) {
                        
                        $check_urls = explode(",",$PHORUM['login_redir_urls']);
                    }
                    $check_urls[]="http://localhost";
                    $check_urls[]=$PHORUM['http_path'];
                                        
                    foreach($check_urls as $check_url) {
                         // the redir-url has to start with one of these URLs
                         if(stripos($redir,$check_url) === 0) {
                                $redir_ok = true;
                                break;
                         }
                    }
                    if(!$redir_ok) {
                        $redir = phorum_api_url(PHORUM_LIST_URL);
                    }   

                    /*
                     * [hook]
                     *     after_login
                     *
                     * [description]
                     *     This hook can be used for performing tasks after a
                     *     successful user login and for changing the page to
                     *     which the user will be redirected (by returning a
                     *     different redirection URL). If you need to access the
                     *     user data, then you can do this through the global 
                     *     <literal>$PHORUM</literal> variable. The user data
                     *     will be in <literal>$PHORUM["user"]</literal>.
                     *
                     * [category]
                     *     Login/Logout
                     *
                     * [when]
                     *     In <filename>login.php</filename>, after a successful
                     *     login, just before redirecting the user to a Phorum
                     *     page.
                     *
                     * [input]
                     *     The redirection URL.
                     *
                     * [output]
                     *     Same as input.
                     *
                     * [example]
                     *     <hookcode>
                     *     function phorum_mod_foo_after_login($url)
                     *     {
                     *         global $PHORUM;
                     *
                     *         // Redirect to the user's chosen page
                     *         $url = $PHORUM["user"]["phorum_mod_foo_user_login_url"];
                     *
                     *         return $url;
                     *     }
                     *     </hookcode>
                     */
                    if (isset($PHORUM["hooks"]["after_login"]))
                        $redir = phorum_api_modules_hook("after_login", $redir);

                    phorum_api_redirect($redir);
                }
            }

            // Login failed or session startup failed. For both we show
            // the invalid login error.
            $error = $PHORUM['DATA']['LANG']["InvalidLogin"];

            /*
             * [hook]
             *     failed_login
             *
             * [description]
             *     This hook can be used for tracking failing login attempts.
             *     This can be used for things like logging or implementing
             *     login failure penalties (like temporary denying access after
             *     X login attempts).
             *
             * [category]
             *     Login/Logout
             *
             * [when]
             *     In <filename>login.php</filename>, when a user login fails.
             *
             * [input]
             *     An array containing three fields (read-only): 
             *     <ul>
             *         <li>username</li>
             *         <li>password</li>
             *         <li>location
             *         <ul>
             *              <li>The location field specifies where the login 
             *              failure occurred and its value can be either 
             *              <literal>forum</literal> or 
             *              <literal>admin</literal>.</li>
             *         </ul></li>
             *     </ul>
             *
             * [output]
             *     None
             *
             * [example]
             *     <hookcode>
             *     function phorum_mod_foo_failed_login($data)
             *     {
             *         global $PHORUM;
             *
             *         // Get the current timestamp
             *         $curr_time = time();
             *
             *         // Check for a previous login failure from the current IP address
             *         if (!empty($PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]])) {
             *             // If the failures occur within the set time window,
             *             // increment the login failure count
             *             if ($curr_time <= ($PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["timestamp"] + (int)$PHORUM["mod_foo"]["login_failures_time_window"])) {
             *                 $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["login_failure_count"] ++;
             *                 $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["timestamp"] = $curr_time;
             *             // Otherwise, reset the count.
             *             } else {
             *                 $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["login_failure_count"] = 1;
             *                 $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["timestamp"] = $curr_time;
             *         } else {
             *             // Log the timestamp and IP address of a login failure
             *             $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["login_failure_count"] = 1;
             *             $PHORUM["mod_foo"]["login_failures"][$_SERVER["REMOTE_ADDR"]]["timestamp"] = $curr_time;
             *         }
             *         phorum_db_update_settings(array("mod_foo" => $PHORUM["mod_foo"]));
             *     }
             *     </hookcode>
             */
            // TODO API: move to user API.
            if (isset($PHORUM["hooks"]["failed_login"]))
                phorum_api_modules_hook("failed_login", array(
                    "username" => $_POST["username"],
                    "password" => $_POST["password"],
                    "location" => "forum"
                ));
        }
    }
}

// No data posted, so this is the first request. Here we set a temporary
// cookie, so we can check if the user's browser supports cookies.
elseif($PHORUM["use_cookies"] > PHORUM_NO_COOKIES) {
    setcookie( "phorum_tmp_cookie", "this will be destroyed once logged in", 0, $PHORUM["session_path"], $PHORUM["session_domain"] );
}

// Determine to what URL the user must be redirected after login.
if (!empty( $PHORUM["args"]["redir"])) {
    $redir = htmlspecialchars(urldecode($PHORUM["args"]["redir"]), ENT_COMPAT, $PHORUM["DATA"]["HCHARSET"]);
} elseif (!empty( $_REQUEST["redir"])) {
    $redir = htmlspecialchars($_REQUEST["redir"], ENT_COMPAT, $PHORUM["DATA"]["HCHARSET"]);
} elseif (!empty( $_SERVER["HTTP_REFERER"])) {
    $base = strtolower(phorum_api_url_base());
    $len = strlen($base);
    if (strtolower(substr($_SERVER["HTTP_REFERER"],0,$len)) == $base) {
        $redir = htmlspecialchars($_SERVER["HTTP_REFERER"], ENT_COMPAT, $PHORUM["DATA"]["HCHARSET"]);
    }
}
if (! isset($redir)) {
    $redir = phorum_api_url(PHORUM_LIST_URL);
}

// fill the breadcrumbs-info.
$PHORUM['DATA']['BREADCRUMBS'][]=array(
    'URL'  => '',
    'TEXT' => $PHORUM['DATA']['LANG']['LogIn'],
    'TYPE' => 'login'
);

// fill the page heading info.
$PHORUM['DATA']['HEADING'] = $PHORUM['DATA']['LANG']['LogIn'];
$PHORUM['DATA']['HTML_DESCRIPTION'] = '';
$PHORUM['DATA']['DESCRIPTION'] = '';

// Setup template data.
$PHORUM["DATA"]["LOGIN"]["redir"] = $redir;
$PHORUM["DATA"]["URL"]["REGISTER"] = phorum_api_url( PHORUM_REGISTER_URL );
$PHORUM["DATA"]["URL"]["ACTION"] = phorum_api_url( PHORUM_LOGIN_ACTION_URL );
$PHORUM["DATA"]["LOGIN"]["forum_id"] = ( int )$PHORUM["forum_id"];
$PHORUM["DATA"]["LOGIN"]["username"] = (!empty($_POST["username"])) ? htmlspecialchars( $_POST["username"], ENT_COMPAT, $PHORUM["DATA"]["HCHARSET"] ) : "";
$PHORUM["DATA"]["ERROR"] = $error;
$PHORUM["DATA"]["OKMSG"] = $okmsg;

$PHORUM["DATA"]["OPENID"] = $PHORUM["open_id"];
if($PHORUM["open_id"]){
    $PHORUM["DATA"]["URL"]["open_id"] = phorum_api_url(PHORUM_OPENID_URL);
}

$PHORUM["DATA"]['POST_VARS'].="<input type=\"hidden\" name=\"redir\" value=\"{$redir}\" />\n";

// Set the field to set the focus to after loading.
$PHORUM["DATA"]["FOCUS_TO_ID"] = empty($_POST["username"]) ? "username" : "password";

// Display the page.
phorum_api_output($template);

?>
