<?php
/**
 */

class CloneDiffHooks {

	public static function addToAdminLinks( ALTree &$adminLinksTree ) {
		$generalSection = $adminLinksTree->getSection( wfMessage( 'adminlinks_general' )->text() );
		$extensionsRow = $generalSection->getRow( 'extensions' );

		if ( is_null( $extensionsRow ) ) {
			$extensionsRow = new ALRow( 'extensions' );
			$generalSection->addRow( $extensionsRow );
		}

		$extensionsRow->addItem( ALItem::newFromSpecialPage( 'CloneDiff' ) );

		return true;
	}

	public static function addToSidebar( Skin $skin, &$bar ) {
		global $wgTitle, $wgCloneDiffWikis;

		if ( $wgTitle->isSpecialPage() ) {
			return true;
		}

        $bar['Clone Wiki Links'] =  array();

		foreach ( $wgCloneDiffWikis as $i => $cloneDiffWiki ) {
			$apiURL = $cloneDiffWiki['API URL'];
			$apiResultData = SpecialCloneDiff::httpRequest( $apiURL . '?action=query&titles='. $wgTitle->getFullText() .'&formatversion=2&format=json' );

			if ( isset( $apiResultData->query ) && isset( $apiResultData->query->pages[0]->pageid ) ) {
				$bar['Clone Wiki Links'][] = array(
					'text'   => 'Compare to page in ' . $cloneDiffWiki['name'],
					'href'   => SpecialPage::getTitleFor( 'CloneDiff' )->getFullURL( "remoteWiki=$i&pageName=" . $wgTitle->getFullText() )
				);
			}
		}

		return true;
	}

}
