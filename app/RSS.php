<?php

namespace TelegramRSS;


class RSS {
    private string $title = 'Channel';
    private const DESCRIPTION = 'Telegram public channel RSS feed';
    private const LINK = 'https://tg.i-c-a.su';

    private string $rss;

    /**
     * RSS constructor.
     * @param array $messages
     * @param string $peer
     */
    public function __construct(array $messages, string $peer) {
        $url = Config::getInstance()->get('url');
        $selfLink = "$url/rss/{$peer}";

        $this->title .= ": $peer";

        $this->createRss($messages, $selfLink);
    }

    /**
     * @param array $messages
     * @param string $selfLink
     * @return $this
     */
    private function createRss(array $messages, string $selfLink): self {
        $latestDate = 0;
        foreach ($messages as $message) {
            if ($message['timestamp'] > $latestDate) $latestDate = $message['timestamp'];
        }
        $lastBuildDate = date(DATE_RSS, $latestDate);
        //Create the RSS feed
        $xmlFeed = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"></rss>'
        );

        $xmlFeed->addChild('channel');
        //Required elements
        $xmlFeed->channel->addChild('title', $this->title);
        $xmlFeed->channel->addChild('link', static::LINK);
        $xmlFeed->channel->addChild('description', static::DESCRIPTION);
        $xmlFeed->channel->addChild('pubDate', $lastBuildDate);
        $xmlFeed->channel->addChild('lastBuildDate', $lastBuildDate);

        $atomLink = $xmlFeed->channel->addChild('atom:atom:link');
        $atomLink->addAttribute('rel', 'self');
        $atomLink->addAttribute('type', 'application/rss+xml');
        $atomLink->addAttribute('href', $selfLink);

        foreach ($messages as $item) {
            $newItem = $xmlFeed->channel->addChild('item');
            //Standard stuff
            if (!empty($item['id'])) $newItem->addChild('guid', $item['id']);
            if (!empty($item['title'])) $newItem->addChild('title', htmlspecialchars($item['title'], ENT_XML1));
            if (!empty($item['description']) || !empty($item['preview']) || !empty($item['webpage'])) {
                $description = '';
                foreach ($item['preview'] as $url) {
                    $description .= "<a href=\"{$url}\" target=\"_blank\" rel=\"nofollow\">";
                    $description .= "<img src=\"{$url}/preview\" style=\"max-width:100%\"/>";
                    $description .= '</a>';
                    $description .= '<br/>';
                }
                $description .= '<br/>';

                $description .= $item['description'];
                if (!empty($item['webpage'])) {
                    if ($description) {
                        $description .= '<br/><br/>';
                    }
                    $description .= "<blockquote cite=\"{$item['webpage']['url']}\">";
                    $description .= "<cite><b>{$item['webpage']['site_name']}</b></cite></br>";
                    if ($item['webpage']['title']) {
                        $description .= "<b>{$item['webpage']['title']}</b></br>";
                    }
                    $description .= "{$item['webpage']['description']}</br>";
                    if ($item['webpage']['preview']) {
                        $description .= "<img src=\"{$item['webpage']['preview']}\" style=\"max-width:100%\"/>";
                    }
                    $description .= '</blockquote>';
                }
                $newItem->addChild('description', htmlspecialchars($description, ENT_XML1));
            }
            if (!empty($item['timestamp'])) $newItem->addChild('pubDate', date(DATE_RSS, $item['timestamp']));
            if (!empty($item['url'])) {
                $newItem->addChild('link', $item['url']);
                $newItem->addChild('guid', $item['url']);
            }
            if (!empty($item['media'])) {
                $media = $item['media'];
                $enclosure = $newItem->addChild('enclosure');
                $enclosure['url'] = $media['url'];
                $enclosure['type'] = $media['mime'];
                $enclosure['length'] = $media['size'];
                unset($enclosure);
            }
        }

        //Make the output pretty
        $dom = new \DOMDocument('1.1', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xmlFeed->asXML());
        $this->rss = $dom->saveXML();
        return $this;
    }

    /**
     * @return string
     */
    public function get(): string {
        return $this->rss;
    }
}