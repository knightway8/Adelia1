<?php

declare(strict_types=1);

namespace Adelia;

final class Config
{
    public function __construct(
        public readonly string $timezone,
        public readonly string $datefmt,
        public readonly string $board,
        public readonly string $boarddesc,
        public readonly string $boardtitle,
        public readonly bool $alwaysnoko,
        public readonly string $captcha,
        public readonly string $replycaptcha,
        public readonly string $reportcaptcha,
        public readonly string $managecaptcha,
        public readonly bool $report,
        public readonly int $autohide,
        public readonly string $reqmod,
        public readonly bool $updatebumped,
        public readonly bool $spoilertext,
        public readonly bool $spoilerimage,
        public readonly int $autorefresh,
        public readonly string $disallowthreads,
        public readonly string $disallowreplies,
        public readonly string $index,
        public readonly string $logo,
        public readonly int $threadsperpage,
        public readonly int $previewreplies,
        public readonly int $truncate,
        public readonly int $wordbreak,
        public readonly int $expandwidth,
        public readonly bool $backlinks,
        public readonly bool $catalog,
        public readonly bool $json,
        public readonly string $defaultstyle,
        public readonly int $delay,
        public readonly int $maxthreads,
        public readonly int $maxreplies,
        public readonly int $maxname,
        public readonly int $maxemail,
        public readonly int $maxsubject,
        public readonly int $maxmessage,
        public readonly int $maxkb,
        public readonly string $maxkbdesc,
        public readonly string $thumbnail,
        public readonly bool $uploadviaurl,
        public readonly bool $stripmetadata,
        public readonly bool $nofileok,
        public readonly int $maxwop,
        public readonly int $maxhop,
        public readonly int $maxw,
        public readonly int $maxh,
        public readonly string $tripseed,
        public readonly string $managekey,
        public readonly string $dbaccounts,
        public readonly string $dbkeywords,
        public readonly string $dblogs,
        public readonly string $dbposts,
        public readonly string $dbreports,
        public readonly string $dbpath,
        public readonly string $dbdriver,
        public readonly array $hidefieldsop,
        public readonly array $hidefields,
        public readonly array $anonymous,
        public readonly array $capcodes,
        public readonly array $stylesheets,
        public readonly array $uploads,
        public readonly array $embeds,
    ) {}

    public string $title {
        get => $this->boardtitle !== '' ? $this->boardtitle : ($this->boarddesc ?: 'Adelia');
    }

    /** @param array<string, mixed> $changes */
    #[\NoDiscard]
    public function with(array $changes): self
    {
        return clone($this, $changes);
    }

    #[\NoDiscard]
    public static function load(string $filename): self
    {
        $values = require $filename;
        if (!is_array($values)) {
            throw new \InvalidArgumentException('settings.php must return a configuration array.');
        }
        $config = new self(...$values);
        if ($config->tripseed === '' || !preg_match('/^[a-zA-Z0-9]+$/D', $config->board)) {
            throw new \InvalidArgumentException('Set a random tripseed and an alphanumeric board identifier.');
        }
        foreach ([$config->captcha, $config->replycaptcha, $config->reportcaptcha, $config->managecaptcha] as $mode) {
            if (!in_array($mode, ['', 'simple'], true)) {
                throw new \InvalidArgumentException('CAPTCHA modes must be blank or simple.');
            }
        }
        if (!in_array($config->dbdriver, ['sqlite', 'flatfile'], true)) {
            throw new \InvalidArgumentException('Use sqlite or flatfile storage.');
        }
        if ($config->threadsperpage < 1 || $config->maxw < 1 || $config->maxh < 1 || $config->maxwop < 1 || $config->maxhop < 1) {
            throw new \InvalidArgumentException('Page and thumbnail dimensions must be positive.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+\.html$/D', $config->index)) {
            throw new \InvalidArgumentException('The index must be an HTML filename.');
        }
        if ($config->anonymous === [] || array_any($config->anonymous, static fn(mixed $name): bool => !is_string($name))) {
            throw new \InvalidArgumentException('anonymous must contain at least one display name.');
        }
        if (!in_array($config->thumbnail, ['gd', 'imagemagick', 'ffmpeg'], true)) {
            throw new \InvalidArgumentException('thumbnail must be gd, imagemagick or ffmpeg.');
        }
        new \DateTimeZone($config->timezone);
        return $config;
    }
}
