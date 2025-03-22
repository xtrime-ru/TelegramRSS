<?php

namespace TelegramRSS\RSS;


use TelegramRSS\Config;
use TelegramRSS\Logger;
use TelegramRSS\TgClient;

use function Amp\async;
use function Amp\Future\await;

class Messages
{
    private const TELEGRAM_URL = 'https://t.me/';

    private array $list = [];
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

    public function __construct(array $telegramResponse, TgClient $client, string $peer)
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

            $groupedMessages = [];
            $futures = [];
            foreach ($messages as $message) {
                $futures[$message['id']] = async($this->getMediaInfo(...), $message);
            }
            $messagesInfo = await($futures);

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
                        'timestamp' => $message['date'] ?? '',
                        'views' => $message['views'] ?? null,
                        'reactions' => null,
                    ];

                    if (!empty($message['reactions']['results'])) {
                        $parsedMessage['reactions'] = array_sum(array_column($message['reactions']['results'], 'count'));
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

    private function setTitle(array $parsedMessage, array $message): array
    {
        $descriptionText = strip_tags($parsedMessage['description']);

        if (mb_strlen($descriptionText) > 50) {
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

    private function getMessageUrl(array $message): string
    {
        if ($message['_'] === 'sponsoredMessage') {
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
        $info = $this->client->getMediaInfo($media);
        if (!empty($info['size']) && !empty($info['mime'])) {
            $info['url'] = $this->getMediaUrl($message, $info, false);
            return $info;
        }

        return null;
    }

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
     * @return array
     */
    public function get(): array
    {
        return $this->list;
    }

}