<?php
    header('Content-type: application/atom+xml');
    $channel_link = HOSTNAME . $_SERVER["REQUEST_URI"];

    $Xml = new SimpleXMLElement('<feed xmlns="http://www.w3.org/2005/Atom"></feed>');
    $Xml->addChild('title', $config['site_title']);
    $Xml->addChild('id', $channel_link);
    # TODO created of last item
    $Xml->addChild('updated', date(DATE_ATOM));
    $Xml->addChild('generator', 'Lamb');

    $Link = $Xml->addChild('atom:link');
    $Link->addAttribute('rel', 'self');
    $Link->addAttribute('href',  $channel_link);

    $Author = $Xml->addChild('author');
    $Author->addChild('name', $config['author_name']);
    $Author->addChild('email', $config['author_email']);

    foreach ($data['items'] as $item) {
        $Entry = $Xml->addChild('entry');
        $Entry->addChild('id', HOSTNAME . '/bleat/'. $item['id']);
        $Entry->addChild('title', $item['title']);
        $Entry->addChild('updated', date(DATE_ATOM, strtotime($item['created'])));
        $Content = $Entry->addChild('content', $item['body']);
        $Content->addAttribute('type', 'html');
        $Link = $Entry->addChild('link');
        $Link->addAttribute('href', $hostname . '/bleat/'. $item['id']);
    }
    echo $Xml->asXML();