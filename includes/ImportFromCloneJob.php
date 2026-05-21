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
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->title );

		$page_text = $this->params['page_text'];

		$editAsUser = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $this->params['user_id'] );
		$edit_summary = wfMessage( 'clonediff-editsummary' )->inContentLanguage()->parse();
		$content = new WikitextContent( $page_text );
		$wikiPage->doUserEditContent( $content, $editAsUser, $edit_summary );

		return true;
	}
}
