<?php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

class atomic_api {

	public $type = '';
	public $recordArray = array();
	public $totalRecords = 0;
	public $pageRecords = 0;
	public $resultsPerPage = 15;
	public $api_table = "";

	//Variables for later use
	public $id;
	public $text;
	public $created_at;
	public $user_id;
	public $user_name;
	public $user_image;
	public $user_location;


	// Class Constructor
	public function __construct() {
		global $wpdb;

		$this->api_table = $wpdb->prefix . 'twitter_cache';

        $this->columns =  array(
            'tweet' => 'Tweet',
            'user_handle' => 'Username'
        );

        //$this->setupMenus();
        add_action( 'admin_menu', array( $this, 'setupMenus') );

	}

	/**
	 * Setup table for API
	 * @return bool yet reurn isn't used
	 */
	function create_table() {

    	global $wpdb;
    	$charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $table_name = $wpdb->prefix . 'twitter_cache';
        $sql = "CREATE TABLE $table_name (
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

	function delete_table() {

		global $wpdb;
    	$charset_collate = $wpdb->get_charset_collate();
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $table_name = $wpdb->prefix . 'api_twitter';
        $sql = "DROP TABLE $table_name";

        dbDelta( $sql );

		return true;

	}
	//dynamically declare public variables
	public function create_variables($name,$value){

		$this->{$name} = $value;

	}


	// GET Functions
	public function setupMenus() {

        add_submenu_page("atomic_apis", 'Twitter', 'Twitter', 'edit_posts', 'atomic_apis', array($this,'apiListPage'));

	}


    public function apiListPage() {


        echo '<div class="wrap">';

			if( !defined('TWITTER_CONSUMER_KEY') ){

				echo '<h2>Twitter API details</h2>';

				echo "Looks like you need to add these Constants to your config file:";

				echo "<pre>";
					echo "define('TWITTER_CONSUMER_KEY','');\n";
					echo "define('TWITTER_CONSUMER_SECRET','');\n";
					echo "define('TWITTER_OAUTH_TOKEN','');\n";
					echo "define('TWITTER_OAUTH_TOKEN_SECRET','');";
				echo "</pre>";

				echo "Once these are in place, come back here to sync your apis";


			}else{

				if(isset($_GET['sync'])){
					$this->pull();
				};

	            $entries = $this->get();

		    	$placeListTable = new Atomic_Api_List_Table($this->columns);

	            echo '<h2>Tweets '. $this->sync_log( true ) .' <a href="admin.php?page=atomic_apis&sync=1" class="add-new-h2">Sync</a></h2>';

		    	$placeListTable->prepare_items();

	            $placeListTable->items = $this->recordArray;

				?>
				<form id="items-filter" method="get">
					<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>"/>
					<?php
					// Now we can render the completed list table
					$placeListTable->display();
	                ?>
				</form>
				<?php
			}

		echo '</div>';

    }

    public function get( $query_args=array() ) {
        global $wpdb;

        $default_args['results_per_page'] = $this->resultsPerPage;
        $default_args['page'] = 1;
        $default_args['keyword'] = '';
        $default_args['orderby'] = 'id';
        $default_args['order'] = 'desc';
        $default_args['tweet_type'] = 'all';

        // Merge query args with defaults, keeping only items that have keys in defaults
        $query_args = array_intersect_key($query_args + $default_args, $default_args);

        // Pagination
        $this->resultsPerPage = $query_args['results_per_page'];

        $firstResult = (int)($query_args['page']-1) * $this->resultsPerPage;

        $whereSqlLines = array();
        $extra_join = array();
        $groupSql = '';

        $fields = "*";

        $mainSql  = "SELECT " . $fields . " FROM " . $this->api_table . " l " . implode(' ',$extra_join);

        $countSql = "SELECT count(l.question_group_id) FROM " . $this->api_table .  " l ";

        if ($this->resultsPerPage>0) {
            $limitSql = $wpdb->prepare("LIMIT %d,%d ", $firstResult, $this->resultsPerPage);
        } else {
            $limitSql = "";
        }

        // Text search filter
        if ($query_args['keyword']) {
            $search_terms = explode(' ',$query_args['keyword']);
            foreach ($search_terms as $search_term) {
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

		if ( $query_args['tweet_type'] != "all" ) {
			$whereSqlLines[] = "( `tweet_type` = '". $query_args['tweet_type'] ."' )";
		}

        $whereSql = "";
        if ($whereSqlLines) {
            $whereSql = 'WHERE ' . implode(' AND ',$whereSqlLines) . ' ';
        }

		// Sort Order
        $orderSql = 'ORDER BY ' . $query_args['orderby'] . ' ' . strtoupper($query_args['order']) . ' ';

        $fullSql = $mainSql . ' ' . $whereSql . ' ' . $groupSql . ' ' . $orderSql . ' ' .$limitSql;

		$wpdb->show_errors();
        $this->recordArray = $wpdb->get_results($fullSql , 'ARRAY_A');
		// $this->recordArray = $wpdb->get_results($fullSql);


		if(count($this->recordArray) > 0){
			foreach($this->recordArray as $key => $tweet){
				$this->recordArray[$key]['human_time_ago'] = $this->human_elapsed_time($this->recordArray[$key]['created_at']);
				$this->recordArray[$key]['tweet_with_links'] = $this->linkify($this->recordArray[$key]['tweet']);
				$this->recordArray[$key]['url'] = "https://twitter.com/" . $this->recordArray[$key]['user_handle'] . "/status/" . $this->recordArray[$key]['id'];
				$this->recordArray[$key]['hashtags'] = unserialize( $this->recordArray[$key]['hashtags'] );
				$this->recordArray[$key]['symbols'] = unserialize( $this->recordArray[$key]['symbols'] );
				$this->recordArray[$key]['user_mentions'] = unserialize( $this->recordArray[$key]['user_mentions'] );
				$this->recordArray[$key]['urls'] = unserialize( $this->recordArray[$key]['urls'] );
				$this->recordArray[$key]['media'] = unserialize( $this->recordArray[$key]['media'] );
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

	public function linkify($tweet) {

	  //Convert urls to <a> links
	  $tweet = preg_replace("/([\w]+\:\/\/[\w-?&;#~=\.\/\@]+[\w\/])/", "<a target=\"_blank\" href=\"$1\">$1</a>", $tweet);

	  //Convert hashtags to twitter searches in <a> links
	  $tweet = preg_replace("/#([A-Za-z0-9\/\.]*)/", "<a target=\"_new\" href=\"http://twitter.com/search?q=$1\">#$1</a>", $tweet);

	  //Convert attags to twitter profiles in <a> links
	  $tweet = preg_replace("/@([A-Za-z0-9\/\.]*)/", "<a href=\"http://www.twitter.com/$1\">@$1</a>", $tweet);

	  return $tweet;

	}


	public function cronUpdate() {
		$this->pull();
	}


	/**
	 * Call API for results, then process
	 * @return [type] [description]
	 */
    public function pull() {

		$stack = HandlerStack::create();

		$middleware = new Oauth1([
			'consumer_key'  => TWITTER_CONSUMER_KEY,
			'consumer_secret' => TWITTER_CONSUMER_SECRET,
			'token'       => TWITTER_OAUTH_TOKEN,
			'token_secret'  => TWITTER_OAUTH_TOKEN_SECRET
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

		if( defined( 'TWITTER_USERNAME' ) ){
			$response = $client->get('statuses/user_timeline.json?screen_name='.TWITTER_USERNAME.'&tweet_mode=extended&count=100', ['auth' => 'oauth']);
		}else{
			$response = $client->get('statuses/user_timeline.json?tweet_mode=extended&count=100', ['auth' => 'oauth']);
		}

		$tweets = $response->getBody()->getContents();
		$decodedContent = json_decode($tweets);

		foreach ($decodedContent as $key => $entry) {

			$this->processEntry($entry);

		};

		update_option( 'twitter_last_synced', date( 'U' ) );

		return $decodedContent;

	}

	public function sync_log( $output = false ){

		if( $output != false ){
			$last_synced = get_option( 'twitter_last_synced' );

			if( $last_synced === false ){
				return "| Not yet synced";
			}else{
				$time_diff = human_time_diff( $last_synced );
				return "| Synced " . $time_diff . " ago";
			}
		}
	}


	/**
	 * Process return to see if it already exists
	 * @param  array  $entry [description]
	 * @return [type]        [description]
	 */
	public function processEntry($entry=array()) {

		if($this->exist($entry->id) == true){
			return $this->updateEntry($entry);
		}else{
			return $this->insertEntry($entry);
		}

	}

	/**
	 * Check to see if API entry exists
	 * @param  string $id API ID
	 * @return [bool] Returns whether the entry exists
	 */
	public function exist($id = "") {

		global $wpdb;

		$result = $wpdb->get_results ("SELECT id FROM ".$this->api_table." WHERE id = '".$id."'");

		if (count ($result) > 0) {
			//$row = current ($result);
			return true;
		} else {
			return false;
		}

	}



    public function insertEntry($entry = array()) {

		global $wpdb;
		$wpdb->show_errors();

		if( isset( $entry->entities->hashtags ) ){
			$hashtags = $entry->entities->hashtags;
		}else{
			$hashtags = array();
		}

		if( isset( $entry->entities->symbols ) ){
			$symbols = $entry->entities->symbols;
		}else{
			$symbols = array();
		}

		if( isset( $entry->entities->user_mentions ) ){
			$user_mentions = $entry->entities->user_mentions;
		}else{
			$user_mentions = array();
		}

		if( isset( $entry->entities->urls ) ){
			$urls = $entry->entities->urls;
		}else{
			$urls = array();
		}

		if( isset( $entry->entities->media ) ){
			$media = $entry->entities->media;
		}else{
			$media = array();
		}

		//ASTODO this is a dupe of update
		$wpdb->insert($this->api_table,
			array(
				'id' => $entry->id,																				// d
				'tweet' => html_entity_decode(stripslashes($entry->full_text), ENT_QUOTES),							// s
				'tweet_type' => $this->get_tweet_type( $entry->full_text ),						// s
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
			),
			array(
				'%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'
			)
		);

		return "added";
	}

	public function updateEntry($entry = array()) {

		global $wpdb;
		$wpdb->show_errors();


		if( isset( $entry->entities->hashtags ) ){
			$hashtags = $entry->entities->hashtags;
		}else{
			$hashtags = array();
		}

		if( isset( $entry->entities->symbols ) ){
			$symbols = $entry->entities->symbols;
		}else{
			$symbols = array();
		}

		if( isset( $entry->entities->user_mentions ) ){
			$user_mentions = $entry->entities->user_mentions;
		}else{
			$user_mentions = array();
		}

		if( isset( $entry->entities->urls ) ){
			$urls = $entry->entities->urls;
		}else{
			$urls = array();
		}

		if( isset( $entry->entities->media ) ){
			$media = $entry->entities->media;
		}else{
			$media = array();
		}


		$wpdb->update($this->api_table,
			array(
				'id' => $entry->id,																				// d
				'tweet' => html_entity_decode(stripslashes($entry->full_text), ENT_QUOTES),							// s
				'tweet_type' => $this->get_tweet_type( $entry->full_text ),						// s
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
			),
			array(
                'id' => $entry->id
            ),
			array(
				'%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'
			),
			array(
                '%d'
            )
		);

		return "updated";
	}

	public function human_elapsed_time($datetime, $full = false) {
	    $now = new DateTime;
	    $ago = new DateTime($datetime);
	    $diff = $now->diff($ago);

	    $diff->w = floor($diff->d / 7);
	    $diff->d -= $diff->w * 7;

	    $string = array(
	        'y' => 'year',
	        'm' => 'month',
	        'w' => 'week',
	        'd' => 'day',
	        'h' => 'hour',
	        'i' => 'minute',
	        's' => 'second',
	    );
	    foreach ($string as $k => &$v) {
	        if ($diff->$k) {
	            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
	        } else {
	            unset($string[$k]);
	        }
	    }

	    if (!$full) $string = array_slice($string, 0, 1);
	    return $string ? implode(', ', $string) . ' ago' : 'just now';
	}

	public function get_tweet_type( $tweet_text = "" ){

		$tweet_type = "tweet";

		if( substr( $tweet_text, 0, 3 ) === 'RT ' ) {
			$tweet_type = "retweet";
		}

		return $tweet_type;

	}

}


if ( defined( 'WP_CLI' ) && WP_CLI ) {
    /**
     * Sync tweet from Twitter API
     *
     * wp atomicsmash create_dates_varient todayÊ
     */
    class TWITTER_CLI extends WP_CLI_Command {
        public function sync_tweets($order_id = ""){
			global $twitterAPI;

			$twitterAPI->pull();

			WP_CLI::success( "Tweets synced" );

        }
    }

    WP_CLI::add_command( 'twitter', 'TWITTER_CLI' );

}


//Use this page as a ref: http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/
//Need to sort pagination

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
