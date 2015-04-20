<?php
/**
 * OpenGraphMeta
 *
 * @file
 * @ingroup Extensions
 * @author Daniel Friesen (http://danf.ca/mw/)
 * @license https://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:OpenGraphMeta Documentation
 */

if ( !defined( 'MEDIAWIKI' ) ) die( "This is an extension to the MediaWiki package and cannot be run standalone." );

$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => "OpenGraphMeta",
	'author' => array("[http://danf.ca/mw/ Daniel Friesen]", "[http://doomwiki.org/wiki/User:Quasar James Haley]"),
	'descriptionmsg' => 'opengraphmeta-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:OpenGraphMeta',
	'license-name' => 'GPL-2.0+',
);

$dir = dirname( __FILE__ );
$wgExtensionMessagesFiles['OpenGraphMetaMagic'] = $dir . '/OpenGraphMeta.magic.php';
$wgMessagesDirs['OpenGraphMeta'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['OpenGraphMeta'] = $dir . '/OpenGraphMeta.i18n.php';

$wgHooks['ParserFirstCallInit'][] = 'efOpenGraphMetaParserInit';
function efOpenGraphMetaParserInit( $parser ) {
	$parser->setFunctionHook( 'setmainimage', 'efSetMainImagePF' );
	return true;
}

function efSetMainImagePF( $parser, $mainimage ) {
	$parserOutput = $parser->getOutput();
	if ( isset($parserOutput->eHasMainImageAlready) && $parserOutput->eHasMainImageAlready )
		return $mainimage;
	$file = Title::newFromText( $mainimage, NS_FILE );
	$parserOutput->addOutputHook( 'setmainimage', array( 'dbkey' => $file->getPrefixedDBkey() ) ); // haleyjd: must use prefixed db key
	$parserOutput->eHasMainImageAlready = true;

	return $mainimage;
}

$wgParserOutputHooks['setmainimage'] = 'efSetMainImagePH';
function efSetMainImagePH( $out, $parserOutput, $data ) {
	$out->mMainImage = wfFindFile( Title::newFromDBkey($data['dbkey']) ); // haleyjd: newFromDBkey uses prefixed db key
}

// haleyjd: PageImages integration; some credit due to wikia for ideas.
$wgHooks['PageContentSaveComplete'][] = 'OpenGraphMetaPageImage::onPageContentSaveComplete';
class OpenGraphMetaPageImage
{
	const MAX_WIDTH = 1500;

	// Get a thumbnail URL if the image is larger than the maximum recommended
	// size for og:image; otherwise, return the full file URL
	public static function getThumbUrl( $file ) {
		$url = false;
		if( is_object( $file ) ) {
			$width = $file->getWidth();
			if ( $width > self::MAX_WIDTH ) {
				$url = wfExpandUrl( $file->createThumb( self::MAX_WIDTH, -1 ) );
			} else {
				$url = $file->getFullUrl();
			}
		} else {
			// In some edge-cases we won't have defined an object but rather a full URL.
			$url = $file;
		}
		return $url;
	}

	// Obtain the PageImages extension's opinion of the best page image
	public static function getPageImage( &$meta, $title ) {
		$cache = wfGetMainCache();
		$cacheKey = wfMemcKey( 'OpenGraphMetaPageImage', md5( $title->getDBkey() ) );
		$imageUrl = $cache->get( $cacheKey );
		if ( is_null($imageUrl) || $imageUrl === false ) {
			$imageUrl = '';
			$file = PageImages::getPageImage( $title );
			if( $file ) {
				$imageUrl = self::getThumbUrl( $file );
			}
			$cache->set( $cacheKey, $imageUrl );
		}
		if( !empty( $imageUrl ) ) {
			$meta["og:image"] = $imageUrl;
		}
	}

	// Hook function to delete cached PageImages result when an article is edited
	public static function onPageContentSaveComplete( $article, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {
		$title = $article->getTitle();
		$cacheKey = wfMemcKey( 'OpenGraphMetaPageImage', md5( $title->getDBkey() ) );
		wfGetMainCache()->delete( $cacheKey );
		return true;
	}
}

$wgHooks['BeforePageDisplay'][] = 'efOpenGraphMetaPageHook';
function efOpenGraphMetaPageHook( &$out, &$sk ) {
	global $wgLogo, $wgSitename, $wgXhtmlNamespaces, $egFacebookAppId, $egFacebookAdmins;
	$wgXhtmlNamespaces["og"] = "http://opengraphprotocol.org/schema/";
	$title = $out->getTitle();
	$isMainpage = $title->isMainPage();

	$meta = array();

	if ( $isMainpage ) {
		$meta["og:type"] = "website";
		$meta["og:title"] = $wgSitename;
	} else {
		$meta["og:type"] = "article";
		$meta["og:site_name"] = $wgSitename;
		// Try to chose the most appropriate title for showing in news feeds.
		if ( ( defined('NS_BLOG_ARTICLE') && $title->getNamespace() == NS_BLOG_ARTICLE ) ||
			( defined('NS_BLOG_ARTICLE_TALK') && $title->getNamespace() == NS_BLOG_ARTICLE_TALK ) ){
			$meta["og:title"] = $title->getSubpageText();
		} else {
			$meta["og:title"] = $title->getText();
		}
	}

	if ( isset( $out->mMainImage ) && ( $out->mMainImage !== false ) ) {
		$meta["og:image"] = OpenGraphMetaPageImage::getThumbUrl( $out->mMainImage );
	} elseif ( $isMainpage ) {
		$meta["og:image"] = wfExpandUrl($wgLogo);
	} elseif ( $title->inNamespace( NS_FILE ) ) { // haleyjd: NS_FILE is trivial
		$file = wfFindFile( $title->getDBkey() );
		if ( $file ) {
			$meta["og:image"] = OpenGraphMetaPageImage::getThumbUrl( $file );
		}
	} elseif ( defined('PAGE_IMAGES_INSTALLED') ) { // haleyjd: integrate with Extension:PageImages
		OpenGraphMetaPageImage::getPageImage( $meta, $title );
	}
	if ( isset($out->mDescription) ) { // set by Description2 extension, install it if you want proper og:description support
		$meta["og:description"] = $out->mDescription;
	}
	$meta["og:url"] = $title->getFullURL();
	if ( $egFacebookAppId ) {
		$meta["fb:app_id"] = $egFacebookAppId;
	}
	if ( $egFacebookAdmins ) {
		$meta["fb:admins"] = $egFacebookAdmins;
	}

	foreach( $meta as $property => $value ) {
		if ( $value ) {
			if ( isset( OutputPage::$metaAttrPrefixes ) && isset( OutputPage::$metaAttrPrefixes['property'] ) ) {
				$out->addMeta( "property:$property", $value );
			} else {
				$out->addHeadItem("meta:property:$property", Html::element( 'meta', array( 'property' => $property, 'content' => $value ) ) ); // haleyjd: rem unnecessary whitespace
			}
		}
	}

	return true;
}

$egFacebookAppId = null;
$egFacebookAdmins = null;

