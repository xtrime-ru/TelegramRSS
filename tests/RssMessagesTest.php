<?php

namespace TelegramRSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TelegramRSS\RSS\Messages;
use TelegramRSS\TgClient;

final class RssMessagesTest extends TestCase
{
    #[DataProvider('titleProvider')]
    public function testTitleUsesFirstLine(string $description, string $expectedTitle): void
    {
        $messages = new Messages(['messages' => [[
            '_' => 'message',
            'id' => 1,
            'message' => $description,
        ]]], $this->createStub(TgClient::class), 'test');

        $item = $messages->get()[0];
        self::assertSame($expectedTitle, $item['title']);
        self::assertSame($description, $item['description']);
    }

    public static function titleProvider(): iterable
    {
        $title = 'Выкса, Кулебаки, Навашино';
        $body = 'Возможно еще появление БПЛА от Рязанской области!';
        yield 'reported HTML breaks' => ["$title<br><br>$body", $title];
        yield 'self closing break' => ["$title<br/>$body", $title];
        yield 'uppercase break' => ["$title<BR />$body", $title];
        yield 'LF' => ["$title\n\n$body", $title];
        yield 'CRLF' => ["$title\r\n$body", $title];
        yield 'CR' => ["$title\r$body", $title];
        yield 'short message' => ["Заголовок\nТекст", 'Заголовок'];
        yield 'leading empty lines and HTML' => ["<br/>\n<b>$title</b><br>$body", $title];
        yield 'punctuation within first line' => ["Стоп! Важное сообщение.\n$body", 'Стоп! Важное сообщение.'];
        yield 'long first line' => [str_repeat('А', 110) . "\n$body", str_repeat('А', 94) . ' [...]'];
        yield 'single line short message' => ['Короткий текст', ''];
        yield 'single line sentence' => ['Первое предложение! ' . $body, 'Первое предложение!'];
    }
}
