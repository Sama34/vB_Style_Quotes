<?php

// KNOWN ISSUE: won't work on all setups (based on PHP version) with some AJAX features


// comment out this line if you want to save a (possible) query being run at the end of the page
//  This will disable the retrieval of post times and profile links.
define('VBQUOTE_USE_COMPLEX_QUOTES', 1);

if(!defined('IN_MYBB'))
	die('This file cannot be accessed directly.');

// Cache our template
if(THIS_SCRIPT == 'newreply.php' || THIS_SCRIPT == 'newthread.php' || THIS_SCRIPT == 'showthread.php' || THIS_SCRIPT == 'editpost.php' || THIS_SCRIPT == 'private.php')
{
	global $templatelist;

	if(isset($templatelist))
	{
		$templatelist .= ',';
	}
	else
	{
		$templatelist = '';
	}

	$templatelist .= 'vbquote';
}

if(!defined('IN_ADMINCP'))
{
	$plugins->add_hook('newreply_start', 'vbquote_newreply');
	$plugins->add_hook('xmlhttp', 'vbquote_xmlhttp');
	$plugins->add_hook('parse_message', 'vbquote_parse');
	$plugins->add_hook('parse_message_start', 'vbquote_parse_pre_fix');
	$plugins->add_hook('text_parse_message', 'vbquote_parse_text');
	$plugins->add_hook('parse_quoted_message', 'vbquote_make_quote');
}

function vbquote_info()
{
	return array(
		'name'			=> 'vB Style Quotes',
		'description'	=> 'Causes quotes to use the simpler vB style syntax, eg, [quote=USERNAME;PID].',
		'website'		=> 'http://mybbhacks.zingaburga.com/',
		'author'		=> 'ZiNgA BuRgA',
		'authorsite'	=> 'http://zingaburga.com/',
		'version'		=> '1.15',
		'compatibility'	=> '16*,17*,18*',
		'guid'			=> ''
	);
}

function vbquote_make_quote(&$msg) {
	global $vbquote_quote_text;
	$vbquote_quote_text .= '[quote=';
	if(strpos($msg['username'], '[') !== false || strpos($msg['username'], ']') !== false || strpos($msg['username'], ';') !== false) {
		$vbquote_quote_text .= '"'.$msg['username'].'"';
	} else {
		$vbquote_quote_text .= $msg['username'];
	}
	$vbquote_quote_text .= ';'.$msg['pid']."]\n$msg[message]\n".'[/quote]'."\n\n";
}

