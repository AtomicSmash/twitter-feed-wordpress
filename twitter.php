<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

/**
 * Atomic Smash wrapper for the twitter API
 *
 * // ASREFACTOR: After looking through this class, there is no utility provided
 * to paginate the results for the table in the admin area. Not sure if required
 * but might be handy in the future?
 */
Class atomic_api
{

	/**
	 * @var string $type {@deprecated}
	 */
	public $type = '';

	/**
	 * @var array $recordArray Array of tweets
	 */
	public $recordArray = [];

	/**
	 * @var int $totalRecords Total records {@deprecated}
	 */
	public $totalRecords = 0;

	/**
	 * @var int $pageRecords Total records currently displayed {@deprecated}
	 */
	public $pageRecords = 0;

	/**
	 * @var int $resultsPerPage Number of results to load
	 */
	public $resultsPerPage = 20;

	/**
	 * @var string $api_table DB table name
	 */
	public $api_table = "";

	/**
	 * Used by code external to this class
	 */
	public $id;
	public $text;
	public $created_at;
	public $user_id;
	public $user_name;
	public $user_image;
	public $user_location;


	/**
	 * Constructor
	 *
	 * @uses $wpdb
	 *
	 * Adds action to the WP admin_menu
	 */
	public function __construct()
	{
		global $wpdb;

		$this->api_table = $wpdb->prefix . 'twitter_cache';

        $this->columns =  [
            'tweet' 		=> 'Tweet',
            'user_handle' 	=> 'Username',
			'tweet_type'	=> 'Type'
        ];

        //$this->setupMenus();
        add_action( 'admin_menu', array( $this, 'setupMenus') );
	}


	/**
	 * Setup table for API
	 *
	 * @return bool Allways returns true
	 */
	function create_table()
	{
    	global $wpdb;

    	$charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $sql = "CREATE TABLE $this->api_table (
            id BIGINT(20) NOT NULL,
            tweet text,
            tweet_type varchar(26) NOT NULL,
            likes int(9) NOT NULL,
            retweets int(9) NOT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL,
            user_id BIGINT(22) NOT NULL,
            user_name varchar(26) NOT NULL,
            user_handle varchar(26) NOT NULL,
    		user_image varchar(130) NOT NULL,
            hashtags LONGTEXT NOT NULL,
            symbols LONGTEXT NOT NULL,
            user_mentions LONGTEXT NOT NULL,
            urls LONGTEXT NOT NULL,
            media LONGTEXT NOT NULL,
            hidden BOOLEAN NOT NULL,
    		UNIQUE KEY id (id)
    	) $charset_collate;";

        dbDelta( $sql );

		return true;
	}


	/**
	 * Deletes the table used to cache tweets
	 *
	 * @return bool Always returns true
	 */
	function delete_table()
	{
		global $wpdb;

    	$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $table_name = $wpdb->prefix . 'api_twitter';
        $sql = "DROP TABLE $table_name";

        dbDelta( $sql );

		return true;
	}


	/**
	 * Allow direct setting of class properties
	 *
	 * Since we're not running strict_mode we can just use:
	 * ```php
	 * $atomic_api->foo = 'bar';
	 * ```
	 *
	 * @deprecated
	 *
	 * @param string $name 	Property name
	 * @param mixed  $value Property value
	 *
	 * @return void N/a
	 */
	public function create_variables( $name, $value )
	{
		$this->{$name} = $value;
	}


	/**
	 * Add submenu's for this plugin
	 *
	 * @return void N/a
	 */
	public function setupMenus()
	{
        add_submenu_page("atomic_apis", 'Twitter', 'Twitter', 'edit_posts', 'atomic_apis', [$this,'apiListPage']);
	}


	/**
	 * Display a list of the currently stored tweets
	 *
	 * @return void N/a
	 */
    public function apiListPage()
	{
		if ( !defined('TWITTER_CONSUMER_KEY') ) {

			?>
				<div class="wrap">
					<h2>Twitter API details</h2>
					Looks like you need to add these Constants to your config file:
					<pre>
						define('TWITTER_CONSUMER_KEY', '');		<br />
						define('TWITTER_CONSUMER_SECRET', ''); 	<br />
						define('TWITTER_OAUTH_TOKEN', ''); 		<br />
						define('TWITTER_OAUTH_TOKEN_SECRET', '');
					</pre>
				 	Once these are in place, come back here to sync your apis!
				</div>
			<?php

		} else {

			if ( isset($_GET['sync']) ) {
				$this->pull();
			};

            $entries = $this->get();

			// Define a new table using our column definition
	    	$placeListTable = new Atomic_Api_List_Table( $this->columns );

			$placeListTable->prepare_items();

            $placeListTable->items = $this->recordArray;

			?>
				<div class="wrap">
					<h2>Tweets <?= $this->sync_log( true ) ?><a  href="admin.php?page=atomic_apis&sync=1" class="add-new-h2">Sync</a></h2>
					<form id="items-filter" method="get">
						<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>"/>
						<?= $placeListTable->display(); ?>
					</form>
				</div>
			<?php
		}
    }


	/**
	 * Get stored tweets from the database
	 *
	 * @param	object $query_args Additional query parameters
	 * @return 	array 			   Tweets
	 */
    public function get( $query_args = [] )
	{
        global $wpdb;

		$default_args = [
			'results_per_page'	=> $this->resultsPerPage,
			'page'				=> 1,
			'keyword'			=> '',
			'orderby'			=> 'id',
			'order'				=> 'desc',
			'tweet_type'		=> 'all'
		];

        // Merge query args with defaults, keeping only items that have keys in defaults
		$query_args = array_intersect_key($query_args + $default_args, $default_args);

		// Make tweet_type an array to deal with multiple options.
		if (is_string($query_args['tweet_type'])) {
			$query_args['tweet_type'] = array ($query_args['tweet_type']);
		}

        // Pagination
        $this->resultsPerPage = is_numeric($query_args['results_per_page']) ? $query_args['results_per_page'] : $this->resultsPerPage;

        $firstResult = (int)($query_args['page'] - 1) * $this->resultsPerPage;

        $whereSqlLines = array();
        $extra_join = array();
        $groupSql = '';

        $fields = "*";

        $mainSql  = "SELECT " . $fields . " FROM " . $this->api_table . " l " . implode(' ',$extra_join);

        $countSql = "SELECT count(l.question_group_id) FROM " . $this->api_table .  " l ";

        if ( $this->resultsPerPage > 0 ) {
            $limitSql = $wpdb->prepare("LIMIT %d,%d ", $firstResult, $this->resultsPerPage);
        } else {
            $limitSql = "";
        }

        // Text search filter
        if ( $query_args['keyword'] ) {

            $search_terms = explode(' ', $query_args['keyword']);

            foreach ( $search_terms as $search_term ) {

                if (is_numeric($search_term)) {
                    $innerWhere[] = $wpdb->prepare( "(l.field1 LIKE '%s' OR l.field2 LIKE '%s' OR l.field3 = %d)",
                        '%' . $search_term . '%',
                        '%' . $search_term . '%',
                        $search_term );
                } else {
                    $innerWhere[] = $wpdb->prepare( "(l.field1 LIKE '%s' OR l.field2 LIKE '%s')",
                        '%' . $search_term . '%',
                        '%' . $search_term . '%');
                }
            }

            $whereSqlLines[] = '(' . implode(" OR ", $innerWhere) . ')';
        }

		if ( $query_args['tweet_type'] !== array('all') ) {
			$type_sql = array();
			foreach ($query_args['tweet_type'] as $type){
				$type_sql[] = "`tweet_type` = '". $type ."'";
			}
			$whereSqlLines[] = "( " . implode(' OR ', $type_sql) . " )";
		}

        $whereSql = "";

        if ( $whereSqlLines ) {
            $whereSql = 'WHERE ' . implode(' AND ',$whereSqlLines) . ' ';
        }

		// Sort Order
        $orderSql = 'ORDER BY ' . $query_args['orderby'] . ' ' . strtoupper($query_args['order']) . ' ';

        $fullSql = $mainSql . ' ' . $whereSql . ' ' . $groupSql . ' ' . $orderSql . ' ' .$limitSql;

		$wpdb->show_errors();

        $this->recordArray = $wpdb->get_results($fullSql , 'ARRAY_A');
		// $this->recordArray = $wpdb->get_results($fullSql);

		if ( !empty($this->recordArray) ) {
			foreach( $this->recordArray as $key => $tweet ) {

				$this->recordArray[$key] = array_merge( $tweet, [
					'human_time_ago'	=> $this->human_elapsed_time($this->recordArray[$key]['created_at']),
					'tweet_with_links'	=> $this->linkify($this->recordArray[$key]['tweet']),
					'url'				=> "https://twitter.com/" . $this->recordArray[$key]['user_handle'] . "/status/" . $this->recordArray[$key]['id'],
					'hashtags'			=> unserialize( $this->recordArray[$key]['hashtags'] ),
					'symbols'			=> unserialize( $this->recordArray[$key]['symbols'] ),
					'user_mentions'		=> unserialize( $this->recordArray[$key]['user_mentions'] ),
					'urls'				=> unserialize( $this->recordArray[$key]['urls'] ),
					'media'				=> unserialize( $this->recordArray[$key]['media'] )
				]);

			}
		}

		return $this->recordArray;

        // $this->pageRecords = count($this->recordArray);

		// // If we have fewer than the max records on the first page, we can use that as the total
		// if ($page=0 && ($this->pageRecords < $this->resultsPerPage)) {
		// 	$this->totalRecords = $this->pageRecords;
		// } else {
		// 	// Otherwise we need to work it out
		// 	$this->totalRecords = $wpdb->get_var($countSql . $whereSql);
		// }

    }


	/**
	 * Add links to a tweet
	 *
	 * @param  string $tweet Subject tweet
	 * @return string 		 Result
	 */
	public function linkify( $tweet )
	{

	  //Convert urls to <a> links
	  $tweet = preg_replace("/([\w]+\:\/\/[\w\-?&;#~=\.\/\@]+[\w\/])/", "<a target=\"_blank\" href=\"$1\">$1</a>", $tweet);

	  //Convert hashtags to twitter searches in <a> links
	  $tweet = preg_replace("/#([A-Za-z0-9\/\.]*)/", "<a target=\"_new\" href=\"http://twitter.com/search?q=$1\">#$1</a>", $tweet);

	  //Convert attags to twitter profiles in <a> links
	  $tweet = preg_replace("/@([A-Za-z0-9\/\.]*)/", "<a href=\"http://www.twitter.com/$1\">@$1</a>", $tweet);

	  return $tweet;
	}


	/**
	 * Method for the cron job to call
	 *
	 * Simply wraps the {@see atomic_api::pull()} function
	 *
	 * @return void N/a
	 */
	public function cronUpdate()
	{
		$this->pull();
	}


	/**
	 * Call API for tweets and process response
	 *
	 * @return array Tweets [Or empty]
	 */
    public function pull()
	{

		$stack = HandlerStack::create();

		$middleware = new Oauth1([
			'consumer_key'  	=> TWITTER_CONSUMER_KEY,
			'consumer_secret' 	=> TWITTER_CONSUMER_SECRET,
			'token'       		=> TWITTER_OAUTH_TOKEN,
			'token_secret'  	=> TWITTER_OAUTH_TOKEN_SECRET
		]);

		$stack->push($middleware);

		$client = new Client([
			'base_uri' => 'https://api.twitter.com/1.1/',
			'handler' => $stack
		]);

		// Pull from hashtag if it's defined
		// if( defined('TWITTER_HASHTAG') ){
		// 	$response = $client->get('search/tweets.json?q='.urlencode( '#'.TWITTER_HASHTAG ), ['auth' => 'oauth']);
		// 	$tweets = $response->getBody()->getContents();
		// 	$decodedContent = json_decode($tweets);
		// 	// Search results return a slightly different object
		// 	$decodedContent = $decodedContent->statuses;
		// }else{
			// $response = $client->get('statuses/user_timeline.json?tweet_mode=extended', ['auth' => 'oauth']);
			// $tweets = $response->getBody()->getContents();
			// $decodedContent = json_decode($tweets);
		// }

		if ( defined( 'TWITTER_USERNAME' ) ) {
			$response = $client->get('statuses/user_timeline.json?screen_name='.TWITTER_USERNAME.'&tweet_mode=extended&count=100', ['auth' => 'oauth']);
		} else {
			$response = $client->get('statuses/user_timeline.json?tweet_mode=extended&count=100', ['auth' => 'oauth']);
		}

		$tweets = $response->getBody()->getContents();

		$decodedContent = json_decode( $tweets );

		// Rudimentary error checking
		if ( json_last_error() !== JSON_ERROR_NONE ) {

			$decodedContent = [];

		} else {

			foreach ( $decodedContent as $key => $entry ) {
				$this->processEntry( $entry );
			}

			update_option( 'twitter_last_synced', date( 'U' ) );
		}

		return $decodedContent;
	}


	/**
	 * Get the last time the log was synched
	 *
	 * The function parameter is unnecessary and may be removed in the future.
	 *
	 * @param  boolean $output 	{@deprecated}
	 * @return string|void		Formatted last sync time
	 */
	public function sync_log( $output = true )
	{
		if ( $output !== false ) {

			$last_synced = get_option( 'twitter_last_synced' );

			if ( $last_synced === false ) {
				return "| Not yet synced";
			} else {
				return "| Synced " . human_time_diff( $last_synced ) . " ago";
			}
		}

		return;
	}


	/**
	 * Stores tweet in cache table, first checking to see if it already exists
	 *
	 * @param  object $entry 	Tweet
	 * @return void 			N/a
	 */
	public function processEntry( $entry = [] )
	{
		if( $this->exist($entry->id) === true ){
			$this->updateEntry($entry);
		} else {
			$this->insertEntry($entry);
		}

		return;
	}


	/**
	 * Check to see if API entry exists
	 *
	 * @param 	string $id 	API ID
	 * @return 	bool 		Whether the entry exists
	 */
	public function exist( $id = '' )
	{
		global $wpdb;

		$result = $wpdb->get_results ("SELECT id FROM " . $this->api_table . " WHERE id = '" . $id . "'");

		return !empty( $result );
	}


	/**
	 * Insert a tweet from the API into the database
	 *
	 * @param object $entry Tweet data
	 * @return void			N/a
	 */
    public function insertEntry( $entry = [] )
	{

		global $wpdb;
		$wpdb->show_errors();

		if ( isset( $entry->entities->hashtags ) ) {
			$hashtags = $entry->entities->hashtags;
		} else {
			$hashtags = [];
		}

		if ( isset( $entry->entities->symbols ) ) {
			$symbols = $entry->entities->symbols;
		} else {
			$symbols = [];
		}

		if ( isset( $entry->entities->user_mentions ) ) {
			$user_mentions = $entry->entities->user_mentions;
		} else {
			$user_mentions = [];
		}

		if ( isset( $entry->entities->urls ) ) {
			$urls = $entry->entities->urls;
		} else {
			$urls = [];
		}

		if ( isset( $entry->entities->media ) ) {
			$media = $entry->entities->media;
		} else {
			$media = [];
		}

		//ASTODO this is a dupe of update
		$wpdb->insert( $this->api_table, [
				'id' => $entry->id,																				// d
				'tweet' => html_entity_decode(stripslashes($entry->full_text), ENT_QUOTES),							// s
				'tweet_type' => $this->get_tweet_type( $entry ),						// s
				'created_at' => date( "Y-m-d H:i:s", strtotime($entry->created_at)),							// s
				'likes' => html_entity_decode($entry->favorite_count,ENT_QUOTES),							// d
				'retweets' => html_entity_decode($entry->retweet_count,ENT_QUOTES),							// d
				'updated_at' => date( "Y-m-d H:i:s", time()),													// s
				'user_id' => html_entity_decode($entry->user->id,ENT_QUOTES),									// d
				'user_name' => html_entity_decode(stripslashes($entry->user->name), ENT_QUOTES),				// s
				'user_handle' => html_entity_decode(stripslashes($entry->user->screen_name), ENT_QUOTES),		// s
				'user_image' => html_entity_decode(stripslashes($entry->user->profile_image_url), ENT_QUOTES),	// s
				'hashtags' => html_entity_decode(stripslashes( serialize( $hashtags ) ), ENT_QUOTES),	// s
				'symbols' => html_entity_decode(stripslashes( serialize( $symbols ) ), ENT_QUOTES),	// s
				'user_mentions' => html_entity_decode(stripslashes( serialize( $user_mentions ) ), ENT_QUOTES),	// s
				'urls' => html_entity_decode(stripslashes( serialize( $urls ) ), ENT_QUOTES),	// s
				'media' => html_entity_decode(stripslashes( serialize( $media ) ), ENT_QUOTES),	// s
				'hidden' => 0,																					// d
			], [
				'%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'
			]
		);

		return;
	}


	/**
	 * Update an existing tweet in the DB table
	 *
	 * @param  object $entry Tweet
	 * @return void			 N/a
	 */
	public function updateEntry( $entry = [] )
	{

		global $wpdb;
		$wpdb->show_errors();


		$hashtags = isset($entry->entities->hashtags) ? $entry->entities->hashtags : [];

		// if ( isset( $entry->entities->hashtags ) ) {
		// 	$hashtags = $entry->entities->hashtags;
		// } else{
		// 	$hashtags = [];
		// }

		if ( isset( $entry->entities->symbols ) ) {
			$symbols = $entry->entities->symbols;
		} else {
			$symbols = [];
		}

		if ( isset( $entry->entities->user_mentions ) ) {
			$user_mentions = $entry->entities->user_mentions;
		} else {
			$user_mentions = [];
		}

		if ( isset( $entry->entities->urls ) ) {
			$urls = $entry->entities->urls;
		} else {
			$urls = [];
		}

		if ( isset( $entry->entities->media ) ) {
			$media = $entry->entities->media;
		} else {
			$media = [];
		}

		$wpdb->update( $this->api_table, [
			'id' => $entry->id,																				// d
			'tweet' => html_entity_decode(stripslashes($entry->full_text), ENT_QUOTES),							// s
			'tweet_type' => $this->get_tweet_type( $entry ),						// s
			'created_at' => date( "Y-m-d H:i:s", strtotime($entry->created_at)),							// s
			'likes' => html_entity_decode($entry->favorite_count,ENT_QUOTES),							// d
			'retweets' => html_entity_decode($entry->retweet_count,ENT_QUOTES),							// d
			'updated_at' => date( "Y-m-d H:i:s", time()),													// s
			'user_id' => html_entity_decode($entry->user->id,ENT_QUOTES),									// d
			'user_name' => html_entity_decode(stripslashes($entry->user->name), ENT_QUOTES),				// s
			'user_handle' => html_entity_decode(stripslashes($entry->user->screen_name), ENT_QUOTES),		// s
			'user_image' => html_entity_decode(stripslashes($entry->user->profile_image_url), ENT_QUOTES),	// s
			'hashtags' => html_entity_decode(stripslashes( serialize( $hashtags ) ), ENT_QUOTES),	// s
			'symbols' => html_entity_decode(stripslashes( serialize( $symbols ) ), ENT_QUOTES),	// s
			'user_mentions' => html_entity_decode(stripslashes( serialize( $user_mentions ) ), ENT_QUOTES),	// s
			'urls' => html_entity_decode(stripslashes( serialize( $urls ) ), ENT_QUOTES),	// s
			'media' => html_entity_decode(stripslashes( serialize( $media ) ), ENT_QUOTES),	// s
			'hidden' => 0,																					// d
		], [
            'id' => $entry->id
        ], [
			'%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'
		], [
            '%d'
        ]);

		return;
	}


	/**
	 * Return formatted string of the difference between now and the given date
	 *
	 * @param  string  $datetime UTC time string
	 * @param  boolean $full     Output full string
	 * @return string            Formatted date
	 */
	public function human_elapsed_time( $datetime, $full = false )
	{
	    $now = new DateTime;
	    $ago = new DateTime( $datetime );
	    $diff = $now->diff($ago);

	    $diff->w = floor($diff->d / 7);
	    $diff->d -= $diff->w * 7;

	    $string = [
	        'y' => 'year',
	        'm' => 'month',
	        'w' => 'week',
	        'd' => 'day',
	        'h' => 'hour',
	        'i' => 'minute',
	        's' => 'second',
	    ];

	    foreach ($string as $k => &$v) {
	        if ( $diff->$k ) {
	            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
	        } else {
	            unset($string[$k]);
	        }
	    }

	    if ( !$full ) {
			$string = array_slice($string, 0, 1);
		}

	    return $string ? implode(', ', $string) . ' ago' : 'just now';
	}


	/**
	 * Perform naive analysis to guess the type of tweet
	 *
	 * @param  array $tweet Tweet
	 * @return string		Tweet type
	 */
	public function get_tweet_type( $tweet )
	{
		if ( isset($tweet->retweeted_status) && !is_null($tweet->retweeted_status) ) {
			$tweet_type = 'retweet';
		} elseif ( isset($tweet->in_reply_to_status_id) && is_numeric($tweet->in_reply_to_status_id) ) {
			$tweet_type = 'reply';
		} else {
			$tweet_type = 'tweet';
		}

		return $tweet_type;
	}

} // atomic_api


