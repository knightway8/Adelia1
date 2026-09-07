<?php

declare(strict_types=1);

namespace Adelia;

final class Captcha
{
    private const int WIDTH = 175;
    private const int HEIGHT = 55;
    private const int LIFETIME = 600;

    public static function verify(string $answer): void
    {
        $expected = (string) ($_SESSION['adeliacaptcha'] ?? '');
        $issued = (int) ($_SESSION['adeliacaptcha_time'] ?? 0);
        unset($_SESSION['adeliacaptcha'], $_SESSION['adeliacaptcha_time']);
        $answer = $answer |> trim(...) |> strtolower(...);
        if ($answer === '') {
            throw new BoardMessage('Please enter the CAPTCHA text.');
        }
        if ($expected === '' || time() - $issued > self::LIFETIME || !hash_equals($expected, $answer)) {
            throw new BoardMessage('Incorrect or expired CAPTCHA text. Click the image and try again.');
        }
    }

    public static function render(string $fontDirectory): void
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $answer = '';
        for ($index = 0; $index < 5; $index++) {
            $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $_SESSION['adeliacaptcha'] = $answer;
        $_SESSION['adeliacaptcha_time'] = time();
        session_write_close();

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $background = imagecolorallocate($image, 254, 254, 254);
        imagefill($image, 0, 0, $background);
        for ($index = 0; $index < 40; $index++) {
            $color = imagecolorallocate($image, random_int(150, 225), random_int(150, 225), random_int(150, 225));
            imagesetpixel($image, random_int(0, self::WIDTH - 1), random_int(0, self::HEIGHT - 1), $color);
        }
        $font = $fontDirectory . '/roboto_bold.ttf';
        $foreground = imagecolorallocate($image, 24, 94, 128);
        for ($index = 0; $index < strlen($answer); $index++) {
            imagettftext($image, 25, random_int(-13, 13), 9 + $index * 31, random_int(35, 43), $foreground, $font, $answer[$index]);
        }
        $line = imagecolorallocate($image, 68, 125, 133);
        imageline($image, 0, random_int(16, 40), self::WIDTH, random_int(16, 40), $line);
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        imagepng($image);
    }
}
