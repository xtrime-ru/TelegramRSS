<?php

namespace TelegramRSS;


class RSS {
    private $rss;
    private const TITLE = 'ICA telegram feed';
    private const DESCRIPTION = 'Публичный канал Telegram в формате RSS';
    private const LINK = 'https://i-c-a.su';

    public function __construct($messages, $selfLink)
    {
        $this->createRss($messages, $selfLink);
    }

    /**
     * @param $messages
     * @return $this
     */
    private function createRss($messages, $selfLink){
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

        $atomLink = $xmlFeed->channel->addChild('atom:atom:link');
        $atomLink->addAttribute('rel', 'self');
        $atomLink->addAttribute('type','application/rss+xml');
        $atomLink->addAttribute('href', $selfLink);

        //Optional elements
        //if (!empty($messages['description'])) $xmlFeed->channel->description = htmlspecialchars($messages['description'], ENT_XML1);
        //if (!empty($messages['home_page_url'])) $xmlFeed->channel->link = $messages['home_page_url'];
        //Items
        foreach ($messages as $item) {
            $newItem = $xmlFeed->channel->addChild('item');
            //Standard stuff
            if (!empty($item['id'])) $newItem->addChild('guid', $item['id']);
            if (!empty($item['title'])) $newItem->addChild('title', htmlspecialchars($item['title'], ENT_XML1));
            if (!empty($item['description']) || !empty($item['preview'])) {
                $description = '';
                if ($item['preview']) {
                    $description .= '<img src="'.$item['preview'].'" style="max-width:100%"/>';
                    $description .= '<br/><br/>';
                }
                $description .= $item['description'];
                $newItem->addChild('description', htmlspecialchars($description, ENT_XML1));
            }
            if (!empty($item['timestamp'])) $newItem->addChild('pubDate', date(DATE_RSS, $item['timestamp']));
            if (!empty($item['url'])) {
                $newItem->addChild('link', $item['url']);
                $newItem->addChild('guid', $item['url']);
            }
            if (!empty($item['media'])) {
                $enclosure = $newItem->addChild('enclosure');
                $enclosure['url'] = $item['media']['url'];
                $enclosure['type'] = $item['media']['mime'];
                $enclosure['length'] = $item['media']['size'];
                unset($enclosure);
            }
        }

        //Make the output pretty
        $dom = new \DOMDocument('1.0');
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