if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * Sync tweet from Twitter API
     *
     * wp atomicsmash create_dates_varient today
     */
    class TWITTER_CLI extends WP_CLI_Command
	{
        public function sync_tweets( $order_id = '' )
		{
			global $twitterAPI;

			$twitterAPI->pull();

			WP_CLI::success( "Tweets synced" );
        }
    }

    WP_CLI::add_command( 'twitter', 'TWITTER_CLI' );
}


//Use this page as a ref: http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/
// TODO: Need to sort pagination

class Atomic_Api_List_Table extends Twitter_Wordpress_List_Table {

	function __construct($columns = array()){

        $this->columns = $columns;

        parent::__construct( array(
			'singular'  => 'item',  //singular name of the listed records
			'plural'    => 'items', //plural name of the listed records
			'ajax'      => false    //does this table support ajax?
		) );

	}

	//Setup column defaults
	function column_default($item, $column_name){
        switch( $column_name ) {
			// case 'tweet':
            // case 'added_at':
            // case 'user_location':
            // return $item[ $column_name ];
			case 'user_image':
			return "<img src='".$item[ $column_name ]."' />";
			case 'user_handle':
			return "@".$item[ $column_name ];
          default:
            return $item[ $column_name ]; //Show the whole array for troubleshooting purposes
        }
	}

	// Prep data for display
	function prepare_items() {
        //Get api items from Atomic_Api_Entry_List

        $columns = $this->columns;
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

	}

}
