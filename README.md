[![Latest Stable Version](https://poser.pugx.org/neos/redirecthandler-neosadapter/v/stable)](https://packagist.org/packages/neos/redirecthandler-neosadapter)
[![Total Downloads](https://poser.pugx.org/neos/redirecthandler-neosadapter/downloads)](https://packagist.org/packages/neos/redirecthandler-neosadapter)
[![License](https://poser.pugx.org/neos/redirecthandler-neosadapter/license)](LICENSE)

# Neos redirect handler

This package enables automatic redirects for renamed/moved pages. This helps with SEO and user experience by avoiding dead links.

Additionally a `410` (gone) status code will be given for removed pages instead of `404` (not found). 

Check the [documentation](https://neos-redirecthandler-adapter.readthedocs.io/en/latest/) for all features and usage.

## Installation

To use the redirect package, you have to install this package.

    composer require "neos/redirecthandler-neosadapter"

and additionally a storage package. A default one for storing redirects in the database can be installed using composer with 

    composer require "neos/redirecthandler-databasestorage"

The backend UI module for managing redirects manually can be installed using composer with 

    composer require "neos/redirecthandler-ui"

### Adjusting your webserver configuration

**Note**: When using this to handle redirects for persistent resources, you must adjust the default
rewrite rules! By default, any miss for `_Resources/…` stops the request and returns a 404 from the
webserver directly:
  
  	# Make sure that not existing resources don't execute Flow
	RewriteRule ^_Resources/.* - [L]

For the redirect handler to even see the request, this has to be removed. Usually the performance impact
can be neglected, since Flow is only hit for resources that once existed and to which someone still holds
a link.

## Configuration

You can find the configuration options in the [documentation](https://neos-redirecthandler-adapter.readthedocs.io/en/latest/).

## License

See [License](./LICENSE.txt).
