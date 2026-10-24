<?php

namespace TelegramRSS\RSS;


use Amp\Cancellation;
use Amp\CancelledException;
use TelegramRSS\Config;
use TelegramRSS\Logger;
use TelegramRSS\TgClient;

use function Amp\async;
use function Amp\Future\await;

/**
 * @phpstan-import-type History from TgClient
 * @phpstan-type FeedItem array{url: string, title: string, description: string, media: list<array<string, mixed>|null>, preview: list<array{href: string|null, image: string|null}>, timestamp: int, views: int|null, forwards: int|null, reactions: array<string, int>|null, source?: array{url: string, title: string}, webpage?: array<string, mixed>}
 */
class Messages
{
    private const TELEGRAM_URL = 'https://t.me/';

    /** @var list<FeedItem> */
    private array $list = [];
    /** @var History */
    private array $telegramResponse;
    private string $channelUrl;
    private string $username;
    private TgClient $client;

    private const MEDIA_TYPES = [
        'messageMediaDocument',
        'messageMediaPhoto',
        'messageMediaVideo',
        'messageMediaWebPage',
    ];

    /**
     * @param History $telegramResponse
     */
    public function __construct(array $telegramResponse, TgClient $client, string $peer, private readonly ?Cancellation $cancellation = null)
    {
        $this->telegramResponse = $telegramResponse;
        $this->client = $client;
        $this->username = $peer;
        $this->channelUrl = static::TELEGRAM_URL . $this->username . '/';
        $this->parseMessages();
    }

    private function parseMessages(): self
    {
        if ($messages = $this->telegramResponse['messages'] ?? []) {
            $messages = array_merge($this->telegramResponse['sponsored_messages'] ?? [], $messages);

            /** @var list<array<string, mixed>> $groupedMessages */
            $groupedMessages = [];
            /** @var array<int, \Amp\Future<array<string, mixed>|null>> $futures */
            $futures = [];
            foreach ($messages as $message) {
                $futures[$message['id']] = async($this->getMediaInfo(...), $message);
            }
            $messagesInfo = await($futures, $this->cancellation);

            foreach ($messages as $key => $message) {
                if (
                    !empty($message['grouped_id']) &&
                    !empty($messages[$key + 1]['grouped_id']) &&
                    $messages[$key + 1]['grouped_id'] === $message['grouped_id']
                ) {
                    $groupedMessages[] = $message;
                    continue;
                }
                $description = $message['message'] ?? '';
                if ($description || $this->hasMedia($message)) {
                    $info = $messagesInfo[$message['id']];
                    $parsedMessage = [
                        'url' => $this->getMessageUrl($message),
                        'title' => '',
                        'description' => $description,
                        'media' => [$info],
                        'preview' => [
                            [
                                'href' => $info['url'] ?? null,
                                'image' => $this->getMediaUrl($message, $info, true),
                            ],
                        ],
                        'timestamp' => $message['edit_date'] ?? $message['date'] ?? 0,
                        'views' => $message['views'] ?? null,
                        'forwards' => $message['forwards'] ?? null,
                        'reactions' => null,
                    ];

                    if (!empty($message['fwd_from']['from_id']) && !empty($message['fwd_from']['channel_post'])) {
                        $info = [];
                        try {
                            $info = $this->client->getInfo($message['fwd_from']['from_id'], $this->username, cancellation: $this->cancellation);
                            $info = $info[array_key_first($info)] ?? [];
                        } catch (CancelledException $exception) {
                            throw $exception;
                        } catch (\Throwable) {
                        }


                        $id = (int)\str_replace(['-100', '-'], '', $message['fwd_from']['from_id']);

                        if (!empty($info['username'])) {
                            $url = "https://t.me/{$info['username']}/{$message['fwd_from']['channel_post']}";
                        } else {
                            $url = "https://t.me/$id/{$message['fwd_from']['channel_post']}";
                        }

                        $parsedMessage['source'] = [
                            'url' => $url,
                            'title' => $info['title'] ?? '',
                        ];

                    }

                    if (!empty($message['reactions']['results'])) {
                        foreach ($message['reactions']['results'] as $reaction) {
                            if ($reaction['_'] === 'reactionCount' && isset($reaction['reaction']['emoticon']) && isset($reaction['count'])) {
                                $parsedMessage['reactions'][$reaction['reaction']['emoticon']] = $reaction['count'];
                            }
                        }
                    }

                    if ($groupedMessages = array_reverse($groupedMessages)) {
                        foreach ($groupedMessages as $media) {
                            $info = $messagesInfo[$media['id']];
                            $preview = [
                                'href' => $info['url'] ?? null,
                                'image' => $this->getMediaUrl($media, $info, true),
                            ];
                            if ($preview['href'] && $preview['image']) {
                                $parsedMessage['preview'][] = $preview;
                                $parsedMessage['media'][] = $info;
                            }
                        }
                        /** @var list<array<string, mixed>> $groupedMessages */
            $groupedMessages = [];
                    }

                    if (!empty($message['media']['webpage'])) {
                        $parsedMessage['webpage'] = [
                            'site_name' => $message['media']['webpage']['site_name'] ?? null,
                            'title' => $message['media']['webpage']['title'] ?? '',
                            'description' => $message['media']['webpage']['description'] ?? null,
                            'preview' => reset($parsedMessage['preview'])['image'] ?? null,
                            'url' => $message['media']['webpage']['url'] ?? null,
                        ];
                        $parsedMessage['preview'] = [];
                    }

                    $parsedMessage = $this->setTitle($parsedMessage, $message);

                    $this->list[] = $parsedMessage;
                }
            }
        }
        return $this;
    }

