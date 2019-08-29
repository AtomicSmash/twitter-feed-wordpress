# Twitter feed for WordPress

## Installation

To make the class available, please add the following to your composer file:

```json
"atomicsmash/twitter-feed-wordpress" : "*",
```

Next, create a twitter app and generate your API access keys [here](https://apps.twitter.com/).

Then add these inside your environment specific constants to your wp-config file,
filling in the values as appropriate:

```php
define('TWITTER_CONSUMER_KEY','');
define('TWITTER_CONSUMER_SECRET','');
define('TWITTER_OAUTH_TOKEN','');
define('TWITTER_OAUTH_TOKEN_SECRET','');
```

At the current time you can only have one twitter feed per site however this may
be changed in the future.

## Pulling Tweets from a Specific User

Just add a constant specifying the username:

```php
define('TWITTER_USERNAME','');
```

Don't worry about adding the '@' symbol. For example
`define('TWITTER_USERNAME','atomicsmash')`

## Using feed in theme

You can query the cached tweets simply by calling the `get` method of the
twitterAPI class:

```php
if( isset( $twitterAPI ) ){

	$tweets = $twitterAPI->get([
		'results_per_page'	=> 4, 		// int
		'order'				=> 'asc',	// 'asc|desc'
		'tweet_type'		=> 'all'	// 'all|tweet|retweet|reply'
	]);

}
```

| Parameter 			| Type 	 | Description 									|
| :---					| :---:  | :--- 										|
| `results_per_page` 	| int 	 | The number of results to show per page 		|
| `order`				| string | `desc` for newest first, `asc` for oldest 	|
| `tweet_type` 			| string | One of `all`, `tweet`, `retweet` or `reply`. What kinds of tweet should be returned. |

## Background syncing

To sync tweets in background, schedule a cron job to run the command:

```bash
wp twitter sync_tweets
```

If you are using composer in your project, then your WordPress core files might be inside a subfolder. Please modify the path to reflect this. The cron job might look like this:

```bash
/usr/local/bin/wp twitter sync_tweets --path=/path/to/www.website.co.uk/wp
```
