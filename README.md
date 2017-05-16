# Neos redirect handler

This package enables automatic redirects for renamed/moved pages. This helps with SEO and user experience by avoiding dead links.

Additionally a `410` (gone) status code will be given for removed pages instead of `404` (not found).

## Installation

To use the redirect package, you have to install this package
	
	composer require "neos/redirecthandler-neosadapter"
	
and additionally a storage package. A default one for storing redirects in the database can be installed using composer with 

	composer require "neos/redirecthandler-databasestorage"

## Configuration

**Note**: When using this to handle redirects for persistent resources, you must adjust the default
rewrite rules! By default, any miss for `_Resources/…` stops the request and returns a 404 from the
webserver directly:
  
  	# Make sure that not existing resources don't execute Flow
	RewriteRule ^_Resources/.* - [L]

For the redirect handler to even see the request, this has to be removed. Usually the performance impact
can be neglected, since Flow is only hit for resources that once existed and to which someone still holds
a link.
