<?php

declare(strict_types=1);

namespace Adelia;

trait Workflow
{
    private bool $requestTransaction = false;

    public function run(): void
    {
        $lock = $this->lockDatabase();
        $this->completeMaintenance();
        $bufferLevel = ob_get_level();
        $reportedBefore = $_SESSION['reported_posts'] ?? [];
        $postedBefore = $_SESSION['last_post_time'] ?? 0;
        ob_start();
        $committed = false;
        try {
            $this->database->begin();
            $this->requestTransaction = true;
            $message = null;
            try {
                $this->handle();
            } catch (BoardMessage $exception) {
                if ($exception->status >= 400) {
                    throw $exception;
                }
                $message = $exception;
            }
            new MediaJournal()->prepare($this->mediaReferences());
            $this->database->commit();
            $committed = true;
            $this->refreshPasswordSession();
            $this->requestTransaction = false;
            try {
                $this->completeMaintenance();
            } catch (\Throwable $exception) {
                error_log('Adelia maintenance remains pending: ' . $exception->getMessage());
                throw new BoardMessage('The change was saved. Page rebuilding or media cleanup is pending; use the maintenance command before submitting it again.', status: 503);
            }
            $output = ob_get_clean();
            if ($message !== null) {
                throw $message;
            }
            echo $output;
        } catch (\Throwable $exception) {
            $this->pendingSessionAccount = null;
            $this->requestTransaction = false;
            $this->database->rollback();
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if (!$committed && !$exception instanceof CommitUncertain) {
                $_SESSION['reported_posts'] = $reportedBefore;
                $_SESSION['last_post_time'] = $postedBefore;
            }
            // Resolve pending uploads against committed records, never against the attempted edit.
            try {
                new MediaJournal()->recover($this->mediaReferences());
            } catch (\Throwable $cleanup) {
                error_log('Adelia upload cleanup remains pending: ' . $cleanup->getMessage());
            }
            if ($exception instanceof CommitUncertain) {
                error_log('Adelia save needs verification: ' . $exception->getMessage());
                throw new BoardMessage('The change may already have been saved. Check the board before submitting it again; the server log has the storage error.', status: 503);
            }
            throw $exception;
        }
    }

    public function maintain(): void
    {
        $lock = $this->lockDatabase();
        $this->database->begin();
        try {
            $this->database->schedule('rebuild');
            $this->database->commit();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
        $this->completeMaintenance();
    }

    private function mediaReferences(): array
    {
        $references = [];
        foreach ($this->database->rows($this->config->dbposts) as $post) {
            if ($post['file'] !== '' && !$this->isEmbed($post['file_hex'])) {
                $references['src/' . $post['file']] = true;
            }
            if ($post['thumb'] !== '') {
                $references['thumb/' . $post['thumb']] = true;
            }
        }
        return $references;
    }

    private function reserveMedia(string $path): void
    {
        new MediaJournal()->reserve($path);
    }

    private function completeMaintenance(): void
    {
        $jobs = $this->database->rows('adelia_jobs');
        foreach ($jobs as $job) {
            if (!in_array($job['kind'], ['rebuild', 'media'], true)) {
                throw new \RuntimeException('Unknown maintenance job.');
            }
            if ($job['kind'] === 'media') {
                MediaJournal::validatePath($job['path']);
            } elseif ($job['path'] !== '') {
                throw new \RuntimeException('Invalid page rebuild job.');
            }
        }
        if (array_any($jobs, static fn(array $job): bool => $job['kind'] === 'rebuild')) {
            $threads = $this->allThreads(false);
            $ids = array_fill_keys(array_column($threads, 'id'), true);
            foreach ($threads as $thread) {
                $this->rebuildThread($thread['id']);
            }
            foreach (glob('res/*') ?: [] as $path) {
                if (preg_match('~^res/([0-9]+)\.(html|json)$~D', $path, $match) && !isset($ids[(int) $match[1]])) {
                    $this->removePage($path);
                }
            }
            $this->rebuildIndexes();
        }
        $references = $this->mediaReferences();
        $journal = new MediaJournal();
        foreach ($jobs as $job) {
            if ($job['kind'] === 'media') {
                $journal->removeUnreferenced($job['path'], $references);
            }
        }
        $journal->recover($references);
        if ($jobs !== []) {
            $this->database->begin();
            try {
                foreach ($jobs as $job) {
                    $this->database->delete('adelia_jobs', Filter::equal('id', $job['id']));
                }
                $this->database->commit();
            } catch (\Throwable $exception) {
                $this->database->rollback();
                throw $exception;
            }
        }
    }

    private function removePage(string $path): void
    {
        if ($this->requestTransaction) {
            $this->database->schedule('rebuild');
            return;
        }
        if (!preg_match('~^(?:res/[0-9]+\.(?:html|json)|[0-9]+\.html|catalog\.(?:html|json)|threads\.json)$~D', $path)) {
            throw new \RuntimeException('Invalid generated page path.');
        }
        if (is_link($path) || is_link(dirname($path))) {
            throw new \RuntimeException('Cannot remove a redirected generated page.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Cannot remove an outdated generated page.');
        }
    }
}
