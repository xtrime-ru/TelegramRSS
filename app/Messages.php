<?php

namespace TelegramRSS;


class Messages
{
	const TELEGRAM_URL = 'https://t.me/';

	private $list;
	private $telegramResponse;
	private $channelUrl;

	public function __construct($telegramResponse)
	{

		$this->telegramResponse = $telegramResponse;
		$this->parseMessages();
	}

	private function parseMessages():self {
		if (!empty($this->telegramResponse->messages)){
			foreach ($this->telegramResponse->messages as $message) {
				$parsedMessage = [
					'url'          => $this->getChannelUrl() . $message->id,
					'title'        => NULL,
					'description'  => $message->message ?? '',
					'image'        => $message->media->photo->id ?? null,
					'timestamp'    => $message->date ?? ''
				];

				$mime = $message->media->document->mime_type ?? '';
				if (strpos($mime,'video')!==false) {
					$parsedMessage['title'] = '[Видео]';
				}
				$this->list[$message->id] = $parsedMessage;
			}
		}
		return $this;
	}

	private function getChannelUrl(){
		if (!$this->channelUrl) {
			$username = $this->telegramResponse->chats[0]->username ?? '';
			if (!$username) {
				throw new \UnexpectedValueException('No channel username');
			}
			$this->channelUrl = static::TELEGRAM_URL . $username . '/';
		}
		return $this->channelUrl;
	}

	public function get(){
		return $this->list;
	}

}