<?php

namespace TelegramRSS;


class RSS {
	private $rss;
	private const TITLE = 'ICA telegram feed';
	private const DESCRIPTION = 'Публичный канал Telegram в формате RSS';
	private const LINK = 'https://i-c-a.su';

	public function __construct($messages)
	{
		$this->createRss($messages);
	}

	private function createRss($messages){
		$latestDate = 0;
		foreach ($messages as $message) {
			if ($message['timestamp'] > $latestDate) $latestDate = $message['timestamp'];
		}
		$lastBuildDate = date(DATE_RSS, $latestDate);
		//Create the RSS feed
		$xmlFeed = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"></rss>');
		$xmlFeed->addChild('channel');
		//Required elements
		$xmlFeed->channel->addChild('title', static::TITLE);
		$xmlFeed->channel->addChild('link', static::LINK);
		$xmlFeed->channel->addChild('description', static::DESCRIPTION);
		$xmlFeed->channel->addChild('pubDate', $lastBuildDate);
		$xmlFeed->channel->addChild('lastBuildDate', $lastBuildDate);
		//Optional elements
		if (isset($messages['description'])) $xmlFeed->channel->description = htmlspecialchars($messages['description'], ENT_XML1);
		if (isset($messages['home_page_url'])) $xmlFeed->channel->link = $messages['home_page_url'];
		//Items
		foreach ($messages as $item) {
			$newItem = $xmlFeed->channel->addChild('item');
			//Standard stuff
			if (isset($item['id'])) $newItem->addChild('guid', $item['id']);
			if (isset($item['title'])) $newItem->addChild('title', htmlspecialchars($item['title'], ENT_XML1));
			if (isset($item['description']) || !empty($item['image'])) {
				$description = !empty($item['image']) ? '<img src="'.$item['image'].'"/>' : '';
				$description .= $item['description'];
				$newItem->addChild('description', htmlspecialchars($description, ENT_XML1));
				unset($description);
			}
			if (isset($item['timestamp'])) $newItem->addChild('pubDate', date(DATE_RSS, $item['timestamp']));
			if (isset($item['url'])) {
				$newItem->addChild('link', $item['url']);
				$newItem->addChild('guid', $item['url']);
			}
			if (isset($item['image'])) {
				$enclosure = $newItem->addChild('enclosure');
				$enclosure['url'] = $item['image'];
				//$enclosure['type'] = $attachment['mime_type'];
				//$enclosure['length'] = $attachment['size_in_bytes'];
				unset($enclosure);
			}
		}
		//Make the output pretty
		$dom = new \DOMDocument("1.0");
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xmlFeed->asXML());
		$this->rss = $dom->saveXML();
		return $this;
	}

	public function get(){
		return $this->rss;
	}
}