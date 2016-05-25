# Neos redirect handler

This package enables automatic redirects for renamed/moved pages. This helps with SEO and user experience by avoiding dead links.

Additionally a `410` (gone) status code will be given for removed pages instead of `404` (not found).

## Installation 
To use the redirect package, you have to install this package
	
	composer require "neos/redirecthandler-neosadapter"
	
and additionally a storage package. A default one for storing redirects in the database can be installed using composer with 

	composer require "neos/redirecthandler-databasestorage"
