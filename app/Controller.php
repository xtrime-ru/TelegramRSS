<?php

namespace TelegramRSS;

class Controller
{
    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Client $client)
    {
        //Parse request and generate response

        //$response->header(...$this->page['headers']);
        $response->status(200);
        $response->end('Hello World!');
    }
}