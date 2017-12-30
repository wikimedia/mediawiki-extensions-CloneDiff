<?php

class SpecialCloneDiff extends SpecialPage {
	private $categories, $namespace;

	const LOCAL_ONLY = 0;
	const REMOTE_ONLY = 1;
	const IN_BOTH = 2;

	public function __construct() {
		parent::__construct( 'CloneDiff', 'clonediff' );
	}

	function execute( $query ) {
		if ( !$this->getUser()->isAllowed( 'clonediff' ) ) {
			throw new PermissionsError( 'clonediff' );
		}
		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if ( $request->getVal( 'pageName' ) != '' ) {
			$this->displayDiffsForm();
			return;
		}

		if ( $request->getCheck( 'continue' ) ) {
			$this->displayDiffsForm();
			return;
		}

		if ( $request->getCheck( 'import' ) ) {
			$this->importAndDisplayResults();
			return;
		}

		// This must be the starting screen - show the form.
		$this->displayInitialForm();
	}

	function displayInitialForm( $warning_msg = null ) {
		global $wgCloneDiffWikis;

		$out = $this->getOutput();

		$out->addHTML(
			Xml::openElement(
				'form',
				[
					'id' => 'powersearch',
					'action' => $this->getTitle()->getFullUrl(),
					'method' => 'post'
				]
			) . "\n" .
			Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
			Html::hidden( 'continue', 1 )
		);

		if ( count( $wgCloneDiffWikis ) == 1 ) {
			$wiki = $wgCloneDiffWikis[0];
			$wikiLink = Html::element( 'a', [ 'href' => $wiki['URL'] ], $wiki['name'] );
			$out->addHTML( "<p><b>Remote wiki: $wikiLink.</b></p>" );
		} else {
			// Make a dropdown
			$dropdownHTML = '<select name="remoteWiki">';
			foreach ( $wgCloneDiffWikis as $i => $cloneDiffWiki ) {
				$dropdownHTML .= '<option value="' . $i . '">' . $cloneDiffWiki['name'] . '</option>';
			}
			$dropdownHTML .= '</select>';
			$out->addHTML( "<p><b>Remote wiki:</b> $dropdownHTML</p>" );
		}

		$out->addHTML( '<p>' . Html::label( 'Username', 'remote_username' ) . ' ' . Html::input( 'remote_username' ) . '</p>' );
		$out->addHTML( '<p>' . Html::label( 'Password', 'remote_password' ) . ' ' . Html::input( 'remote_password' ) . '</p>' );

		if ( is_null( $warning_msg ) ) {
			$out->addWikiMsg( 'clonediff-docu' );
		} else {
			$out->wrapWikiMsg(
				"<div class=\"errorbox\">\n$1\n</div><br clear=\"both\" />",
				$warning_msg
			);
		}

		$out->addHTML( '<p>' . Xml::checkLabel( 'Include pages that only exist locally', "viewLocalOnly", "viewLocalOnly", true ) . '</p>' );
		$out->addHTML( '<p>' . Xml::checkLabel( 'Include pages that only exist remotely', "viewRemoteOnly", "viewRemoteOnly", true ) . '</p>' );

		// The interface is heavily based on the one in Special:Search.
		$namespaces = SearchEngine::searchableNamespaces();
		$nsText = "\n";
		foreach ( $namespaces as $ns => $name ) {
			if ( '' == $name ) {
				$name = $this->msg( 'blanknamespace' )->text();
			}
			$name = str_replace( '_', ' ', $name );
			$nsButton = '<label><input type="radio" name="namespace"' .
				' value="' . $ns . '"';
			$nsButton .= '" />' . $name . '</label>';
			$nsText .= '<span style="float: left; width: 150px;">' .
				$nsButton . "</span>\n";
		}
		$out->addHTML(
			"<fieldset id=\"mw-searchoptions\">\n" .
			Xml::tags( 'h4', null, "Search in namespace:" ) .
			"$nsText\n</fieldset>"
		);

		$dbr = wfGetDB( DB_SLAVE );
		$categorylinks = $dbr->tableName( 'categorylinks' );
		$res = $dbr->query( "SELECT DISTINCT cl_to FROM $categorylinks" );
		$categories = array();
		while ( $row = $dbr->fetchRow( $res ) ) {
			$categories[] = str_replace( '_', ' ', $row[0] );
		}
		$dbr->freeResult( $res );
		sort( $categories );

		//$tables = $this->categoryTables( $categories );
		$categoriesText = '';
		foreach ( $categories as $cat ) {
			$categoryCheck = Xml::checkLabel( $cat, "categories[$cat]", "mw-search-category-$cat" );
			$categoriesText .= '<span style="float: left; width: 170px; padding-right: 15px;">' .
				$categoryCheck . '</span>';
		}
		$out->addHTML(
			"<fieldset id=\"mw-searchoptions\">\n" .
			Xml::tags( 'h4', null, "Search in categories:" ) .
			"$categoriesText\n</fieldset>"
		);

		$out->addHTML(
			Xml::submitButton( $this->msg( 'clonediff-continue' )->parse() ) .
			Xml::closeElement( 'form' )
		);
	}

