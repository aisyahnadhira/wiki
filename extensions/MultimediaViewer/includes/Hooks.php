<?php
/**
 * This file is part of the MediaWiki extension MultimediaViewer.
 *
 * MultimediaViewer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * MultimediaViewer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with MultimediaViewer.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author Mark Holmquist <mtraceur@member.fsf.org>
 * @copyright Copyright © 2013, Mark Holmquist
 */

namespace MediaWiki\Extension\MultimediaViewer;

use Category;
use CategoryPage;
use ExtensionRegistry;
use MediaWiki\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use OutputPage;
use Skin;
use ThumbnailImage;
use User;

class Hooks implements MakeGlobalVariablesScriptHook {
	/** Link to more information about this module */
	protected static $infoLink =
		'https://mediawiki.org/wiki/Special:MyLanguage/Extension:Media_Viewer/About';

	/** Link to a page where this module can be discussed */
	protected static $discussionLink =
		'https://mediawiki.org/wiki/Special:MyLanguage/Extension_talk:Media_Viewer/About';

	/** Link to help about this module */
	protected static $helpLink =
		'https://mediawiki.org/wiki/Special:MyLanguage/Help:Extension:Media_Viewer';

	/**
	 * @var UserOptionsLookup
	 */
	private $userOptionsLookup;

	/**
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct( UserOptionsLookup $userOptionsLookup ) {
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserGetDefaultOptions
	 * @param array &$defaultOptions
	 */
	public static function onUserGetDefaultOptions( array &$defaultOptions ) {
		global $wgMediaViewerEnableByDefault;

		if ( $wgMediaViewerEnableByDefault ) {
			$defaultOptions['multimediaviewer-enable'] = 1;
		}
	}

	/**
	 * Checks the context for whether to load the viewer.
	 * @param UserIdentity $performer
	 * @return bool
	 */
	protected function shouldHandleClicks( UserIdentity $performer ) {
		global $wgMediaViewerEnableByDefaultForAnonymous,
			$wgMediaViewerEnableByDefault;

		if ( $performer->isRegistered() ) {
			return (bool)$this->userOptionsLookup->getOption( $performer, 'multimediaviewer-enable' );
		}

		return (bool)( $wgMediaViewerEnableByDefaultForAnonymous ?? $wgMediaViewerEnableByDefault );
	}

	/**
	 * Handler for all places where we add the modules
	 * Could be on article pages or on Category pages
	 * @param OutputPage $out
	 */
	protected static function getModules( OutputPage $out ) {
		// The MobileFrontend extension provides its own implementation of MultimediaViewer.
		// See https://phabricator.wikimedia.org/T65504 and subtasks for more details.
		// To avoid loading MMV twice, we check the environment we are running in.
		$isMobileFrontendView = ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
			MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' )
				->shouldDisplayMobileView();
		if ( !$isMobileFrontendView ) {
			$out->addModules( [ 'mmv.head', 'mmv.bootstrap.autostart' ] );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * Add JavaScript to the page when an image is on it
	 * and the user has enabled the feature
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, $skin ) {
		$pageHasThumbnails = count( $out->getFileSearchOptions() ) > 0;
		$pageIsFilePage = $out->getTitle()->inNamespace( NS_FILE );
		// TODO: Have Flow work out if there are any images on the page
		$pageIsFlowPage = ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) &&
			// CONTENT_MODEL_FLOW_BOARD
			$out->getTitle()->getContentModel() === 'flow-board';
		$fileRelatedSpecialPages = [ 'NewFiles', 'ListFiles', 'MostLinkedFiles',
			'MostGloballyLinkedFiles', 'UncategorizedFiles', 'UnusedFiles', 'Search' ];
		$pageIsFileRelatedSpecialPage = $out->getTitle()->inNamespace( NS_SPECIAL )
			&& in_array( $out->getTitle()->getText(), $fileRelatedSpecialPages );

		if ( $pageHasThumbnails || $pageIsFilePage || $pageIsFileRelatedSpecialPage || $pageIsFlowPage ) {
			self::getModules( $out );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/CategoryPageView
	 * Add JavaScript to the page if there are images in the category
	 * @param CategoryPage $catPage
	 */
	public static function onCategoryPageView( CategoryPage $catPage ) {
		$title = $catPage->getTitle();
		$cat = Category::newFromTitle( $title );
		if ( $cat->getFileCount() > 0 ) {
			$out = $catPage->getContext()->getOutput();
			self::getModules( $out );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 * Adds a default-enabled preference to gate the feature
	 * @param User $user
	 * @param array &$prefs
	 */
	public static function onGetPreferences( $user, &$prefs ) {
		$prefs['multimediaviewer-enable'] = [
			'type' => 'toggle',
			'label-message' => 'multimediaviewer-optin-pref',
			'section' => 'rendering/files',
		];
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderGetConfigVars
	 * Export variables used in both PHP and JS to keep DRY
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		global $wgMediaViewerUseThumbnailGuessing, $wgMediaViewerExtensions,
			$wgMediaViewerImageQueryParameter, $wgMediaViewerRecordVirtualViewBeaconURI;

		$vars['wgMultimediaViewer'] = [
			'infoLink' => self::$infoLink,
			'discussionLink' => self::$discussionLink,
			'helpLink' => self::$helpLink,
			'useThumbnailGuessing' => (bool)$wgMediaViewerUseThumbnailGuessing,
			'imageQueryParameter' => $wgMediaViewerImageQueryParameter,
			'recordVirtualViewBeaconURI' => $wgMediaViewerRecordVirtualViewBeaconURI,
			'tooltipDelay' => 1000,
			'extensions' => $wgMediaViewerExtensions,
		];
		$vars['wgMediaViewer'] = true;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MakeGlobalVariablesScript
	 * Export variables which depend on the current user
	 * @param array &$vars
	 * @param OutputPage $out
	 * @return void
	 */
	public function onMakeGlobalVariablesScript( &$vars, $out ): void {
		$isMultimediaViewerEnable = $this->userOptionsLookup->getDefaultOption( 'multimediaviewer-enable' );

		$user = $out->getUser();
		$vars['wgMediaViewerOnClick'] = $this->shouldHandleClicks( $user );
		// needed because of T71942; could be different for anon and logged-in
		$vars['wgMediaViewerEnabledByDefault'] = (bool)$isMultimediaViewerEnable;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ThumbnailBeforeProduceHTML
	 * Modify thumbnail DOM
	 * @param ThumbnailImage $thumbnail
	 * @param array &$attribs Attributes of the <img> element
	 * @param array|bool &$linkAttribs Attributes of the wrapping <a> element
	 */
	public static function onThumbnailBeforeProduceHTML(
		ThumbnailImage $thumbnail,
		array &$attribs,
		&$linkAttribs
	) {
		$file = $thumbnail->getFile();

		if ( $file ) {
			$attribs['data-file-width'] = $file->getWidth();
			$attribs['data-file-height'] = $file->getHeight();
		}
	}
}