    /**
     * @param FeedItem $parsedMessage
     * @param array<string, mixed> $message
     * @return FeedItem
     */
    private function setTitle(array $parsedMessage, array $message): array
    {
        $descriptionText = trim(strip_tags(preg_replace('/<br\b[^>]*>/i', "\n", $parsedMessage['description'])));
        $lines = preg_split('/\R/u', $descriptionText, 2);

        if (count($lines) > 1) {
            $parsedMessage['title'] = trim($lines[0]);
        } elseif (mb_strlen($descriptionText) > 50) {
            //Get first sentence from decription
            preg_match('/(?<sentence>.*?\b\W*[.?!;\n])(\W|$)/ui', $descriptionText, $matches);

            $parsedMessage['title'] = $matches['sentence'] ?? mb_strimwidth($descriptionText, 0, 100, ' [...]');
            $parsedMessage['title'] = trim($parsedMessage['title']);
        }

        if (!empty($message['media'])) {
            $mime = $message['media']['document']['mime_type'] ?? '';
            if (str_contains($mime, 'video')) {
                $parsedMessage['title'] = '[Video] ' . $parsedMessage['title'];
            } elseif ($message['media']['_'] === 'messageMediaPhoto') {
                $parsedMessage['title'] = '[Photo] ' . $parsedMessage['title'];
            } else {
                $parsedMessage['title'] = '[Media] ' . $parsedMessage['title'];
            }
        }

        if ($message['_'] === 'sponsoredMessage') {
            $parsedMessage['title'] = '[Sponsored] ' . $parsedMessage['title'];
        }

        $parsedMessage['title'] = trim($parsedMessage['title']);

        //Get first 100 symbols from description
        if (mb_strlen($parsedMessage['title']) > 100) {
            $parsedMessage['title'] = mb_strimwidth($parsedMessage['title'], 0, 100, ' [...]');
        }

        return $parsedMessage;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function getMessageUrl(array $message): string
    {
        if ($message['_'] === 'sponsoredMessage') {
            if (!empty($message['url'])) {
                return $message['url'];
            }
            if (!empty($message['webpage']['url'])) {
                return $message['webpage']['url'];
            }
            if (!empty($message['peer'])) {
                $postId = !empty($message['channel_post']) ? '/' . $message['channel_post'] : '';
                $startParam = !empty($message['start_param']) ? '/?start=' . $message['start_param'] : '';
                $peer = $message['peer']['bot_api_id'];
                foreach ($message['peer'] as $property) {
                    if (!empty($property['username'])) {
                        $peer = $property['username'];
                        break;
                    }
                }
                return self::TELEGRAM_URL . $peer . $postId . $startParam;
            }

            Logger::getInstance()->notice('Can get url for message', [
                'message' => $message,
            ]);
            return md5(json_encode($message));
        } else {
            return $this->channelUrl . $message['id'];
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return array{size?: int, mime?: string, name?: string, ext?: string, url?: string, ...}|null
     */
    private function getMediaInfo(array $message): ?array
    {
        if (!$this->hasMedia($message)) {
            return null;
        }
        if (!empty($message['media']['webpage']['photo'])) {
            $media = $message['media']['webpage']['photo'];
        } else {
            $media = $message['media'];
        }
        $info = $this->client->getMediaInfo($media, $this->username, $this->cancellation);
        if (!empty($info['size']) && !empty($info['mime'])) {
            $info['url'] = $this->getMediaUrl($message, $info, false);
            return $info;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function hasMedia(array $message): bool
    {
        if (
            empty($message['media'])
            || !in_array($message['media']['_'], static::MEDIA_TYPES, true)
            ||
            (
                !empty($message['media']['webpage']) &&
                empty($message['media']['webpage']['photo'])
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed>|null $info
     */
    private function getMediaUrl(array $message, ?array $info, bool $preview = false): ?string
    {
        if (!$this->hasMedia($message)) {
            return null;
        }

        $url = Config::getInstance()->get('url');
        $url .= "/media/{$this->username}/{$message['id']}";

        if ($preview) {
            $url .= '/preview/thumb.jpeg';
        } elseif (!empty($info['name']) && !empty($info['ext'])) {
            $filename = mb_substr(trim($info['name']), 0, 50);
            $filename = urlencode("{$filename}{$info['ext']}");
            $url .= "/$filename";
        }
        return $url;
    }

    /**
     * @return list<FeedItem>
     */
    public function get(): array
    {
        return $this->list;
    }

}
