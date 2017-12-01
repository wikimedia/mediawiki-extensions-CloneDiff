== About ==

CloneDiff is a MediaWiki extension that lets you compare pages on
the local wiki to pages in one or more "clone wikis" - wikis that
have some of the same page structure, though with possibly
different contents in the pages. For pages that are different (or
when the page only exists in the remote wiki), the extension lets
you also import the current text on the remote wiki into the
local wiki.

== Download ==

To download the code, call the following in the /extensions
directory:

git clone https://bitbucket.org/wikiworksdev/clonediff.git CloneDiff


== Installation ==

To install CloneDiff, add the following to LocalSettings.php:

wfLoadExtension( 'CloneDiff' );

You then need to add one or more values for $wgCloneDiffWikis
in order to use the extension. Each such value has to be an
array of three elements: 'name', 'API URL' and 'URL'. Here is
one example:

$wgCloneDiffWikis[] = array(
	'name' => 'My Example Wiki',
	'API URL' => 'https://example.org/w/api.php',
	'URL' => 'https://example.com'
);

The 'clonediff' permission lets you dictate who can access the
page Special:CloneDiff. By default, only administrators can.
To enable, for instance, all users to access that page, you
could add the following to LocalSettings.php:

$wgGroupPermissions['user']['clonediff'] = true;

== Usage ==

If you are an administrator, you can make use of the CloneDiff
functionality by simply going to the page "Special:CloneDiff"
and following the instructions.

== Credits ==

The CloneDiff extension was created by Yaron Koren for WikiWorks.