	public function getLocalPages() {
		$dbr = wfGetDB( DB_REPLICA );
		$tables = [ 'page' ];
		$vars = [ 'page_id', 'page_namespace', 'page_title' ];
		$conds = [];
		if ( $this->namespace !== null ) {
			$conds['page_namespace'] = $this->namespace;
		}

		if ( count( $this->categories ) > 0 ) {
			$categoryStrings = [];
			foreach ( $this->categories as $category ) {
				$categoryStrings[] = "'" . str_replace( ' ', '_', $category ) . "'";
			}
			$tables[] = 'categorylinks';
			$conds[] = 'page_id = cl_from';
			$conds[] = 'cl_to IN (' . implode( ', ', $categoryStrings ) . ')';
		}

		$options = [ 'ORDER BY' => 'page_namespace, page_title' ];

		$res = $dbr->select( $tables, $vars, $conds, __METHOD__, $options );

		$localPages = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			if ( $title == null ) {
				continue;
			}
			$localPages[] = $title->getPrefixedText();
		}

		return $localPages;
	}


	function getAllRemotePagesInCategories( $remoteAPIURL ) {
		$remotePages = [];

		foreach ( $this->categories as $category ) {
			$offset = 0;
			do {
				$apiURL = $remoteAPIURL . '?action=query&list=categorymembers&cmtitle=Category:' . $category;
				$apiURL .= "&offset=$offset&limit=500&format=json";
				$apiResultData = self::httpRequest( $apiURL );
				if ( $apiResultData == '' ) {
					throw new MWException( "API at $remoteAPIURL is not responding." );
				}
				if ( isset( $apiResultData->error ) ) {
					throw new MWException( "Error accessing remote API: code = " . $apiResultData->error->code . ", message = " . $apiResultData->error->info . "." );
				}
				$remotePageData = $apiResultData->query->categorymembers;
				foreach ( $remotePageData as $remotePage ) {
					$remotePages[] = $remotePage->title;
				}
				$offset += 500;
			} while ( count( $remotePageData ) == 500 );
		}
		return $remotePages;
	}

	function getAllRemotePagesInNamespace( $remoteAPIURL ) {
		$remotePages = [];

		$offset = 0;
		do {
			$apiURL = $remoteAPIURL . '?action=query&list=allpages&apnamespace=' .
				$this->namespace . "&offset=$offset&aplimit=500&format=json";
			$apiResultData = self::httpRequest( $apiURL );
			if ( $apiResultData == '' ) {
				throw new MWException( "API at $remoteAPIURL is not responding." );
			}
			if ( isset( $apiResultData->error ) ) {
				throw new MWException( "Error accessing remote API: code = " . $apiResultData->error->code . ", message = " . $apiResultData->error->info . "." );
			}
			$remotePageData = $apiResultData->query->allpages;
			foreach ( $remotePageData as $remotePage ) {
				$remotePages[] = $remotePage->title;
			}
		} while ( count( $remotePageData ) == 500 );

		return $remotePages;
	}

	function displayDiffHeader( $pageLink, $timestamp, $lastEditor ) {
		$diffHeader = $pageLink;
		if ( $timestamp != null ) {
			$lang = $this->getLanguage();
			$user = $this->getUser();
			$timestampText = $lang->userTimeAndDate( $timestamp, $user );
			$diffHeader .= '<div id="mw-diff-otitle1"><strong>' . $timestampText . '</strong></div>';
		}
		if ( $lastEditor != null ) {
			$diffHeader .= '<div id="mw-diff-otitle2">Last edited by: ' . $lastEditor . '</div>';
		}
		return $diffHeader;
	}

	function displayDiffsForm() {
		global $wgCloneDiffWikis;

		wfProfileIn( __METHOD__ );

		$out = $this->getOutput();
		$request = $this->getRequest();

		if ( count( $wgCloneDiffWikis ) == 1 ) {
			$selectedWiki = 0;
		} else {
			$selectedWiki = $request->getVal( 'remoteWiki' );
		}

		$formOpts = [
			'id' => 'choose_pages',
			'method' => 'post',
			'action' => $this->getTitle()->getFullUrl()
		];
		$out->addHTML(
			Xml::openElement( 'form', $formOpts ) . "\n" .
			Html::hidden( 'title', $this->getTitle()->getPrefixedText() ) .
			Html::hidden( 'import', 1 ) .
			Html::hidden( 'wikinum', $selectedWiki )
		);

		$apiURL = $wgCloneDiffWikis[$selectedWiki]['API URL'];

		$pagesToBeDisplayed = array();
		$showNavigation = true;
		if ( $request->getVal( 'pageName' ) != '' ) {
			$pagesToBeDisplayed[$request->getVal( 'pageName' )] = 2;
			$showNavigation = false;
		} else {
			$pagesToBeDisplayed = $this->getPagesToBeDisplayed( $apiURL );
			if ( !is_array( $pagesToBeDisplayed ) ) {
				wfProfileOut( __METHOD__ );
				return;
			}
		}

		if ( $showNavigation ) {
			list( $limit, $offset ) = $request->getLimitOffset();
			$this->showNavigation( count( $pagesToBeDisplayed ), $limit, $offset, true );
		}

		$localAndRemoteData = $this->getLocalAndRemoteDataForPageSet( $apiURL, $pagesToBeDisplayed );

		$diffEngine = new DifferenceEngine();
		foreach ( $localAndRemoteData as $pageName => $curPageData ) {
			$localText = $curPageData['localText'];
			$remoteText = $curPageData['remoteText'];
			$title = null;
			if ( $localText != null ) {
				$title = Title::newFromText( $pageName );
			}
			if ( $remoteText != $localText && $remoteText != '' ) {
				$out->addHTML( Xml::check( $pageName, true ) . ' ' );
			}
			$out->addHTML( "<big><b>$pageName</b></big><br />" );
			if ( $remoteText == null ) {
				$html = '<p>Exists only on local wiki.</p>';
			} elseif ( $localText == $remoteText ) {
				$html = '<p>No change.</p>';
			} else {
				$html = '';
				if ( $localText == null ) {
					$html = '<p>Exists only on remote wiki.</p>';
				}
				$localContent = new WikitextContent( $localText );
				$remoteContent = new WikitextContent( $remoteText );
				$diffText = $diffEngine->generateContentDiffBody( $remoteContent, $localContent );

				// Replace line numbers with the text in the user's language
				if ( $diffText !== false ) {
					$diffText = $diffEngine->localiseLineNumbers( $diffText );
				}

				$url = str_replace( 'api.php', 'index.php?title=' . str_replace( ' ', '_', $pageName ), $apiURL );
				$remoteLink = Html::element( 'a', [ 'href' => $url ], 'Remote version' );
				$remoteHeader = $this->displayDiffHeader( $remoteLink, $curPageData['remoteTime'], $curPageData['remoteUser'] );

				if ( $title == null ) {
					$localLink = 'Local version (nonexistent)';
					$time = null;
					$user = null;
				} else {
					$localLink = Linker::link( $title, 'Local version' );
					$rev = Revision::newFromTitle( $title );
					$time = $rev->getTimestamp();
					$user = $rev->getUserText();
				}
				$localHeader = $this->displayDiffHeader( $localLink, $time, $user );

				$html .= $diffEngine->addHeader( $diffText, $remoteHeader, $localHeader );
			}
			$out->addHTML( $html );
			$out->addHTML( "<br /><hr /><br />\n" );
		}

		$out->addHTML(
			Xml::submitButton( $this->msg( 'clonediff-import' )->text() ) .
			Xml::closeElement( 'form' )
		);

		if ( $showNavigation ) {
			$this->showNavigation( count( $pagesToBeDisplayed ), $limit, $offset, false );
		}

		$out->addModuleStyles( 'mediawiki.diff.styles' );

		wfProfileOut( __METHOD__ );
	}

	function getPagesToBeDisplayed( $apiURL ) {
		wfProfileIn( __METHOD__ );

		$request = $this->getRequest();

		$this->categories = [];
		$categoriesFromRequest = $request->getArray( 'categories' );
		if ( is_array( $categoriesFromRequest ) ) {
			foreach ( $request->getArray( 'categories' ) as $category => $val ) {
				$this->categories[] = $category;
			}
		}
		$this->namespace = $request->getVal( 'namespace' );

		$viewPagesOnlyInLocal = $request->getCheck( 'viewLocalOnly' );
		$viewPagesOnlyInRemote = $request->getCheck( 'viewRemoteOnly' );

		// Make sure that at least one namespace or one
		// category has been selected.
		if ( $this->namespace == null && count( $this->categories ) == 0 ) {
			$this->displayInitialForm( 'clonediff-nonamespace' );
			wfProfileOut( __METHOD__ );
			return;
		}
		$localPages = $this->getLocalPages();

		if ( count( $this->categories ) == 0 ) {
			$remotePages = $this->getAllRemotePagesInNamespace( $apiURL );
		} else {
			$remotePages = $this->getAllRemotePagesInCategories( $apiURL );
		}

		$allPages = array();

		if ( $viewPagesOnlyInLocal ) {
			$pagesOnlyInLocal = array_diff( $localPages, $remotePages );
			foreach ( $pagesOnlyInLocal as $pageName ) {
				$allPages[$pageName] = self::LOCAL_ONLY;
			}
		}

		if ( $viewPagesOnlyInRemote ) {
			$pagesOnlyInRemote = array_diff( $remotePages, $localPages );
			foreach ( $pagesOnlyInRemote as $pageName ) {
				$allPages[$pageName] = self::REMOTE_ONLY;
			}
		}

		$pagesInBoth = array_intersect( $localPages, $remotePages );
		foreach ( $pagesInBoth as $pageName ) {
			$allPages[$pageName] = self::IN_BOTH;
		}

		ksort( $allPages );

		$allPageNames = array_keys( $allPages );

		list( $limit, $offset ) = $request->getLimitOffset();

		$pagesToBeDisplayed = array();
		for ( $i = $offset; $i < $offset + $limit && $i < count( $allPageNames ); $i++ ) {
			$pageName = $allPageNames[$i];
			$status = $allPages[$pageName];
			$pagesToBeDisplayed[$pageName] = $status;
		}

		wfProfileOut( __METHOD__ );

		return $pagesToBeDisplayed;
	}

	function getRemoteDataForPageSet( $apiURL, $pagesInRemoteWiki ) {
		$pageDataURL = $apiURL .
			'?action=query&prop=revisions&titles=' . 
			str_replace( ' ', '_', implode( '|', $pagesInRemoteWiki ) ) .
			'&rvprop=user|timestamp|content&format=json';

		$apiResultData = self::httpRequest( $pageDataURL );
		if ( $apiResultData == '' ) {
			throw new MWException( "API at $remoteAPIURL is not responding." );
		}
		if ( isset( $apiResultData->query ) ) {
			return get_object_vars( $apiResultData->query->pages );
		} else {
			return [];
		}
	}

	function getLocalAndRemoteDataForPageSet( $apiURL, $pageSet ) {
		$pagesNotInRemoteWiki = array();
		$pagesInRemoteWiki = array();
		foreach( $pageSet as $pageName => $status ) {
			if ( $status == self::LOCAL_ONLY ) {
				$pagesNotInRemoteWiki[] = $pageName;
			} else {
				$pagesInRemoteWiki[] = $pageName;
			}
		}

		$remotePageData = $this->getRemoteDataForPageSet( $apiURL, $pagesInRemoteWiki );
		$allPageData = [];
		foreach ( $remotePageData as $remotePage ) {
			$curPageData = [];
			$pageName = $remotePage->title;
			if ( $pageSet[$pageName] == self::IN_BOTH ) {
				$localTitle = Title::newFromText( $pageName );
				$rev = Revision::newFromTitle( $localTitle );
				$localContent = $rev->getContent();
				$localText = $localContent->serialize();
			} else {
				$localText = '';
			}
			$curPageData['localText'] = $localText;

			$remotePageRev = $remotePage->revisions[0];
			$remoteText = $remotePageRev->{'*'}; // ???
			$curPageData['remoteText'] = $remoteText;

			if ( $localText !== $remoteText ) {
				$curPageData['remoteTime'] = $remotePageRev->timestamp;
				$curPageData['remoteUser'] = $remotePageRev->user;
			}
			$allPageData[$pageName] = $curPageData;
		}

		foreach ( $pagesNotInRemoteWiki as $pageName ) {
			$curPageData = [];
			$localTitle = Title::newFromText( $pageName );
			$rev = Revision::newFromTitle( $localTitle );
			$localContent = $rev->getContent();
			$localText = $localContent->serialize();
			$curPageData['localText'] = $localText;
			$curPageData['remoteText'] = null;
			$allPageData[$pageName] = $curPageData;
		}

		ksort( $allPageData );

		return $allPageData;
	}

	// Based on code in the QueryPage class.
	function showNavigation( $numRows, $limit, $offset, $showMessage ) {
		$out = $this->getOutput();
		if ( $numRows > 0 ) {
			if ( $showMessage ) {
				$out->addHTML( $this->msg( 'showingresultsinrange' )->numParams(
					min( $numRows, $limit ), # do not show the one extra row, if exist
					$offset + 1, ( min( $numRows, $limit ) + $offset ) )->parseAsBlock() );
			}
			# Disable the "next" link when we reach the end
			$atEnd = ( $numRows <= $offset + $limit );
			$paging = $this->getLanguage()->viewPrevNext(
				$this->getPageTitle(), $offset,
				$limit, $this->linkParameters(), $atEnd
			);
			$out->addHTML( '<p>' . $paging . '</p>' );
		} else {
			# No results to show, so don't bother with "showing X of Y" etc.
			# -- just let the user know and give up now
			if ( $showMessage ) {
				$out->addWikiMsg( 'specialpage-empty' );
			}
			$out->addHTML( Xml::closeElement( 'div' ) );
		}
	}

	function linkParameters() {
		$params = [ 'continue' => true ];
		foreach ( $this->categories as $category ) {
			$params['categories'][$category] = true;
		}
		return $params;
	}

	function importAndDisplayResults() {
		global $wgCloneDiffWikis;

		wfProfileIn( __METHOD__ );

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		if ( count( $wgCloneDiffWikis ) == 1 ) {
			$selectedWiki = 0;
		} else {
			$selectedWiki = $request->getVal( 'remoteWiki' );
		}
		$apiURL = $wgCloneDiffWikis[$selectedWiki]['API URL'];

		$replacement_params['user_id'] = $user->getId();
		//$replacement_params['edit_summary'] = $this->msg( 'clonediff-editsummary' )->inContentLanguage()->plain();

		$pagesToImport = [];
		foreach ( $request->getValues() as $key => $value ) {
			if ( $value == '1' && $key !== 'import' && $key !== 'wikinum' ) {
				$pagesToImport[] = $key;
			}
		}

		$jobs = [];
		$remotePageData = $this->getRemoteDataForPageSet( $apiURL, $pagesToImport );
		foreach ( $remotePageData as $remotePage ) {
			$pageName = $remotePage->title;
			$remotePageRev = $remotePage->revisions[0];
			$replacement_params['page_text'] = $remotePageRev->{'*'}; // ???

			$title = Title::newFromText( $pageName );
			if ( $title !== null ) {
				$jobs[] = new ImportFromCloneJob( $title, $replacement_params );
			}
		}

		JobQueueGroup::singleton()->push( $jobs );

		$count = $this->getLanguage()->formatNum( count( $jobs ) );
		$out->addWikiMsg( 'clonediff-success', $count );

		// Link back
		$out->addHTML(
			Linker::link( $this->getTitle(),
				$this->msg( 'clonediff-return' )->escaped() )
		);

		wfProfileOut( __METHOD__ );
	}

	protected function getGroupName() {
		return 'wiki';
	}

	public static function httpRequest( $url, $post_params = '' ) {
		global $wgRequest;
		try {
			$ch = curl_init();
			//Change the user agent below suitably
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9');
			curl_setopt($ch, CURLOPT_URL, ($url));
			curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . "/cookies.tmp");
			curl_setopt($ch, CURLOPT_COOKIEJAR, __DIR__ . "/cookies.tmp");
			curl_setopt($ch, CURLOPT_COOKIESESSION, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			if (!empty($post_params)) {
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);
			}

			$data = curl_exec($ch);

			if (!$data) {
				throw new MWException( "Error getting data from server: " . curl_error($ch) );
			}

			curl_close($ch);
		} catch ( Exception $e ) {
			throw new MWException( "Error getting data from server: " . $e->getMessage() );
		}

		$apiResultData = json_decode( $data );
		if ( isset( $apiResultData->error ) && $apiResultData->error->code == 'readapidenied' ) {
			global $wgCloneDiffWikis;
			if ( count( $wgCloneDiffWikis ) == 1 ) {
				$selectedWiki = 0;
			} else {
				$selectedWiki = $wgRequest->getVal( 'remoteWiki' );
			}
			if ( $selectedWiki == '' ) {
				return $apiResultData;
			}
			$apiURL = $wgCloneDiffWikis[$selectedWiki]['API URL'];
			$login_token = '';
			$token_result = self::httpRequest( $apiURL . '?action=query&meta=tokens&type=login&format=json' );
			$login_token = $token_result->query->tokens->logintoken;

			$post_params = http_build_query(
				array(
					"lgname" => $wgRequest->getVal('remote_username'),
					"lgpassword" => $wgRequest->getVal('remote_password'),
					"lgtoken" => $login_token
				)
			);

			$login_result = self::httpRequest( $apiURL . "?action=login&format=json",  $post_params );
			if ( $login_result->login->result == "Success" ) {
				return self::httpRequest( $url );
			} else {
				throw new MWException( "Login failed. Please check the entered username and password." );
			}
		}
		return $apiResultData;
	}
}