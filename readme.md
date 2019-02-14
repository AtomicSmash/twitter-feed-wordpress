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
define('TWITTER_CONSUMER_KEY','xxxxxxxxxxxxxxxxxxx');
define('TWITTER_CONSUMER_SECRET','xxxxxxxxxxxxxxxxxxx');
define('TWITTER_OAUTH_TOKEN','xxxxxxxxxxxxxxxxxxx');
define('TWITTER_OAUTH_TOKEN_SECRET','xxxxxxxxxxxxxxxxxxx');
```

## Using API in theme

You can query the cached tweet by using:

```php
$args['results_per_page'] = 2;
$tweets = $twitterAPI->get($args);
```
Current arguments include:

- results_per_page
- order

## Todo

- Get API options into the admin interface
- Have a look at turning the classes and different classes into an 'interface'
