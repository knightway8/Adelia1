<?php

declare(strict_types=1);

namespace Adelia;

trait Moderation
{
    private function requireModerator(): void
    {
        if (!$this->loggedin || !in_array($this->account['role'] ?? 0, [Role::SuperAdministrator->value, Role::Administrator->value, Role::Moderator->value], true)) {
            throw new BoardMessage('Moderator access is required.', status: 403);
        }
    }

    private function formatMessage(string $source): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if ($this->config->wordbreak > 0) {
            $source = preg_replace('/([^\s]{' . $this->config->wordbreak . '})(?=[^\s])/u', '$1@!@ADELIA_WORDBREAK@!@', $source) ?? $source;
        }
        $message = str_replace("\n", '<br>', $this->makeLinksClickable($this->colorQuote($this->postLink($this->cleanString(rtrim($source))))));
        if ($this->config->spoilertext) {
            $message = preg_replace('/&lt;(s|spoiler|spoilers)&gt;(.*?)&lt;\/\1&gt;/i', '<span class="spoiler">$2</span>', $message) ?? $message;
        }
        return $this->config->wordbreak > 0 ? $this->finishWordBreak($message) : $message;
    }

    private function editableMessage(array $post): string
    {
        if ($post['source_known'] === 1) {
            return $post['message_source'];
        }
        // Older posts contain rendered HTML only. Parse it inertly; never execute it.
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8"><body>' . $post['message'], LIBXML_NOERROR, 'UTF-8');
        foreach ($document->querySelectorAll('script, style, iframe, object') as $element) {
            $element->remove();
        }
        foreach ($document->querySelectorAll('br') as $element) {
            $element->replaceWith("\n");
        }
        foreach ($document->querySelectorAll('span.spoiler') as $element) {
            $element->replaceWith('<spoiler>' . $element->textContent . '</spoiler>');
        }
        foreach ($document->querySelectorAll('p, div, li, blockquote') as $element) {
            $element->append("\n");
        }
        return rtrim($document->body->textContent);
    }

    private function postRevision(array $post): string
    {
        return hash_hmac('sha256', json_encode($post, JSON_THROW_ON_ERROR), $this->config->tripseed);
    }

    private function editPost(): string
    {
        $this->requireModerator();
        $post = $this->postByID($this->request->queryInt('edit'));
        if ($post === []) {
            throw new BoardMessage('The post no longer exists.', status: 404);
        }
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            return $this->manageEditPostForm($post);
        }
        if (!hash_equals($this->postRevision($post), (string) ($this->request->form['revision'] ?? ''))) {
            throw new BoardMessage('This post changed after you opened the editor. Reload it before saving.', status: 409);
        }
        foreach (['subject', 'message'] as $field) {
            if (!is_string($this->request->form[$field] ?? null)) {
                throw new BoardMessage('The subject and message fields are required.');
            }
        }
        $this->checkMessageSize();
        $subject = $this->request->form['subject'];
        if ($this->config->maxsubject > 0 && $this->length($subject) > $this->config->maxsubject) {
            throw new BoardMessage('The subject exceeds the configured length limit.');
        }
        $updated = $post;
        $updated['subject'] = $this->cleanString($subject);
        $source = str_replace(["\r\n", "\r"], "\n", $this->request->form['message']);
        if ($source !== $this->editableMessage($post)) {
            $updated['message_source'] = $source;
            $updated['source_known'] = 1;
            $updated['message'] = $this->formatMessage($source);
        }
        $file = $this->request->files['file'] ?? [];
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error)) {
            throw new BoardMessage('Invalid upload field.');
        }
        $replace = $error !== UPLOAD_ERR_NO_FILE;
        $remove = ($this->request->form['removefile'] ?? '') === '1';
        if ($replace && $remove) {
            throw new BoardMessage('Choose either a replacement file or removal of the attachment.');
        }
        if ($replace || $remove) {
            $empty = $this->newPost($post['parent']);
            foreach (['file', 'file_hex', 'file_original', 'file_size', 'file_size_formatted', 'image_width', 'image_height', 'thumb', 'thumb_width', 'thumb_height'] as $field) {
                $updated[$field] = $empty[$field];
            }
        }
        if ($replace) {
            $this->validateFileUpload();
            if (!is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)) {
                throw new BoardMessage('Invalid upload field.');
            }
            $updated = $this->attachFile($updated, $file['tmp_name'], $file['name'], true, $this->config->spoilerimage && isset($this->request->form['spoiler']));
        }
        try {
            if ($updated['file'] === '' && trim($source) === '') {
                throw new BoardMessage('A post must contain a message or an attachment.');
            }
            if ($updated['parent'] === 0 && $updated['file'] === '' && !$this->config->nofileok) {
                throw new BoardMessage('This board requires an attachment on a thread.');
            }
            $changes = array_intersect_key($updated, array_flip(['subject', 'message', 'message_source', 'source_known', 'file', 'file_hex', 'file_original', 'file_size', 'file_size_formatted', 'image_width', 'image_height', 'thumb', 'thumb_width', 'thumb_height']));
            $this->database->update($this->config->dbposts, $post['id'], $changes);
        } catch (\Throwable $exception) {
            if ($replace) {
                $this->deletePostImages($updated);
            }
            throw $exception;
        }
        $this->manageLogAction('Edited post &gt;&gt;' . $post['id'] . ($replace ? ' (replaced attachment)' : ($remove ? ' (removed attachment)' : '')));
        $this->threadUpdated($this->getParent($post));
        // Retain the old files until both the database and public pages use the new ones.
        if ($replace || $remove) {
            $this->deletePostImages($post);
        }
        return $this->manageInfo('Post saved.') . $this->manageModeratePost($this->postByID($post['id']));
    }

    private function setThreadState(): string
    {
        $this->requireModerator();
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            throw new BoardMessage('Use the moderation form to change a thread.', status: 405);
        }
        $action = isset($this->request->query['sticky']) ? 'sticky' : 'lock';
        $post = $this->postByID($this->request->queryInt($action));
        if ($post === []) {
            throw new BoardMessage('The post no longer exists.', status: 404);
        }
        $state = $this->request->form['set' . $action] ?? null;
        if (!in_array($state, ['0', '1'], true)) {
            throw new BoardMessage('Choose a valid thread state.');
        }
        $thread = $this->postByID($this->getParent($post));
        if ($thread === []) {
            throw new BoardMessage('The thread no longer exists.', status: 404);
        }
        if (!hash_equals($this->postRevision($thread), (string) ($this->request->form['revision'] ?? ''))) {
            throw new BoardMessage('The thread changed. Reload the moderation page before trying again.', status: 409);
        }
        if ($action === 'sticky') {
            $this->stickyThreadByID($thread['id'], (int) $state);
            $label = $state === '1' ? 'Pinned' : 'Unpinned';
        } else {
            $this->lockThreadByID($thread['id'], (int) $state);
            $label = $state === '1' ? 'Locked' : 'Unlocked';
        }
        $this->threadUpdated($thread['id']);
        $this->manageLogAction($label . ' thread &gt;&gt;' . $thread['id']);
        return $this->manageInfo($label . ' thread No.' . $thread['id'] . '.') . $this->manageModeratePost($this->postByID($post['id']));
    }

    private function manageEditPostForm(array $post): string
    {
        $id = $post['id'];
        $revision = $this->postRevision($post);
        $subject = $this->cleanString(html_entity_decode($post['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $message = $this->cleanString($this->editableMessage($post));
        $current = $this->buildPost($post, false, true);
        $remove = $post['file'] !== '' ? '<label><input type="checkbox" name="removefile" value="1"> Remove the current attachment</label>' : '';
        $spoiler = $this->config->spoilerimage ? '<label><input type="checkbox" name="spoiler" value="1"> Spoiler thumbnail for the replacement</label>' : '';
        return <<<HTML
            <fieldset class="post-editor"><legend>Edit post No.$id</legend>
            <div class="edit-preview">$current</div>
            <form method="post" action="?manage&amp;edit=$id" enctype="multipart/form-data">
            <input type="hidden" name="revision" value="$revision">
            <label for="edit-subject">Subject</label>
            <input id="edit-subject" type="text" name="subject" value="$subject">
            <label for="edit-message">Message</label>
            <textarea id="edit-message" name="message" rows="12">$message</textarea>
            <p>Quotes, links and spoiler text use the normal posting format. The author and original posting time stay the same.</p>
            <label for="edit-file">Replace image or attachment</label>
            <input id="edit-file" type="file" name="file">
            <p>Leave this empty to keep the current attachment.</p>
            $remove $spoiler
            <p><button type="submit" class="managebutton">Save changes</button> <a href="?manage&amp;moderate=$id">Cancel</a></p>
            </form></fieldset>
            HTML;
    }

    private function manageModeratePost(array $post, bool $compact = false): string
    {
        $id = $post['id'];
        $thread = $this->postByID($this->getParent($post));
        $threadId = $thread['id'];
        $revision = $this->postRevision($thread);
        $pin = $thread['stickied'] === 1 ? 'Unpin thread' : 'Pin thread';
        $pinState = $thread['stickied'] === 1 ? 0 : 1;
        $lock = $thread['locked'] === 1 ? 'Unlock thread' : 'Lock thread';
        $lockState = $thread['locked'] === 1 ? 0 : 1;
        $delete = $post['parent'] === 0 ? 'Delete thread and replies' : 'Delete reply';
        $preview = $this->buildPost($post, false, true);
        $approval = '';
        $reports = count($this->reportsByPost($id));
        if ($post['moderated'] === 0 || $reports > 0) {
            $approval = '<form method="post" action="?manage&amp;clearreports=' . $id . '"><button class="managebutton">Approve post / clear reports</button></form>';
        }
        return <<<HTML
            <fieldset><legend>Moderating No.$id</legend>
            <div class="moderation-layout"><div class="moderation-preview">$preview</div>
            <div class="moderation-actions">
            <p><a class="managebutton" href="?manage&amp;edit=$id">Edit post / change image</a></p>
            <p>Thread No.$threadId — pinning keeps it at the top of the board; locking closes it to public replies.</p>
            <form method="post" action="?manage&amp;sticky=$id"><input type="hidden" name="setsticky" value="$pinState"><input type="hidden" name="revision" value="$revision"><button class="managebutton">$pin</button></form>
            <form method="post" action="?manage&amp;lock=$id"><input type="hidden" name="setlock" value="$lockState"><input type="hidden" name="revision" value="$revision"><button class="managebutton">$lock</button></form>
            $approval
            <form method="post" action="?manage&amp;delete=$id"><button class="managebutton">$delete</button></form>
            <p><a href="res/$threadId.html#$id">View post</a></p>
            </div></div></fieldset>
            HTML;
    }

    private function manageModerateAll(array $post_ids, int $threads, int $replies): string
    {
        $ids = $this->cleanString(implode(',', $post_ids));
        return '<fieldset><legend>Selected posts</legend><p>' . $threads . ' threads and ' . $replies . ' replies selected. Deleting a thread also deletes its replies.</p><form method="post" action="?manage&amp;delete=' . $ids . '"><button class="managebutton">Delete selected posts</button></form></fieldset>';
    }

    private function manageReportsPage(): string
    {
        $counts = array_count_values(array_column($this->allReports(), 'post'));
        $html = '<fieldset><legend>Reported posts</legend>';
        foreach ($counts as $id => $count) {
            $post = $this->postByID((int) $id);
            if ($post !== []) {
                $html .= '<p>' . $count . ' ' . $this->plural($count, 'report', 'reports') . '</p>' . $this->manageModeratePost($post);
            }
        }
        return $html . ($counts === [] ? '<p>There are currently no reported posts.</p>' : '') . '</fieldset>';
    }
}
