<?php

declare(strict_types=1);

namespace Adelia;

trait Rendering
{
    private function pageHeader(): string
    {
        $title = $this->cleanString($this->config->title);
        $stylesheets = $this->pageStylesheets();

        return <<<EOF
            <!DOCTYPE html>
            <html lang="en">
            	<head>
            		<meta http-equiv="content-type" content="text/html;charset=UTF-8">
            		<meta http-equiv="cache-control" content="max-age=0">
            		<meta http-equiv="cache-control" content="no-cache">
            		<meta http-equiv="expires" content="0">
            		<meta http-equiv="expires" content="Tue, 01 Jan 1980 1:00:00 GMT">
            		<meta http-equiv="pragma" content="no-cache">
            		<meta name="viewport" content="width=device-width,initial-scale=1">
            		<title>$title</title>
            		<link rel="shortcut icon" href="favicon.ico">
            		$stylesheets
            		<script type="module" src="js/adelia.js?v=adelia-1"></script>
            <script src="js/security.js" defer></script>
            		
            	</head>
            EOF;
    }

    /** @return array<string, string> */
    private function pageStyleOptions(): array
    {
        $styles = ['style' => 'Yotsuba B (original)'];
        foreach (glob(dirname(__DIR__) . '/stylesheets/*.css') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name !== 'style' && preg_match('/^[a-zA-Z0-9_+\-]+$/D', $name)) {
                $styles[$name] = ucwords(str_replace(['-', '_', '+'], [' ', ' ', ' + '], $name));
            }
        }
        return $styles;
    }

    private function pageDefaultStyle(): string
    {
        return array_key_exists($this->config->defaultstyle, $this->pageStyleOptions()) ? $this->config->defaultstyle : 'style';
    }

    private function pageStylesheets(): string
    {
        $style = rawurlencode($this->pageDefaultStyle());
        return '<link rel="stylesheet" href="stylesheets/style.css">'
            . '<link rel="stylesheet" href="stylesheets/' . $style . '.css" id="mainStylesheet">'
            . '<link rel="stylesheet" href="stylesheets/shared/adelia.css?v=adelia-2">';
    }

    private function pageStyleSelector(): string
    {
        $selected = $this->pageDefaultStyle();
        $html = '<label class="theme-picker" for="switchStylesheet">Style <select id="switchStylesheet">';
        foreach ($this->pageStyleOptions() as $name => $title) {
            $html .= '<option value="' . $this->cleanString($name) . '"' . ($name === $selected ? ' selected' : '') . '>' . $this->cleanString($title) . '</option>';
        }
        return $html . '</select></label>';
    }

    private function pageFooter(): string
    {

        return <<<EOF
            		<div class="footer">
                <p>Adelia</p>
                <p>Styles: <a href="https://github.com/vichan-devel/vichan">vichan</a> Copyright &copy; 2012-2025 vichan-devel<br>Tinyboard Copyright &copy; 2010-2014 Tinyboard Development Group</p>
            		</div>
            	</body>
            </html>
            EOF;
    }

    private function supportedFileTypes(): string
    {

        if (empty($this->config->uploads)) {
            return '';
        }

        $types_allowed = array_map('strtoupper', array_unique(array_column($this->config->uploads, 0)));
        if (count($types_allowed) == 1) {
            return sprintf('Supported file type is %s', $types_allowed[0]);
        }
        $last_type = array_pop($types_allowed);
        return sprintf('Supported file types are %1$s and %2$s.', implode(', ', $types_allowed), $last_type);
    }

    private function linkCallback(array $matches): string
    {
        if (!isset($matches[1])) {
            return '';
        }
        $url = $this->cleanQuotes($matches[1]);
        $text = $matches[1];
        return '<a href="' . $url . '" target="_blank">' . $text . '</a>';
    }

    private function makeLinksClickable(string $text): string
    {
        $text = preg_replace_callback('!(((f|ht)tp(s)?://)[-a-zA-Zа-яА-Я()0-9@%\!_+.,~#?&;:|\'/=]+)!i', $this->linkCallback(...), $text);
        $text = preg_replace('/\(\<a href\=\"(.*)\)"\ target\=\"\_blank\">(.*)\)\<\/a>/i', '(<a href="$1" target="_blank">$2</a>)', $text);
        $text = preg_replace('/\<a href\=\"(.*)\."\ target\=\"\_blank\">(.*)\.\<\/a>/i', '<a href="$1" target="_blank">$2</a>.', $text);
        $text = preg_replace('/\<a href\=\"(.*)\,"\ target\=\"\_blank\">(.*)\,\<\/a>/i', '<a href="$1" target="_blank">$2</a>,', $text);

        return $text;
    }

    private function buildPostForm(int $parent, bool $staff_post = false): string
    {

        $hide_fields = $parent == 0 ? $this->config->hidefieldsop : $this->config->hidefields;

        $postform_extra = ['name' => '', 'email' => '', 'subject' => '', 'footer' => ''];
        $input_submit = '<input type="submit" value="' . 'Submit' . '" accesskey="z">';
        if ($staff_post || !in_array('subject', $hide_fields)) {
            $postform_extra['subject'] = $input_submit;
        } elseif (!in_array('email', $hide_fields)) {
            $postform_extra['email'] = $input_submit;
        } elseif (!in_array('name', $hide_fields)) {
            $postform_extra['name'] = $input_submit;
        } elseif (!in_array('email', $hide_fields)) {
            $postform_extra['email'] = $input_submit;
        } else {
            $postform_extra['footer'] = $input_submit;
        }

        $form_action = 'imgboard.php';
        $form_extra = '<input type="hidden" name="parent" value="' . $parent . '">';
        $input_extra = '';
        $rules_extra = '';

        $maxlen_name = -1;
        $maxlen_email = -1;
        $maxlen_subject = -1;
        $maxlen_message = -1;
        if ($this->config->maxname > 0) {
            $maxlen_name = $this->config->maxname;
        }
        if ($this->config->maxemail > 0) {
            $maxlen_email = $this->config->maxemail;
        }
        if ($this->config->maxsubject > 0) {
            $maxlen_subject = $this->config->maxsubject;
        }
        if ($this->config->maxmessage > 0) {
            $maxlen_message = $this->config->maxmessage;
        }
        if ($staff_post) {

            $txt_reply_to = 'Reply to';
            $txt_new_thread = '0 to start a new thread';

            $form_action = '?';
            $form_extra = '<input type="hidden" name="staffpost" value="1">';
            $input_extra = <<<EOF
                					
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_reply_to
                						</th>
                						<td>
                							<input type="text" name="parent" size="28" maxlength="75" value="0" accesskey="t">&nbsp;$txt_new_thread
                						</td>
                					</tr>
                EOF;

            $maxlen_name = -1;
            $maxlen_email = -1;
            $maxlen_subject = -1;
            $maxlen_message = -1;
        }

        $max_file_size_input_html = '';
        $max_file_size_rules_html = '';
        $reqmod_html = '';
        $filetypes_html = '';
        $file_input_html = '';
        $embed_input_html = '';
        $unique_posts_html = '';

        $captcha_setting = $parent == 0 ? $this->config->captcha : $this->config->replycaptcha;

        $captcha_html = '';
        if ($captcha_setting && !$staff_post) {
            { // Simple CAPTCHA
                $captcha_inner_html = '
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . '(enter the text below)' . '<br>
<img id="captchaimage" src="inc/captcha.php" width="175" height="55" alt="CAPTCHA" data-action="captcha-refresh" role="button" tabindex="0" aria-label="Get a new CAPTCHA" style="margin-top: 5px;cursor: pointer;">';
            }

            $txt_captcha = 'CAPTCHA';
            $captcha_html = <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_captcha
                						</th>
                						<td>
                							$captcha_inner_html
                						</td>
                					</tr>
                EOF;
        }

        if (!empty($this->config->uploads) && ($staff_post || !in_array('file', $hide_fields))) {
            if ($this->config->maxkb > 0) {
                $max_file_size_input_html = '<input type="hidden" name="MAX_FILE_SIZE" value="' . (string) ($this->config->maxkb * 1024) . '">';
                $max_file_size_rules_html = '<li>' . sprintf('Maximum file size allowed is %s.', $this->config->maxkbdesc) . '</li>';
            }

            $filetypes_html = '<li>' . $this->supportedFileTypes() . '</li>';

            $txt_file = 'File';
            $spoiler_html = '';
            if ($this->config->spoilerimage) {
                $spoiler_html = '<label><input type="checkbox" name="spoiler" value="1"> Spoiler</label>';
            }
            $file_input_html = <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_file
                						</th>
                						<td>
                							<input type="file" name="file" size="35" accesskey="f">
                							$spoiler_html
                						</td>
                					</tr>
                EOF;
        }

        $embeds_enabled = (!empty($this->config->embeds) || $this->config->uploadviaurl) && ($staff_post || !in_array('embed', $hide_fields));
        if ($embeds_enabled) {
            $txt_embed = 'Embed';
            $txt_embed_help = '';
            $txt_embed_help = '(paste a YouTube URL)';
            $embed_input_html = <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_embed
                						</th>
                						<td>
                							<input type="text" name="embed" size="28" accesskey="x" autocomplete="off">&nbsp;&nbsp;$txt_embed_help
                						</td>
                					</tr>
                EOF;
        }

        if ($this->config->reqmod == 'all') {
            $reqmod_html = '<li>' . 'All posts are moderated before being shown.' . '</li>';
        } elseif ($this->config->reqmod == 'files') {
            $reqmod_html = '<li>' . 'All posts with a file attached are moderated before being shown.' . '</li>';
        }

        $thumbnails_html = '';
        if (isset($this->config->uploads['image/jpeg']) || isset($this->config->uploads['image/pjpeg']) || isset($this->config->uploads['image/png']) || isset($this->config->uploads['image/gif'])) {
            $maxdimensions = $this->config->maxwop . 'x' . $this->config->maxhop;
            if ($this->config->maxw != $this->config->maxwop || $this->config->maxh != $this->config->maxhop) {
                $maxdimensions .= ' (new thread) or ' . $this->config->maxw . 'x' . $this->config->maxh . ' (reply)';
            }

            $thumbnails_html = '<li>' . sprintf('Images greater than %s will be thumbnailed.', $maxdimensions) . '</li>';
        }

        $output = <<<EOF
            		<div class="postarea post-form">
            			<form name="postform" id="postform" action="$form_action" method="post" enctype="multipart/form-data">
            			$max_file_size_input_html
            			$form_extra
            			<table class="postform">
            				<tbody>
            					$input_extra
            EOF;
        if ($staff_post || !in_array('name', $hide_fields)) {
            $txt_name = 'Name';
            $output .= <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_name
                						</th>
                						<td>
                							<input type="text" name="name" size="28" maxlength="{$maxlen_name}" accesskey="n" autocomplete="off" spellcheck="false" aria-describedby="tripcode-help"><small id="tripcode-help"> Name##secret adds a secure tripcode.</small>
                							{$postform_extra['name']}
                						</td>
                					</tr>
                EOF;
        }
        if ($staff_post || !in_array('email', $hide_fields)) {
            $txt_email = 'E-mail';
            $output .= <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_email
                						</th>
                						<td>
                							<input type="text" name="email" size="28" maxlength="{$maxlen_email}" accesskey="e">
                							{$postform_extra['email']}
                						</td>
                					</tr>
                EOF;
        }
        if ($staff_post || !in_array('subject', $hide_fields)) {
            $txt_subject = 'Subject';
            $output .= <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_subject
                						</th>
                						<td>
                							<input type="text" name="subject" size="40" maxlength="{$maxlen_subject}" accesskey="s" autocomplete="off">
                							{$postform_extra['subject']}
                						</td>
                					</tr>
                EOF;
        }
        if ($staff_post || !in_array('message', $hide_fields)) {
            $txt_message = 'Message';
            $output .= <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_message
                						</th>
                						<td>
                							<textarea id="message" name="message" cols="48" rows="4" maxlength="{$maxlen_message}" accesskey="m"></textarea>
                						</td>
                					</tr>
                EOF;
        }

        $output .= <<<EOF
            					$captcha_html
            					$file_input_html
            					$embed_input_html
            EOF;
        if ($staff_post || !in_array('password', $hide_fields)) {
            $txt_password = 'Password';
            $txt_password_help = '(for post and file deletion)';
            $output .= <<<EOF
                					<tr>
                						<th class="postblock" scope="row">
                							$txt_password
                						</th>
                						<td>
                							<input type="password" name="password" id="newpostpassword" size="8" accesskey="p">&nbsp;&nbsp;$txt_password_help
                						</td>
                					</tr>
                EOF;
        }
        if ($postform_extra['footer'] != '') {
            $output .= <<<EOF
                					<tr>
                						<td>
                							&nbsp;
                						</td>
                						<td>
                							{$postform_extra['footer']}
                						</td>
                					</tr>
                EOF;
        }
        $output .= <<<EOF
            					<tr>
            						<td colspan="2" class="rules">
            							$rules_extra
            							<ul>
            								$reqmod_html
            								$filetypes_html
            								$max_file_size_rules_html
            								$thumbnails_html
            								$unique_posts_html
            							</ul>
            						</td>
            					</tr>
            				</tbody>
            			</table>
            			</form>
            		</div>
            EOF;

        return $output;
    }

    private function backlinks(array $post): string
    {
        if (!$this->config->backlinks) {
            return '';
        }

        $posts = $this->postsInThreadByID($this->getParent($post));
        $needle = '&gt;&gt;' . $post['id'];
        $return = '';
        foreach ($posts as $reply) {
            if (strpos($reply['message'], $needle) !== false) {
                if ($return != '') {
                    $return .= ', ';
                }
                $return .= $this->postLink('&gt;&gt;' . $reply['id']);
            }
        }
        if ($return != '') {
            $return = '&nbsp;' . $return;
        }
        return ' <small><span id="backlinks' . $post['id'] . '" class="backlinks">' . $return . '</span></small>';
    }

    private function buildPost(array $post, bool $res, bool $compact = false): string
    {
        $return = '';
        $threadid = ($post['parent'] == 0) ? $post['id'] : $post['parent'];

        if ($this->config->report) {
            $reflink = '<a href="imgboard.php?report=' . $post['id'] . '" title="' . 'Report' . '">R</a> ';
        } else {
            $reflink = '';
        }

        if ($res == true) {
            $reflink .= "<a href=\"$threadid.html#{$post['id']}\">No.</a><a href=\"$threadid.html#q{$post['id']}\" data-quote=\"{$post['id']}\">{$post['id']}</a>";
        } else {
            $reflink .= "<a href=\"res/$threadid.html#{$post['id']}\">No.</a><a href=\"res/$threadid.html#q{$post['id']}\">{$post['id']}</a>";
        }

        if ($post['stickied'] == 1) {
            $reflink .= ' <img src="sticky.png" alt="' . 'Stickied' . '" title="' . 'Stickied' . '" width="16" height="16">';
        }

        if ($post['locked'] == 1) {
            $reflink .= ' <img src="lock.png" alt="' . 'Locked' . '" title="' . 'Locked' . '" width="16" height="16">';
        }

        if (!isset($post['omitted'])) {
            $post['omitted'] = 0;
        }

        $filehtml = '';
        $filesize = '';
        $expandhtml = '';
        $direct_link = $this->isEmbed($post['file_hex']) ? '#' : (($res == true ? '../' : '') . 'src/' . $post['file']);

        if ($post['parent'] == 0 && $post['file'] != '') {
            $filesize .= ($this->isEmbed($post['file_hex']) ? 'Embed' : 'File') . ': ';
        }

        $w = $this->config->expandwidth;
        if ($this->isEmbed($post['file_hex'])) {
            $expandhtml = $post['file'];
        } elseif (substr($post['file'], -5) == '.webm' || substr($post['file'], -4) == '.mp4') {
            $dimensions = 'width="500" height="50"';
            if ($post['image_width'] > 0 && $post['image_height'] > 0) {
                $dimensions = 'width="' . $post['image_width'] . '" height="' . $post['image_height'] . '"';
            }
            $expandhtml = <<<EOF
                <video $dimensions style="position: static; pointer-events: inherit; display: inline; max-width: {$w}vw; height: auto; max-height: 100%;" controls autoplay loop>
                	<source src="$direct_link"></source>
                </video>
                EOF;
        } elseif (in_array(substr($post['file'], -4), ['.jpg', '.png', '.gif'])) {
            $expandhtml = "<a href=\"$direct_link\" data-expand=\"{$post['id']}\"><img src=\"" . ($res == true ? '../' : '') . "src/{$post['file']}\" width=\"{$post['image_width']}\" style=\"min-width: {$post['thumb_width']}px;min-height: {$post['thumb_height']}px;max-width: {$w}vw;height: auto;\"></a>";
        }

        $thumblink = "<a href=\"$direct_link\" target=\"_blank\"" . (($this->isEmbed($post['file_hex']) || in_array(substr($post['file'], -4), ['.jpg', '.png', '.gif', 'webm', '.mp4'])) ? " data-expand=\"{$post['id']}\"" : '') . '>';
        $expandhtml = rawurlencode($expandhtml);

        if ($this->isEmbed($post['file_hex'])) {
            $filesize .= "<a href=\"$direct_link\" data-expand=\"{$post['id']}\">{$post['file_original']}</a>&ndash;({$post['file_hex']})";
        } elseif ($post['file'] != '') {
            $filesize .= $thumblink . "{$post['file']}</a>&ndash;({$post['file_size_formatted']}";
            if ($post['image_width'] > 0 && $post['image_height'] > 0) {
                $filesize .= ', ' . $post['image_width'] . 'x' . $post['image_height'];
            }
            if ($post['file_original'] != '') {
                $filesize .= ', ' . $post['file_original'];
            }
            $filesize .= ')';
        }

        if ($filesize != '') {
            $filesize = '<p class="fileinfo filesize">' . $filesize . '</p>';
        }

        if ($filesize != '') {
            $filehtml .= '<div class="file">' . $filesize . '<div id="thumbfile' . $post['id'] . '">';
            if ($post['thumb_width'] > 0 && $post['thumb_height'] > 0) {
                $filehtml .= <<<EOF
                    $thumblink
                    	<img src="thumb/{$post['thumb']}" alt="{$post['id']}" class="post-image thumb" id="thumbnail{$post['id']}" width="{$post['thumb_width']}" height="{$post['thumb_height']}">
                    </a>
                    EOF;
            }
            $filehtml .= '</div>';

            if ($expandhtml != '') {
                $filehtml .= <<<EOF
                    <div id="expand{$post['id']}" style="display: none;">$expandhtml</div>
                    <div id="file{$post['id']}" class="file-expanded" style="display: none;"></div>
                    EOF;
            }
        }
        if ($filehtml !== '') {
            $filehtml .= '</div>';
        }
        // Aliases also style existing posts, whose formatted content is stored in the database.
        $post['nameblock'] = str_replace(['class="postername"', 'class="postertrip"'], ['class="name postername"', 'class="trip postertrip"'], $post['nameblock']);
        $post['message'] = str_replace('class="unkfunc"', 'class="quote unkfunc"', $post['message']);
        $postClass = $post['parent'] === 0 ? 'op' : 'reply';
        $return .= '<div id="post' . $post['id'] . '" class="post ' . $postClass . '">';

        $return .= <<<EOF
            <a id="{$post['id']}"></a>
            <p class="intro"><label>
            	<input class="delete" type="checkbox" name="delete[]" value="{$post['id']}"> 
            EOF;

        if ($post['subject'] != '') {
            $return .= ' <span class="subject filetitle">' . $post['subject'] . '</span> ';
        }

        $return .= <<<EOF
            {$post['nameblock']}
            </label>
            <span class="reflink">
            	$reflink
            </span>
            EOF;

        if ($post['parent'] != 0) {
            $return .= $this->backlinks($post);
        }

        if ($post['parent'] == 0) {
            if ($res == false) {
                $return .= "&nbsp;[<a href=\"res/{$post['id']}.html\">" . 'Reply' . '</a>]';
            }
            $return .= $this->backlinks($post);
        }

        $return .= '</p>' . $filehtml;

        if ($this->config->truncate > 0 && !$res && $this->countOccurrences($post['message'], '<br>') > $this->config->truncate) { // Truncate messages on board index pages for readability
            $br_offsets = $this->strallpos($post['message'], '<br>');
            $post['message'] = $this->substring($post['message'], 0, $br_offsets[$this->config->truncate - 1]);
            $post['message'] .= '<br><span class="omittedposts">' . 'Post truncated. Click Reply to view.' . '</span><br>';
        }
        $return .= <<<EOF
            <div class="body message">
            {$post['message']}
            </div>
            EOF;

        if ($post['parent'] == 0) {
            $return .= '</div>';
            if ($res == false && $post['omitted'] > 0) {
                if ($post['omitted'] == 1) {
                    $return .= '<span class="omittedposts">' . '1 post omitted. Click Reply to view.' . '</span>';
                } else {
                    $return .= '<span class="omittedposts">' . sprintf('%d posts omitted. Click Reply to view.', $post['omitted']) . '</span>';
                }
            }
        } else {
            $return .= '</div><br class="clear">';
        }

        return $return;
    }

    private function buildPage(string $htmlposts, int $parent, int $pages = 0, int $thispage = 0, int $lastpostid = 0): string
    {

        $cataloglink = $this->config->catalog ? ('[<a href="catalog.html" style="text-decoration: underline;">' . 'Catalog' . '</a>]') : '';
        $managelink = ($this->config->managekey == '') ? ('[<a href="' . basename($this->request->server['PHP_SELF']) . '?manage" style="text-decoration: underline;">' . 'Manage' . '</a>]') : '';

        $postingmode = '';
        $pagenavigator = '';
        if ($parent == 0) {
            $pages = max($pages, 0);
            $previous = ($thispage == 1) ? 'index' : $thispage - 1;
            $next = $thispage + 1;

            $pagelinks = ($thispage == 0) ? ('<td>' . 'Previous' . '</td>') : ('<td><form method="get" action="' . $previous . '.html"><input value="' . 'Previous' . '" type="submit"></form></td>');

            $pagelinks .= '<td>';
            for ($i = 0; $i <= $pages; $i++) {
                if ($thispage == $i) {
                    $pagelinks .= '&#91;' . $i . '&#93; ';
                } else {
                    $href = ($i == 0) ? 'index' : $i;
                    $pagelinks .= '&#91;<a href="' . $href . '.html">' . $i . '</a>&#93; ';
                }
            }
            $pagelinks .= '</td>';

            $pagelinks .= ($pages <= $thispage) ? ('<td>' . 'Next' . '</td>') : ('<td><form method="get" action="' . $next . '.html"><input value="' . 'Next' . '" type="submit"></form></td>');

            $pagenavigator = <<<EOF
                <table border="1" style="display: inline-block;">
                	<tbody>
                		<tr>
                			$pagelinks
                		</tr>
                	</tbody>
                </table>
                EOF;
            if ($this->config->catalog) {
                $txt_catalog = 'Catalog';
                $pagenavigator .= <<<EOF
                    <table border="1" style="display: inline-block;margin-left: 21px;">
                    	<tbody>
                    		<tr>
                    			<td><form method="get" action="catalog.html"><input value="$txt_catalog" type="submit"></form></td>
                    		</tr>
                    	</tbody>
                    </table>
                    EOF;
            }
        } elseif ($parent == -1) {
            $postingmode = '&#91;<a href="index.html">' . 'Return' . '</a>&#93;<div class="banner replymode">' . 'Catalog' . '</div> ';
        } else {
            $postingmode = '&#91;<a href="../">' . 'Return' . '</a>&#93;<div class="banner replymode">' . 'Posting mode: Reply' . '</div> ';
        }

        $postform = '';
        if ($parent >= 0) { // Negative values indicate the post form should be hidden
            $postform = $this->buildPostForm($parent) . '<hr>';
        }

        $threadId = max(0, $parent);
        $refreshDelay = $parent > 0 ? max(0, $this->config->autorefresh) : 0;
        $backlinksEnabled = $this->config->backlinks ? '1' : '0';

        $txt_password = 'Password';
        $txt_delete = 'Delete';
        $txt_delete_post = 'Delete Post';

        $select_style = $this->pageStyleSelector();
        $bodyClass = $parent === -1 ? 'adelia theme-catalog' : 'adelia';
        $postsClass = match ($parent) {
            -1 => 'threads', 0 => 'board-posts', default => 'thread'
        };

        $body = <<<EOF
            	<body class="$bodyClass">
            		<div class="boardlist adminbar">
            			$cataloglink
            			$managelink
            			$select_style
            		</div>
            		<header><h1 class="logo">
            EOF;
        $body .= $this->config->logo . $this->config->boarddesc . <<<EOF
            		</h1></header>
            		<hr width="90%">
            		$postingmode
            		$postform
            		<form id="delform" action="imgboard.php?delete" method="post">
            		<input type="hidden" name="board" 
            EOF;
        $body .= 'value="' . $this->config->board . '">' . <<<EOF
            		<div id="posts" class="$postsClass" data-thread="$threadId" data-since="$lastpostid" data-refresh="$refreshDelay" data-backlinks="$backlinksEnabled">
            		$htmlposts
            		</div>
            		<hr>
            		<table class="userdelete">
            			<tbody>
            				<tr>
            					<td>
            						$txt_delete_post <input type="password" name="password" id="deletepostpassword" size="8" placeholder="$txt_password">&nbsp;<input name="deletepost" value="$txt_delete" type="submit">
            					</td>
            				</tr>
            			</tbody>
            		</table>
            		</form>
            		<div class="pages">$pagenavigator</div>
            		<br>
            EOF;
        return $this->pageHeader() . $body . $this->pageFooter();
    }

    private function buildCatalogPost(array $post): string
    {
        $thumb = '#' . $post['id'];
        if ($post['thumb'] != '') {
            $thumb = <<<EOF
                		<img src="thumb/{$post['thumb']}" alt="{$post['id']}" width="{$post['thumb_width']}" height="{$post['thumb_height']}" class="thread-image" loading="lazy">
                EOF;
        }
        $replies = $this->numRepliesToThreadByID($post['id']);
        $subject = trim($post['subject']) != '' ? $post['subject'] : $this->substring(trim(str_ireplace("\n", '', strip_tags($post['message']))), 0, 75);

        return <<<EOF
            <div class="thread catalogpost">
            	<a href="res/{$post['id']}.html">
            		$thumb
            	</a>
            	<p class="replies">Replies: <b>$replies</b></p>
            	<div class="catalog-subject">$subject</div>
            </div>
            EOF;
    }

    private function rebuildCatalog(): void
    {
        if ($this->requestTransaction) {
            $this->database->schedule('rebuild');
            return;
        }
        $threads = $this->allThreads();
        $htmlposts = '';
        foreach ($threads as $post) {
            $htmlposts .= $this->buildCatalogPost($post);
        }

        $this->writePage('catalog.html', $this->buildPage($htmlposts, -1));
    }

    private function rebuildIndexes(): void
    {
        if ($this->requestTransaction) {
            $this->database->schedule('rebuild');
            return;
        }
        $page = 0;
        $i = 0;
        $htmlposts = '';
        $threads = $this->allThreads();
        $pages = max(0, intdiv(count($threads) - 1, $this->config->threadsperpage));

        foreach ($threads as $thread) {
            $replies = $this->postsInThreadByID($thread['id']);
            $thread['omitted'] = max(0, count($replies) - $this->config->previewreplies - 1);

            // Build replies for preview
            $htmlreplies = [];
            for ($j = count($replies) - 1; $j > $thread['omitted']; $j--) {
                $htmlreplies[] = $this->buildPost($replies[$j], false);
            }

            if ($i > 0) {
                $htmlposts .= "\n<hr>";
            }
            $htmlposts .= '<div class="thread" id="thread' . $thread['id'] . '">' . $this->buildPost($thread, false) . implode('', array_reverse($htmlreplies)) . '</div>';

            if (++$i >= $this->config->threadsperpage) {
                $file = ($page == 0) ? $this->config->index : ($page . '.html');
                $this->writePage($file, $this->buildPage($htmlposts, 0, $pages, $page));

                $page++;
                $i = 0;
                $htmlposts = '';
            }
        }

        if ($page == 0 || $htmlposts != '') {
            $file = ($page == 0) ? $this->config->index : ($page . '.html');
            $this->writePage($file, $this->buildPage($htmlposts, 0, $pages, $page));
        }

        foreach (glob('[0-9]*.html') ?: [] as $filename) {
            if (preg_match('/^([0-9]+)\.html$/D', $filename, $matches) && (int) $matches[1] > $pages) {
                $this->removePage($filename);
            }
        }

        if ($this->config->catalog) {
            $this->rebuildCatalog();
        } else {
            $this->removePage('catalog.html');
        }

        if ($this->config->json) {
            $this->writePage('threads.json', $this->buildIndexJson());
            $this->writePage('catalog.json', $this->buildCatalogJson());
        } else {
            $this->removePage('threads.json');
            $this->removePage('catalog.json');
        }
    }

    private function rebuildThread(int $id): void
    {
        if ($this->requestTransaction) {
            $this->database->schedule('rebuild');
            return;
        }
        $id = (int) $id;

        $post = $this->postByID($id);
        if (empty($post) || $post['moderated'] == 0) {
            if (is_file('res/' . $id . '.html')) {
                $this->removePage('res/' . $id . '.html');
            }
            if (is_file('res/' . $id . '.json')) {
                $this->removePage('res/' . $id . '.json');
            }
            return;
        }

        $posts = $this->postsInThreadByID($id);
        if (count($posts) == 0) {
            if (is_file('res/' . $id . '.html')) {
                $this->removePage('res/' . $id . '.html');
            }
            if (is_file('res/' . $id . '.json')) {
                $this->removePage('res/' . $id . '.json');
            }
            return;
        }

        $htmlposts = '';
        $lastpostid = 0;
        foreach ($posts as $post) {
            $htmlposts .= $this->buildPost($post, true);
            $lastpostid = $post['id'];
        }

        $this->writePage('res/' . $id . '.html', $this->fixLinksInRes($this->buildPage($htmlposts, $id, 0, 0, $lastpostid)));

        if ($this->config->json) {
            $this->writePage('res/' . $id . '.json', $this->buildSingleThreadJson($id));
        } else {
            $this->removePage('res/' . $id . '.json');
        }
    }

    private function adminBar(): string
    {

        $return = '[<a href="' . $this->returnlink . '" style="text-decoration: underline;">' . 'Return' . '</a>]';
        if (!$this->loggedin) {
            return $return;
        }

        if ($this->mustChangePassword()) {
            return '[<a href="?manage&changepassword">Change Password</a>] [<a href="?manage&logout">Log Out</a>]';
        }
        $output = '';
        if ($this->isadmin) {
            if ($this->account['role'] == Role::SuperAdministrator->value) {
                $output .= ' [<a href="?manage&accounts">' . 'Accounts' . '</a>]';
            }
            $output .= ' [<a href="?manage&keywords">' . 'Keywords' . '</a>]';

        }
        $output .= ' [<a href="?manage&moderate">' . 'Moderate Post' . '</a>]';
        if ($this->isadmin) {
            $output .= ' [<a href="?manage&modlog">' . 'Moderation Log' . '</a>]';
            $output .= ' [<a href="?manage&rebuildall">' . 'Rebuild All' . '</a>]';
            if ($this->config->report) {
                $output .= ' [<a href="?manage&reports">' . 'Reports' . '</a>]';
            }
        }
        $output .= ' [<a href="?manage&staffpost">' . 'Staff Post' . '</a>]';
        $output .= ' [<a href="?manage">' . 'Status' . '</a>]';

        $output .= ' &middot;  [<a href="?manage&changepassword">' . 'Change Password' . '</a>]';
        $output .= ' [<a href="?manage&logout">' . 'Log Out' . '</a>]';
        $output .= ' &middot; ' . $return;
        return $output;
    }

    private function managePage(string $text, string $onload = ''): string
    {
        $adminbar = $this->adminBar() . ' ' . $this->pageStyleSelector();
        $txt_manage_mode = 'Manage mode';
        $body = <<<EOF
            	<body class="adelia manage-page"$onload>
            		<div class="boardlist adminbar">
            			$adminbar
            		</div>
            		<header><h1 class="logo">
            EOF;
        $body .= $this->config->logo . $this->config->boarddesc . <<<EOF
            		</h1></header>
            		<hr width="90%">
            		<div class="banner replymode">$txt_manage_mode</div>
            		$text
            		<hr>
            EOF;
        return $this->pageHeader() . $body . $this->pageFooter();
    }

    private function manageOnLoad(string $page): string
    {
        $field = match ($page) {
            'accounts', 'login' => 'username', 'keywords' => 'text', 'moderate' => 'moderate', 'staffpost' => 'message', default => '',
        };
        return $field === '' ? '' : ' data-focus="' . $field . '"';
    }

    private function manageLogInForm(): string
    {
        $txt_login = 'Log In';
        $txt_login_prompt = 'Enter a username and password';
        $captcha_inner_html = '';
        if ($this->config->managecaptcha === 'simple') {
            $captcha_inner_html = '
<br>
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . '(enter the text below)' . '<br>
<img id="captchaimage" src="inc/captcha.php" width="175" height="55" alt="CAPTCHA" data-action="captcha-refresh" role="button" tabindex="0" aria-label="Get a new CAPTCHA" style="margin-top: 5px;cursor: pointer;"><br><br>';
        }
        $managekey = htmlentities($this->request->query['manage'], ENT_QUOTES);
        return <<<EOF
            	<form id="adelia" name="adelia" method="post" action="?manage=$managekey">
            	<fieldset>
            	<legend align="center">$txt_login_prompt</legend>
            	<div class="login">
            	<input type="text" id="username" name="username" placeholder="Username"><br>
            	<input type="password" id="managepassword" name="managepassword" placeholder="Password"><br>
            	$captcha_inner_html
            	<input type="submit" value="$txt_login" class="managebutton">
            	</div>
            	</fieldset>
            	</form>
            	<br>
            EOF;
    }

    private function manageModerationLog(int $offset): string
    {
        $offset = (int) $offset;
        $limit = 50;

        $logs = $this->getLogs($offset, $limit);

        $u = [];

        $text = '';
        foreach ($logs as $log) {
            if (!isset($u[$log['account']])) {
                $username = '';
                if ($log['account'] > 0) {
                    $a = $this->accountByID($log['account']);
                    if (!empty($a)) {
                        $username = $a['username'];
                    }
                }
                $u[$log['account']] = $username;
            }
            $text .= '<tr><td>' . $this->formatDate($log['timestamp']) . '</td><td>' . htmlentities($u[$log['account']]) . '</td><td>' . $this->postLink($this->cleanString(html_entity_decode(strip_tags($log['message']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '</td></tr>';
        }

        if ($text == '') {
            $text = '<i>' . 'No logs.' . '</i>';
        }

        $txt_moderation_log = 'Moderation log';
        $nav = '';
        if ($offset > 0) {
            $nav .= '<a href="?manage&modlog=' . ($offset - $limit) . '">Previous ' . $limit . '</a> ';
        }
        if (count($logs) == $limit) {
            $nav .= '<a href="?manage&modlog=' . ($offset + $limit) . '">Next ' . $limit . '</a> ';
        }
        $nav_top = '';
        $nav_bottom = '';
        if ($nav != '') {
            $nav_top = $nav . '<br><br>';
            $nav_bottom = '<br><br>' . $nav;
        }
        return <<<EOF
            		$nav_top
            		<fieldset>
            		<legend>$txt_moderation_log</legend>
            		<table border="0" cellspacing="0" cellpadding="0" width="100%">
            		<tr><th align="left">Date/time</th><th align="left">Account</th><th align="left">Action</th></tr>
            		$text
            		</table>
            		</fieldset>
            		$nav_bottom
            EOF;
    }

    private function manageChangePasswordForm(): string
    {
        $txt_header = 'Change Password';
        $txt_submit = 'Submit';
        return <<<EOF
            	<form id="adelia" name="adelia" method="post" action="?manage&changepassword">
            	<fieldset>
            	<legend>$txt_header</legend>
                <p>Use a passphrase with 12 to 64 characters.</p>
            	<table border="0">
            	<tr><td><label for="current-password">Current password</label></td><td><input type="password" name="current_password" id="current-password" autocomplete="current-password" required></td></tr>
                <tr><td><label for="password">New password</label></td><td><input type="password" name="password" id="password" autocomplete="new-password" minlength="12" maxlength="64" required></td></tr>
            	<tr><td><label for="confirm">Confirm</label></td><td><input type="password" name="confirm" id="confirm" autocomplete="new-password" minlength="12" maxlength="64" required></td></tr>
            	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
            	</table>
            	<legend>
            	</fieldset>
            	</form><br>
            EOF;
    }

    private function manageAccountForm(int $id = 0): string
    {
        $a = [
            'id' => 0,
            'username' => '',
            'password' => '',
            'role' => 0,
        ];
        $txt_header = 'Add an account';
        $txt_password_hint = '';
        if ($id > 0) {
            $txt_header = 'Update an account';
            $txt_password_hint = '(' . 'Leave blank to maintain current password' . ')';
            $a = $this->accountByID($id);
        }

        $a['username'] = htmlentities($a['username'], ENT_QUOTES);

        $txt_username = 'Username';
        $txt_password = 'Password';
        $txt_role = 'Role';
        $return = <<<EOF
            	<form id="adelia" name="adelia" method="post" action="?manage&accounts">
            	<input type="hidden" name="id" value="{$a['id']}">
            	<fieldset>
            	<legend>$txt_header</legend>
            	<table border="0">
            	<tr><td><label for="username">$txt_username</label></td><td><input type="text" name="username" id="username" value="{$a['username']}"></td></tr>
            	<tr><td><label for="password">$txt_password</label></td><td><input type="password" name="password" id="password" value=""> <small>$txt_password_hint</small></td></tr>
            	<tr><td><label for="role">$txt_role</label></td><td><select name="role" id="role">
            EOF;
        $return .= '<option value="0" ' . ($a['role'] == 0 ? ' selected' : '') . '>' . 'Choose a role' . '</option>';
        $return .= '<option value="1" ' . ($a['role'] == 1 ? ' selected' : '') . '>' . 'Super-administrator' . '</option>';
        $return .= '<option value="2" ' . ($a['role'] == 2 ? ' selected' : '') . '>' . 'Administrator' . '</option>';
        $return .= '<option value="3" ' . ($a['role'] == 3 ? ' selected' : '') . '>' . 'Moderator' . '</option>';
        $return .= '<option value="99" ' . ($a['role'] == 99 ? ' selected' : '') . '>' . 'Disabled' . '</option>';
        $txt_submit = 'Submit';
        $return .= <<<EOF
            	</select></td></tr>
            	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
            	</table>
            	</fieldset>
            	</form><br>
            EOF;
        return $return;
    }

    private function manageAccountsTable(): string
    {
        $text = '';
        $allaccounts = $this->allAccounts();
        if (count($allaccounts) > 0) {
            $text .= '<table border="1"><tr><th>' . 'Username' . '</th><th>' . 'Role' . '</th><th>' . 'Last active' . '</th><th>&nbsp;</th></tr>';
            foreach ($allaccounts as $account) {
                $lastactive = ($account['lastactive'] > 0) ? $this->formatDate($account['lastactive']) : 'Never';
                $text .= '<tr><td>' . htmlentities($account['username']) . '</td><td>';
                switch ((int) ($account['role'])) {
                    case Role::SuperAdministrator->value:
                        $text .= 'Super-administrator';
                        break;
                    case Role::Administrator->value:
                        $text .= 'Administrator';
                        break;
                    case Role::Moderator->value:
                        $text .= 'Moderator';
                        break;
                    case Role::Disabled->value:
                        $text .= 'Disabled';
                        break;
                }
                $text .= '</td><td>' . $lastactive . '</td><td><a href="?manage&accounts=' . $account['id'] . '">' . 'update' . '</a></td></tr>';
            }
            $text .= '</table>';
        }
        return $text;
    }

    private function manageModeratePostForm(): string
    {
        $txt_moderate = 'Moderate a post';
        $txt_postid = 'Post ID';
        $txt_submit = 'Submit';
        $txt_tip = 'Tip';
        $txt_tiptext1 = 'While browsing the image board, you can easily moderate a post if you are logged in.';
        $txt_tiptext2 = 'Tick the box next to a post and click "Delete" at the bottom of the page with a blank password.';
        return <<<EOF
            	<form id="adelia" name="adelia" method="get" action="?">
            	<input type="hidden" name="manage" value="">
            	<fieldset>
            	<legend>$txt_moderate</legend>
            	<div valign="top"><label for="moderate">$txt_postid</label> <input type="text" name="moderate" id="moderate"> <input type="submit" value="$txt_submit" class="managebutton"></div><br>
            	<b>$txt_tip:</b> $txt_tiptext1<br>
            	$txt_tiptext2<br>
            	</fieldset>
            	</form><br>
            EOF;
    }

    private function manageEditKeyword(int $id): string
    {
        $id = (int) $id;

        $v_text = '';
        $v_action = '';
        $v_regexp_checked = '';
        if ($id > 0) {
            $keyword = $this->keywordByID($id);
            if (empty($keyword)) {
                $this->fancyDie("Sorry, there doesn't appear to be a keyword with that ID.");
            }
            $v_text = htmlentities($keyword['text'], ENT_QUOTES);
            $v_action = $keyword['action'];

            if (substr($v_text, 0, 7) == 'REGEXP:') {
                $v_regexp_checked = 'selected';
                $v_text = substr($v_text, 7);
            }
        }

        $txt_keyword = 'Keyword';
        $txt_keywords = 'Keywords';
        $txt_action = 'Action';
        $txt_submit = $id > 0 ? 'Update' : 'Add';

        $return = <<<EOF
            	<form id="adelia" name="adelia" method="post" action="?manage&keywords=$id">
            	<fieldset>
            	<legend>$txt_keywords</legend>
            	<table border="0">
            	<tr><td><label for="keyword">$txt_keyword</label></td><td><input type="text" name="text" id="text" value="$v_text"> <label for="regexp">&nbsp; <input type="checkbox" name="regexp" value="1" $v_regexp_checked> Regular expression</label></td></tr>
            	<tr><td><label for="action">$txt_action</label></td><td><select name="action">
            EOF;
        if ($this->config->report && $this->config->reqmod != 'all') {
            $return .= '<option value="report"' . ($v_action == 'report' ? ' selected' : '') . '>' . 'Report' . '</option>';
        }
        $return .= '<option value="delete"' . ($v_action == 'delete' ? ' selected' : '') . '>' . 'Delete' . '</option>';
        $return .= '<option value="hide"' . ($v_action == 'hide' ? ' selected' : '') . '>' . 'Hide until approved' . '</option>';
        return $return . <<<EOF
            	</select></td></tr>
            	<tr><td>&nbsp;</td><td><input type="submit" value="$txt_submit" class="managebutton"></td></tr>
            	</table>
            	</fieldset>
            	</form><br>
            EOF;
    }

    private function manageKeywordsTable(): string
    {
        $text = '';
        $keywords = $this->allKeywords();
        if (count($keywords) > 0) {
            $text .= '<table border="1"><tr><th>' . 'Keyword' . '</th><th>' . 'Action' . '</th><th>&nbsp;</th></tr>';
            foreach ($keywords as $keyword) {
                $action = '';
                switch ($keyword['action']) {
                    case 'report':
                        $action = 'Report';
                        break;
                    case 'hide':
                        $action = 'Hide until approved';
                        break;
                    case 'delete':
                        $action = 'Delete';
                        break;
                }
                $text .= '<tr><td>' . htmlentities($keyword['text']) . '</td><td>' . $action . '</td><td><a href="?manage&keywords=' . $keyword['id'] . '">' . 'Edit' . '</a> <a href="?manage&keywords&deletekeyword=' . $keyword['id'] . '">' . 'Delete' . '</a></td></tr>';
            }
            $text .= '</table>';
        }
        return $text;
    }

    private function manageStatus(): string
    {

        $threads = $this->countThreads();
        $reports = $this->allReports();

        $info = $threads . ' ' . $this->plural($threads, 'thread', 'threads');
        if ($this->config->report) {
            $info .= ', ' . count($reports) . ' ' . $this->plural(count($reports), 'report', 'reports');
        }

        $output = '';

        $reqmod_html = '';

        if ($this->config->reqmod == 'files' || $this->config->reqmod == 'all') {
            $reqmod_post_html = '';

            $reqmod_posts = $this->latestPosts(false);
            foreach ($reqmod_posts as $post) {
                if ($reqmod_post_html != '') {
                    $reqmod_post_html .= '<tr><td colspan="2"><hr></td></tr>';
                }
                $reqmod_post_html .= '<tr><td>' . $this->buildPost($post, false) . '</td><td valign="top" align="right">
			<table border="0"><tr><td>
			<form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="approve" value="' . $post['id'] . '"><input type="submit" value="' . 'Approve' . '" class="managebutton"></form>
			</td><td>
			<form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="moderate" value="' . $post['id'] . '"><input type="submit" value="' . 'More Info' . '" class="managebutton"></form>
			</td></tr><tr><td align="right" colspan="2">
			<form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="delete" value="' . $post['id'] . '"><input type="submit" value="' . 'Delete' . '" class="managebutton"></form>
			</td></tr></table>
			</td></tr>';
            }

            if ($reqmod_post_html != '') {
                $txt_pending = 'Pending posts';
                $reqmod_html = <<<EOF
                    	<fieldset>
                    	<legend>$txt_pending</legend>
                    	<table border="0" cellspacing="0" cellpadding="0" width="100%">
                    	$reqmod_post_html
                    	</table>
                    	</fieldset>
                    EOF;
            }
        }

        if ($this->config->report && !empty($reports)) {
            $status_html = $this->manageReportsPage();
        } else {
            $posts = $this->latestPosts(true);
            $txt_recent_posts = 'Recent posts';

            $post_html = '';
            foreach ($posts as $post) {
                if ($post_html != '') {
                    $post_html .= '<tr><td colspan="2"><hr></td></tr>';
                }

                $post_html .= '<tr><td>' . $this->buildPost($post, false) . '</td><td valign="top" align="right"><form method="get" action="?"><input type="hidden" name="manage" value=""><input type="hidden" name="moderate" value="' . $post['id'] . '"><input type="submit" value="' . 'Moderate' . '" class="managebutton"></form></td></tr>';
            }

            $status_html = <<<EOF
                		<fieldset>
                		<legend>$txt_recent_posts</legend>
                		<table border="0" cellspacing="0" cellpadding="0" width="100%">
                			$post_html
                		</table>
                		</fieldset>
                EOF;
        }

        $txt_status = 'Status';
        $txt_info = 'Info';
        $output .= <<<EOF
            	<fieldset>
            	<legend>$txt_status</legend>
            	
            	<fieldset>
            	<legend>$txt_info</legend>
            	<table border="0" cellspacing="0" cellpadding="0" width="100%">
            	<tbody>
            	<tr><td>
            		$info
            	</td>
            	</tr>
            	</tbody>
            	</table>
            	</fieldset>

            	$reqmod_html
            	
            	$status_html
            	
            	</fieldset>
            	<br>
            EOF;

        return $output;
    }

    private function manageInfo(string $text): string
    {
        return '<div class="manageinfo">' . $text . '</div>';
    }

    private function encodeJson(array $array): string
    {
        return json_encode($array, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function buildSinglePostJson(array $post): array
    {
        $name = $post['name'];
        if ($name == '') {
            $name = 'Anonymous';
        }

        $output = ['id' => $post['id'], 'parent' => $post['parent'], 'timestamp' => $post['timestamp'], 'bumped' => $post['bumped'], 'name' => $name, 'tripcode' => $post['tripcode'], 'subject' => $post['subject'], 'message' => $post['message'], 'file' => $post['file'], 'file_hex' => $post['file_hex'], 'file_original' => $post['file_original'], 'file_size' => $post['file_size'], 'file_size_formated' => $post['file_size_formatted'], 'image_width' => $post['image_width'], 'image_height' => $post['image_height'], 'thumb' => $post['thumb'], 'thumb_width' => $post['thumb_width'], 'thumb_height' => $post['thumb_height']];

        if ($post['parent'] == 0) {
            $replies = count($this->postsInThreadByID($post['id'])) - 1;
            $images = $this->imagesInThreadByID($post['id']);

            $output = array_merge($output, ['stickied' => $post['stickied'], 'locked' => $post['locked'], 'replies' => $replies, 'images' => $images]);
        }

        return $output;
    }

    private function buildIndexJson(): string
    {
        $output = ['threads' => []];

        $threads = $this->allThreads();
        foreach ($threads as $thread) {
            array_push($output['threads'], ['id' => $thread['id'], 'subject' => $thread['subject'], 'bumped' => $thread['bumped']]);
        }

        return $this->encodeJson($output);
    }

    private function buildCatalogJson(): string
    {
        $output = ['threads' => []];

        $threads = $this->allThreads();
        foreach ($threads as $post) {
            array_push($output['threads'], $this->buildSinglePostJson($post));
        }

        return $this->encodeJson($output);
    }

    private function buildSingleThreadJson(int $id): string
    {
        $output = ['posts' => []];

        $posts = $this->postsInThreadByID($id);
        foreach ($posts as $post) {
            array_push($output['posts'], $this->buildSinglePostJson($post));
        }

        return $this->encodeJson($output);
    }

}