function vbquote_control_db() {
	function vbquote_fix_quotes() {
		$GLOBALS['message'] = $GLOBALS['vbquote_quote_text'];
		unset($GLOBALS['vbquote_quote_text']);
	}
	$GLOBALS['vbquote_quote_text'] = '';
	control_object($GLOBALS['db'], '
		function query($string, $hide_errors=0, $write_query=0) {
			if(!isset($this->vbquote_query) && !$write_query && preg_match("~^\s+SELECT p\\\\.subject, p\\\\.message, p\\\\.pid, p\\\\.tid, p\\\\.username, p\\\\.dateline, .+?FROM .*?posts p\s+LEFT JOIN .*?threads t ON \\\\(t\\\\.tid=p\\\\.tid\\\\)\s+LEFT JOIN .*?users u ON \\\\(u\\\\.uid=p\\\\.uid\\\\)\s+WHERE .*?p\\\\.pid IN .+$~s", $string)) {
				return ($this->vbquote_query = parent::query($string, $hide_errors, $write_query));
			}
			return parent::query($string, $hide_errors, $write_query);
		}
		function fetch_array($query, $resulttype=1) {
			if($this->vbquote_query === $query) {
				$ret = parent::fetch_array($query, $resulttype);
				if(!$ret) {
					vbquote_fix_quotes();
					$this->vbquote_query = 1;
				}
				return $ret;
			}
			return parent::fetch_array($query, $resulttype);
		}
	');
	$GLOBALS['db']->vbquote_query = null;
}

function vbquote_newreply() {
	global $mybb, $reply_errors;
	if(!$mybb->input['previewpost'] && !$reply_errors && $mybb->input['action'] != "editdraft" && !$mybb->input['attachmentaid'] && !$mybb->input['newattachment'] && !$mybb->input['updateattachment']) {
		vbquote_control_db();
	}
}

function vbquote_xmlhttp() {
	if(!defined('IN_XMLHTTP'))
		define('IN_XMLHTTP', 1);
	
	global $mybb;
	if($mybb->input['action'] != 'get_multiquoted') return;
	vbquote_control_db();
}

// work around MyBB's dodgy quote parser for [] characters
function vbquote_parse_pre_fix_parse($name) {
	return strtr($name, array('[' => '&#x5B;', ']' => '&#x5D;', "\r" => ''));
}
function &vbquote_parse_pre_fix(&$message) {
	$pattern = '~\[quote=(&quot;|["\'])(.*?)\\1(;[0-9]+\].*?\[/quote\])~sie';
	while($message != ($new_msg = preg_replace('~\[quote=(&quot;|["\'])(.*?)\\1(;[0-9]+\].*?\[/quote\])~sie', '\'[quote=$1\'.vbquote_parse_pre_fix_parse(\'$2\').\'$1$3\'', $message)))
		$message = $new_msg;
	return $message;
}
function &vbquote_parse(&$message, $text_only=false)
{
	// TODO: push regex into parser cache
	global $parser, $lang;
	if(is_object($parser))
	{
		if($GLOBALS['mybb']->version_code >= 1500 && $GLOBALS['mybb']->version_code <= 1600) {
			// it's a private variable on MyBB 1.6 :(
			// so we have to do an elaborate hack to get this to work... >_>
			$ptxt = serialize($parser);
			$p = strpos($ptxt, "s:19:\"\0postParser\0options\";a:");
			if($p) {
				$ptxt = substr($ptxt, $p + 27);
				$ptxt = substr($ptxt, 0, strpos($ptxt, '}') + 1);
				$opts = @unserialize($ptxt);
			}
		} else
			$opts =& $parser->options;
		// check if MyCode is being parsed
		if($opts['allow_mycode'] == 0)
			return $message;
	}
	
	$pattern = array('~\[quote=(&quot;|["\'])?(.*?)\\1;([0-9]+)\](.*?)\[/quote\]'."\n?".'~sie');
	if($text_only) // a little dodgey, but, well...
		$pattern[] = "#(\n)([^<\n]*?);([0-9]+) ".preg_quote($lang->wrote,'#')."(\n--\n)#se";
	else
		$pattern[] = '#(\<cite\>)([^<]*?);([0-9]+) '.preg_quote($lang->wrote,'#').'(\</cite\>)#se'; // no case insenstive flag cause I feel like it
	
	while($message != ($new_msg = preg_replace($pattern, array(
		'vbquote_parse_quote(\'$2\', \'$3\')',
		'\'$1\'.vbquote_parse_quote(str_replace(\'\\"\', \'"\', \'$2\'), \'$3\', '.($text_only?'true':'false').').\'$4\'',
	), $message)))
		$message = $new_msg;
	
	return $message;
}

function vbquote_parse_text(&$msg) { return vbquote_parse($msg, true); }

function vbquote_parse_quote($user, $pid, $text_only=false)
{
	global $lang, $templates, $mybb, $theme;
	
	// revert [] chars if fixed
	$user = strtr($user, array('&amp;#x5B;' => '[', '&amp;#x5D;' => ']'));
	
	// if we're processing complex quotes
	if(defined('VBQUOTE_USE_COMPLEX_QUOTES') && !$text_only && !defined('IN_ARCHIVE'))
	{
		global $vbquote_quotedpids, $plugins;
		$vbquote_quotedpids[$pid] = $user;
		
		if(defined('PREPARSER_ACTIVE'))
			$plugins->add_hook('preparser_dynamic_parse', 'vbquote_parse_complex');
		else
			// slightly lower priority hook to work around imei Page Optimizer
			$plugins->add_hook('pre_output_page', 'vbquote_parse_complex', 9);
		if(defined('IN_XMLHTTP') || $mybb->input['ajax']==1)
		{
			static $ajax_done;
			if(!$ajax_done)
			{
				ob_start();
				if($mybb->version_code >= 1700)
				{
					function vbquote_xmlhttp_preoutput()
					{
						run_shutdown();	// reconstruct objects if destroyed (urgh, won't fix $lang, $templates and $theme)
						$page = ob_get_clean();
						$data = @json_decode($page);
						if(!is_object($data))
						{ // something not right, bail
							echo $page;
							return;
						}
						if(isset($data->data)) // newreply
							$data->data = vbquote_parse_complex($data->data);
						elseif(isset($data->message)) // editpost
							$data->message = vbquote_parse_complex($data->message);
						echo json_encode($data);
					}
				}
				else
				{
					function vbquote_xmlhttp_preoutput()
					{
						run_shutdown();	// reconstruct objects if destroyed (urgh, won't fix $lang, $templates and $theme)
						$page = ob_get_clean();
						echo vbquote_parse_complex($page);
					}
				}
				register_shutdown_function('vbquote_xmlhttp_preoutput');
				$ajax_done = true;
			}
		}
		
		return '<!-- VBQUOTE_COMPLEX_QUOTE_'.$pid.' -->';
	}
	
	return vbquote_parse_quote_user($user, $pid, $text_only);
}

function vbquote_parse_quote_user($user, $pid, $text_only=false)
{
	global $lang, $templates, $mybb, $theme;
	if($text_only)
		return $user.' '.$lang->wrote;
	
	$url = $mybb->settings['bburl'].'/'.get_post_link($pid).'#pid'.$pid;
	if(defined('IN_ARCHIVE'))
		$linkback = ' <a href="'.$url.'">[ -> ]</a>';
	else
		eval('$linkback = " '.$templates->get('postbit_gotopost', 1, 0).'";');
	
	//return "<p>\n<blockquote><cite>".htmlspecialchars_uni($user)." $lang->wrote{$linkback}</cite>{$msg}</blockquote></p>\n";
	return $user.' '.$lang->wrote.$linkback;
}

function vbquote_parse_complex(&$page)
{
	global $vbquote_quotedpids;
	if(empty($vbquote_quotedpids)) return $page;
	static $done;
	if($done) return $page;
	$done = true;
	
	global $db, $lang, $mybb, $templates, $theme;
	$posts = array();
	$query = $db->simple_select('posts p LEFT JOIN '.TABLE_PREFIX.'users u ON (u.uid=p.uid)', 'p.*, u.usergroup, u.displaygroup, u.avatar, u.avatardimensions', 'p.pid IN ('.implode(',',array_keys($vbquote_quotedpids)).')');
	while($post = $db->fetch_array($query))
		$posts[$post['pid']] = $post;

	if(!isset($templates->cache['vbquote']))
	{
		//$templates->cache['vbquote'] = '<span style="float: right; font-weight: normal;"> ({$date})</span>{$username} {$lang->wrote}{$linkback}';
	}
	$replaces = array();
	foreach($vbquote_quotedpids as $pid => $uname)
	{
		$post = &$posts[$pid];
		// posts doesn't exist anymore...?
		if(!$post)
		{
			$r = vbquote_parse_quote_user(htmlspecialchars_uni($uname), $pid);
		}
		else
		{
			$url = $mybb->settings['bburl'].'/'.get_post_link($pid).'#pid'.$pid;
			eval('$linkback = " '.$templates->get('postbit_gotopost', 1, 0).'";');

			$username = htmlspecialchars_uni($post['username']);
			$formatedname = format_name($username, $post['usergroup'], $post['displaygroup']);
			$profilelink_plain = get_profile_link($post['uid']);
			$profilelink = build_profile_link($formatedname, $post['uid']);

			if($mybb->version_code >= 1700)
			{
				$date = my_date('relative', $post['dateline']);
				$avatar = format_avatar($post['avatar'], $post['avatardimensions']);
			}
			else
			{
				$date = my_date($mybb->settings['dateformat'], $post['dateline']).' '.my_date($mybb->settings['timeformat'], $post['dateline']);
				$avatar = array(
					'image'			=> $post['avatar'],
					'width_height'	=> '',
				);
			}

			eval('$r = "'.$templates->get('vbquote').'";');
		}
		$replaces['<!-- VBQUOTE_COMPLEX_QUOTE_'.$pid.' -->'] = $r;
	}
	
	// TODO: handle deleted posts
	
	return strtr($page, $replaces);
}

if(!function_exists('control_object')) {
	function control_object(&$obj, $code) {
		static $cnt = 0;
		$newname = '_objcont_'.(++$cnt);
		$objserial = serialize($obj);
		$classname = get_class($obj);
		$checkstr = 'O:'.strlen($classname).':"'.$classname.'":';
		$checkstr_len = strlen($checkstr);
		if(substr($objserial, 0, $checkstr_len) == $checkstr) {
			$vars = array();
			// grab resources/object etc, stripping scope info from keys
			foreach((array)$obj as $k => $v) {
				if($p = strrpos($k, "\0"))
					$k = substr($k, $p+1);
				$vars[$k] = $v;
			}
			if(!empty($vars))
				$code .= '
					function ___setvars(&$a) {
						foreach($a as $k => &$v)
							$this->$k = $v;
					}
				';
			eval('class '.$newname.' extends '.$classname.' {'.$code.'}');
			$obj = unserialize('O:'.strlen($newname).':"'.$newname.'":'.substr($objserial, $checkstr_len));
			if(!empty($vars))
				$obj->___setvars($vars);
		}
		// else not a valid object or PHP serialize has changed
	}
}