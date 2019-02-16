# Apis

## Installing

To install add this to your composer file:

```
"atomicsmash/apis" : "dev-master"
```

## Setup Twitter API

Create a twitter app and generate API access keys from https://apps.twitter.com/.

Then add these inside your environment specific constants to your wp-config file:

```php
define('TWITTER_CONSUMER_KEY','');
define('TWITTER_CONSUMER_SECRET','');
define('TWITTER_OAUTH_TOKEN','');
define('TWITTER_OAUTH_TOKEN_SECRET','');
```

## Using API in theme

You can query the cached tweet by using:

```php
if( isset( $twitterAPI ) ){
	$args['results_per_page'] = 4;
	$tweets = $twitterAPI->get($args);
}
```
Current arguments include:

- results_per_page
- order
