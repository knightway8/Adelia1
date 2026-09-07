<?php

declare(strict_types=1);

namespace Adelia;

trait BoardOperations
{
    private function lockDatabase(): BoardLock
    {
        return new BoardLock('adelia.lock');
    }

    private function length(string $string): int
    {
        return mb_strlen($string, 'UTF-8');
    }

    private function position(string $haystack, string $needle, int $offset = 0): int|false
    {
        return mb_strpos($haystack, $needle, $offset, 'UTF-8');
    }

    private function substring(string $string, int $start, ?int $length = null): string
    {
        return mb_substr($string, $start, $length, 'UTF-8');
    }

    private function countOccurrences(string $haystack, string $needle): int
    {
        return mb_substr_count($haystack, $needle, 'UTF-8');
    }

    private function cleanString(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function cleanQuotes(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        if ($plural == 's') {
            $plural = $singular . $plural;
        }
        return ($count == 1 ? $singular : $plural);
    }

    private function threadUpdated(int $id): void
    {
        $this->rebuildThread($id);
        $this->rebuildIndexes();
    }

    private function newPost(int $parent = 0): array
    {
        return [
            'parent' => $parent,
            'timestamp' => 0,
            'bumped' => 0,
            'name' => '',
            'tripcode' => '',
            'email' => '',
            'nameblock' => '',
            'subject' => '',
            'message' => '',
            'message_source' => '',
            'source_known' => 1,
            'password' => '',
            'file' => '',
            'file_hex' => '',
            'file_original' => '',
            'file_size' => 0,
            'file_size_formatted' => '',
            'image_width' => 0,
            'image_height' => 0,
            'thumb' => '',
            'thumb_width' => 0,
            'thumb_height' => 0,
            'stickied' => 0,
            'locked' => 0,
            'moderated' => 1,
        ];
    }

    private function convertBytes(int $number): string
    {
        $len = strlen((string) $number);
        if ($len < 4) {
            return sprintf('%dB', $number);
        } elseif ($len <= 6) {
            return sprintf('%0.2fKB', $number / 1024);
        } elseif ($len <= 9) {
            return sprintf('%0.2fMB', $number / 1024 / 1024);
        }

        return sprintf('%0.2fGB', $number / 1024 / 1024 / 1024);
    }

    private function nameAndTripcode(#[\SensitiveParameter] string $name): array
    {
        return Tripcodes::split($name, $this->config->tripseed);
    }

    private function nameBlock(string $name, string $tripcode, string $email, int $timestamp, string $capcode): string
    {

        $anonymous = $this->config->anonymous[array_rand($this->config->anonymous)];

        $output = '<span class="name postername">';
        $output .= ($name == '' && $tripcode == '') ? $anonymous : $name;

        if ($tripcode != '') {
            $output .= '</span><span class="trip postertrip">!' . $tripcode;
        }

        $output .= '</span>';

        if ($email != '' && strtolower($email) != 'noko') {
            $output = '<a href="mailto:' . $email . '">' . $output . '</a>';
        }

        return $output . $capcode . ' ' . $this->formatDate($timestamp);
    }

    private function writePage(string $filename, string $contents): void
    {
        if ($this->requestTransaction) {
            $this->database->schedule('rebuild');
            return;
        }
        StorageFiles::replace($filename, $contents, 0o664);
    }

    private function fixLinksInRes(string $html): string
    {
        $search = [' href="stylesheets/', ' src="js/', ' href="src/', ' href="thumb/', ' href="res/', ' href="imgboard.php', ' href="catalog.html', ' href="favicon.ico', 'src="thumb/', 'src="inc/', 'src="sticky.png', 'src="lock.png', ' action="imgboard.php', ' action="catalog.html'];
        $replace = [' href="../stylesheets/', ' src="../js/', ' href="../src/', ' href="../thumb/', ' href="../res/', ' href="../imgboard.php', ' href="../catalog.html', ' href="../favicon.ico', 'src="../thumb/', 'src="../inc/', 'src="../sticky.png', 'src="../lock.png', ' action="../imgboard.php', ' action="../catalog.html'];

        return str_replace($search, $replace, $html);
    }

    private function postLinkCallback(array $matches): string
    {
        if (strlen($matches[1]) > 18) {
            return $matches[0];
        }
        $post = $this->postByID(Request::integer($matches[1]));
        if ($post) {
            $is_op = $post['parent'] == 0;
            return '<a href="res/' . ($is_op ? $post['id'] : $post['parent']) . '.html#' . $matches[1] . '" class="' . ($is_op ? 'refop' : 'refreply') . '">' . $matches[0] . '</a>';
        }
        return $matches[0];
    }

    private function postLink(string $message): string
    {
        return preg_replace_callback('/&gt;&gt;([0-9]+)/', $this->postLinkCallback(...), $message);
    }

    private function finishWordBreakCallback(array $matches): string
    {
        return '<a' . $matches[1] . 'href="' . str_replace('@!@ADELIA_WORDBREAK@!@', '', $matches[2]) . '"' . $matches[3] . '>' . str_replace('@!@ADELIA_WORDBREAK@!@', '<br>', $matches[4]) . '</a>';
    }

    private function finishWordBreak(string $message): string
    {
        return str_replace('@!@ADELIA_WORDBREAK@!@', '<br>', preg_replace_callback('/<a(.*?)href="([^"]*?)"(.*?)>(.*?)<\/a>/', $this->finishWordBreakCallback(...), $message));
    }

    private function colorQuote(string $message): string
    {
        if (substr($message, -1, 1) != "\n") {
            $message .= "\n";
        }
        return preg_replace('/^(&gt;[^\>](.*))\n/m', '<span class="quote unkfunc">\\1</span>' . "\n", $message);
    }

    private function deletePostImages(array $post): void
    {
        if (!$this->isEmbed($post['file_hex']) && $post['file'] !== '') {
            MediaJournal::validatePath('src/' . $post['file']);
            $this->database->schedule('media', 'src/' . $post['file']);
        }
        if ($post['thumb'] !== '') {
            MediaJournal::validatePath('thumb/' . $post['thumb']);
            $this->database->schedule('media', 'thumb/' . $post['thumb']);
        }
    }

    private function deletePost(int $id): void
    {
        $id = (int) $id;

        $is_op = false;
        $parent = 0;
        $op = [];
        $posts = $this->postsInThreadByID($id, false);
        foreach ($posts as $post) {
            if ($post['parent'] == 0) {
                if ($post['id'] == $id) {
                    $is_op = true;
                }
                $op = $post;
                continue;
            } elseif ($post['id'] == $id) {
                $parent = $post['parent'];
            }

            $this->deletePostImages($post);
            $this->deleteReportsByPost($post['id']);
            $this->deletePostByID($post['id']);
        }
        if (!empty($op)) {
            $this->deletePostImages($op);
            $this->deleteReportsByPost($op['id']);
            $this->deletePostByID($op['id']);
        }

        if ($is_op) {
            if (is_file('res/' . $id . '.html')) {
                $this->removePage('res/' . $id . '.html');
            }
            if (is_file('res/' . $id . '.json')) {
                $this->removePage('res/' . $id . '.json');
            }
            return;
        }

        $current_bumped = 0;
        $new_bumped = 0;
        $posts = $this->postsInThreadByID($parent, false);
        foreach ($posts as $post) {
            if ($post['parent'] == 0) {
                $current_bumped = $post['bumped'];
            } elseif ($post['id'] == $id || strtolower($post['email']) == 'sage') {
                continue;
            }
            $new_bumped = $post['timestamp'];
        }
        if ($new_bumped >= $current_bumped) {
            return;
        }
        $this->updatePostBumped($parent, $new_bumped);
        $this->rebuildIndexes();
    }

    private function checkCaptcha(string $mode): void
    {
        if ($mode !== '') {
            Captcha::verify((string) ($this->request->form['captcha'] ?? ''));
        }
    }

    private function checkKeywords(string $text): array
    {
        $keywords = $this->allKeywords();
        foreach ($keywords as $keyword) {
            if (substr($keyword['text'], 0, 7) == 'regexp:') {
                if (preg_match(substr($keyword['text'], 7), $text)) {
                    $keyword['text'] = substr($keyword['text'], 7);
                    return $keyword;
                }
                continue;
            }

            if (stripos($text, $keyword['text']) !== false) {
                return $keyword;
            }
        }
        return [];
    }

    private function checkFlood(): void
    {
        $remaining = $this->config->delay - (time() - (int) ($_SESSION['last_post_time'] ?? 0));
        if ($remaining > 0) {
            $this->fancyDie('Please wait ' . $remaining . ' ' . $this->plural($remaining, 'second', 'seconds') . ' before posting again.');
        }
    }

    private function checkMessageSize(): void
    {
        if ($this->config->maxmessage > 0 && $this->length($this->request->form['message']) > $this->config->maxmessage) {
            $this->fancyDie(sprintf('Please shorten your message, or post it in multiple parts. Your message is %1$d characters long, and the maximum allowed is %2$d.', $this->length($this->request->form['message']), $this->config->maxmessage));
        }
    }

    private function checkAutoHide(array $post): void
    {
        if ($this->config->autohide <= 0) {
            return;
        }

        $reports = $this->reportsByPost($post['id']);
        if (count($reports) >= $this->config->autohide) {
            $this->approvePostByID($post['id'], 0);

            $parent_id = $post['parent'] == 0 ? $post['id'] : $post['parent'];
            $this->threadUpdated($parent_id);
        }
    }

    private function manageCheckLogIn(bool $requireKey): array
    {
        if ($this->config->managekey !== '') {
            $key = (string) ($this->request->query['manage'] ?? ($_SESSION['manage_key'] ?? ''));
            if (!hash_equals($this->config->managekey, $key)) {
                if ($requireKey) {
                    $this->fancyDie('Invalid management key.');
                }
                return [[], false, false];
            }
            $_SESSION['manage_key'] = $key;
        }
        $username = (string) ($this->request->form['username'] ?? '');
        $password = (string) ($this->request->form['managepassword'] ?? '');
        if ($username !== '' && $password !== '') {
            $this->checkCaptcha($this->config->managecaptcha);
            $account = $this->accountByUsername($username);
            if ($account === [] || !Passwords::verify($password, $account['password']) || $account['role'] === Role::Disabled->value) {
                $this->fancyDie('Invalid username or password.');
            }
            if (password_needs_rehash($account['password'], PASSWORD_DEFAULT)) {
                $account['password'] = Passwords::hash($password);
                $this->database->update($this->config->dbaccounts, $account['id'], ['password' => $account['password']]);
            }
            session_regenerate_id(true);
            $_SESSION['account_id'] = $account['id'];
            $_SESSION['account_fingerprint'] = hash('sha256', $account['password']);
            if (hash_equals(Passwords::INITIAL_PASSWORD, $password)) {
                $_SESSION['initial_password'] = $_SESSION['account_fingerprint'];
            } else {
                unset($_SESSION['initial_password']);
            }
            unset($this->request->form['username'], $this->request->form['managepassword']);
        }
        $account = $this->accountByID((int) ($_SESSION['account_id'] ?? 0));
        if ($account === [] || $account['role'] === Role::Disabled->value || !hash_equals(hash('sha256', $account['password']), (string) ($_SESSION['account_fingerprint'] ?? ''))) {
            return [[], false, false];
        }
        if ($account['lastactive'] < time() - 60) {
            $this->database->update($this->config->dbaccounts, $account['id'], ['lastactive' => time()]);
        }
        return [$account, true, Role::from($account['role'])->canAdminister()];
    }

    private function manageLogAction(string $action): void
    {

        $account_id = 0;
        if (isset($this->account['id'])) {
            $account_id = $this->account['id'];
        }
        $log = [
            'timestamp' => time(),
            'account' => $account_id,
            'message' => $action,
        ];
        $this->insertLog($log);
    }

    private function setParent(): int
    {
        $parent = $this->request->formInt('parent');
        if ($parent > 0 && !$this->threadExistsByID($parent)) {
            throw new BoardMessage('Invalid parent thread ID.');
        }
        return $parent;
    }

    private function getParent(array $post): int
    {
        if ($post['parent'] == 0) {
            return $post['id'];
        }
        return $post['parent'];
    }

    private function isStaffPost(): bool
    {
        if (isset($this->request->form['staffpost'])) {
            [$staff_account, $loggedin, $isadmin] = $this->manageCheckLogIn(false);
            return $loggedin;
        }

        return false;
    }

    private function validateFileUpload(): void
    {
        $error = $this->request->files['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_OK) {
            return;
        }
        throw new BoardMessage(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the size limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'The upload could not be saved.',
        });
    }

    private function checkDuplicateFile(string $hex, int $exclude = 0): void
    {
        $hexmatches = $this->postsByHex($hex);
        if (count($hexmatches) > 0) {
            foreach ($hexmatches as $hexmatch) {
                if ($hexmatch['id'] === $exclude) {
                    continue;
                }
                $this->fancyDie(sprintf('Duplicate file uploaded. That file has already been posted <a href="%s">here</a>.', 'res/' . (($hexmatch['parent'] == 0) ? $hexmatch['id'] : $hexmatch['parent']) . '.html#' . $hexmatch['id']));
            }
        }
    }

    private function thumbnailDimensions(array $post): array
    {
        if ($post['parent'] == 0) {
            $max_width = $this->config->maxwop;
            $max_height = $this->config->maxhop;
        } else {
            $max_width = $this->config->maxw;
            $max_height = $this->config->maxh;
        }
        return ($post['image_width'] > $max_width || $post['image_height'] > $max_height) ? [$max_width, $max_height] : [$post['image_width'], $post['image_height']];
    }

    private function videoDimensions(string $file_location): array
    {
        $output = Process::run(['ffprobe','-v','error','-select_streams','v:0','-show_entries','stream=width,height','-of','json',$file_location]);
        $stream = array_first(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['streams'] ?? []);
        return $stream === null ? [0,0] : [(int) $stream['width'], (int) $stream['height']];
    }

    private function videoDuration(string $file_location): float
    {
        return (float) Process::run(['ffprobe','-v','error','-show_entries','format=duration','-of','default=noprint_wrappers=1:nokey=1',$file_location]);
    }

    private function ffmpegThumbnail(string $file_location, string $thumb_location, int $new_w, int $new_h): bool
    {
        $position = (string) ($this->videoDuration($file_location) / 4);
        Process::run(['ffmpeg','-y','-v','error','-ss',$position,'-i',$file_location,'-frames:v','1','-vf',"scale=w=$new_w:h=$new_h:force_original_aspect_ratio=decrease",$thumb_location]);
        return is_file($thumb_location);
    }

    private function createThumbnail(string $file_location, string $thumb_location, int $new_w, int $new_h, bool $spoiler): bool
    {
        if ($this->config->thumbnail === 'imagemagick') {
            Process::run(['magick',$file_location,'-auto-orient','-thumbnail',"{$new_w}x{$new_h}",'-coalesce','-layers','OptimizeFrame',$thumb_location]);
            return is_file($thumb_location);
        }
        if ($this->config->thumbnail === 'ffmpeg') {
            return $this->ffmpegThumbnail($file_location, $thumb_location, $new_w, $new_h);
        }
        $source = imagecreatefromstring((string) file_get_contents($file_location));
        if (!$source) {
            throw new BoardMessage('The uploaded image could not be decoded.');
        }
        $scale = min($new_w / imagesx($source), $new_h / imagesy($source), 1.0);
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $thumbnail = imagecreatetruecolor($width, $height);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagefill($thumbnail, 0, 0, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        if ($spoiler) {
            for ($iteration = 0; $iteration < 40; $iteration++) {
                imagefilter($thumbnail, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }
        return match (strtolower(pathinfo($thumb_location, PATHINFO_EXTENSION))) {
            'jpg','jpeg' => imagejpeg($thumbnail, $thumb_location, 85),
            'png' => imagepng($thumbnail, $thumb_location),
            'gif' => imagegif($thumbnail, $thumb_location),
            'webp' => imagewebp($thumbnail, $thumb_location, 85),
            default => throw new BoardMessage('Unsupported thumbnail format.'),
        };
    }

    private function addVideoOverlay(string $thumb_location): void
    {
        if (!is_file('video_overlay.png')) {
            return;
        }
        $thumbnail = imagecreatefromstring((string) file_get_contents($thumb_location));
        $overlay = imagecreatefrompng('video_overlay.png');
        if (!$thumbnail || !$overlay) {
            throw new BoardMessage('The video overlay could not be decoded.');
        }
        imagealphablending($thumbnail, true);
        imagecopy($thumbnail, $overlay, (int) round((imagesx($thumbnail) - imagesx($overlay)) / 2), (int) round((imagesy($thumbnail) - imagesy($overlay)) / 2), 0, 0, imagesx($overlay), imagesy($overlay));
        if (strtolower(pathinfo($thumb_location, PATHINFO_EXTENSION)) === 'png') {
            imagepng($thumbnail, $thumb_location);
        } else {
            imagejpeg($thumbnail, $thumb_location, 85);
        }
    }

    private function strallpos(string $haystack, string $needle, int $offset = 0): array
    {
        $result = [];
        for ($i = $offset; $i < $this->length($haystack); $i++) {
            $pos = $this->position($haystack, $needle, $i);
            if ($pos !== false) {
                $offset = $pos;
                if ($offset >= $i) {
                    $i = $offset;
                    $result[] = $offset;
                }
            }
        }
        return $result;
    }

    private function httpUri(string $url): \Uri\Rfc3986\Uri
    {
        return RemoteHttp::uri($url);
    }

    private function fetchUrl(string $url): string
    {
        $limit = $this->config->maxkb > 0 ? max(1048576, min($this->config->maxkb, 16384) * 1024) : 16777216;
        return RemoteHttp::fetch($url, $limit);
    }

    private function attachRemote(array $post, string $url, bool $spoiler): array
    {
        $uri = $this->httpUri($url);
        [$service, $embed] = $this->getEmbed($url);
        $validEmbed = is_string($embed['html'] ?? null) && is_string($embed['title'] ?? null) && is_string($embed['thumbnail_url'] ?? null);
        if (!$validEmbed && !$this->config->uploadviaurl) {
            throw new BoardMessage('Invalid embed URL. Supported services: ' . $this->cleanString(implode(', ', array_keys($this->config->embeds))) . '.');
        }
        $data = $this->fetchUrl($validEmbed ? $embed['thumbnail_url'] : $url);
        if ($data === '') {
            throw new BoardMessage('The remote file could not be downloaded within the size and time limits.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'adelia-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create a temporary download.');
        }
        try {
            if (file_put_contents($temporary, $data) !== strlen($data)) {
                throw new BoardMessage('The remote download could not be saved completely.');
            }
            if (!$validEmbed) {
                return $this->attachFile($post, $temporary, basename($uri->getPath()), false, $spoiler);
            }
            $info = getimagesize($temporary);
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
                throw new BoardMessage('The remote thumbnail is invalid or too large.');
            }
            $extension = match ($info['mime']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => throw new BoardMessage('Unsupported remote thumbnail format.'),
            };
            $post['thumb'] = bin2hex(random_bytes(16)) . '.' . $extension;
            $thumbnail = 'thumb/' . $post['thumb'];
            $this->reserveMedia($thumbnail);
            $post['image_width'] = $info[0];
            $post['image_height'] = $info[1];
            [$width, $height] = $this->thumbnailDimensions($post);
            if (!$this->createThumbnail($temporary, $thumbnail, $width, $height, $spoiler)) {
                throw new BoardMessage('The remote thumbnail could not be created.');
            }
            $this->addVideoOverlay($thumbnail);
            $thumb = getimagesize($thumbnail);
            if ($thumb === false) {
                throw new BoardMessage('The remote thumbnail could not be created.');
            }
            $post['image_width'] = $info[0];
            $post['image_height'] = $info[1];
            $post['thumb_width'] = $thumb[0];
            $post['thumb_height'] = $thumb[1];
            $post['file_hex'] = $service;
            $post['file_original'] = $this->cleanString($embed['title']);
            $post['file'] = $embed['html'];
            return $post;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function isEmbed(string $file_hex): bool
    {
        // Stored provider names survive disabling new embeds in settings.
        // Uploaded files carry a hex digest (including earlier 32-character digests).
        return $file_hex !== '' && !preg_match('/^[a-f0-9]{32}(?:[a-f0-9]{32})?$/iD', $file_hex);
    }

    private function getEmbed(string $url): array
    {

        foreach ($this->config->embeds as $service => $service_url) {
            $service_url = str_ireplace('ADELIAEMBED', urlencode($url), $service_url);
            $data = $this->fetchUrl($service_url);
            if ($data != '') {
                $result = json_decode($data, true);
                if (is_array($result) && $result !== []) {
                    return [$service, $result];
                }
            }
        }

        return ['', []];
    }

    private function attachFile(array $post, string $filepath, string $filename, bool $uploaded, bool $spoiler): array
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new BoardMessage('The upload could not be read.');
        }
        $size = filesize($filepath);
        if ($size === false || $size === 0 || ($this->config->maxkb > 0 && $size > $this->config->maxkb * 1024)) {
            throw new BoardMessage('The file is empty or exceeds the upload size limit.');
        }
        $mime = new \finfo(FILEINFO_MIME_TYPE)->file($filepath);
        if ($mime === false || !isset($this->config->uploads[$mime])) {
            throw new BoardMessage($this->supportedFileTypes());
        }
        $digest = hash_file('sha256', $filepath);
        $this->checkDuplicateFile($digest, (int) ($post['id'] ?? 0));
        $extension = $this->config->uploads[$mime][0];
        if (!preg_match('/^[a-z0-9]+$/D', $extension)) {
            throw new \RuntimeException('Invalid upload extension in settings.');
        }
        $base = bin2hex(random_bytes(16));
        $target = 'src/' . $base . '.' . $extension;
        $this->reserveMedia($target);
        $thumbnail = '';
        try {
            if (!($uploaded ? move_uploaded_file($filepath, $target) : rename($filepath, $target))) {
                throw new BoardMessage('The uploaded file could not be saved.');
            }
            if ($this->config->stripmetadata) {
                $this->stripMetadata($target);
            }
            $post['file'] = basename($target);
            $post['file_original'] = $this->cleanString(mb_substr($filename, 0, 50));
            $post['file_hex'] = $digest;
            $post['file_size'] = (int) filesize($target);
            $post['file_size_formatted'] = $this->convertBytes($post['file_size']);
            if (str_starts_with($mime, 'image/')) {
                $dimensions = getimagesize($target);
                if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > 40_000_000) {
                    throw new BoardMessage('The image is invalid or too large to process.');
                }
                [$post['image_width'],$post['image_height']] = $dimensions;
                $thumbnail = 'thumb/' . $base . 's.' . $extension;
                $this->reserveMedia($thumbnail);
                [$width,$height] = $this->thumbnailDimensions($post);
                if (!$this->createThumbnail($target, $thumbnail, $width, $height, $spoiler)) {
                    throw new BoardMessage('The thumbnail could not be created.');
                }
            } elseif (isset($this->config->uploads[$mime][1])) {
                $preset = $this->config->uploads[$mime][1];
                $thumbnail = 'thumb/' . $base . 's.' . pathinfo($preset, PATHINFO_EXTENSION);
                $this->reserveMedia($thumbnail);
                if (!copy($preset, $thumbnail)) {
                    throw new BoardMessage('The thumbnail could not be copied.');
                }
            } elseif (str_starts_with($mime, 'video/') || in_array($mime, ['audio/webm','audio/mp4'], true)) {
                [$post['image_width'],$post['image_height']] = $this->videoDimensions($target);
                if ($post['image_width'] > 0 && $post['image_height'] > 0) {
                    $thumbnail = 'thumb/' . $base . 's.jpg';
                    $this->reserveMedia($thumbnail);
                    [$width,$height] = $this->thumbnailDimensions($post);
                    if (!$this->ffmpegThumbnail($target, $thumbnail, $width, $height)) {
                        throw new BoardMessage('The video thumbnail could not be generated.');
                    }
                    $this->addVideoOverlay($thumbnail);
                }
                $duration = (int) round($this->videoDuration($target));
                if ($duration > 0) {
                    $post['file_original'] = sprintf('%d:%02d, %s', intdiv($duration, 60), $duration % 60, $post['file_original']);
                }
            }
            if ($thumbnail !== '') {
                $dimensions = getimagesize($thumbnail);
                if ($dimensions === false) {
                    throw new BoardMessage('The thumbnail could not be read.');
                }
                $post['thumb'] = basename($thumbnail);
                [$post['thumb_width'],$post['thumb_height']] = $dimensions;
            }
            return $post;
        } catch (\Throwable $exception) {
            if (is_file($target)) {
                unlink($target);
            }
            if ($thumbnail !== '' && is_file($thumbnail)) {
                unlink($thumbnail);
            }
            if ($exception instanceof \ErrorException) {
                throw new BoardMessage('The uploaded media is invalid.');
            }
            throw $exception;
        }
    }

    private function stripMetadata(string $filename): void
    {
        Process::run(['exiftool','-overwrite_original','-all=',$filename]);
    }

    private function formatDate(int $timestamp): string
    {
        return new \DateTimeImmutable("@$timestamp")->setTimezone(new \DateTimeZone($this->config->timezone))->format($this->config->datefmt);
    }

}
