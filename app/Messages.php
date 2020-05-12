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
        'messageMediaWebPage',
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
            $groupedMessages = [];
            foreach ($messages as $key => $message) {
                if (
                    !empty($message->grouped_id) &&
                    !empty($messages[$key + 1]->grouped_id) &&
                    $messages[$key + 1]->grouped_id === $message->grouped_id
                ) {
                    $groupedMessages[] = $message;
                    continue;
                }
                $description = $message->message ?? '';
                if ($description || $this->hasMedia($message)) {
                    $parsedMessage = [
                        'url' => $this->getMessageUrl($message->id),
                        'title' => null,
                        'description' => $description,
                        'media' => $this->getMediaInfo($message),
                        'preview' => [$this->getMediaUrl($message)],
                        'timestamp' => $message->date ?? '',
                    ];

                    if ($groupedMessages = array_reverse($groupedMessages)) {
                        foreach ($groupedMessages as $media) {
                            $parsedMessage['preview'][] = $this->getMediaUrl($media);
                        }
                        $groupedMessages = [];
                    }
                    $parsedMessage['preview'] = array_filter($parsedMessage['preview']);

                    $mime = $message->media->document->mime_type ?? '';
                    if (strpos($mime, 'video') !== false) {
                        $parsedMessage['title'] = '[Video]';
                    }

                    if (!empty($message->media->webpage)) {
                        $parsedMessage['webpage'] = [
                            'site_name' => $message->media->webpage->site_name ?? '',
                            'title' => $message->media->webpage->title ?? '',
                            'description' => $message->media->webpage->description ?? '',
                            'preview' => reset($parsedMessage['preview']) ?: [],
                            'url' => $message->media->webpage->url ?? '',
                        ];
                        $parsedMessage['preview'] = [];
                    }

                    $this->list[$message->id] = $parsedMessage;
                }
            }
        }
        return $this;
    }

    /**
     * @param string $messageId
     * @return string|null
     */
    private function getMessageUrl($messageId = '') {
        if (!$this->channelUrl) {
            $this->username = $this->telegramResponse->chats[0]->username ?? '';
            if (!$this->username) {
                return null;
            }
            $this->channelUrl = static::TELEGRAM_URL . $this->username . '/';
        }
        return $this->channelUrl . $messageId;
    }

    private function getMediaInfo($message) {
        if (!$this->hasMedia($message)) {
            return [];
        }
        if (!empty($message->media->webpage->photo)) {
            $media = $message->media->webpage->photo;
        } else {
            $media = $message->media;
        }
        $info = $this->client->getMediaInfo($media);
        if (!empty($info->size) && !empty($info->mime)) {
            return [
                'url' => $this->getMediaUrl($message),
                'mime' => $info->mime,
                'size' => $info->size,
            ];
        }

        return [];
    }

    private function hasMedia($message) {
        if (
            empty($message->media) ||
            !in_array($message->media->{'_'}, static::MEDIA_TYPES, true) ||
            (
                property_exists($message->media, 'photo') &&
                empty($message->media->photo)
            ) ||
            (
                !empty($message->media->webpage) &&
                empty($message->media->webpage->photo)
            )
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