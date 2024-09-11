<?php

use MediaWiki\MediaWikiServices;

/**
 * Background job to modify or create a page,
 */
class ImportFromCloneJob extends Job {

	function __construct( $title, $params = '', $id = 0 ) {
		parent::__construct( 'importFromClone', $title, $params, $id );
	}

	/**
	 * @return boolean success
	 */
	function run() {
		if ( is_null( $this->title ) ) {
			$this->error = wfMessage( 'clonediff-invalidtitle' )->text();
			return false;
		}
		if ( $this->title->getContentModel() !== CONTENT_MODEL_WIKITEXT ) {
			$this->error = wfMessage( 'clonediff-irregulartext', $this->title->getPrefixedDBkey() )->text();
			return false;
		}
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->title );
		} else {
			$wikiPage = new WikiPage( $this->title );
		}

		$page_text = $this->params['page_text'];

		$editAsUser = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $this->params['user_id'] );
		$edit_summary = wfMessage( 'clonediff-editsummary' )->inContentLanguage()->parse();
		$content = new WikitextContent( $page_text );
		if ( method_exists( $wikiPage, 'doUserEditContent' ) ) {
			// MW 1.36+
			$wikiPage->doUserEditContent( $content, $editAsUser, $edit_summary );
		} else {
			global $wgUser;
			// Change global $wgUser variable to the one
			// specified by the job only for the extent of this
			// replacement.
			$actual_user = $wgUser;
			$wgUser = $editAsUser;
			$wikiPage->doEditContent( $content, $edit_summary );
			$wgUser = $actual_user;
		}

		return true;
	}
}
