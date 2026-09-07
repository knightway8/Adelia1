<?php

declare(strict_types=1);

namespace Adelia {
    // Deterministic transport fixture: exercises the real URL policy, option wiring
    // and image processing without connecting to an external or private server.
    function dns_get_record(string $host, int $type): array
    {
        $GLOBALS['remote_dns_calls']++;
        return [['ip' => '1.1.1.1']];
    }

    function curl_init(string $url): \CurlHandle|false
    {
        $handle = \curl_init($url);
        if ($handle === false) {
            return false;
        }
        $GLOBALS['remote_requests'][spl_object_id($handle)] = ['url' => $url];
        return $handle;
    }

    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        $GLOBALS['remote_requests'][spl_object_id($handle)]['options'] = $options;
        return true;
    }

    function curl_exec(\CurlHandle $handle): bool
    {
        $request = $GLOBALS['remote_requests'][spl_object_id($handle)];
        $GLOBALS['remote_completed'][] = $request;
        $body = str_contains($request['url'], '/oembed') ? json_encode([
            'html' => '<iframe src="https://player.vimeo.com/video/123"></iframe>',
            'title' => 'Test media', 'thumbnail_url' => 'https://thumb.example/image.png',
        ], JSON_THROW_ON_ERROR) : $GLOBALS['remote_body'];
        $write = $request['options'][CURLOPT_WRITEFUNCTION];
        return $write($handle, $body) === strlen($body);
    }

    function curl_getinfo(\CurlHandle $handle, int $option): int
    {
        return $GLOBALS['remote_status'];
    }
}

namespace {
    use Adelia\Application;
    use Adelia\BoardMessage;
    use Adelia\Config;
    use Adelia\Database;
    use Adelia\RemoteHttp;
    use Adelia\Request;

    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    require dirname(__DIR__) . '/bootstrap.php';
    $GLOBALS['remote_dns_calls'] = 0;
    $GLOBALS['remote_requests'] = $GLOBALS['remote_completed'] = [];
    $GLOBALS['remote_status'] = 200;
    $passed = 0;
    $check = static function (bool $ok, string $description) use (&$passed): void {
        if (!$ok) {
            throw new RuntimeException('FAIL: ' . $description);
        }
        $passed++;
        echo 'PASS: ' . $description . PHP_EOL;
    };
    $image = imagecreatetruecolor(320, 180);
    imagefill($image, 0, 0, imagecolorallocate($image, 50, 100, 150));
    ob_start();
    imagepng($image);
    $GLOBALS['remote_body'] = ob_get_clean();
    $directory = sys_get_temp_dir() . '/adelia-remote-' . bin2hex(random_bytes(12));
    mkdir($directory, 0o700);
    mkdir($directory . '/thumb');
    chdir($directory);
    try {
        $config = new Config(...array_replace(require __DIR__ . '/fixtures/config.php', ['embeds' => ['Test provider' => 'https://embed.example/oembed?url=ADELIAEMBED']]));
        $db = new Database($config, create: true);
        $request = new Request([], [], [], ['PHP_SELF' => '/imgboard.php', 'REQUEST_METHOD' => 'GET']);
        $app = new Application($config, $db, $request);
        $post = new ReflectionMethod($app, 'newPost')->invoke($app);
        $post = new ReflectionMethod($app, 'attachRemote')->invoke($app, $post, 'https://video.example/watch', false);
        $check($post['image_width'] === 320 && $post['image_height'] === 180 && $post['thumb_width'] === 250 && $post['thumb_height'] === 141, 'Remote thumbnails use source dimensions rather than becoming one pixel');
        $check(is_file('thumb/' . $post['thumb']), 'The remote attachment produces a usable thumbnail');
        $check($GLOBALS['remote_dns_calls'] === 2 && count($GLOBALS['remote_completed']) === 2, 'Each provider/thumbnail request resolves exactly once');
        foreach ($GLOBALS['remote_completed'] as $item) {
            $options = $item['options'];
            $check(count($options[CURLOPT_RESOLVE]) === 1 && str_ends_with($options[CURLOPT_RESOLVE][0], ':443:1.1.1.1'), 'cURL connects through the previously validated address set');
            $check($options[CURLOPT_PROXY] === '' && $options[CURLOPT_FOLLOWLOCATION] === false, 'Environment proxies and automatic redirects cannot bypass the policy');
            $check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'HTTPS certificate and hostname checks remain enabled');
        }
        $before = count($GLOBALS['remote_completed']);
        try {
            $unused = RemoteHttp::fetch('http://127.0.0.1/', 1024);
            throw new RuntimeException('Private address accepted.');
        } catch (BoardMessage) {
            $check(count($GLOBALS['remote_completed']) === $before, 'A private target is rejected before starting a transfer');
        }
        $GLOBALS['remote_body'] = str_repeat('x', 20);
        $check(RemoteHttp::fetch('https://download.example/file', 10) === '', 'Oversized remote responses are rejected');
        $GLOBALS['remote_status'] = 302;
        $check(RemoteHttp::fetch('https://download.example/file', 100) === '', 'Redirect responses are not accepted as downloaded content');
        $post['id'] = $db->insert($config->dbposts, $post);
        $disabled = new Application($config->with(['embeds' => []]), $db, $request);
        $check(new ReflectionMethod($disabled, 'isEmbed')->invoke($disabled, 'Test provider'), 'Saved embeds remain identifiable after disabling new embeds');
        $references = new ReflectionMethod($disabled, 'mediaReferences')->invoke($disabled);
        $check(count($references) === 1 && isset($references['thumb/' . $post['thumb']]), 'Disabling providers never mistakes saved iframe markup for an upload path');
    } finally {
        chdir(dirname(__DIR__));
        foreach (glob($directory . '/thumb/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory . '/thumb');
        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isFile() && !$file->isLink()) {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
    echo $passed . ' remote-media checks passed.' . PHP_EOL;
}
