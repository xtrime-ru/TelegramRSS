<?php

namespace TelegramRSS\RSS;


use TelegramRSS\Config;

class Feed {
    private string $title;
    private string $description;

    private string $rss;
    private string $link;

    /**
     * RSS constructor.
     * @param array $messages
     * @param string $peer
     */
    public function __construct(array $messages, string $peer, array $info) {
        $url = Config::getInstance()->get('url');
        $selfLink = "$url/rss/" . urlencode($peer);

        $this->title = $info['Chat']['title'];
        $this->description = $info['full']['about'];
        $this->link = Config::getInstance()->get('url');

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
        $xmlFeed->channel->addChild('title', htmlspecialchars($this->title));
        $xmlFeed->channel->addChild('link', $this->link);
        $xmlFeed->channel->addChild('description', htmlspecialchars($this->description));
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
                    if ($url['href'] && $url['image']) {
                        $description .= "<a href=\"{$url['href']}\" target=\"_blank\" rel=\"nofollow\">";
                        $description .= "<img src=\"{$url['image']}\" style=\"max-width:100%\"/>";
                        $description .= '</a>';
                        $description .= '<br/>';
                    }

                }
                $description .= '<br/>';

                $description .= $item['description'];
                if (!empty($item['webpage'])) {
                    if ($description) {
                        $description .= '<br/><br/>';
                    }

                    $webPagePreview = '';
                    if ($item['webpage']['site_name']) {
                        $webPagePreview .= "<cite><b>{$item['webpage']['site_name']}</b></cite></br>";
                    }
                    if ($item['webpage']['title']) {
                        $webPagePreview .= "<b>{$item['webpage']['title']}</b></br>";
                    }
                    if ($item['webpage']['description']) {
                        $webPagePreview .= "{$item['webpage']['description']}</br>";
                    }
                    if ($item['webpage']['preview']) {
                        $webPagePreview .= "<img src=\"{$item['webpage']['preview']}\" style=\"max-width:100%\"/>";
                    }

                    if ($webPagePreview) {
                        $cite  = $item['webpage']['url'] ? "cite=\"{$item['webpage']['url']}\"" : '';
                        $description .= "<blockquote {$cite}>";
                        $description .= $webPagePreview;
                        $description .= '</blockquote>';
                    }

                }
                $newItem->addChild('description', htmlspecialchars($description, ENT_XML1));
            }
            if (!empty($item['timestamp'])) $newItem->addChild('pubDate', date(DATE_RSS, $item['timestamp']));
            if (!empty($item['url'])) {
                $newItem->addChild('link', $item['url']);
                $newItem->addChild('guid', $item['url']);
            }
            foreach ($item['media'] as $media) {
                if (!empty($media['url'])) {
                    $enclosure = $newItem->addChild('enclosure');
                    $enclosure['url'] = $media['url'];
                    $enclosure['type'] = $media['mime'];
                    $enclosure['length'] = $media['size'];
                    unset($enclosure);
                }
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