<?php
global $config;
global $data;

function escape( string $html ) : string {
	return htmlspecialchars( $html, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE );
}

function permalink( $item ) : string {
	return ROOT_URL . '/status/' . $item['id'];
}

header( 'Content-type: application/atom+xml' );
$channel_link = ROOT_URL . '/feed';

$Xml = new SimpleXMLElement( '<feed xmlns="http://www.w3.org/2005/Atom"></feed>' );
$Xml->addChild( 'title', escape( $config['site_title'] ) );
$Xml->addChild( 'id', escape( $channel_link ) );
$Xml->addChild( 'updated', date( DATE_ATOM, strtotime( $data['items'][0]['updated'] ) ) );
$Xml->addChild( 'generator', 'Lamb' );

$Link = $Xml->addChild( 'atom:link' );
$Link->addAttribute( 'rel', 'self' );
$Link->addAttribute( 'href', escape( $channel_link ) );

$Author = $Xml->addChild( 'author' );
$Author->addChild( 'name', escape( $config['author_name'] ) );
$Author->addChild( 'email', escape( $config['author_email'] ) );

foreach ( $data['items'] as $item ) {
	$Entry = $Xml->addChild( 'entry' );
	# TODO assumed status
	$Entry->addChild( 'id', permalink( $item ) );
	$Entry->addChild( 'title', escape( (string) $item['title'] ) );
	$Entry->addChild( 'updated', date( DATE_ATOM, strtotime( $item['updated'] ) ) );
	$Content = $Entry->addChild( 'content', $item['body'] );
	$Content->addAttribute( 'type', 'html' );
	$Link = $Entry->addChild( 'link' );
	# TODO assumed, store with item?
	$Link->addAttribute( 'href', permalink( $item ) );
}
echo $Xml->asXML();
