<?php

declare(strict_types=1);

namespace Adelia;

final class Application
{
    use BoardOperations;
    use Rendering;
    use Storage;
    use Moderation;
    use Workflow;
    use AccountAccess;

    private array $account = [];
    private bool $loggedin = false;
    private bool $isadmin = false;
    private string $returnlink = 'imgboard.php';

    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly Request $request,
    ) {}

    private function fancyDie(string $message, int $go_back = 1, int $status = 400): never
    {
        throw new BoardMessage($message, $go_back, $status);
    }

    private function handle(): void
    {
        $loginSubmitted = isset($this->request->form['managepassword']);
        $this->initializeAccounts();
        [$this->account, $this->loggedin, $this->isadmin] = $this->manageCheckLogIn(false);
        $logout = isset($this->request->query['manage'], $this->request->query['logout']) && count($this->request->query) === 2;
        if ($this->mustChangePassword() && !$logout) {
            if (!isset($this->request->query['manage']) || ($this->request->server['REQUEST_METHOD'] === 'POST' && !$loginSubmitted && !isset($this->request->query['changepassword']))) {
                throw new BoardMessage('Change the initial password before using management.', status: 403);
            }
            $text = isset($this->request->query['changepassword'])
                ? $this->changeOwnPassword()
                : $this->manageInfo('Choose your own password before using management. The initial login is admin / password.') . $this->manageChangePasswordForm();
            echo $this->managePage($text);
            return;
        }

        $redirect = true;
        // Check if the request is to make a post
        if (!isset($this->request->query['delete']) && !isset($this->request->query['manage']) && (isset($this->request->form['name']) || isset($this->request->form['email']) || isset($this->request->form['subject']) || isset($this->request->form['message']) || isset($this->request->form['file']) || isset($this->request->form['embed']) || isset($this->request->form['password']))) {

            foreach (['name', 'email', 'subject', 'message', 'password', 'embed'] as $field) {
                if (isset($this->request->form[$field]) && !is_string($this->request->form[$field])) {
                    $this->fancyDie('Invalid post field.');
                }
                $this->request->form[$field] ??= '';
            }

            $staffpost = $this->isStaffPost();
            $capcode = '';
            if (!$staffpost) {
                $this->checkMessageSize();
            }

            $post = $this->newPost($this->setParent());

            if (!$this->loggedin) {
                $this->checkCaptcha($post['parent'] == 0 ? $this->config->captcha : $this->config->replycaptcha);
                $this->checkFlood();
            }

            if (!$this->loggedin) {
                if ($post['parent'] == 0 && $this->config->disallowthreads != '') {
                    $this->fancyDie($this->config->disallowthreads);
                } elseif ($post['parent'] != 0 && $this->config->disallowreplies != '') {
                    $this->fancyDie($this->config->disallowreplies);
                }
            }

            $hide_fields = $post['parent'] == 0 ? $this->config->hidefieldsop : $this->config->hidefields;

            if ($post['parent'] != 0 && !$this->loggedin) {
                $parent = $this->postByID($post['parent']);
                if (!isset($parent['locked'])) {
                    $this->fancyDie('Invalid parent thread ID supplied, unable to create post.');
                } elseif ($parent['locked'] == 1) {
                    $this->fancyDie('Replies are not allowed to locked threads.');
                }
            }

            if ($post['name'] == '' && $post['tripcode'] == '') {

                $post['name'] = $this->config->anonymous[array_rand($this->config->anonymous)];
            }

            $spoiler = $this->config->spoilerimage && isset($this->request->form['spoiler']);

            if ($staffpost || !in_array('name', $hide_fields)) {
                [$post['name'], $post['tripcode']] = $this->nameAndTripcode($this->request->form['name']);
                if ($this->config->maxname > 0) {
                    $post['name'] = $this->substring($post['name'], 0, $this->config->maxname);
                }
                $post['name'] = $this->cleanString($post['name']);
            }
            if ($staffpost || !in_array('email', $hide_fields)) {
                $post['email'] = $this->request->form['email'];
                if ($this->config->maxemail > 0) {
                    $post['email'] = $this->substring($post['email'], 0, $this->config->maxemail);
                }
                $post['email'] = $this->cleanString(str_replace('"', '&quot;', $post['email']));
            }
            if ($staffpost) {
                $capcode = ($this->isadmin) ? ' <span style="color: ' . $this->config->capcodes[0][1] . ' ;">## ' . $this->config->capcodes[0][0] . '</span>' : ' <span style="color: ' . $this->config->capcodes[1][1] . ';">## ' . $this->config->capcodes[1][0] . '</span>';
            }
            if ($staffpost || !in_array('subject', $hide_fields)) {
                $post['subject'] = $this->request->form['subject'];
                if ($this->config->maxsubject > 0) {
                    $post['subject'] = $this->substring($post['subject'], 0, $this->config->maxsubject);
                }
                $post['subject'] = $this->cleanString($post['subject']);
            }
            if ($staffpost || !in_array('message', $hide_fields)) {
                $post['message_source'] = $this->request->form['message'];
                $post['message'] = $this->formatMessage($post['message_source']);
            }
            if ($staffpost || !in_array('password', $hide_fields)) {
                $post['password'] = ($this->request->form['password'] !== '') ? Passwords::hash($this->request->form['password']) : '';
            }

            $hide_post = false;
            $report_post = false;
            foreach ([$post['name'], $post['email'], $post['subject'], $post['message']] as $field) {
                $keyword = $this->checkKeywords($field);
                if (empty($keyword)) {
                    continue;
                }

                switch ($keyword['action']) {
                    case 'report':
                        $report_post = true;
                        break;
                    case 'hide':
                        $hide_post = true;
                        break;
                    case 'delete':
                        $this->fancyDie('Your post contains a blocked keyword.');
                }
                break;
            }

            $post['nameblock'] = $this->nameBlock($post['name'], $post['tripcode'], $post['email'], time(), $capcode);

            if (isset($this->request->form['embed']) && trim($this->request->form['embed']) != '' && ($staffpost || !in_array('embed', $hide_fields))) {
                if (isset($this->request->files['file']) && $this->request->files['file']['name'] != '') {
                    $this->fancyDie('Embedding a URL and uploading a file at the same time is not supported.');
                }

                $post = $this->attachRemote($post, trim($this->request->form['embed']), $spoiler);
            } elseif (isset($this->request->files['file']) && $this->request->files['file']['name'] != '' && ($staffpost || !in_array('file', $hide_fields))) {
                $this->validateFileUpload();

                $post = $this->attachFile($post, $this->request->files['file']['tmp_name'], $this->request->files['file']['name'], true, $spoiler);
            }

            if ($post['file'] == '') { // No file uploaded
                $file_ok = !empty($this->config->uploads) && ($staffpost || !in_array('file', $hide_fields));
                $embed_ok = (!empty($this->config->embeds) || $this->config->uploadviaurl) && ($staffpost || !in_array('embed', $hide_fields));
                $allowed = '';
                if ($file_ok && $embed_ok) {
                    $allowed = 'upload a file or embed a URL';
                } elseif ($file_ok) {
                    $allowed = 'upload a file';
                } elseif ($embed_ok) {
                    $allowed = 'embed a URL';
                }
                if ($post['parent'] == 0 && $allowed != '' && !$this->config->nofileok) {
                    $this->fancyDie(sprintf('Please %s to start a new thread.', $allowed));
                }
                if (!$staffpost && str_replace('<br>', '', $post['message']) == '') {
                    $message_ok = !in_array('message', $hide_fields);
                    if ($message_ok) {
                        if ($allowed != '') {
                            $this->fancyDie(sprintf('Please enter a message and/or %s.', $allowed));
                        }
                        $this->fancyDie('Please enter a message.');
                    }
                    $this->fancyDie(sprintf('Please %s.', $allowed));
                }
            }

            if (!$this->loggedin && (($post['file'] != '' && $this->config->reqmod == 'files') || $this->config->reqmod == 'all')) {
                $post['moderated'] = 0;
                echo sprintf('Your %s will be shown <b>once it has been approved</b>.', $post['parent'] == 0 ? 'thread' : 'post') . '<br>';
                $slow_redirect = true;
            }

            $post['id'] = $this->insertPost($post);
            $_SESSION['last_post_time'] = time();

            if ($report_post) {
                $report = ['post' => $post['id']];
                $this->insertReport($report);
                $this->checkAutoHide($post);
            }

            if ($hide_post) {
                $this->approvePostByID($post['id'], 0);
            }

            if ($post['moderated'] === 1) {
                if ($this->config->alwaysnoko || strtolower($post['email']) == 'noko') {
                    $redirect = 'res/' . ($post['parent'] == 0 ? $post['id'] : $post['parent']) . '.html#' . $post['id'];
                }

                $this->trimThreads();

                echo 'Updating thread...' . '<br>';
                if ($post['parent'] != 0) {
                    $this->rebuildThread($post['parent']);

                    if (strtolower($post['email']) != 'sage') {
                        if ($this->config->maxreplies == 0 || $this->numRepliesToThreadByID($post['parent']) <= $this->config->maxreplies) {
                            $this->bumpThreadByID($post['parent']);
                        }
                    }
                } else {
                    $this->rebuildThread($post['id']);
                }

                echo 'Updating index...' . '<br>';
                $this->rebuildIndexes();
            }

            if ($staffpost) {
                $this->manageLogAction('Created staff post' . ' ' . $this->postLink('&gt;&gt;' . $post['id']));
            }
            // Check if the request is to preview a post
        } elseif (isset($this->request->query['preview']) && !isset($this->request->query['manage'])) {
            $post = $this->postByID($this->request->queryInt('preview'));
            if (!$this->canViewPost($post)) {
                throw new BoardMessage('Post not found.', status: 404);
            }

            $html = $this->buildPost($post, isset($this->request->query['res']), true);
            if (isset($this->request->query['res'])) {
                $html = $this->fixLinksInRes($html);
            }

            echo $html;
            return;
            // Check if the request is to auto-refresh a thread
        } elseif (isset($this->request->query['posts']) && !isset($this->request->query['manage'])) {
            if ($this->config->autorefresh <= 0) {
                $this->fancyDie('Automatic refreshing is disabled.');
            }

            $thread_id = $this->request->queryInt('posts');
            $new_since = $this->request->queryInt('since');
            if ($thread_id <= 0 || $new_since < 0) {
                $this->fancyDie('');
            }

            $thread = $this->postByID($thread_id);
            if (!$this->canViewPost($thread) || $thread['parent'] !== 0) {
                throw new BoardMessage('Thread not found.', status: 404);
            }
            $json_posts = [];
            $posts = $this->postsInThreadByID($thread_id);
            if ($new_since > 0) {
                foreach ($posts as $i => $post) {
                    if ($post['id'] <= $new_since) {
                        continue;
                    }
                    $json_posts[$post['id']] = $this->fixLinksInRes($this->buildPost($post, true));
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($json_posts, JSON_THROW_ON_ERROR);
            return;
            // Check if the request is to report a post
        } elseif (isset($this->request->query['report']) && !isset($this->request->query['manage'])) {

            if (!$this->config->report) {
                $this->fancyDie('Reporting is disabled.');
            }

            $post = $this->postByID($this->request->queryInt('report'));
            if (!$this->canViewPost($post)) {
                $this->fancyDie('Sorry, an invalid post identifier was sent. Please go back, refresh the page, and try again.');
            }

            if ($post['moderated'] == 2) {
                $this->fancyDie('Moderators have determined that post does not break any rules.');
            }

            if (in_array($post['id'], $_SESSION['reported_posts'] ?? [], true)) {
                $this->fancyDie('You have already submitted a report for that post.');
            }

            $go_back = 1;
            if ($this->config->reportcaptcha != '') {
                if (isset($this->request->query['verify'])) {
                    $this->checkCaptcha($this->config->reportcaptcha);
                    $go_back = 2;
                } else {
                    { // Simple CAPTCHA
                        $captcha = '
<br>
<input type="text" name="captcha" id="captcha" size="6" accesskey="c" autocomplete="off">&nbsp;&nbsp;' . '(enter the text below)' . '<br>
<img id="captchaimage" src="inc/captcha.php" width="175" height="55" alt="CAPTCHA" data-action="captcha-refresh" role="button" tabindex="0" aria-label="Get a new CAPTCHA" style="margin-top: 5px;cursor: pointer;"><br><br>';
                    }

                    $txt_report = 'Please complete a CAPTCHA to submit your report';
                    $txt_submit = 'Submit';
                    $body = <<<EOF
                        <form id="adelia" name="adelia" method="post" action="?report={$post['id']}&verify">
                        <fieldset>
                        <legend align="center">$txt_report</legend>
                        <div class="login">
                        $captcha
                        <input type="submit" value="$txt_submit" class="managebutton">
                        </div>
                        </fieldset>
                        </form>
                        EOF;

                    echo $this->pageHeader() . $body . $this->pageFooter();
                    return;
                }
            }

            $report = ['post' => $post['id']];
            $this->insertReport($report);
            $this->checkAutoHide($post);

            $_SESSION['reported_posts'] = array_slice([...($_SESSION['reported_posts'] ?? []), $post['id']], -100);
            $this->fancyDie('Post reported.', $go_back, 200);
            // Check if the request is to delete a post and/or its associated image
        } elseif (isset($this->request->query['delete']) && !isset($this->request->query['manage'])) {

            if (!isset($this->request->form['delete'])) {
                $this->fancyDie('Tick the box next to a post and click "Delete" to delete it.');
            }

            $post_ids = Request::deletions($this->request->form['delete']);

            [$this->account, $this->loggedin, $this->isadmin] = $this->manageCheckLogIn(false);
            if (!empty($this->account)) {
                // Redirect to post moderation page
                echo '--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=' . basename($this->request->server['PHP_SELF']) . '?manage&moderate=' . implode(',', $post_ids) . '">';
                return;
            }

            $posts = [];
            foreach ($post_ids as $post_id) {
                $post = $this->postByID($post_id);
                if ($post === [] || $post['password'] === '' || !Passwords::verify($this->request->form['password'] ?? '', $post['password'])) {
                    $this->fancyDie('Invalid post or deletion password.');
                }
                $posts[] = $post;
            }
            foreach ($posts as $post) {
                // A selected parent may already have removed a selected reply.
                if ($this->postByID($post['id']) === []) {
                    continue;
                }
                $this->deletePost($post['id']);
                $this->threadUpdated($this->getParent($post));
            }
            $this->fancyDie(count($posts) === 1 ? 'Post deleted.' : 'Posts deleted.', status: 200);

            // Check if the request is to access the management area
        } elseif (isset($this->request->query['manage'])) {

            $text = '';
            $onload = '';
            $navbar = '&nbsp;';
            $redirect = false;
            $this->loggedin = false;
            $this->isadmin = false;
            $this->returnlink = basename($this->request->server['PHP_SELF']);

            if (isset($this->request->query['logout'])) {
                $_SESSION = [];
                session_destroy();
                echo '--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="0;url=imgboard.php">';
                return;
            }

            [$this->account, $this->loggedin, $this->isadmin] = $this->manageCheckLogIn(true);

            if ($this->loggedin) {
                $this->requireModerator();
                $allowed = ['manage', 'logout', 'rebuildall', 'modlog', 'reports', 'accounts', 'keywords', 'deletekeyword', 'delete', 'approve', 'moderate', 'edit', 'sticky', 'lock', 'clearreports', 'staffpost', 'changepassword'];
                if (array_diff(array_keys($this->request->query), $allowed) !== []) {
                    $this->fancyDie('Management page not found.', status: 404);
                }
                if (!$this->isadmin && array_any(['rebuildall', 'modlog', 'reports', 'accounts', 'keywords', 'deletekeyword'], fn(string $key): bool => isset($this->request->query[$key]))) {
                    $this->fancyDie('Administrator access is required.', status: 403);
                }
                if ($this->isadmin) {
                    if (isset($this->request->query['rebuildall'])) {
                        $allthreads = $this->allThreads();
                        foreach ($allthreads as $thread) {
                            $this->rebuildThread($thread['id']);
                        }
                        $this->rebuildIndexes();
                        $text .= $this->manageInfo('Rebuilt board.');
                    } elseif (isset($this->request->query['modlog'])) {
                        $text .= $this->manageModerationLog($this->request->queryInt('modlog'));
                    } elseif (isset($this->request->query['reports'])) {
                        if (!$this->config->report) {
                            $this->fancyDie('Reporting is disabled.');
                        }
                        $text .= $this->manageReportsPage();
                    } elseif (isset($this->request->query['accounts'])) {
                        if ($this->account['role'] != Role::SuperAdministrator->value) {
                            $this->fancyDie('Access denied');
                        }

                        $id = $this->request->queryInt('accounts');
                        if (isset($this->request->form['id'])) {
                            $id = $this->request->formInt('id');
                        }
                        $a = ['id' => 0];
                        if ($id > 0) {
                            $a = $this->accountByID($id);
                            if (empty($a)) {
                                $this->fancyDie('Account not found.');
                            }
                        }

                        if (isset($this->request->form['id'])) {
                            if ($id == 0 && $this->request->form['password'] == '') {
                                $this->fancyDie('A password is required.');
                            }

                            $prev = $a;

                            $a['username'] = $this->request->form['username'];
                            if ($this->request->form['password'] != '') {
                                $a['password'] = $this->request->form['password'];
                            }
                            $a['role'] = $this->request->formInt('role');
                            if ($a['role'] !== Role::SuperAdministrator->value && $a['role'] != Role::Administrator->value && $a['role'] != Role::Moderator->value && $a['role'] != Role::Disabled->value) {
                                $this->fancyDie('Invalid role.');
                            }

                            if ($id == 0) {
                                $this->insertAccount($a);
                                $this->manageLogAction(sprintf('Added account %s', $this->cleanString($a['username'])));
                                $text .= $this->manageInfo('Added account');
                            } else {
                                $this->updateAccount($a);
                                if ($a['username'] != $prev['username']) {
                                    $this->manageLogAction(sprintf('Renamed account %1$s as %2$s', $this->cleanString($prev['username']), $this->cleanString($a['username'])));
                                }
                                if ($a['password'] != $prev['password']) {
                                    $this->manageLogAction(sprintf('Changed password of account %s', $this->cleanString($a['username'])));
                                }
                                if ($a['role'] != $prev['role']) {
                                    $r = '';
                                    switch ($a['role']) {
                                        case Role::SuperAdministrator->value:
                                            $r = 'Super-administrator';
                                            break;
                                        case Role::Administrator->value:
                                            $r = 'Administrator';
                                            break;
                                        case Role::Moderator->value:
                                            $r = 'Moderator';
                                            break;
                                        case Role::Disabled->value:
                                            $r = 'Disabled';
                                            break;
                                    }
                                    $this->manageLogAction(sprintf('Changed role of account %s to %s', $this->cleanString($a['username']), $r));
                                }
                                $text .= $this->manageInfo('Updated account');
                            }
                        }

                        $onload = $this->manageOnLoad('accounts');
                        $text .= $this->manageAccountForm($this->request->queryInt('accounts'));
                        if ($this->request->queryInt('accounts') == 0) {
                            $text .= $this->manageAccountsTable();
                        }
                    } elseif (isset($this->request->query['keywords'])) {
                        if (isset($this->request->form['text']) && $this->request->form['text'] != '') {
                            if (!in_array($this->request->form['action'] ?? '', ['report', 'hide', 'delete'], true)) {
                                $this->fancyDie('Invalid keyword action.');
                            }
                            if ($this->request->query['keywords'] > 0) {
                                $this->deleteKeyword($this->request->queryInt('keywords'));
                            }

                            $keyword_exists = $this->keywordByText($this->request->form['text']);
                            if ($keyword_exists) {
                                $this->fancyDie('Sorry, that keyword has already been added.');
                            }

                            $keyword = [];
                            $keyword['text'] = $this->request->form['text'];
                            $keyword['action'] = $this->request->form['action'];

                            $kw = $keyword['text'];

                            if (isset($this->request->form['regexp']) && $this->request->form['regexp'] == '1') {
                                $keyword['text'] = 'regexp:' . $keyword['text'];
                            }

                            $this->insertKeyword($keyword);
                            if ($this->request->query['keywords'] > 0) {
                                $this->manageLogAction(sprintf('Updated keyword %s', htmlentities($kw)));
                                $text .= $this->manageInfo('Keyword updated.');
                                $this->request->query['keywords'] = '0';
                            } else {
                                $this->manageLogAction(sprintf('Updated keyword %s', htmlentities($kw)));
                                $text .= $this->manageInfo('Keyword added.');
                            }
                        } elseif (isset($this->request->query['deletekeyword'])) {
                            $keyword = $this->keywordByID($this->request->queryInt('deletekeyword'));
                            if (empty($keyword)) {
                                $this->fancyDie('That keyword does not exist.');
                            }

                            $kw = $keyword['text'];
                            if (substr($keyword['text'], 0, 7) == 'regexp:') {
                                $kw = substr($keyword['text'], 7);
                            }

                            $this->deleteKeyword($this->request->queryInt('deletekeyword'));
                            $this->manageLogAction(sprintf('Deleted keyword %s', htmlentities($kw)));
                            $text .= $this->manageInfo('Keyword deleted.');
                        }

                        $onload = $this->manageOnLoad('keywords');
                        if ($this->request->query['keywords'] > 0) {
                            $text .= $this->manageEditKeyword($this->request->queryInt('keywords'));
                        } else {
                            $text .= $this->manageEditKeyword(0);
                            $text .= $this->manageKeywordsTable();
                        }
                    }
                }

                if (isset($this->request->query['delete'])) {
                    $post_ids = Request::ids($this->request->query['delete']);
                    $posts = [];
                    foreach ($post_ids as $post_id) {
                        $post = $this->postByID($post_id);
                        if (!$post) {
                            continue; // The post has already been deleted
                        }
                        $posts[$post_id] = $post;
                    }
                    foreach ($posts as $post_id => $post) {

                        $this->deletePost($post['id']);
                        if ($post['parent'] == 0) {
                            $this->rebuildThread($post['id']);
                        } else {
                            $this->rebuildThread($post['parent']);
                        }

                        $action = sprintf('Deleted %s', '&gt;&gt;' . $post['id']);
                        $stripped = strip_tags($post['message']);
                        if ($stripped != '') {
                            $action .= ' - ' . htmlentities($this->substring($stripped, 0, 32));
                            if ($this->length($stripped) > 32) {
                                $action .= '...';
                            }
                        }
                        $this->manageLogAction($action);
                    }
                    $this->rebuildIndexes();
                    if (count($post_ids) == 1) {
                        $text .= $this->manageInfo('Deleted 1 post');
                    } else {
                        $text .= $this->manageInfo(sprintf('Deleted %d posts', count($post_ids)));
                    }
                } elseif (isset($this->request->query['approve'])) {
                    if ($this->request->query['approve'] > 0) {
                        $post = $this->postByID($this->request->queryInt('approve'));
                        if ($post) {
                            $this->approvePostByID($post['id'], 2);
                            $thread_id = $post['parent'] == 0 ? $post['id'] : $post['parent'];

                            if (strtolower($post['email']) != 'sage' && ($this->config->maxreplies == 0 || $this->numRepliesToThreadByID($thread_id) <= $this->config->maxreplies)) {
                                $this->bumpThreadByID($thread_id);
                            }
                            $this->threadUpdated($thread_id);

                            $this->manageLogAction('Approved' . ' ' . $this->postLink('&gt;&gt;' . $post['id']));
                            $text .= $this->manageInfo(sprintf('Post No.%d approved.', $post['id']));
                        } else {
                            $this->fancyDie("Sorry, there doesn't appear to be a post with that ID.");
                        }
                    }
                } elseif (isset($this->request->query['moderate'])) {
                    if ($this->request->query['moderate'] != '' && $this->request->query['moderate'] != '0') {
                        $post_ids = Request::ids($this->request->query['moderate']);
                        $compact = count($post_ids) > 1;
                        $posts = [];
                        $threads = 0;
                        $replies = 0;

                        foreach ($post_ids as $post_id) {
                            $post = $this->postByID($post_id);
                            if (!$post) {
                                $this->fancyDie("Sorry, there doesn't appear to be a post with that ID.");
                            }
                            if ($post['parent'] == 0) {
                                $threads++;
                            } else {
                                $replies++;
                            }

                            $posts[$post_id] = $post;
                        }

                        if (count($post_ids) > 1) {
                            $text .= $this->manageModerateAll($post_ids, $threads, $replies);
                        }
                        foreach ($post_ids as $post_id) {
                            $text .= $this->manageModeratePost($posts[$post_id], $compact);
                        }
                    } else {
                        $onload = $this->manageOnLoad('moderate');
                        $text .= $this->manageModeratePostForm();
                    }
                } elseif (isset($this->request->query['edit'])) {
                    $text .= $this->editPost();
                } elseif (isset($this->request->query['sticky']) || isset($this->request->query['lock'])) {
                    $text .= $this->setThreadState();
                } elseif (isset($this->request->query['clearreports'])) {
                    if ($this->request->query['clearreports'] > 0) {
                        $post = $this->postByID($this->request->queryInt('clearreports'));
                        if ($post) {
                            $this->approvePostByID($post['id'], 2);
                            $this->deleteReportsByPost($post['id']);
                            $this->threadUpdated($this->getParent($post));

                            $this->manageLogAction('Approved' . ' ' . $this->postLink('&gt;&gt;' . $post['id']));
                            $text .= $this->manageInfo(sprintf('Post No.%d approved.', $post['id']));
                        } else {
                            $this->fancyDie("Sorry, there doesn't appear to be a post with that ID.");
                        }
                    }
                } elseif (isset($this->request->query['staffpost'])) {
                    $onload = $this->manageOnLoad('staffpost');
                    $text .= $this->buildPostForm(0, true);
                } elseif (isset($this->request->query['changepassword'])) {
                    $text .= $this->changeOwnPassword();
                }

                if ($text == '') {
                    $text = $this->manageStatus();
                }
            } else {
                $onload = $this->manageOnLoad('login');
                $text .= $this->manageLogInForm();
            }

            echo $this->managePage($text, $onload);
        } elseif (PHP_SAPI === 'cli' || !file_exists($this->config->index) || $this->countThreads() == 0) {
            $this->rebuildIndexes();
        }

        if (PHP_SAPI === 'cli') {
            echo 'Rebuilt ' . $this->config->index . PHP_EOL;
        } elseif ($redirect) {
            echo '--&gt; --&gt; --&gt;<meta http-equiv="refresh" content="' . (isset($slow_redirect) ? '3' : '0') . ';url=' . (is_string($redirect) ? $redirect : $this->config->index) . '">';
        }

    }
}
