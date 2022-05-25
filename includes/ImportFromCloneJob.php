<?php

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
		$wikiPage = new WikiPage( $this->title );

		$page_text = $this->params['page_text'];

		// Change global $wgUser variable to the one
		// specified by the job only for the extent of this
		// replacement.
		global $wgUser;
		$actual_user = $wgUser;
		$editAsUser = User::newFromId( $this->params['user_id'] );
		$wgUser = $editAsUser;
		$edit_summary = wfMessage( 'clonediff-editsummary' )->inContentLanguage()->parse();
		$content = new WikitextContent( $page_text );
		if ( method_exists( $wikiPage, 'doUserEditContent' ) ) {
			// MW 1.36+
			$wikiPage->doUserEditContent( $content, $editAsUser, $edit_summary );
		} else {
			$wikiPage->doEditContent( $content, $edit_summary );
		}

		$wgUser = $actual_user;
		return true;
	}
}
