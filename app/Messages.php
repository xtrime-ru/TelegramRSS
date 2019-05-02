<?php

namespace TelegramRSS;


class Messages {
    private const TELEGRAM_URL = 'https://t.me/';

    private $list = [];
    private $telegramResponse;
    private $channelUrl;
    private $username;
    private $client;

    private const MEDIA_TYPES = [
        'messageMediaDocument',
        'messageMediaPhoto',
        'messageMediaVideo',
        'messageMediaDocument',
    ];

    /**
     * Messages constructor.
     * @param $telegramResponse
     * @param Client $client
     */
    public function __construct($telegramResponse, Client $client) {
        $this->telegramResponse = $telegramResponse;
        $this->client = $client;
        $this->parseMessages();
    }

    private function parseMessages(): self {
        if ($messages = $this->telegramResponse->messages ?? []) {
            $size = count($messages);
            $chan = new \Co\Channel($size);
            foreach ($messages as $message) {
                go(
                    function () use ($chan, $message) {
                        if ($channelUrl = $this->getChannelUrl()) {
                            $parsedMessage = [
                                'url' => $this->getChannelUrl() . $message->id,
                                'title' => null,
                                'description' => $message->message ?? '',
                                'media' => $this->getMediaInfo($message),
                                'preview' => $this->hasMedia($message) ? $this->getMediaUrl($message) . '/preview' : '',
                                'timestamp' => $message->date ?? '',
                            ];
                        } elseif ($description = $message->message ?? '') {
                            $parsedMessage = [
                                'url' => null,
                                'title' => null,
                                'description' => $description,
                                'media' => null,
                                'preview' => null,
                                'timestamp' => $message->date ?? '',
                            ];
                        } else {
                            $parsedMessage = [];
                        }

                        $mime = $message->media->document->mime_type ?? '';
                        if (strpos($mime, 'video') !== false) {
                            $parsedMessage['title'] = '[Видео]';
                        }
                        $chan->push([$message->id => $parsedMessage]);
                    }
                );
            }

            for ($i = 0; $i < $size; $i++) {
                $element = $chan->pop();
                $key = array_key_first($element);
                $this->list[$key] = $element[$key];
            }
            $this->list = array_filter($this->list);
            krsort($this->list);
        }
        return $this;
    }

    private function getChannelUrl() {
        if (!$this->channelUrl) {
            $this->username = $this->telegramResponse->chats[0]->username ?? '';
            if (!$this->username) {
                return '';
            }
            $this->channelUrl = static::TELEGRAM_URL . $this->username . '/';
        }
        return $this->channelUrl;
    }

    private function getMediaInfo($message) {
        if (!$this->hasMedia($message)) {
            return [];
        }
        $info = $this->client->getMediaInfo($message);
        if (!empty($info->size) && !empty($info->mime)) {
            return [
                'url' => $this->getMediaUrl($message),
                'mime' => $info->mime,
                'size' => $info->size,
            ];
        }
    }

    private function hasMedia($message) {
        if (
            empty($message->media) ||
            !in_array($message->media->{'_'}, static::MEDIA_TYPES, true)
        ) {
            return false;
        }

        return true;
    }

    private function getMediaUrl($message) {
        if (!$this->hasMedia($message)) {
            return false;
        }
        $url = Config::getInstance()->get('url');

        return "{$url}/media/{$this->username}/{$message->id}";
    }

    /**
     * @return array
     */
    public function get(): array {
        return $this->list;
    }

}